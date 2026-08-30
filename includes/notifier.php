<?php
/**
 * Webhook notifier.
 *
 * Flow: report.php -> queue_webhook() -> process_webhook_queue() (best effort,
 * synchronous). If delivery fails the row stays pending and cron/send-webhooks.php
 * retries it with exponential backoff.
 *
 * Supports three formats:
 *   - generic: raw JSON as-is
 *   - discord: Discord embed message
 *   - slack:  Slack-formatted text message
 */

define('WEBHOOK_EVENT_FAILED', 'backup_failed');
define('WEBHOOK_EVENT_PARTIAL', 'backup_partial');
define('WEBHOOK_EVENT_COMPLETED', 'backup_completed');
define('WEBHOOK_EVENT_STUCK', 'backup_stuck');

function queue_webhook(string $event, array $data): void
{
    $rows = db_query('SELECT * FROM webhooks WHERE is_active = 1')->fetchAll();
    $payload = json_encode(['event' => $event] + $data, JSON_UNESCAPED_SLASHES);
    foreach ($rows as $hook) {
        db_query(
            'INSERT INTO webhook_queue (webhook_id, event, payload, status, attempts, next_attempt_at, created_at)
             VALUES (?, ?, ?, "pending", 0, NOW(), NOW())',
            [(int)$hook['id'], $event, $payload]
        );
    }
}

function process_webhook_queue(int $limit = 10): void
{
    $rows = db_query(
        'SELECT * FROM webhook_queue
         WHERE status = "pending" AND next_attempt_at <= NOW()
         ORDER BY id ASC LIMIT ' . (int)$limit
    )->fetchAll();

    if (!$rows) {
        return;
    }

    // Pre-load all active webhooks into a map (avoids N+1 queries)
    $hooks = [];
    foreach (db_query('SELECT * FROM webhooks WHERE is_active = 1')->fetchAll() as $h) {
        $hooks[(int)$h['id']] = $h;
    }

    foreach ($rows as $item) {
        $hook = $hooks[(int)$item['webhook_id']] ?? null;
        if (!$hook) {
            db_query('UPDATE webhook_queue SET status = "failed" WHERE id = ?', [(int)$item['id']]);
            continue;
        }

        $attempt = (int)$item['attempts'] + 1;
        $result = send_webhook_to_url($hook['url'], $item['payload'], $hook['format'] ?? 'generic');

        // Pull server name + users from the payload for the delivery log
        $pl = json_decode($item['payload'], true);
        $logServer = is_array($pl) ? ($pl['server_name'] ?? $pl['server'] ?? null) : null;
        $logUsers  = is_array($pl) ? ($pl['cpanel_user'] ?? null) : null;

        db_query(
            'INSERT INTO webhook_logs (webhook_id, event, server_name, cpanel_user, status, http_status, response, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                (int)$hook['id'],
                $item['event'],
                $logServer,
                $logUsers,
                $result['ok'] ? 'success' : 'failed',
                $result['http_status'],
                $result['ok'] ? '' : mb_substr($result['error'], 0, 500),
            ]
        );

        if ($result['ok']) {
            db_query('UPDATE webhook_queue SET status = "success", attempts = ?, next_attempt_at = NOW() WHERE id = ?', [$attempt, (int)$item['id']]);
            db_query('UPDATE webhooks SET last_triggered_at = NOW() WHERE id = ?', [(int)$hook['id']]);
        } elseif ($attempt >= WEBHOOK_MAX_ATTEMPTS) {
            db_query('UPDATE webhook_queue SET status = "failed", attempts = ?, last_error = ?, next_attempt_at = NOW() WHERE id = ?', [$attempt, mb_substr($result['error'], 0, 500), (int)$item['id']]);
        } else {
            $backoff = min(3600, 60 * $attempt * $attempt);
            db_query('UPDATE webhook_queue SET attempts = ?, last_error = ?, next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?', [$attempt, mb_substr($result['error'], 0, 500), $backoff, (int)$item['id']]);
        }
    }
}

function send_webhook_to_url(string $url, string $payloadJson, string $format = 'generic'): array
{
    $data = json_decode($payloadJson, true);
    if (!is_array($data)) {
        $data = ['event' => 'unknown', 'raw' => $payloadJson];
    }

    // Fallback auto-detect from the URL so Discord/Slack URLs never receive a raw payload
    if ($format === 'generic') {
        if (strpos($url, 'discord.com') !== false) {
            $format = 'discord';
        } elseif (strpos($url, 'hooks.slack.com') !== false) {
            $format = 'slack';
        }
    }

    $formatted = format_payload_by_format($data, $format);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $formatted,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'User-Agent: JBWizerd-Panel/1.0',
        ],
    ]);
    $response = curl_exec($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    if ($response === false) {
        return ['ok' => false, 'http_status' => 0, 'error' => $error ?: 'Connection failed'];
    }
    return [
        'ok'          => $httpStatus >= 200 && $httpStatus < 300,
        'http_status' => $httpStatus,
        'error'       => mb_substr((string)$response, 0, 1000),
    ];
}

/**
 * Format payload for the target platform.
 */
function format_payload_by_format(array $data, string $format): string
{
    switch ($format) {
        case 'discord':
            return format_discord($data);
        case 'slack':
            return format_slack($data);
        default:
            return json_encode($data, JSON_UNESCAPED_SLASHES);
    }
}

/**
 * Format the user-grouped error log for a webhook:
 *  - Shows the FIRST user's errors only (up to 5 lines, condensed)
 *  - Other users are summarized: "... and N more users with errors (view full log in panel)"
 * The FULL log always stays in the panel (View Log).
 */
function error_log_webhook(string $errorLog): string
{
    $blocks = preg_split('/\n\s*\n/', trim($errorLog));
    if (!$blocks) {
        return '';
    }
    $firstBlock = array_shift($blocks);
    $lines = preg_split('/\n/', trim($firstBlock));
    $firstUser = array_shift($lines);
    if ($firstUser === null || $firstUser === '') {
        return '';
    }
    $errors = array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));
    $out = [$firstUser];
    $shown = 0;
    $lineLimit = 5;
    foreach ($errors as $err) {
        $out[] = '  ' . $err;
        $shown++;
        if ($shown >= $lineLimit) {
            $remaining = count($errors) - $shown;
            if ($remaining > 0) {
                $out[] = '  ... and ' . $remaining . ' more error' . ($remaining > 1 ? 's' : '') . ' for this user';
            }
            break;
        }
    }
    $remainingUsers = count($blocks);
    if ($remainingUsers > 0) {
        $out[] = '... and ' . $remainingUsers . ' more user' . ($remainingUsers > 1 ? 's' : '') . ' with errors (view full log in panel)';
    }
    return implode("\n", $out);
}

function format_discord(array $data): string
{
    $statusMeta = [
        'success' => ['Backup Completed', 0x3c8a3c],
        'partial' => ['Backup Partially Completed', 0xc97b1f],
        'failed'  => ['Backup Failed', 0xe74c3c],
        'aborted' => ['Backup Aborted', 0xe74c3c],
        'running' => ['Backup Running', 0x3b6fc4],
        'stuck'   => ['⚠ Backup Stuck — Check Manually', 0xe74c3c],
    ];
    $event = $data['event'] ?? 'backup';
    $status = $data['status'] ?? $event;
    [$label, $color] = $statusMeta[$status] ?? ['Backup Notification', 0x3b6fc4];

    $fields = [];
    if ($status === 'stuck') {
        $stuckHours = defined('STUCK_BACKUP_HOURS') ? (int)STUCK_BACKUP_HOURS : 24;
        $fields[] = ['name' => 'URGENT', 'value' => '⚠ Backup has been running for over ' . $stuckHours . ' hours without completing. Check it manually.', 'inline' => false];
    }
    $fields[] = ['name' => 'Server', 'value' => ($data['server'] ?? '—'), 'inline' => true];
    if (!empty($data['server_ip'])) {
        $fields[] = ['name' => 'IP Address', 'value' => $data['server_ip'], 'inline' => true];
    }
    if ($status !== 'success' && ($data['event'] ?? '') !== 'backup_stuck' && !empty($data['cpanel_user'])) {
        $fields[] = ['name' => 'cPanel User', 'value' => $data['cpanel_user'], 'inline' => true];
    }
    $fields[] = ['name' => 'Destination', 'value' => ($data['destination'] ?? '') ?: '—', 'inline' => true];
    if (!empty($data['disk_used']) && !empty($data['disk_total'])) {
        $storage = ($data['disk_used'] ?? '') . ' used / ' . ($data['disk_total'] ?? '') . ' total';
        if (!empty($data['disk_free'])) {
            $storage .= ' (' . $data['disk_free'] . ' free)';
        }
        $fields[] = ['name' => 'Storage', 'value' => $storage, 'inline' => true];
    }
    if (!empty($data['end_time'])) {
        $fields[] = ['name' => 'Last Updated', 'value' => 'at ' . fmt_dt_long($data['end_time']), 'inline' => true];
    }
    $fields[] = ['name' => 'Started', 'value' => fmt_dt($data['start_time'] ?? null), 'inline' => true];
    $fields[] = ['name' => 'Ended', 'value' => fmt_dt($data['end_time'] ?? null), 'inline' => true];
    $fields[] = ['name' => 'Duration', 'value' => ($data['duration'] ?? '') ?: '—', 'inline' => true];

    if (!empty($data['error_log'])) {
        $fields[] = ['name' => 'Error Log', 'value' => '```' . mb_substr(error_log_webhook($data['error_log']), 0, 1000) . '```', 'inline' => false];
    }

    // Discord embeds show this as the notification timestamp. Use the
    // backup's START time so the embed is grouped under the correct day
    // (a backup that starts Aug 28 and finishes Aug 29 stays on Aug 28).
    $embedTs = $data['start_time'] ?? date('Y-m-d H:i:s');
    $embed = [
        'title'     => $label,
        'color'     => $color,
        'footer'    => ['text' => 'JBWizerd Panel'],
        'timestamp' => to_rfc3339($embedTs),
        'fields'    => $fields,
    ];

    return json_encode([
        'username' => 'JBWizerd',
        'embeds'   => [$embed],
    ], JSON_UNESCAPED_SLASHES);
}

function format_slack(array $data): string
{
    $statusMeta = [
        'success' => 'Backup Completed',
        'partial' => 'Backup Partially Completed',
        'failed'  => 'Backup Failed',
        'aborted' => 'Backup Aborted',
        'running' => 'Backup Running',
        'stuck'   => '⚠ Backup Stuck — Check Manually',
    ];
    $event = $data['event'] ?? 'backup';
    $status = $data['status'] ?? $event;
    $label = $statusMeta[$status] ?? 'Backup Notification';

    $text = "*$label*";
    if ($status === 'stuck') {
        $stuckHours = defined('STUCK_BACKUP_HOURS') ? (int)STUCK_BACKUP_HOURS : 24;
        $text .= "\n:warning: *Backup has been running for over " . $stuckHours . " hours without completing. Check it manually.*";
    }
    $text .= "\n*Server:* " . ($data['server'] ?? '—');
    if (!empty($data['server_ip'])) {
        $text .= "\n*IP:* " . $data['server_ip'];
    }
    if ($status !== 'success' && ($data['event'] ?? '') !== 'backup_stuck' && !empty($data['cpanel_user'])) {
        $text .= "\n*cPanel User:* " . $data['cpanel_user'];
    }
    $text .= "\n*Destination:* " . ($data['destination'] ?: '—');
    if (!empty($data['disk_used']) && !empty($data['disk_total'])) {
        $storage = ($data['disk_used'] ?? '') . ' used / ' . ($data['disk_total'] ?? '') . ' total';
        if (!empty($data['disk_free'])) {
            $storage .= ' (' . $data['disk_free'] . ' free)';
        }
        $text .= "\n*Storage:* " . $storage;
    }
    if (!empty($data['end_time'])) {
        $text .= "\n*Last Updated:* at " . fmt_dt_long($data['end_time']);
    }
    $text .= "\n*Started:* " . fmt_dt($data['start_time'] ?? null);
    $text .= "\n*Ended:* " . fmt_dt($data['end_time'] ?? null);
    $text .= "\n*Duration:* " . ($data['duration'] ?: '—');

    if (!empty($data['error_log'])) {
        $text .= "\n*Error Log:*\n```" . mb_substr(error_log_webhook($data['error_log']), 0, 1000) . '```';
    }

    return json_encode(['text' => $text], JSON_UNESCAPED_SLASHES);
}

/**
 * Build the standard webhook data array for a backup event.
 */
function build_webhook_data(array $backup, array $server): array
{
    return [
        'status'      => $backup['status'],
        'server'      => $backup['server_name'] ?: $server['name'],
        'server_ip'   => $server['ip'] ?? '',
        'cpanel_user' => $backup['cpanel_user'],
        'destination' => $backup['destination'],
        'disk_used'   => $backup['disk_used'] ?? '',
        'disk_free'   => $backup['disk_free'] ?? '',
        'disk_total'  => $backup['disk_total'] ?? '',
        'disk_used_pct' => $backup['disk_used_pct'] ?? '',
        'start_time'  => $backup['start_time'],
        'end_time'    => $backup['end_time'],
        'duration'    => $backup['duration'] ?: duration($backup['start_time'], $backup['end_time']),
        'error'       => $backup['error'],
        'error_log'   => $backup['error_log'],
    ];
}
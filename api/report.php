<?php
/**
 * API endpoint for JetBackup hook reports.
 *
 * POST /api/report.php
 * Headers: Authorization: Bearer <server_token>
 * Body (JSON):
 *   {
 *     "backup_id": "abc123",              // unique id for this backup run (optional)
 *     "server_name": "srv01.example.com",
 *     "server_ip": "203.0.113.10",
 *     "cpanel_user": "johndoe",           // comma-joined if multiple users
 *     "destination": "local",
 *     "status": "running"|"success"|"failed"|"partial",
 *     "start_time": "2026-08-23T02:00:00Z",
 *     "end_time":   "2026-08-23T04:30:00Z",
 *     "error": "No space left on device",
 *     "error_log": "..."
 *   }
 *
 * Returns: { "ok": true, "backup_id": <row id> }
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifier.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}
enforce_https_api();

$server = authenticate_api_server();
if ($server === null) {
    json_out(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$body = request_json();

$statusMap = [
    'running'   => 'running',
    'started'   => 'running',
    'start'     => 'running',
    'in-progress' => 'running',
    'in_progress' => 'running',
    'success'   => 'success',
    'completed' => 'success',
    'complete'  => 'success',
    'done'      => 'success',
    'failed'    => 'failed',
    'error'     => 'failed',
    'failure'   => 'failed',
    'partial'   => 'partial',
    'partially' => 'partial',
    'partial success' => 'partial',
    'aborted'  => 'aborted',
];
$statusRaw = strtolower(trim((string)($body['status'] ?? '')));
$status = $statusMap[$statusRaw] ?? null;
if ($status === null) {
    json_out(['ok' => false, 'error' => "Invalid status: " . $statusRaw], 400);
}

$serverId    = (int)$server['id'];
$serverName  = $body['server_name'] ?? $body['hostname'] ?? $server['name'] ?? '';
$serverIp    = $body['server_ip'] ?? '';
$cpanelUser  = $body['cpanel_user'] ?? $body['username'] ?? '';
$destination = $body['destination'] ?? '';
$backupId    = $body['backup_id'] ?? $body['id'] ?? null;
$startTime   = to_mysql_datetime($body['start_time'] ?? null);
$endTime     = to_mysql_datetime($body['end_time'] ?? null);
$error       = $body['error'] ?? '';
$errorLog    = $body['error_log'] ?? '';
$diskUsed    = $body['disk_used'] ?? '';
$diskFree    = $body['disk_free'] ?? '';
$diskTotal   = $body['disk_total'] ?? '';
$diskPct     = $body['disk_used_pct'] ?? '';
$duration    = $body['duration'] ?? '';
$progress    = $body['progress'] ?? '';

// Refresh server connection info
if ($serverIp !== '') {
    db_query('UPDATE servers SET last_seen_at = NOW(), ip = ? WHERE id = ?', [$serverIp, $serverId]);
} else {
    db_query('UPDATE servers SET last_seen_at = NOW() WHERE id = ?', [$serverId]);
}

$backupRowId = null;

if ($status === 'running') {
    // Dedupe: if a running row for this server already exists (within 15 min), update it instead of inserting
    $existingRun = null;
    if ($backupId !== null && $backupId !== '') {
        $stmt = db_query(
            'SELECT id FROM backups WHERE server_id = ? AND backup_id = ? AND status = "running" LIMIT 1',
            [$serverId, $backupId]
        );
        $existingRun = $stmt->fetch();
    }
    if (empty($existingRun)) {
        // Use PHP time (UTC) for the window — start_time is written by PHP, not MySQL NOW()
        $stmt = db_query(
            'SELECT id FROM backups WHERE server_id = ? AND status = "running" AND start_time >= ? ORDER BY id DESC LIMIT 1',
            [$serverId, date('Y-m-d H:i:s', time() - 900)]
        );
        $existingRun = $stmt->fetch();
    }
    if ($existingRun) {
        db_query(
            'UPDATE backups SET backup_id = ?, server_name = ?, cpanel_user = ?, destination = ?, start_time = ?, payload = ?, disk_used = ?, disk_free = ?, disk_total = ?, disk_used_pct = ?, progress = ? WHERE id = ?',
            [$backupId, $serverName, $cpanelUser, $destination, $startTime ?: now(), json_encode($body, JSON_UNESCAPED_SLASHES), $diskUsed, $diskFree, $diskTotal, $diskPct, $progress, (int)$existingRun['id']]
        );
        $backupRowId = (int)$existingRun['id'];
    } else {
        db_query(
            'INSERT INTO backups (server_id, backup_id, server_name, cpanel_user, destination, status, start_time, end_time, error, error_log, payload, disk_used, disk_free, disk_total, disk_used_pct, progress, created_at)
             VALUES (?, ?, ?, ?, ?, "running", ?, NULL, NULL, NULL, ?, ?, ?, ?, ?, ?, NOW())',
            [$serverId, $backupId, $serverName, $cpanelUser, $destination, $startTime ?: now(), json_encode($body, JSON_UNESCAPED_SLASHES), $diskUsed, $diskFree, $diskTotal, $diskPct, $progress]
        );
        $backupRowId = (int)db()->lastInsertId();
    }
} else {
    // Final status: match an existing row by backup_id first, then fall back
    // to the newest ROW for this server (any status) so a Pre-hook "running"
    // row is ALWAYS found — regardless of cpanel_user (the running row has an
    // empty user; the final partial/failed report has users). This prevents
    // duplicate rows + duplicate webhooks.
    $existing = null;
    if ($backupId !== null && $backupId !== '') {
        $stmt = db_query(
            'SELECT id, error_log, webhook_sent FROM backups WHERE server_id = ? AND backup_id = ? ORDER BY id DESC LIMIT 1',
            [$serverId, $backupId]
        );
        $existing = $stmt->fetch();
    }
    if (empty($existing)) {
        // Any recent running row for this server (no user filter!) — this is
        // the Pre-hook row we must finalize, never duplicate it.
        $stmt = db_query(
            "SELECT id, error_log, webhook_sent FROM backups WHERE server_id = ? AND status = 'running' ORDER BY id DESC LIMIT 1",
            [$serverId]
        );
        $existing = $stmt->fetch();
    }
    if (empty($existing) && $startTime !== null && $startTime !== '') {
        // Last resort: match by server + start_time (same job always has the
        // same start time, even if a prior delivery already changed status or
        // backup_id differed). Window ±2h covers clock skew across retries.
        $stmt = db_query(
            'SELECT id, error_log, webhook_sent FROM backups
             WHERE server_id = ? AND start_time BETWEEN ? AND ?
             ORDER BY id DESC LIMIT 1',
            [$serverId, date('Y-m-d H:i:s', strtotime($startTime) - 7200), date('Y-m-d H:i:s', strtotime($startTime) + 7200)]
        );
        $existing = $stmt->fetch();
    }

    if (!empty($existing)) {
        $wasSent = (int)($existing['webhook_sent'] ?? 0);
        db_query(
            'UPDATE backups SET status = ?, end_time = ?, error = ?, error_log = ?, payload = ?, server_name = ?, destination = ?, cpanel_user = ?, disk_used = ?, disk_free = ?, disk_total = ?, disk_used_pct = ?, duration = ?, progress = ?, backup_id = ? WHERE id = ?',
            [$status, $endTime ?: now(), $error, $errorLog !== '' ? $errorLog : ($existing['error_log'] ?? null), json_encode($body, JSON_UNESCAPED_SLASHES), $serverName, $destination, $cpanelUser, $diskUsed, $diskFree, $diskTotal, $diskPct, $duration, $progress, $backupId, (int)$existing['id']]
        );
        $backupRowId = (int)$existing['id'];
        // If this row was already webhooked by an earlier delivery, keep that flag
        // so we do NOT send a duplicate. (Re-fetch below reads webhook_sent.)
        if ($wasSent) {
            db_query('UPDATE backups SET webhook_sent = 1 WHERE id = ?', [$backupRowId]);
        }
    } else {
        db_query(
            'INSERT INTO backups (server_id, backup_id, server_name, cpanel_user, destination, status, start_time, end_time, error, error_log, payload, disk_used, disk_free, disk_total, disk_used_pct, duration, progress, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [$serverId, $backupId, $serverName, $cpanelUser, $destination, $status, $startTime, $endTime ?: now(), $error, $errorLog, json_encode($body, JSON_UNESCAPED_SLASHES), $diskUsed, $diskFree, $diskTotal, $diskPct, $duration, $progress]
        );
        $backupRowId = (int)db()->lastInsertId();
    }
}

// --- Trigger webhook notifications (dedupe: once per backup) ---
$backup = db_query('SELECT * FROM backups WHERE id = ?', [$backupRowId])->fetch();
if ($backup) {
    $shouldSend = false;
    if ($status === 'failed' || $status === 'aborted') {
        $shouldSend = true;
        $event = WEBHOOK_EVENT_FAILED;
    } elseif ($status === 'partial') {
        $shouldSend = true;
        $event = WEBHOOK_EVENT_PARTIAL;
    } elseif ($status === 'success') {
        $shouldSend = true;
        $event = WEBHOOK_EVENT_COMPLETED;
    }
    // Global dedupe: if ANY row for this server+backup_id was already
    // webhooked, skip — protects against duplicate rows/deliveries.
    if ($shouldSend) {
        $alreadySent = (int)$backup['webhook_sent'];
        if (!$alreadySent && $backupId !== null && $backupId !== '') {
            $c = db_query(
                'SELECT COUNT(*) AS c FROM backups WHERE server_id = ? AND backup_id = ? AND webhook_sent = 1',
                [$serverId, $backupId]
            )->fetch()['c'];
            $alreadySent = (int)$c > 0;
        }
        if (!$alreadySent) {
            queue_webhook($event, build_webhook_data($backup, $server));
            db_query('UPDATE backups SET webhook_sent = 1 WHERE id = ?', [(int)$backup['id']]);
            // Mark every row sharing this backup_id as sent (kills duplicates)
            if ($backupId !== null && $backupId !== '') {
                db_query('UPDATE backups SET webhook_sent = 1 WHERE server_id = ? AND backup_id = ?', [$serverId, $backupId]);
            }
        }
    }
}

// Best-effort immediate delivery (cron catches up if this fails)
process_webhook_queue(10);

json_out(['ok' => true, 'backup_id' => $backupRowId]);
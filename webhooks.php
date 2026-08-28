<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifier.php';
require_login();

$activePage = 'webhooks';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $url    = trim($_POST['url'] ?? '');
        $format = trim($_POST['format'] ?? 'generic');
        if (!in_array($format, ['generic', 'discord', 'slack'], true)) {
            $format = 'generic';
        }
        if ($format === 'auto') {
            if (strpos($url, 'discord.com') !== false) {
                $format = 'discord';
            } elseif (strpos($url, 'hooks.slack.com') !== false) {
                $format = 'slack';
            } else {
                $format = 'generic';
            }
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            flash('error', 'Please enter a valid webhook URL.');
        } else {
            $events = [WEBHOOK_EVENT_FAILED, WEBHOOK_EVENT_PARTIAL, WEBHOOK_EVENT_COMPLETED];
            db_query('INSERT INTO webhooks (url, events, format, is_active, created_at) VALUES (?, ?, ?, 1, NOW())', [$url, implode(',', $events), $format]);
            audit('webhook_add', 'Webhook "' . $url . '" added');
            flash('success', 'Webhook added.');
        }
        redirect('webhooks.php');
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $hook = db_query('SELECT is_active, url FROM webhooks WHERE id = ?', [$id])->fetch();
        if ($hook) {
            $newStatus = (int)$hook['is_active'] === 1 ? 0 : 1;
            db_query('UPDATE webhooks SET is_active = ? WHERE id = ?', [$newStatus, $id]);
            audit('webhook_toggle', 'Webhook #' . $id . ' ' . ($newStatus ? 'enabled' : 'disabled'));
            flash('success', 'Webhook status updated.');
        }
        redirect('webhooks.php');
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $hook = db_query('SELECT url FROM webhooks WHERE id = ?', [$id])->fetch();
        db_query('DELETE FROM webhooks WHERE id = ?', [$id]);
        audit('webhook_delete', 'Webhook "' . ($hook['url'] ?? '#' . $id) . '" deleted');
        flash('success', 'Webhook deleted.');
        redirect('webhooks.php');
    } elseif ($action === 'test') {
        $id = (int)($_POST['id'] ?? 0);
        $hook = db_query('SELECT * FROM webhooks WHERE id = ?', [$id])->fetch();
        if ($hook) {
            $payload = json_encode([
                'event'       => 'backup_failed',
                'status'      => 'failed',
                'server'      => 'test-server.example.com',
                'server_ip'   => '203.0.113.10',
                'cpanel_user' => 'testuser',
                'destination' => 'local',
                'start_time'  => date('Y-m-d H:i:s', time() - 3600),
                'end_time'    => date('Y-m-d H:i:s'),
                'duration'    => '1h 00m',
                'error'       => 'This is a test notification from JBWizerd Panel.',
                'error_log'   => "alice:\n  Failed exporting database data. Database Handler Error: timeout\n  Failed to transfer public_html/wp-config.php\n  Failed to transfer public_html/wp-content/uploads/2026/01/image.jpg\n\nbob:\n  Disk quota exceeded on destination\n\ncarol:\n  Connection reset by peer\n  No space left on device",
            ], JSON_UNESCAPED_SLASHES);
            $result = send_webhook_to_url($hook['url'], $payload, $hook['format'] ?? 'generic');
            db_query(
                'INSERT INTO webhook_logs (webhook_id, event, server_name, cpanel_user, status, http_status, response, created_at)
                 VALUES (?, "backup_failed", "test-server.example.com", "testuser", ?, ?, ?, NOW())',
                [$id, $result['ok'] ? 'success' : 'failed', $result['http_status'], $result['ok'] ? '' : mb_substr($result['error'], 0, 500)]
            );
            if ($result['ok']) {
                audit('webhook_test', 'Test delivered to "' . $hook['url'] . '" (HTTP ' . (int)$result['http_status'] . ')');
                flash('success', 'Test webhook delivered successfully (HTTP ' . (int)$result['http_status'] . ').');
            } else {
                audit('webhook_test_failed', 'Test to "' . $hook['url'] . '" failed (HTTP ' . (int)$result['http_status'] . ')');
                flash('error', 'Test webhook failed (HTTP ' . (int)$result['http_status'] . '): ' . $result['error']);
            }
        }
        redirect('webhooks.php');
    }
}

$webhooks = db_query(
    'SELECT w.*, (SELECT COUNT(*) FROM webhook_queue q WHERE q.webhook_id = w.id AND q.status = "pending") AS pending
     FROM webhooks w ORDER BY w.id DESC'
)->fetchAll();

$logs = db_query('SELECT * FROM webhook_logs ORDER BY id DESC LIMIT 50')->fetchAll();

// --- Stats for header cards ---
$totalWebhooks = (int)db_query('SELECT COUNT(*) AS c FROM webhooks')->fetch()['c'];
$activeWebhooks = (int)db_query('SELECT COUNT(*) AS c FROM webhooks WHERE is_active = 1')->fetch()['c'];
$inactiveWebhooks = $totalWebhooks - $activeWebhooks;
$pendingWebhooks = (int)db_query('SELECT COUNT(*) AS c FROM webhook_queue WHERE status = "pending"')->fetch()['c'];
$failedDeliveries = (int)db_query("SELECT COUNT(*) AS c FROM webhook_logs WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch()['c'];

$formatLabels = [
    'discord' => ['Discord', 'badge-partial'],
    'slack'   => ['Slack', 'badge-running'],
    'generic' => ['Generic', 'badge-inactive'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Webhooks — JBWizerd</title>
<link rel="icon" type="image/png" href="assets/favicon.png">
<link rel="stylesheet" href="assets/style.css">
<script src="assets/theme.js"></script>
</head>
<body>
<?php require __DIR__ . '/includes/mobile_header.php'; ?>
<?php require __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
    <header class="topbar">
        <button class="btn btn-primary" id="btn-add-webhook">+ Add Webhook</button>
    </header>

    <?php foreach (flash_out() as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>

    <section class="stat-grid">
        <div class="stat-card stat-total">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($totalWebhooks) ?></div>
                <div class="stat-label">Total Webhooks</div>
            </div>
        </div>
        <div class="stat-card stat-success">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($activeWebhooks) ?></div>
                <div class="stat-label">Active</div>
            </div>
        </div>
        <div class="stat-card stat-failed">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($inactiveWebhooks) ?></div>
                <div class="stat-label">Inactive</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 11h-2a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2z"/><path d="M9 3H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($pendingWebhooks) ?></div>
                <div class="stat-label">Pending Queued</div>
            </div>
        </div>
        <div class="stat-card stat-partial">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($failedDeliveries) ?></div>
                <div class="stat-label">Failed (24h)</div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Webhooks <span class="count"><?= count($webhooks) ?></span></h2>
        </div>
        <?php if (!$webhooks): ?>
            <p class="empty-panel">No webhooks configured. Add one to get Slack/Discord notifications.</p>
        <?php endif; ?>
        <div class="wh-grid">
            <?php foreach ($webhooks as $w): ?>
                <?php
                [$fmtLabel, $fmtBadge] = $formatLabels[$w['format'] ?? 'generic'] ?? ['Generic', 'badge-inactive'];
                $inactiveHook = (int)$w['is_active'] !== 1;
                ?>
                <div class="wh-card<?= $inactiveHook ? ' wh-inactive' : '' ?>">
                    <div class="wh-head">
                        <div class="wh-icon">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                        </div>
                        <div class="wh-url" title="<?= e($w['url']) ?>"><?= e($w['url']) ?></div>
                        <span class="badge badge-<?= $inactiveHook ? 'inactive' : 'success' ?>"><?= $inactiveHook ? 'Inactive' : 'Active' ?></span>
                    </div>
                    <div class="wh-tags">
                        <span class="badge <?= e($fmtBadge) ?>"><?= e($fmtLabel) ?></span>
                    </div>
                    <div class="wh-meta muted">
                        <?php if ($w['pending']): ?>
                            <span class="wh-pending"><?= (int)$w['pending'] ?> pending</span> ·
                        <?php endif; ?>
                        Last triggered: <?= e(fmt_dt($w['last_triggered_at'])) ?>
                    </div>
                    <div class="wh-actions">
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="test">
                            <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                            <button class="btn btn-sm" title="Send a test notification">Test</button>
                        </form>
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                            <button class="btn btn-sm"><?= $inactiveHook ? 'Enable' : 'Disable' ?></button>
                        </form>
                        <form method="post" class="inline" data-confirm="Delete this webhook?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Delivery Logs <span class="muted">(last 50)</span></h2>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th class="tbl-nowrap">Time</th>
                        <th class="tbl-nowrap">Event</th>
                        <th class="tbl-wrap">Server</th>
                        <th class="tbl-nowrap">HTTP</th>
                        <th class="tbl-nowrap">Status</th>
                        <th class="tbl-wrap">Response</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$logs): ?>
                        <tr><td colspan="6" class="empty">No webhook deliveries yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td class="tbl-nowrap"><?= e(fmt_dt($l['created_at'])) ?></td>
                            <td class="tbl-nowrap"><span class="tag"><?= e($l['event'] ?: '—') ?></span></td>
                            <td class="tbl-wrap"><?= e($l['server_name'] ?: '—') ?></td>
                            <td class="tbl-nowrap"><?= $l['http_status'] ? (int)$l['http_status'] : '—' ?></td>
                            <td class="tbl-nowrap">
                                <span class="badge badge-<?= e($l['status']) ?>"><?= e($l['status']) ?></span>
                            </td>
                            <td class="tbl-wrap log-response" title="<?= e($l['response']) ?>"><?= e($l['response'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<!-- Add Webhook Modal -->
<div class="modal" id="add-webhook-modal" hidden>
    <div class="modal-box">
        <h2>Add Webhook</h2>
        <p class="muted">We'll POST a JSON payload to this URL for every backup event (failed, partial, and completed). Works with Slack, Discord and any custom endpoint.</p>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <label>Webhook URL *
                <input type="url" name="url" required placeholder="https://discord.com/api/webhooks/... or https://hooks.slack.com/services/...">
            </label>
            <label>Message Format
                <select name="format" class="input">
                    <option value="auto" selected>Auto-detect (recommended)</option>
                    <option value="discord">Discord embed</option>
                    <option value="slack">Slack message</option>
                    <option value="generic">Generic JSON</option>
                </select>
            </label>
            <div class="modal-actions">
                <button type="button" class="btn" data-close-modal>Cancel</button>
                <button type="submit" class="btn btn-primary">Save Webhook</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/app.js"></script>
</body>
</html>
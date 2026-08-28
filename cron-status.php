<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();

$activePage = 'cron-status';

$statusPath = __DIR__ . '/cron/cron-status.json';
$status = [];
if (file_exists($statusPath)) {
    $decoded = json_decode((string)file_get_contents($statusPath), true);
    if (is_array($decoded)) {
        $status = $decoded;
    }
}

$jobs = [
    'webhooks' => [
        'label'    => 'Webhook Sender',
        'command'  => 'php ' . __DIR__ . '/cron/send-webhooks.php',
        'schedule' => 'Every minute',
        'tolerance' => 5 * 60, // 5 minutes
    ],
    'cleanup' => [
        'label'    => 'Retention Cleanup',
        'command'  => 'php ' . __DIR__ . '/cron/cleanup.php',
        'schedule' => 'Daily at 03:00',
        'tolerance' => 26 * 3600, // ~26 hours
    ],
];

$now = time();
$rows = [];
foreach ($jobs as $key => $job) {
    $entry = $status[$key] ?? null;
    $lastRun = $entry['last_run'] ?? null;
    $elapsed = $lastRun ? $now - strtotime($lastRun) : null;

    if ($elapsed === null) {
        $state = ['label' => 'Never Run', 'class' => 'inactive'];
    } elseif ($elapsed <= $job['tolerance']) {
        $state = ['label' => 'Active', 'class' => 'success'];
    } else {
        $state = ['label' => 'Not Running', 'class' => 'failed'];
    }

    $rows[] = [
        'label'    => $job['label'],
        'command'  => $job['command'],
        'schedule' => $job['schedule'],
        'last_run' => $lastRun,
        'result'   => $entry['result'] ?? '',
        'state'    => $state,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cron Status — JBWizerd</title>
<link rel="icon" type="image/png" href="assets/favicon.png">
<link rel="stylesheet" href="assets/style.css">
<script src="assets/theme.js"></script>
</head>
<body>
<?php require __DIR__ . '/includes/mobile_header.php'; ?>
<?php require __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
    <?php foreach (flash_out() as $flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <section class="panel">
        <div class="panel-head">
            <h2>Cron Status</h2>
            <span class="muted">Shows whether the scheduled jobs are actually running</span>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th class="tbl-nowrap">Job</th>
                        <th class="tbl-nowrap">Schedule</th>
                        <th class="tbl-wrap">Command</th>
                        <th class="tbl-nowrap">Last Run</th>
                        <th class="tbl-nowrap">Status</th>
                        <th class="tbl-wrap">Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="tbl-nowrap"><strong><?= e($r['label']) ?></strong></td>
                            <td class="tbl-nowrap"><?= e($r['schedule']) ?></td>
                            <td class="tbl-wrap"><code><?= e($r['command']) ?></code></td>
                            <td class="tbl-nowrap"><?= e(fmt_dt($r['last_run'])) ?></td>
                            <td class="tbl-nowrap">
                                <span class="badge badge-<?= e($r['state']['class']) ?>"><?= e($r['state']['label']) ?></span>
                            </td>
                            <td class="tbl-wrap" title="<?= e($r['result']) ?>"><?= e($r['result'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel regkey-panel">
        <div class="panel-head">
            <h2>How to Set Them Up</h2>
            <span class="muted">Add both jobs in your hosting control panel</span>
        </div>
        <div class="regkey-cmd">
            <p class="muted" style="margin-bottom:14px">Add these two cron jobs in your hosting control panel (cPanel → Cron Jobs, Plesk → Scheduled Tasks, DirectAdmin → Cron Jobs). The commands below are pre-filled with the correct path for this panel.</p>

            <div class="regkey-cmd-head">
                <span class="card-label">Webhook Sender</span>
                <span class="badge badge-running">Every minute</span>
            </div>
            <p class="muted" style="margin-bottom:8px;font-size:.85em">Delivers queued Slack/Discord notifications.</p>
            <div class="regkey-box">
                <code id="cron-cmd-webhooks">* * * * * php <?= e(__DIR__ . '/cron/send-webhooks.php') ?></code>
                <button class="btn btn-sm btn-copy" data-copy="#cron-cmd-webhooks">Copy</button>
            </div>

            <div class="regkey-cmd-head" style="margin-top:16px">
                <span class="card-label">Retention Cleanup</span>
                <span class="badge badge-running">Daily at 03:00</span>
            </div>
            <p class="muted" style="margin-bottom:8px;font-size:.85em">Deletes expired backups, webhook logs, queue rows, and audit logs.</p>
            <div class="regkey-box">
                <code id="cron-cmd-cleanup">0 3 * * * php <?= e(__DIR__ . '/cron/cleanup.php') ?></code>
                <button class="btn btn-sm btn-copy" data-copy="#cron-cmd-cleanup">Copy</button>
            </div>

            <div style="margin-top:14px;padding:10px 12px;border:1px dashed var(--border-strong);border-radius:8px;font-size:.85em;color:var(--text-dim)">
                <strong>Status refreshes automatically</strong> — this page updates the next time each cron runs. If a job shows <span class="badge badge-failed">Not Running</span>, it was added incorrectly or never ran.
            </div>
        </div>
    </section>
</main>

<script src="assets/app.js"></script>
</body>
</html>

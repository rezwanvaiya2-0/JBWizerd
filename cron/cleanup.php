<?php
/**
 * Cron job: retention cleanup.
 *  - Deletes backup reports older than RETENTION_DAYS (default 365 days = 1 year).
 *  - Deletes webhook logs older than WEBHOOK_LOG_RETENTION_DAYS.
 *  - Deletes finished webhook queue rows older than 30 days.
 *  - Deletes audit log entries older than AUDIT_LOG_RETENTION_DAYS (default 90 days).
 *  - Flags backups stuck in "running" for over 24h as failed and notifies via webhook.
 *
 * Usage (shared hosting / cPanel cron):
 *   php /home/USER/public_html/cron/cleanup.php
 *   (add the subfolder to the path if installed in a subfolder)
 *
 * Recommended: daily at 3:00 AM   0 3 * * *
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifier.php';

$retention = defined('RETENTION_DAYS') ? (int)RETENTION_DAYS : 365;
$logRetention = defined('WEBHOOK_LOG_RETENTION_DAYS') ? (int)WEBHOOK_LOG_RETENTION_DAYS : 90;
$auditRetention = defined('AUDIT_LOG_RETENTION_DAYS') ? (int)AUDIT_LOG_RETENTION_DAYS : 90;
$stuckHours = defined('STUCK_BACKUP_HOURS') ? (int)STUCK_BACKUP_HOURS : 24;

$r1 = db_query('DELETE FROM backups WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [$retention])->rowCount();
$r2 = db_query('DELETE FROM webhook_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [$logRetention])->rowCount();
$r3 = db_query("DELETE FROM webhook_queue WHERE status IN ('success','failed') AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)")->rowCount();
$r4 = db_query('DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [$auditRetention])->rowCount();

// --- Stuck-running detection: running for > 24h with no completion ---
$r5 = 0;
$stuckRows = db_query(
    "SELECT b.*, s.name AS server_name FROM backups b
     LEFT JOIN servers s ON s.id = b.server_id
     WHERE b.status = 'running' AND b.start_time < DATE_SUB(NOW(), INTERVAL ? HOUR)",
    [$stuckHours]
)->fetchAll();
foreach ($stuckRows as $row) {
    $errMsg = "Backup has been running for over {$stuckHours} hours without completing. Check it manually.";
    db_query(
        "UPDATE backups SET status = 'failed', end_time = COALESCE(end_time, start_time),
         cpanel_user = NULL,
         error = ? WHERE id = ?",
        [$errMsg, (int)$row['id']]
    );
    if (db()->rowCount() > 0) {
        $r5++;
        $server = ['id' => $row['server_id'], 'name' => $row['server_name'], 'ip' => null];
        $data = build_webhook_data($row, $server);
        // Mark as STUCK so the webhook shows the URGENT "Backup Stuck" alert
        $data['status'] = 'stuck';
        $data['error'] = $errMsg;
        $data['error_log'] = '';
        queue_webhook(WEBHOOK_EVENT_STUCK, $data);
    }
}
process_webhook_queue(10);

update_cron_status('cleanup', "backups=$r1 logs=$r2 queue=$r3 audit=$r4 stuck=$r5");

if (PHP_SAPI === 'cli') {
    echo "cleanup done: backups=$r1 logs=$r2 queue=$r3 audit=$r4 stuck=$r5\n";
}
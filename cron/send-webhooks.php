<?php
/**
 * Cron job: deliver pending webhook notifications.
 *
 * Usage (shared hosting / cPanel cron):
 *   php /home/USER/public_html/cron/send-webhooks.php
 *   (add the subfolder to the path if installed in a subfolder)
 *
 * Recommended every minute:  * * * * *
 * Optional CLI arg: number of items to process per run (default 25).
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifier.php';

$limit = isset($argv[1]) ? max(1, (int)$argv[1]) : 25;
process_webhook_queue($limit);
update_cron_status('webhooks', "Processed up to $limit queued webhooks");

if (PHP_SAPI === 'cli') {
    echo "webhook queue processed (limit $limit)\n";
}
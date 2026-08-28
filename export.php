<?php
/**
 * Excel (CSV) export of the backup report.
 * Works with the same filters as index.php. CSV with UTF-8 BOM opens directly in Excel.
 *
 * Usage:
 *   export.php?date=2026-08-23&server=1&status=failed   (current view)
 *   export.php?all=1                                     (ALL data across all servers and dates)
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$exportAll = !empty($_GET['all']);

$date  = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');
$statusFilter = $_GET['status'] ?? '';
$serverFilter = (int)($_GET['server'] ?? 0);
$search       = trim($_GET['q'] ?? '');

$validStatuses = ['running', 'success', 'failed', 'partial'];
if ($statusFilter !== '' && !in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

if ($exportAll) {
    // Whole dataset — every server, every date
    $whereSql = '1 = 1';
    $params = [];
} elseif ($search !== '') {
    // Search across ALL dates (same as the Backup Report global search)
    $whereSql = '(server_name LIKE ? OR cpanel_user LIKE ? OR destination LIKE ? OR backup_id LIKE ?)';
    $searchLike = '%' . $search . '%';
    $params = [$searchLike, $searchLike, $searchLike, $searchLike];
    if ($statusFilter !== '') {
        $whereSql .= ' AND status = ?';
        $params[] = $statusFilter;
    }
    if ($serverFilter > 0) {
        $whereSql .= ' AND server_id = ?';
        $params[] = $serverFilter;
    }
} else {
    $where  = ['start_time >= ?', 'start_time <= ?'];
    $params = [$date . ' 00:00:00', $date . ' 23:59:59'];
    if ($statusFilter !== '') {
        $where[] = 'status = ?';
        $params[] = $statusFilter;
    }
    if ($serverFilter > 0) {
        $where[] = 'server_id = ?';
        $params[] = $serverFilter;
    }
    $whereSql = implode(' AND ', $where);
}

$stmt = db_query(
    "SELECT id, server_id, backup_id, server_name, cpanel_user, destination, status,
            start_time, end_time, error, error_log, disk_used, disk_free, disk_total,
            disk_used_pct, duration, progress, created_at
     FROM backups WHERE $whereSql ORDER BY id ASC",
    $params
);

// CSV output with UTF-8 BOM so Excel renders UTF-8 correctly
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=jb_backup_report_' . ($exportAll ? 'all' : ($search !== '' ? 'search' : $date)) . '.csv');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

$columns = [
    'Server', 'cPanel User', 'Destination', 'Status',
    'Start Time', 'End Time', 'Duration', 'Error', 'Error Log',
];
fputcsv($out, $columns, ',', '"', '');

while ($b = $stmt->fetch()) {
    fputcsv($out, [
        $b['server_name'] ?: $b['server_id'],
        $b['cpanel_user'],
        $b['destination'],
        $b['status'],
        $b['start_time'],
        $b['end_time'],
        duration($b['start_time'], $b['end_time']),
        $b['error'],
        $b['error_log'],
    ], ',', '"', '');
}

fclose($out);
exit;
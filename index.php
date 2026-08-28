<?php
/**
 * First-visit router: visiting the domain root redirects to setup.php
 * until the panel is fully configured. After that, shows the daily report.
 */

require_once __DIR__ . '/includes/functions.php';

$configPath = __DIR__ . '/includes/config.php';
$setupNeeded = true;

if (file_exists($configPath)) {
    require_once $configPath;
    require_once __DIR__ . '/includes/db.php';
    try {
        $tablesExist = (bool)db_query("SHOW TABLES LIKE 'admin_users'")->fetch();
        $adminExists = $tablesExist && (bool)db_query('SELECT id FROM admin_users LIMIT 1')->fetch();
        if ($tablesExist && $adminExists) {
            $setupNeeded = false;
        }
    } catch (Exception $e) {
        // DB not reachable — treat as setup needed
    }
}

if ($setupNeeded) {
    redirect('setup.php');
}

require_once __DIR__ . '/includes/auth.php';
require_login();

$activePage = 'index';

$today = date('Y-m-d');
$date  = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : null;
$statusFilter = $_GET['status'] ?? '';
$serverFilter = (int)($_GET['server'] ?? 0);
$search       = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$validStatuses = ['running', 'success', 'failed', 'partial', 'aborted'];
if ($statusFilter !== '' && !in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

// --- Filters ---
// Default: last 24 hours (rolling window) since backups take ~24h to complete.
// When a specific date is chosen, show that calendar day.
// When a global search is active, search ACROSS ALL DATES.
if ($search !== '') {
    $where  = ['1=1'];
    $params = [];
    $searchLike = '%' . $search . '%';
    $where[] = '(server_name LIKE ? OR cpanel_user LIKE ? OR destination LIKE ? OR backup_id LIKE ?)';
    array_push($params, $searchLike, $searchLike, $searchLike, $searchLike);
} elseif ($date) {
    $where  = ['start_time >= ?', 'start_time <= ?'];
    $params = [$date . ' 00:00:00', $date . ' 23:59:59'];
} else {
    $where  = ['start_time >= ?'];
    $params = [date('Y-m-d H:i:s', time() - 86400)]; // last 24 hours
}
if ($statusFilter !== '') {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}
if ($serverFilter > 0) {
    $where[] = 'server_id = ?';
    $params[] = $serverFilter;
}
$whereSql = implode(' AND ', $where);

$backups = db_query(
    "SELECT id, server_id, backup_id, server_name, cpanel_user, destination, status,
            start_time, end_time, error, error_log, disk_used, disk_free, disk_total,
            disk_used_pct, duration, progress, webhook_sent, created_at
     FROM backups WHERE $whereSql ORDER BY id DESC",
    $params
)->fetchAll();

$serverList = db_query('SELECT id, name FROM servers ORDER BY name ASC')->fetchAll();

$qs = http_build_query(array_filter([
    'date'   => $date,
    'status' => $statusFilter,
    'server' => $serverFilter ?: null,
    'q'      => $search !== '' ? $search : null,
]));

// --- Stat cards (same window as the table: last 24h or selected day) ---
if ($date) {
    $statWhere  = 'start_time >= ? AND start_time <= ?';
    $statParams = [$date . ' 00:00:00', $date . ' 23:59:59'];
} else {
    $statWhere  = 'start_time >= ?';
    $statParams = [date('Y-m-d H:i:s', time() - 86400)];
}
$stats = db_query(
    'SELECT COUNT(*) AS total,
            SUM(status = "success") AS success,
            SUM(status = "failed") AS failed,
            SUM(status = "partial") AS partial,
            SUM(status = "running") AS running
     FROM backups
     WHERE ' . $statWhere,
    $statParams
)->fetch();
$stTotal = (int)$stats['total'];
$stSuccess = (int)$stats['success'];
$stPartial = (int)$stats['partial'];
$stFailed = (int)$stats['failed'];
$stRunning = (int)$stats['running'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Backup Report — JBWizerd</title>
<link rel="icon" type="image/png" href="assets/favicon.png">
<link rel="stylesheet" href="assets/style.css">
<script src="assets/theme.js"></script>
</head>
<body>
<?php require __DIR__ . '/includes/mobile_header.php'; ?>
<?php require __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
    <header class="topbar">
        <form method="get" class="filters">
            <input type="search" name="q" value="<?= e($search) ?>" class="input" placeholder="Search server, user, destination, ID...">
            <input type="date" name="date" value="<?= e($date ?? '') ?>" max="<?= e($today) ?>" class="input" placeholder="Last 24h">
            <select name="server" class="input">
                <option value="">All servers</option>
                <?php foreach ($serverList as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= $serverFilter === (int)$s['id'] ? 'selected' : '' ?>>
                        <?= e($s['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="input">
                <option value="">All statuses</option>
                <option value="success" <?= $statusFilter === 'success' ? 'selected' : '' ?>>Success</option>
                <option value="failed"  <?= $statusFilter === 'failed'  ? 'selected' : '' ?>>Failed</option>
                <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partial</option>
                <option value="running" <?= $statusFilter === 'running' ? 'selected' : '' ?>>Running</option>
            </select>
            <button type="submit" class="btn btn-primary">Apply</button>
        </form>
        <div class="filters">
            <a class="btn btn-export" href="export.php?<?= e($qs) ?>" title="Export the current view to Excel (CSV)">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Export Excel</a>
            <a class="btn btn-export" href="export.php?all=1" title="Export all backup data across all servers and dates">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Export All Data</a>
        </div>
    </header>

    <?php foreach (flash_out() as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>

    <section class="stat-grid">
        <div class="stat-card stat-total">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($stTotal) ?></div>
                <div class="stat-label"><?= $date ? 'Records on ' . e($date) : 'Records (Last 24h)' ?></div>
            </div>
        </div>
        <div class="stat-card stat-success">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($stSuccess) ?></div>
                <div class="stat-label">Success</div>
            </div>
            <div class="stat-pct muted"><?= $stTotal ? round($stSuccess / $stTotal * 100) : 0 ?>%</div>
        </div>
        <div class="stat-card stat-partial">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($stPartial) ?></div>
                <div class="stat-label">Partial</div>
            </div>
            <div class="stat-pct muted"><?= $stTotal ? round($stPartial / $stTotal * 100) : 0 ?>%</div>
        </div>
        <div class="stat-card stat-failed">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($stFailed) ?></div>
                <div class="stat-label">Failed</div>
            </div>
            <div class="stat-pct muted"><?= $stTotal ? round($stFailed / $stTotal * 100) : 0 ?>%</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($stRunning) ?></div>
                <div class="stat-label">Running</div>
            </div>
            <div class="stat-pct muted"><?= $stTotal ? round($stRunning / $stTotal * 100) : 0 ?>%</div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Backup Records <?= $date ? 'on ' . e($date) : '(Last 24 Hours)' ?></h2>
            <span class="muted"><?= number_format($totalRows) ?> records</span>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th class="tbl-wrap">Server</th>
                        <th class="tbl-wrap">cPanel User</th>
                        <th class="tbl-wrap">Destination</th>
                        <th class="tbl-wrap">Storage</th>
                        <th class="tbl-nowrap">Start</th>
                        <th class="tbl-nowrap">End</th>
                        <th class="tbl-nowrap">Duration</th>
                        <th class="tbl-nowrap">Progress</th>
                        <th class="tbl-nowrap">Status</th>
                        <th class="tbl-nowrap"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$backups): ?>
                        <tr><td colspan="10" class="empty">No backups found for this day.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($backups as $b): ?>
                        <tr>
                            <td class="tbl-wrap"><?= e($b['server_name'] ?: $b['server_id']) ?></td>
                            <td class="tbl-wrap"><?= $b['status'] === 'success' ? '—' : e($b['cpanel_user'] ?: '—') ?></td>
                            <td class="tbl-wrap"><?= e($b['destination'] ?: '—') ?></td>
                            <td class="tbl-wrap" title="Last Updated at <?= e(fmt_dt_long($b['end_time'])) ?>">
                                <?php if ($b['disk_used'] && $b['disk_total']): ?>
                                    <?= e($b['disk_used']) ?> used / <?= e($b['disk_total']) ?> total
                                    <?php if ($b['disk_free']): ?> (<?= e($b['disk_free']) ?> free)<?php endif; ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td class="tbl-nowrap"><?= e(fmt_dt($b['start_time'])) ?></td>
                            <td class="tbl-nowrap"><?= e(fmt_dt($b['end_time'])) ?></td>
                            <td class="tbl-nowrap" title="<?= e(duration_human($b['start_time'], $b['end_time'])) ?>">
                                <?= e($b['duration'] ?: duration($b['start_time'], $b['end_time'])) ?>
                            </td>
                            <td class="tbl-nowrap">
                                <?php if ($b['progress'] && $b['status'] === 'running'): ?>
                                    <span class="progress-text"><?= e($b['progress']) ?></span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td class="tbl-nowrap"><span class="badge badge-<?= e($b['status']) ?>"><?= e(status_label($b['status'])) ?></span></td>
                            <td class="tbl-nowrap">
                                <?php if (in_array($b['status'], ['failed', 'partial', 'aborted']) || $b['error']): ?>
                                    <button class="btn btn-sm btn-view" data-id="<?= (int)$b['id'] ?>">View Log</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (in_array($b['status'], ['failed', 'partial', 'aborted']) || $b['error']): ?>
                            <tr class="error-row" id="err-<?= (int)$b['id'] ?>" hidden>
                                <td colspan="10">
                                    <div class="error-box">
                                        <strong>Error Log</strong>
                                        <?php if ($b['error_log']): ?>
                                            <pre><?= e($b['error_log']) ?></pre>
                                        <?php elseif ($b['error']): ?>
                                            <pre><?= e($b['error']) ?></pre>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </section>
</main>

<script src="assets/app.js"></script>
</body>
</html>
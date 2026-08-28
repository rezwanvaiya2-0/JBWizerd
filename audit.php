<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();

$activePage = 'audit';

$actionFilter = $_GET['action'] ?? '';
$userFilter   = trim($_GET['user'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$where  = [];
$params = [];
if ($actionFilter !== '') {
    $where[] = 'action = ?';
    $params[] = $actionFilter;
}
if ($userFilter !== '') {
    $where[] = 'username LIKE ?';
    $params[] = '%' . $userFilter . '%';
}
$whereSql = $where ? implode(' AND ', $where) : '1 = 1';

$totalRows = (int)db_query("SELECT COUNT(*) AS c FROM audit_log WHERE $whereSql", $params)->fetch()['c'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$entries = db_query(
    "SELECT * FROM audit_log WHERE $whereSql ORDER BY id DESC LIMIT $perPage OFFSET $offset",
    $params
)->fetchAll();

$actions = db_query('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

// Stats
$totalEvents = (int)db_query('SELECT COUNT(*) AS c FROM audit_log')->fetch()['c'];
$todayEvents = (int)db_query('SELECT COUNT(*) AS c FROM audit_log WHERE created_at >= ?', [date('Y-m-d') . ' 00:00:00'])->fetch()['c'];
$loginEvents = (int)db_query("SELECT COUNT(*) AS c FROM audit_log WHERE action IN ('login','login_failed','logout')")->fetch()['c'];
$actionEvents = (int)db_query("SELECT COUNT(*) AS c FROM audit_log WHERE action NOT IN ('login','login_failed','logout')")->fetch()['c'];

$qs = http_build_query(array_filter(['action' => $actionFilter, 'user' => $userFilter]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Audit Log — JBWizerd</title>
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
            <select name="action" class="input">
                <option value="">All actions</option>
                <?php foreach ($actions as $a): ?>
                    <option value="<?= e($a) ?>" <?= $actionFilter === $a ? 'selected' : '' ?>><?= e($a) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="user" value="<?= e($userFilter) ?>" placeholder="Search user..." class="input">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a class="btn" href="audit.php">Reset</a>
        </form>
    </header>

    <?php foreach (flash_out() as $flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <section class="stat-grid">
        <div class="stat-card stat-total">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="10"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($totalEvents) ?></div>
                <div class="stat-label">Total Events</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($todayEvents) ?></div>
                <div class="stat-label">Today</div>
            </div>
        </div>
        <div class="stat-card stat-servers">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($loginEvents) ?></div>
                <div class="stat-label">Logins</div>
            </div>
        </div>
        <div class="stat-card stat-partial">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($actionEvents) ?></div>
                <div class="stat-label">Actions</div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Activity Log <span class="count"><?= number_format($totalRows) ?></span></h2>
            <span class="muted">Newest first</span>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th class="tbl-nowrap">Time</th>
                        <th class="tbl-nowrap">User</th>
                        <th class="tbl-nowrap">Action</th>
                        <th class="tbl-wrap">Details</th>
                        <th class="tbl-nowrap">IP</th>
                        <th class="tbl-wrap">Device (User-Agent)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$entries): ?>
                        <tr><td colspan="6" class="empty">No activity recorded.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($entries as $en): ?>
                        <tr>
                            <td class="tbl-nowrap"><?= e(fmt_dt($en['created_at'])) ?></td>
                            <td class="tbl-nowrap"><?= e($en['username'] ?: '—') ?></td>
                            <td class="tbl-nowrap">
                                <span class="badge badge-<?= in_array($en['action'], ['login', 'logout']) ? 'success' : (in_array($en['action'], ['login_failed']) ? 'failed' : 'running') ?>">
                                    <?= e($en['action']) ?>
                                </span>
                            </td>
                            <td class="tbl-wrap" title="<?= e($en['details']) ?>"><?= e($en['details'] ?: '—') ?></td>
                            <td class="tbl-nowrap"><?= e($en['ip'] ?: '—') ?></td>
                            <td class="tbl-wrap" title="<?= e($en['user_agent']) ?>"><?= e($en['user_agent'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php if ($p === $page): ?>
                        <span class="page current"><?= $p ?></span>
                    <?php else: ?>
                        <a class="page" href="audit.php?<?= e($qs) ?>&page=<?= $p ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<script src="assets/app.js"></script>
</body>
</html>
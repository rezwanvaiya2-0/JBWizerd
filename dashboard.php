<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$activePage = 'dashboard';

$today = date('Y-m-d');

$range = $_GET['range'] ?? '7';
$range = in_array($range, ['7', '15', '30'], true) ? $range : '7';
$days = (int)$range;

// --- Summary cards (today) ---
$summary = db_query(
    'SELECT COUNT(*) AS total,
            SUM(status = "success") AS success,
            SUM(status = "failed") AS failed,
            SUM(status = "partial") AS partial,
            SUM(status = "aborted") AS aborted,
            SUM(status = "running") AS running
     FROM backups
     WHERE start_time >= ? AND start_time <= ?',
    [$today . ' 00:00:00', $today . ' 23:59:59']
)->fetch();

$activeServers = (int)db_query('SELECT COUNT(*) AS c FROM servers WHERE is_active = 1')->fetch()['c'];
$totalServers  = (int)db_query('SELECT COUNT(*) AS c FROM servers')->fetch()['c'];

$s = (int)$summary['success'];
$p = (int)$summary['partial'];
$f = (int)$summary['failed'];
$r = (int)$summary['running'];
$total = (int)$summary['total'];

// --- Trend chart ---
$trendRows = db_query(
    "SELECT DATE(start_time) AS day,
            SUM(status = 'success') AS success,
            SUM(status = 'failed') AS failed,
            SUM(status = 'partial') AS partial,
            SUM(status = 'aborted') AS aborted
     FROM backups
     WHERE start_time >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
     GROUP BY DATE(start_time)
     ORDER BY day ASC",
    [$days - 1]
)->fetchAll();
$trend = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trend[$d] = ['success' => 0, 'failed' => 0, 'partial' => 0];
}
foreach ($trendRows as $row) {
    if (isset($trend[$row['day']])) {
        $trend[$row['day']] = ['success' => (int)$row['success'], 'failed' => (int)$row['failed'], 'partial' => (int)$row['partial']];
    }
}
$trendMax = 1;
foreach ($trend as $t) {
    $trendMax = max($trendMax, $t['success'] + $t['failed'] + $t['partial']);
}

// --- Status donut (today) ---
$donut = '';
if ($total > 0) {
    $pctS = round($s / $total * 360, 1);
    $pctP = round($p / $total * 360, 1);
    $pctF = round($f / $total * 360, 1);
    $pctR = max(0, 360 - $pctS - $pctP - $pctF);
    $donut = "conic-gradient(
        var(--chart-success) 0deg {$pctS}deg,
        var(--chart-partial) {$pctS}deg " . ($pctS + $pctP) . "deg,
        var(--chart-failed) " . ($pctS + $pctP) . "deg " . ($pctS + $pctP + $pctF) . "deg,
        var(--chart-running) " . ($pctS + $pctP + $pctF) . "deg 360deg
    )";
}

// --- Server health cards (today) ---
$serverHealth = db_query(
    "SELECT s.id, s.name AS server_name, s.ip, s.last_seen_at, s.is_active,
            COUNT(b.id) AS total,
            SUM(b.status = 'success') AS success,
            SUM(b.status = 'failed') AS failed,
            SUM(b.status = 'partial') AS partial,
            SUM(b.status = 'aborted') AS aborted,
            SUM(b.status = 'running') AS running,
            (SELECT b2.disk_used FROM backups b2 WHERE b2.server_id = s.id AND b2.disk_used IS NOT NULL ORDER BY b2.id DESC LIMIT 1) AS disk_used,
            (SELECT b2.disk_total FROM backups b2 WHERE b2.server_id = s.id AND b2.disk_total IS NOT NULL ORDER BY b2.id DESC LIMIT 1) AS disk_total,
            (SELECT b2.disk_free FROM backups b2 WHERE b2.server_id = s.id AND b2.disk_free IS NOT NULL ORDER BY b2.id DESC LIMIT 1) AS disk_free,
            (SELECT b2.end_time FROM backups b2 WHERE b2.server_id = s.id AND b2.end_time IS NOT NULL ORDER BY b2.id DESC LIMIT 1) AS last_disk_at
     FROM servers s
     LEFT JOIN backups b ON b.server_id = s.id AND b.start_time >= ? AND b.start_time <= ?
     WHERE s.is_active = 1
     GROUP BY s.id, s.name, s.ip, s.last_seen_at, s.is_active
     ORDER BY s.name ASC",
    [$today . ' 00:00:00', $today . ' 23:59:59']
)->fetchAll();

$connWindow = defined('CONNECTED_WINDOW_HOURS') ? (int)CONNECTED_WINDOW_HOURS : 48;
foreach ($serverHealth as $k => $sh) {
    $serverHealth[$k]['conn'] = $sh['last_seen_at'] === null ? 'never'
        : (strtotime($sh['last_seen_at']) >= time() - $connWindow * 3600 ? 'connected' : 'offline');
}

// --- Recent failures (last 8) ---
$recentFailures = db_query(
    "SELECT b.id, b.server_id, b.server_name, b.cpanel_user, b.status, b.end_time, b.error
     FROM backups b
     WHERE b.status IN ('failed','partial','aborted')
     ORDER BY b.id DESC LIMIT 8"
)->fetchAll();

// --- Last success time (for health footer) ---
$lastSuccess = db_query(
    "SELECT MAX(end_time) AS t FROM backups WHERE status = 'success'"
)->fetch()['t'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard — JBWizerd</title>
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

    <!-- Stat cards -->
    <section class="stat-grid">
        <div class="stat-card stat-total">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($total) ?></div>
                <div class="stat-label">Backups Today</div>
            </div>
            <div class="stat-trend muted">24h window</div>
        </div>
        <div class="stat-card stat-success">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($s) ?></div>
                <div class="stat-label">Successful</div>
            </div>
            <div class="stat-pct muted"><?= $total ? round($s / $total * 100) : 0 ?>%</div>
        </div>
        <div class="stat-card stat-partial">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($p) ?></div>
                <div class="stat-label">Partial</div>
            </div>
            <div class="stat-pct muted"><?= $total ? round($p / $total * 100) : 0 ?>%</div>
        </div>
        <div class="stat-card stat-failed">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($f) ?></div>
                <div class="stat-label">Failed</div>
            </div>
            <div class="stat-pct muted"><?= $total ? round($f / $total * 100) : 0 ?>%</div>
        </div>
        <div class="stat-card stat-servers">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($activeServers) ?><small class="muted">/<?= number_format($totalServers) ?></small></div>
                <div class="stat-label">Active Servers</div>
            </div>
            <div class="stat-trend muted"><?= number_format($activeServers ?: 0) ?> online</div>
        </div>
    </section>

    <!-- Charts row -->
    <section class="dash-grid">
        <div class="panel panel-trend">
            <div class="panel-head">
                <h2>Backup Trend</h2>
                <div class="filters">
                    <a class="btn btn-sm <?= $range === '7' ? 'btn-primary' : '' ?>" href="dashboard.php?range=7">7D</a>
                    <a class="btn btn-sm <?= $range === '15' ? 'btn-primary' : '' ?>" href="dashboard.php?range=15">15D</a>
                    <a class="btn btn-sm <?= $range === '30' ? 'btn-primary' : '' ?>" href="dashboard.php?range=30">30D</a>
                </div>
            </div>
            <div class="chart chart-tall">
                <?php foreach ($trend as $d => $t): ?>
                    <div class="chart-col" title="<?= date('M j', strtotime($d)) ?>">
                        <div class="chart-bars">
                            <?php if ($t['failed'] > 0): ?>
                                <div class="bar bar-failed" style="height:<?= max(4, round($t['failed'] / $trendMax * 100)) ?>%"></div>
                            <?php endif; ?>
                            <?php if ($t['partial'] > 0): ?>
                                <div class="bar bar-partial" style="height:<?= max(4, round($t['partial'] / $trendMax * 100)) ?>%"></div>
                            <?php endif; ?>
                            <?php if ($t['success'] > 0): ?>
                                <div class="bar bar-success" style="height:<?= max(4, round($t['success'] / $trendMax * 100)) ?>%"></div>
                            <?php endif; ?>
                        </div>
                        <div class="chart-day"><?= date('j', strtotime($d)) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="chart-legend">
                <span><i class="dot dot-success"></i> Success</span>
                <span><i class="dot dot-partial"></i> Partial</span>
                <span><i class="dot dot-failed"></i> Failed</span>
            </div>
        </div>

        <div class="panel panel-donut">
            <div class="panel-head">
                <h2>Status Breakdown</h2>
            </div>
            <div class="donut-wrap">
                <?php if ($donut): ?>
                    <div class="donut" style="background:<?= $donut ?>">
                        <div class="donut-center">
                            <div class="donut-value"><?= number_format($total) ?></div>
                            <div class="donut-label">backups</div>
                        </div>
                    </div>
                    <div class="donut-legend">
                        <span><i class="dot dot-success"></i> Success <b><?= number_format($s) ?></b></span>
                        <span><i class="dot dot-partial"></i> Partial <b><?= number_format($p) ?></b></span>
                        <span><i class="dot dot-failed"></i> Failed <b><?= number_format($f) ?></b></span>
                        <span><i class="dot dot-running"></i> Running <b><?= number_format($r) ?></b></span>
                    </div>
                <?php else: ?>
                    <p class="empty-panel">No backup data today yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Servers + failures row -->
    <section class="dash-grid">
        <div class="panel">
            <div class="panel-head">
                <h2>Server Health</h2>
                <a class="btn btn-sm" href="servers.php">Manage</a>
            </div>
            <?php if (!$serverHealth): ?>
                <p class="empty-panel">No active servers.</p>
            <?php endif; ?>
            <div class="server-list">
                <?php foreach ($serverHealth as $sh): ?>
                    <?php $shTotal = max(1, (int)$sh['total']); $shS = (int)$sh['success']; $shF = (int)$sh['failed']; $shP = (int)$sh['partial']; ?>
                    <a class="server-card" href="index.php?server=<?= (int)$sh['id'] ?>">
                        <div class="server-head">
                            <span class="conn-dot conn-<?= e($sh['conn']) ?>" title="Last seen: <?= e($sh['last_seen_at'] ?: 'never') ?>"></span>
                            <span class="server-name"><?= e($sh['server_name']) ?></span>
                            <?php if ($sh['ip']): ?><span class="server-ip muted"><?= e($sh['ip']) ?></span><?php endif; ?>
                        </div>
                        <div class="server-stats">
                            <span class="ss ss-success"><?= number_format($shS) ?> ok</span>
                            <span class="ss ss-partial"><?= number_format($shP) ?> partial</span>
                            <span class="ss ss-failed"><?= number_format($shF) ?> failed</span>
                        </div>
                        <?php if ($sh['disk_used'] && $sh['disk_total']): ?>
                            <div class="server-disk">
                                <span class="sd-used"><?= e($sh['disk_used']) ?> used</span>
                                <span class="muted">/ <?= e($sh['disk_total']) ?> total (<?= e($sh['disk_free'] ?: '—') ?> free)</span>
                                <span class="muted sd-time">Last Updated at <?= e(fmt_dt_long($sh['last_disk_at'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="server-bar">
                            <span class="sb sb-success" style="width:<?= round($shS / $shTotal * 100) ?>%"></span>
                            <span class="sb sb-partial" style="width:<?= round($shP / $shTotal * 100) ?>%"></span>
                            <span class="sb sb-failed" style="width:<?= round($shF / $shTotal * 100) ?>%"></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <h2>Recent Failures</h2>
                <a class="btn btn-sm" href="index.php?status=failed">View all</a>
            </div>
            <?php if (!$recentFailures): ?>
                <p class="empty-panel">No recent failures. All good!</p>
            <?php endif; ?>
            <div class="timeline">
                <?php foreach ($recentFailures as $fb): ?>
                    <div class="tl-item tl-<?= e($fb['status']) ?>">
                        <span class="tl-dot"></span>
                        <div class="tl-body">
                            <div class="tl-head">
                                <strong><?= e($fb['server_name'] ?: $fb['server_id']) ?></strong>
                                <span class="badge badge-<?= e($fb['status']) ?>"><?= e(status_label($fb['status'])) ?></span>
                            </div>
                            <div class="tl-meta"><?= e($fb['cpanel_user'] ?: '—') ?> · <?= e(fmt_dt_short($fb['end_time'])) ?></div>
                            <?php if ($fb['error']): ?>
                                <div class="tl-error"><?= e($fb['error']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <div class="foot-stats muted">
        <span>Last successful backup: <?= e(fmt_dt($lastSuccess)) ?></span>
        <span>·</span>
        <span>Data retained: 365 days</span>
    </div>
</main>

<script src="assets/app.js"></script>
</body>
</html>
<?php
/**
 * Backup Leaderboard — servers ranked by reliability.
 * Shows success streak, success rate, uptime, and an overall score.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$activePage = 'leaderboard';

$connectedWindow = defined('CONNECTED_WINDOW_HOURS') ? (int)CONNECTED_WINDOW_HOURS : 48;

// Fetch all servers with their backup stats
$servers = db_query(
    "SELECT s.id, s.name, s.ip, s.is_active, s.last_seen_at,
        (SELECT COUNT(*) FROM backups b WHERE b.server_id = s.id) AS total_backups,
        (SELECT COUNT(*) FROM backups b WHERE b.server_id = s.id AND b.status = 'success') AS success_count,
        (SELECT COUNT(*) FROM backups b WHERE b.server_id = s.id AND b.status = 'failed') AS failed_count,
        (SELECT COUNT(*) FROM backups b WHERE b.server_id = s.id AND b.status = 'partial') AS partial_count,
        (SELECT MAX(b.id) FROM backups b WHERE b.server_id = s.id AND b.status = 'success') AS last_success_id,
        (SELECT MAX(b.id) FROM backups b WHERE b.server_id = s.id) AS last_backup_id,
        (SELECT end_time FROM backups b WHERE b.server_id = s.id ORDER BY b.id DESC LIMIT 1) AS last_time,
        CASE
            WHEN s.last_seen_at IS NULL THEN 'never'
            WHEN s.last_seen_at >= DATE_SUB(NOW(), INTERVAL $connectedWindow HOUR) THEN 'connected'
            ELSE 'offline'
        END AS connection
     FROM servers s
     ORDER BY s.name ASC"
)->fetchAll();

$leaderboard = [];
foreach ($servers as $s) {
    $total = (int)$s['total_backups'];
    $success = (int)$s['success_count'];
    $failed = (int)$s['failed_count'];
    $partial = (int)$s['partial_count'];

    // Success rate (success / total)
    $rate = $total > 0 ? round($success / $total * 100) : 0;

    // Success streak: walk backwards from the last backup and count consecutive successes
    $streak = 0;
    if ($total > 0) {
        $backups = db_query(
            "SELECT status FROM backups WHERE server_id = ? ORDER BY id DESC LIMIT 100",
            [(int)$s['id']]
        )->fetchAll();
        foreach ($backups as $b) {
            if ($b['status'] === 'success') {
                $streak++;
            } else {
                break;
            }
        }
    }

    // Connection score
    $connScore = 0;
    if ($s['connection'] === 'connected') $connScore = 25;
    elseif ($s['connection'] === 'offline') $connScore = 10;
    // never: 0

    // Overall score: rate (0-50) + streak bonus (0-25) + connection (0-25) = 0-100
    $rateScore = $rate * 0.5;
    $streakScore = min($streak * 3, 25);
    $score = round($rateScore + $streakScore + $connScore);

    $leaderboard[] = [
        'id' => $s['id'],
        'name' => $s['name'],
        'ip' => $s['ip'],
        'connection' => $s['connection'],
        'total' => $total,
        'success' => $success,
        'failed' => $failed,
        'partial' => $partial,
        'rate' => $rate,
        'streak' => $streak,
        'score' => $score,
        'last_time' => $s['last_time'],
        'is_active' => $s['is_active'],
    ];
}

// Sort by score descending
usort($leaderboard, fn($a, $b) => $b['score'] <=> $a['score']);

// --- Stats ---
$scoreLabels = [
    ['range' => [90, 100], 'class' => 'success', 'label' => 'Excellent'],
    ['range' => [70, 89],  'class' => 'running', 'label' => 'Good'],
    ['range' => [40, 69],  'class' => 'partial', 'label' => 'Fair'],
    ['range' => [0, 39],   'class' => 'failed',  'label' => 'Poor'],
];
$connLabels = [
    'connected' => ['Connected', 'success'],
    'offline'   => ['Offline', 'offline'],
    'never'     => ['Never', 'inactive'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Leaderboard — JBWizerd</title>
<link rel="icon" type="image/png" href="assets/favicon.png">
<link rel="stylesheet" href="assets/style.css">
<script src="assets/theme.js"></script>
</head>
<body>
<?php require __DIR__ . '/includes/mobile_header.php'; ?>
<?php require __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
    <header class="topbar">
        <h1>Backup Leaderboard</h1>
        <p class="muted">Servers ranked by backup reliability — success streak, rate, and uptime.</p>
    </header>

    <?php foreach (flash_out() as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>

    <?php if (!$leaderboard): ?>
        <div class="panel"><p class="empty-panel">No servers yet. Add one to see the leaderboard.</p></div>
    <?php endif; ?>

    <?php foreach ($leaderboard as $i => $s): ?>
        <?php
        $rank = $i + 1;
        $inactive = (int)$s['is_active'] !== 1;
        [$connText, $connBadge] = $connLabels[$s['connection']] ?? ['—', 'inactive'];
        $scoreEntry = $scoreLabels[0];
        foreach ($scoreLabels as $sl) {
            if ($s['score'] >= $sl['range'][0] && $s['score'] <= $sl['range'][1]) {
                $scoreEntry = $sl;
                break;
            }
        }
        ?>
        <div class="lb-card<?= $inactive ? ' lb-inactive' : '' ?>">
            <div class="lb-rank lb-rank-<?= e($scoreEntry['class']) ?>">
                <?php if ($rank <= 3): ?>
                    <span class="lb-trophy"><?= ['🥇', '🥈', '🥉'][$rank - 1] ?></span>
                <?php endif; ?>
                <span class="lb-num">#<?= $rank ?></span>
            </div>
            <div class="lb-body">
                <div class="lb-head">
                    <span class="conn-dot conn-<?= e($s['connection']) ?>" title="Last seen: <?= e($s['last_seen_at'] ?: 'never') ?>"></span>
                    <div class="lb-title">
                        <div class="lb-name"><?= e($s['name']) ?></div>
                        <div class="lb-sub muted"><?= e($s['ip'] ?: 'no IP') ?></div>
                    </div>
                    <?php if ($inactive): ?>
                        <span class="badge badge-inactive">Disabled</span>
                    <?php else: ?>
                        <span class="badge badge-<?= e($connBadge) ?>"><?= e($connText) ?></span>
                    <?php endif; ?>
                </div>
                <div class="lb-stats">
                    <div class="lb-stat">
                        <span class="lb-score lb-score-<?= e($scoreEntry['class']) ?>"><?= $s['score'] ?></span>
                        <span class="lb-score-label"><?= e($scoreEntry['label']) ?></span>
                    </div>
                    <div class="lb-stat">
                        <b><?= number_format($s['streak']) ?></b>
                        <span>Streak</span>
                    </div>
                    <div class="lb-stat">
                        <b><?= $s['rate'] ?>%</b>
                        <span>Success</span>
                    </div>
                    <div class="lb-stat">
                        <b><?= number_format($s['total']) ?></b>
                        <span>Total</span>
                    </div>
                    <div class="lb-stat lb-stat-last">
                        <span class="badge badge-<?= $s['rate'] >= 90 ? 'success' : ($s['rate'] >= 70 ? 'running' : ($s['rate'] >= 40 ? 'partial' : 'failed')) ?>">
                            <?= $s['success'] ?>/<?= $s['total'] ?>
                        </span>
                        <span><?= $s['last_time'] ? fmt_dt_short($s['last_time']) : '—' ?></span>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</main>

<script src="assets/app.js"></script>
</body>
</html>
<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$activePage = 'servers';

$newToken = $_SESSION['new_token'] ?? null;
unset($_SESSION['new_token']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name  = trim($_POST['name'] ?? '');
        $ip    = trim($_POST['ip'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $group = trim($_POST['server_group'] ?? '');
        if ($name === '') {
            flash('error', 'Server name is required.');
        } else {
            $token = generate_token();
            db_query(
                'INSERT INTO servers (name, token_hash, ip, notes, server_group, is_active, last_seen_at, created_at)
                 VALUES (?, ?, ?, ?, ?, 1, NULL, NOW())',
                [$name, hash_token($token), $ip ?: null, $notes ?: null, $group ?: null]
            );
            $_SESSION['new_token'] = $token;
            audit('server_add', 'Server "' . $name . '" added');
            flash('success', 'Server added. Token generated below — copy it now, it is shown only once.');
        }
        redirect('servers.php');
    } elseif ($action === 'edit_group') {
        $id = (int)($_POST['id'] ?? 0);
        $group = trim($_POST['server_group'] ?? '');
        if ($group === '__new') {
            $group = trim($_POST['server_group_new'] ?? '');
        }
        $srv = db_query('SELECT name FROM servers WHERE id = ?', [$id])->fetch();
        if ($srv) {
            db_query('UPDATE servers SET server_group = ? WHERE id = ?', [$group !== '' ? $group : null, $id]);
            audit('server_group', 'Group updated for "' . $srv['name'] . '" → ' . ($group !== '' ? $group : '(none)'));
            flash('success', 'Server group updated.');
        }
        redirect('servers.php');
    } elseif ($action === 'delete_group') {
        $group = trim($_POST['server_group'] ?? '');
        if ($group !== '' && $group !== '__none') {
            $count = (int)db_query('UPDATE servers SET server_group = NULL WHERE server_group = ?', [$group])->rowCount();
            audit('group_delete', 'Group "' . $group . '" deleted (' . $count . ' server(s) ungrouped)');
            flash('success', 'Group "' . $group . '" deleted. ' . $count . ' server(s) moved to ungrouped.');
        }
        redirect('servers.php' . ($groupFilter !== '' ? '?group=' . urlencode($groupFilter) : ''));
    } elseif ($action === 'regenerate') {
        if (!is_admin()) {
            http_response_code(403);
            exit('Access denied. Admins only.');
        }
        $id = (int)($_POST['id'] ?? 0);
        $srv = db_query('SELECT name FROM servers WHERE id = ?', [$id])->fetch();
        if ($id > 0 && $srv) {
            $token = generate_token();
            db_query('UPDATE servers SET token_hash = ? WHERE id = ?', [hash_token($token), $id]);
            $_SESSION['new_token'] = $token;
            audit('server_key_regenerate', 'New key for "' . $srv['name'] . '"');
            flash('success', 'New token generated. Update the server config with the command below.');
        }
        redirect('servers.php');
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $server = db_query('SELECT is_active, name FROM servers WHERE id = ?', [$id])->fetch();
        if ($server) {
            $newStatus = (int)$server['is_active'] === 1 ? 0 : 1;
            db_query('UPDATE servers SET is_active = ? WHERE id = ?', [$newStatus, $id]);
            audit('server_toggle', '"' . $server['name'] . '" ' . ($newStatus ? 'enabled' : 'disabled'));
            flash('success', 'Server status updated.');
        }
        redirect('servers.php');
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $srv = db_query('SELECT name FROM servers WHERE id = ?', [$id])->fetch();
        db_query('DELETE FROM servers WHERE id = ?', [$id]);
        audit('server_delete', 'Server "' . ($srv['name'] ?? '#' . $id) . '" deleted');
        flash('success', 'Server deleted.');
        redirect('servers.php');
    } elseif ($action === 'regenerate_regkey') {
        if (!is_admin()) {
            http_response_code(403);
            exit('Access denied. Admins only.');
        }
        $newRegKey = generate_registration_key();
        if (update_config_value('REGISTRATION_KEY', $newRegKey)) {
            audit('registration_key_regenerate', 'New registration key generated');
            flash('success', 'New registration key generated. Servers using the old key must be re-registered.');
        } else {
            flash('error', 'Could not update config.php. Check file permissions.');
        }
        redirect('servers.php');
    }
}

/**
 * Update a define('KEY', 'value') entry in includes/config.php.
 * Returns true on success, false on failure.
 */
function update_config_value(string $key, string $value): bool
{
    $path = __DIR__ . '/includes/config.php';
    $content = @file_get_contents($path);
    if ($content === false) {
        return false;
    }
    $pattern = "/define\('" . preg_quote($key, '/') . "',\s*'[^']*'\)/";
    $replacement = "define('" . $key . "', '" . $value . "')";
    $newContent = preg_replace($pattern, $replacement, $content, 1, $count);
    if ($count === 0) {
        return false;
    }
    return @file_put_contents($path, $newContent) !== false;
}

$connectedWindow = defined('CONNECTED_WINDOW_HOURS') ? (int)CONNECTED_WINDOW_HOURS : 48;
$groupFilter = trim($_GET['group'] ?? '');
$searchFilter = trim($_GET['q'] ?? '');
$groupWhere = '';
$groupParams = [];
if ($groupFilter === '__none') {
    $groupWhere = 'WHERE (s.server_group IS NULL OR s.server_group = \'\')';
} elseif ($groupFilter !== '') {
    $groupWhere = 'WHERE s.server_group = ?';
    $groupParams[] = $groupFilter;
}
if ($searchFilter !== '') {
    $searchClause = 'WHERE (s.name LIKE ? OR s.ip LIKE ?)';
    $searchParams = ['%' . $searchFilter . '%', '%' . $searchFilter . '%'];
    if ($groupWhere !== '') {
        $groupWhere .= ' AND ' . $searchClause;
        $groupParams = array_merge($groupParams, $searchParams);
    } else {
        $groupWhere = $searchClause;
        $groupParams = $searchParams;
    }
}

$servers = db_query(
    "SELECT s.*,
        (SELECT COUNT(*) FROM backups b WHERE b.server_id = s.id AND b.start_time >= ?) AS backups_today,
        (SELECT COUNT(*) FROM backups b WHERE b.server_id = s.id) AS backups_total,
        (SELECT status FROM backups b WHERE b.server_id = s.id ORDER BY b.id DESC LIMIT 1) AS last_status,
        (SELECT end_time FROM backups b WHERE b.server_id = s.id ORDER BY b.id DESC LIMIT 1) AS last_time,
        CASE
            WHEN s.last_seen_at IS NULL THEN 'never'
            WHEN s.last_seen_at >= DATE_SUB(NOW(), INTERVAL $connectedWindow HOUR) THEN 'connected'
            ELSE 'offline'
        END AS connection
     FROM servers s
     $groupWhere
     ORDER BY COALESCE(s.server_group, ''), s.name ASC",
    array_merge([date('Y-m-d') . ' 00:00:00'], $groupParams)
)->fetchAll();

$groups = db_query("SELECT DISTINCT server_group FROM servers WHERE server_group IS NOT NULL AND server_group != '' ORDER BY server_group ASC")->fetchAll();

$panelUrl = panel_url();
$regKey = REGISTRATION_KEY;

// --- Stats for header cards ---
$todayStart = date('Y-m-d') . ' 00:00:00';
$totalServersCount = (int)db_query('SELECT COUNT(*) AS c FROM servers')->fetch()['c'];
$activeCount = (int)db_query('SELECT COUNT(*) AS c FROM servers WHERE is_active = 1')->fetch()['c'];
$connectedCount = (int)db_query("SELECT COUNT(*) AS c FROM servers WHERE is_active = 1 AND last_seen_at >= DATE_SUB(NOW(), INTERVAL $connectedWindow HOUR)")->fetch()['c'];
$backupsTodayCount = (int)db_query('SELECT COUNT(*) AS c FROM backups WHERE start_time >= ?', [$todayStart])->fetch()['c'];
$failedTodayCount = (int)db_query("SELECT COUNT(*) AS c FROM backups WHERE start_time >= ? AND status = 'failed'", [$todayStart])->fetch()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Servers — JBWizerd</title>
<link rel="icon" type="image/png" href="assets/favicon.png">
<link rel="stylesheet" href="assets/style.css">
<script src="assets/theme.js"></script>
</head>
<body>
<?php require __DIR__ . '/includes/mobile_header.php'; ?>
<?php require __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
    <header class="topbar topbar-servers">
        <div class="tb-left">
            <select name="group" class="input" onchange="location.href='servers.php<?= $searchFilter !== '' ? '?q=' . urlencode($searchFilter) : '' ?><?= $searchFilter !== '' ? '&' : '?' ?>group='+encodeURIComponent(this.value)">
                <option value="">All groups</option>
                <?php foreach ($groups as $g): ?>
                    <option value="<?= e($g['server_group']) ?>" <?= $groupFilter === $g['server_group'] ? 'selected' : '' ?>>
                        <?= e($g['server_group']) ?>
                    </option>
                <?php endforeach; ?>
                <option value="__none" <?= $groupFilter === '__none' ? 'selected' : '' ?>>Ungrouped</option>
            </select>
            <?php foreach ($groups as $g): ?>
                <?php if ($groupFilter === $g['server_group']): ?>
                    <form method="post" class="inline" data-confirm="Delete group &quot;<?= e($g['server_group']) ?>&quot;? Servers in this group will become ungrouped.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_group">
                        <input type="hidden" name="server_group" value="<?= e($g['server_group']) ?>">
                        <button class="btn btn-sm btn-danger" title="Delete this group">✕</button>
                    </form>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <form method="get" class="tb-search">
            <?php if ($groupFilter !== ''): ?><input type="hidden" name="group" value="<?= e($groupFilter) ?>"><?php endif; ?>
            <input type="search" name="q" value="<?= e($searchFilter) ?>" class="input" placeholder="Search hostname or IP...">
            <button type="submit" class="btn btn-primary">Search</button>
        </form>
        <div class="tb-right">
            <button class="btn btn-primary" id="btn-add-server">+ Add Server</button>
        </div>
    </header>

    <?php foreach (flash_out() as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>

    <?php if ($newToken !== null): ?>
        <div class="flash flash-success flash-persist">
            <strong>New server key — copy it now, it is shown only once:</strong>
            <code class="token-code" id="token-display"><?= e($newToken) ?></code>
            <div class="hint">On the server, re-run the registration command to apply a fresh key:
                <code class="install-cmd">bash &lt;(curl -sL <?= e($panelUrl) ?>/hook/install.sh) --panel-url <?= e($panelUrl) ?> --register-key <?= e($regKey) ?></code>
            </div>
        </div>
    <?php endif; ?>

    <section class="stat-grid">
        <div class="stat-card stat-total">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($totalServersCount) ?></div>
                <div class="stat-label">Total Servers</div>
            </div>
        </div>
        <div class="stat-card stat-servers">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($activeCount) ?></div>
                <div class="stat-label">Active Servers</div>
            </div>
        </div>
        <div class="stat-card stat-success">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($connectedCount) ?></div>
                <div class="stat-label">Connected Now</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($backupsTodayCount) ?></div>
                <div class="stat-label">Backups Today</div>
            </div>
        </div>
        <div class="stat-card stat-failed">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($failedTodayCount) ?></div>
                <div class="stat-label">Failed Today</div>
            </div>
        </div>
    </section>

    <?php if (is_admin()): ?>
    <section class="panel regkey-panel">
        <div class="regkey-main">
            <div class="regkey-icon"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg></div>
            <div class="regkey-body">
                <h2>Registration Key</h2>
                <p class="muted">New servers use this key to register automatically and receive their own unique key.</p>
                <div class="regkey-box">
                    <code id="regkey"><?= e($regKey) ?></code>
                    <form method="post" class="inline" data-confirm="Generate a new registration key? Servers using the old key will be rejected until re-registered.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="regenerate_regkey">
                        <button class="btn btn-primary btn-copy"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Generate New Key</button>
                    </form>
                    <button class="btn btn-copy" data-copy="#regkey"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> Copy Key</button>
                </div>
            </div>
        </div>
        <div class="regkey-cmd">
            <div class="regkey-cmd-head">
                <span class="muted">Install command for each server</span>
                <button class="btn btn-sm" data-copy="#install-cmd"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> Copy</button>
            </div>
            <code class="install-cmd" id="install-cmd">bash &lt;(curl -sL <?= e($panelUrl) ?>/hook/install.sh) --panel-url <?= e($panelUrl) ?> --register-key <?= e($regKey) ?></code>
        </div>
    </section>

    <!-- Full installation guide -->
    <section class="panel guide-panel">
        <div class="panel-head">
            <h2>Server Installation Guide</h2>
            <button class="btn btn-sm" id="btn-toggle-guide">Show / Hide</button>
        </div>
        <div id="install-guide" class="guide-body" hidden>
            <div class="guide-steps">
                <div class="guide-step">
                    <span class="guide-num">1</span>
                    <div class="guide-content">
                        <h3>Run the install command on the server</h3>
                        <p class="muted">SSH into the JetBackup server as <strong>root</strong> and run:</p>
                        <code class="install-cmd">bash &lt;(curl -sL <?= e($panelUrl) ?>/hook/install.sh) --panel-url <?= e($panelUrl) ?> --register-key <?= e($regKey) ?></code>
                        <p class="muted" style="margin-top:6px">This downloads the script to <code>/JBWizerd/</code>, writes <code>config.json</code>, and registers the server automatically.</p>
                    </div>
                </div>
                <div class="guide-step">
                    <span class="guide-num">2</span>
                    <div class="guide-content">
                        <h3>Add the hooks in JetBackup (manual step)</h3>
                        <p class="muted">The installer cannot add hooks for you — JetBackup requires UI setup. Open <strong>JetBackup 5 → API / Hooks</strong> and add both:</p>
                        <table class="table guide-table">
                            <thead><tr><th>Hook</th><th>Command</th><th>When it fires</th></tr></thead>
                            <tbody>
                                <tr>
                                    <td><strong>Pre backup hook</strong></td>
                                    <td><code>/usr/bin/python3 /JBWizerd/jb_hook.py</code></td>
                                    <td>Job starts → sends <span class="badge badge-running">running</span> + progress</td>
                                </tr>
                                <tr>
                                    <td><strong>Post backup hook</strong></td>
                                    <td><code>/usr/bin/python3 /JBWizerd/jb_hook.py</code></td>
                                    <td>Job completes → sends final status + duration</td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="muted">Both use the same script — it detects which event it is by checking whether the newest job has finished.</p>
                    </div>
                </div>
                <div class="guide-step">
                    <span class="guide-num">3</span>
                    <div class="guide-content">
                        <h3>Verify the installation</h3>
                        <p class="muted">After adding the hooks:</p>
                        <ul class="guide-list">
                            <li>Server shows as <strong>Connected</strong> on this page</li>
                            <li>Next real backup appears in the Backup Report</li>
                            <li>Check <code>cat /JBWizerd/hook-errors.log</code> on the server if anything looks wrong</li>
                        </ul>
                    </div>
                </div>
                <div class="guide-step">
                    <span class="guide-num">4</span>
                    <div class="guide-content">
                        <h3>Test the hook manually (optional)</h3>
                        <p class="muted">On the server, run a fake report to verify the pipeline works instantly:</p>
                        <code class="install-cmd">echo '{"status":"success","start_time":"<?= e(date('Y-m-d\TH:i:s', time() - 3600)) ?>Z","end_time":"<?= e(date('Y-m-d\TH:i:s')) ?>Z","username":"testuser"}' | /usr/bin/python3 /JBWizerd/jb_hook.py</code>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-head">
            <h2>Servers <span class="count"><?= count($servers) ?></span></h2>
        </div>
        <?php if (!$servers): ?>
            <p class="empty-panel">No servers yet. Add one or run the install command on a server.</p>
        <?php endif; ?>
        <div class="srv-grid">
            <?php foreach ($servers as $s): ?>
                <?php
                $connLabels = [
                    'connected' => ['Connected', 'success'],
                    'offline'   => ['Offline', 'offline'],
                    'never'     => ['Never', 'inactive'],
                ];
                [$connText, $connBadge] = $connLabels[$s['connection']] ?? ['—', 'inactive'];
                $inactive = (int)$s['is_active'] !== 1;
                ?>
                <div class="srv-card<?= $inactive ? ' srv-inactive' : '' ?>">
                    <div class="srv-head">
                        <span class="conn-dot conn-<?= e($s['connection']) ?>" title="Last seen: <?= e($s['last_seen_at'] ?: 'never') ?>"></span>
                        <div class="srv-title">
                            <div class="srv-name"><?= e($s['name']) ?></div>
                            <div class="srv-sub muted"><?= e($s['ip'] ?: 'no IP') ?><?= $s['notes'] ? ' · ' . e($s['notes']) : '' ?></div>
                        </div>
                        <?php if ($s['server_group']): ?>
                            <span class="badge badge-group" title="Group"><?= e($s['server_group']) ?></span>
                        <?php endif; ?>
                        <?php if ($inactive): ?>
                            <span class="badge badge-inactive">Disabled</span>
                        <?php else: ?>
                            <span class="badge badge-<?= e($connBadge) ?>"><?= e($connText) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="srv-stats">
                        <div class="srv-stat">
                            <b><?= number_format((int)$s['backups_today']) ?></b>
                            <span>Today</span>
                        </div>
                        <div class="srv-stat">
                            <b><?= number_format((int)$s['backups_total']) ?></b>
                            <span>Total</span>
                        </div>
                        <div class="srv-stat srv-stat-last">
                            <?php if ($s['last_status']): ?>
                                <span class="badge badge-<?= e($s['last_status']) ?>"><?= e($s['last_status']) ?></span>
                            <?php else: ?>
                                <span class="badge badge-inactive">No data</span>
                            <?php endif; ?>
                            <span><?= e(fmt_dt_short($s['last_time'])) ?></span>
                        </div>
                    </div>

                    <?php
                    // Registration / hook health checklist
                    $regChecks = [];
                    // 1. Hook reachable: server has reported (last_seen) within the window
                    if ($s['connection'] === 'connected') {
                        $regChecks[] = ['Hook active (reporting)', true, 'Last hook run: ' . ($s['last_seen_at'] ? fmt_dt($s['last_seen_at']) : 'never')];
                    } elseif ($s['connection'] === 'offline') {
                        $regChecks[] = ['Hook inactive', false, 'No report in > ' . $connectedWindow . 'h'];
                    } else {
                        $regChecks[] = ['Hook never reported', false, 'Register with the install command below'];
                    }
                    // 2. Report received: at least one backup report exists
                    if ((int)$s['backups_total'] > 0) {
                        $regChecks[] = ['Reports received', true, number_format((int)$s['backups_total']) . ' total report(s)'];
                    } else {
                        $regChecks[] = ['Reports received', false, 'No backup reports yet'];
                    }
                    // 3. Backup report recency: any report within last 48h
                    $lastTimeTs = $s['last_time'] ? strtotime($s['last_time']) : 0;
                    if ($lastTimeTs >= time() - 48 * 3600) {
                        $regChecks[] = ['Recent backup report', true, 'Last: ' . ($s['last_time'] ? fmt_dt_short($s['last_time']) : '—')];
                    } elseif ($lastTimeTs > 0) {
                        $regChecks[] = ['Recent backup report', false, 'Older than 48h'];
                    } else {
                        $regChecks[] = ['Recent backup report', false, 'None yet'];
                    }
                    ?>
                    <div class="reg-check">
                        <?php foreach ($regChecks as [$label, $ok, $detail]): ?>
                            <div class="reg-item <?= $ok ? 'reg-ok' : 'reg-bad' ?>">
                                <span class="reg-icon"><?= $ok ? '✓' : '✕' ?></span>
                                <span class="reg-label"><?= e($label) ?></span>
                                <span class="reg-detail muted"><?= e($detail) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="srv-actions">
                        <?php if (is_admin()): ?>
                        <form method="post" class="inline" data-confirm="Generate a new key for <?= e($s['name']) ?>? The old key will stop working immediately.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="regenerate">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <button class="btn btn-sm btn-primary" title="Generate a new key">New Key</button>
                        </form>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-group" data-group-id="<?= (int)$s['id'] ?>" data-group-name="<?= e($s['server_group'] ?? '') ?>" title="Edit group">Group</button>
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <button class="btn btn-sm"><?= $inactive ? 'Enable' : 'Disable' ?></button>
                        </form>
                        <?php if (is_admin()): ?>
                        <form method="post" class="inline" data-confirm="Delete this server and all its backup history?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</main>

<!-- Add Server Modal -->
<div class="modal" id="add-server-modal" hidden>
    <div class="modal-box">
        <h2>Add Server</h2>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <label>Server Name / Hostname *
                <input type="text" name="name" required placeholder="srv01.example.com">
            </label>
            <label>IP Address
                <input type="text" name="ip" placeholder="203.0.113.10">
            </label>
            <label>Group / Location
                <input type="text" name="server_group" placeholder="e.g. Dedicated-USA, Shared-DH, bd26" list="group-list">
                <datalist id="group-list">
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= e($g['server_group']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </label>
            <label>Notes
                <input type="text" name="notes" placeholder="Optional">
            </label>
            <div class="modal-actions">
                <button type="button" class="btn" data-close-modal>Cancel</button>
                <button type="submit" class="btn btn-primary">Generate Key</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Group Modal -->
<div class="modal" id="group-modal" hidden>
    <div class="modal-box">
        <h2>Edit Server Group</h2>
        <p class="muted">Select an existing group or create a new one.</p>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_group">
            <input type="hidden" name="id" id="group-id" value="">
            <label>Group / Location
                <select name="server_group" id="group-select" class="input" onchange="toggleNewGroup(this)">
                    <option value="">— No group —</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= e($g['server_group']) ?>"><?= e($g['server_group']) ?></option>
                    <?php endforeach; ?>
                    <option value="__new">+ New group...</option>
                </select>
            </label>
            <label id="new-group-label" hidden>New group name
                <input type="text" name="server_group_new" id="group-new-input" class="input" placeholder="e.g. Dedicated-USA, Shared-DH">
            </label>
            <div class="modal-actions">
                <button type="button" class="btn" data-close-modal>Cancel</button>
                <button type="submit" class="btn btn-primary">Save Group</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/app.js"></script>
</body>
</html>
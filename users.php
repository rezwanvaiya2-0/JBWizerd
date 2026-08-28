<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();

$activePage = 'users';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $role     = ($_POST['role'] ?? '') === 'member' ? 'member' : 'admin';
        if ($username === '' || $password === '') {
            flash('error', 'Username and password are required.');
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            flash('error', 'Please enter a valid email address.');
        } elseif ($policyErr = password_policy_error($password)) {
            flash('error', $policyErr);
        } else {
            $exists = db_query('SELECT id FROM admin_users WHERE username = ? OR email = ? LIMIT 1', [$username, $email])->fetch();
            if ($exists) {
                flash('error', 'A user with that username or email already exists.');
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                db_query(
                    'INSERT INTO admin_users (username, email, password_hash, password_history, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())',
                    [$username, $email, $hash, json_encode([$hash]), $role]
                );
                audit('user_add', 'User "' . $username . '" (' . $role . ') added');
                flash('success', 'User "' . e($username) . '" added.');
            }
        }
        redirect('users.php');
    } elseif ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        $password = (string)($_POST['password'] ?? '');
        if ($id > 0 && password_policy_error($password) === '') {
            $row = db_query('SELECT username FROM admin_users WHERE id = ?', [$id])->fetch();
            $hash = password_hash($password, PASSWORD_BCRYPT);
            db_query('UPDATE admin_users SET password_hash = ? WHERE id = ?', [$hash, $id]);
            push_password_history($id, $hash);
            audit('user_password_reset', 'Password reset for "' . ($row['username'] ?? '#' . $id) . '"');
            flash('success', 'Password updated.');
        } elseif ($id > 0) {
            flash('error', password_policy_error($password));
        }
        redirect('users.php');
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)current_user()['id']) {
            flash('error', 'You cannot disable your own account.');
        } else {
            $user = db_query('SELECT is_active, username FROM admin_users WHERE id = ?', [$id])->fetch();
            if ($user) {
                $ns = (int)$user['is_active'] === 1 ? 0 : 1;
                db_query('UPDATE admin_users SET is_active = ? WHERE id = ?', [$ns, $id]);
                audit('user_toggle', '"' . $user['username'] . '" ' . ($ns ? 'enabled' : 'disabled'));
                flash('success', 'User status updated.');
            }
        }
        redirect('users.php');
    } elseif ($action === 'role') {
        $id = (int)($_POST['id'] ?? 0);
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'member';
        if ($id === (int)current_user()['id']) {
            flash('error', 'You cannot change your own role.');
        } else {
            $row = db_query('SELECT username FROM admin_users WHERE id = ?', [$id])->fetch();
            db_query('UPDATE admin_users SET role = ? WHERE id = ?', [$role, $id]);
            audit('user_role', '"' . ($row['username'] ?? '#' . $id) . '" set to ' . $role);
            flash('success', 'User role updated.');
        }
        redirect('users.php');
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)current_user()['id']) {
            flash('error', 'You cannot delete your own account.');
        } else {
            $row = db_query('SELECT username FROM admin_users WHERE id = ?', [$id])->fetch();
            db_query('DELETE FROM admin_users WHERE id = ?', [$id]);
            audit('user_delete', 'User "' . ($row['username'] ?? '#' . $id) . '" deleted');
            flash('success', 'User deleted.');
        }
        redirect('users.php');
    }
}

$users = db_query('SELECT id, username, email, role, is_active, created_at FROM admin_users ORDER BY role DESC, username ASC')->fetchAll();
$totalUsers = count($users);
$adminCount = (int)db_query("SELECT COUNT(*) AS c FROM admin_users WHERE role = 'admin'")->fetch()['c'];
$memberCount = $totalUsers - $adminCount;
$activeCount = (int)db_query('SELECT COUNT(*) AS c FROM admin_users WHERE is_active = 1')->fetch()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Users — JBWizerd</title>
<link rel="icon" type="image/png" href="assets/favicon.png">
<link rel="stylesheet" href="assets/style.css">
<script src="assets/theme.js"></script>
</head>
<body>
<?php require __DIR__ . '/includes/mobile_header.php'; ?>
<?php require __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
    <header class="topbar">
        <button class="btn btn-primary" id="btn-add-user">+ Add User</button>
    </header>

    <?php foreach (flash_out() as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>

    <section class="stat-grid">
        <div class="stat-card stat-total">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($totalUsers) ?></div>
                <div class="stat-label">Total Users</div>
            </div>
        </div>
        <div class="stat-card stat-success">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($adminCount) ?></div>
                <div class="stat-label">Admins</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($memberCount) ?></div>
                <div class="stat-label">Members</div>
            </div>
        </div>
        <div class="stat-card stat-servers">
            <div class="stat-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($activeCount) ?></div>
                <div class="stat-label">Active</div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Users <span class="count"><?= count($users) ?></span></h2>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$users): ?>
                        <tr><td colspan="6" class="empty">No users.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($users as $u): ?>
                        <?php $isSelf = (int)$u['id'] === (int)current_user()['id']; ?>
                        <tr>
                            <td><strong><?= e($u['username']) ?></strong></td>
                            <td><?= e($u['email']) ?></td>
                            <td><span class="badge badge-<?= $u['role'] === 'admin' ? 'success' : 'running' ?>"><?= e($u['role']) ?></span></td>
                            <td>
                                <span class="badge badge-<?= (int)$u['is_active'] === 1 ? 'success' : 'inactive' ?>">
                                    <?= (int)$u['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td><?= e(fmt_dt($u['created_at'])) ?></td>
                            <td class="actions">
                                <?php if ($isSelf): ?>
                                    <a href="change-password.php" class="btn btn-sm">Change Password</a>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-reset" data-id="<?= (int)$u['id'] ?>" data-user="<?= e($u['username']) ?>">Reset Pwd</button>
                                <?php endif; ?>
                                <form method="post" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="role">
                                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                    <input type="hidden" name="role" value="<?= $u['role'] === 'admin' ? 'member' : 'admin' ?>">
                                    <button class="btn btn-sm" <?= $isSelf ? 'disabled' : '' ?>><?= $u['role'] === 'admin' ? 'Demote' : 'Promote' ?></button>
                                </form>
                                <form method="post" class="inline" data-confirm="Toggle active status for <?= e($u['username']) ?>?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                    <button class="btn btn-sm" <?= $isSelf ? 'disabled' : '' ?>><?= (int)$u['is_active'] === 1 ? 'Disable' : 'Enable' ?></button>
                                </form>
                                <form method="post" class="inline" data-confirm="Delete user <?= e($u['username']) ?>?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                    <button class="btn btn-sm btn-danger" <?= $isSelf ? 'disabled' : '' ?>>Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<!-- Add User Modal -->
<div class="modal" id="add-user-modal" hidden>
    <div class="modal-box">
        <h2>Add User</h2>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <label>Username *
                <input type="text" name="username" required placeholder="janedoe">
            </label>
            <label>Email *
                <input type="email" name="email" required placeholder="jane@example.com">
            </label>
            <label>Password *
                <input type="password" name="password" required minlength="10">
            </label>
            <label>Role
                <select name="role" class="input">
                    <option value="member" selected>Team Member</option>
                    <option value="admin">Administrator</option>
                </select>
            </label>
            <div class="modal-actions">
                <button type="button" class="btn" data-close-modal>Cancel</button>
                <button type="submit" class="btn btn-primary">Add User</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal" id="reset-pwd-modal" hidden>
    <div class="modal-box">
        <h2>Reset Password</h2>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="id" id="reset-pwd-id" value="">
            <p class="muted">New password for <strong id="reset-pwd-user"></strong></p>
            <label>New Password
                <input type="password" name="password" required minlength="10" autofocus>
            </label>
            <div class="modal-actions">
                <button type="button" class="btn" data-close-modal>Cancel</button>
                <button type="submit" class="btn btn-primary">Update Password</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/app.js"></script>
</body>
</html>
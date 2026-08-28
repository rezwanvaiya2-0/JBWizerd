<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$activePage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $u = current_user();

    $row = db_query('SELECT password_hash FROM admin_users WHERE id = ?', [(int)$u['id']])->fetch();
    if (!$row || !password_verify($current, $row['password_hash'])) {
        flash('error', 'Current password is incorrect.');
    } elseif ($new !== $confirm) {
        flash('error', 'New passwords do not match.');
    } elseif ($policyErr = password_policy_error($new)) {
        flash('error', $policyErr);
    } elseif (password_was_recent((int)$u['id'], $new)) {
        flash('error', 'You have recently used this password. Please choose a different one.');
    } else {
        $hash = password_hash($new, PASSWORD_BCRYPT);
        db_query('UPDATE admin_users SET password_hash = ? WHERE id = ?', [$hash, (int)$u['id']]);
        push_password_history((int)$u['id'], $hash);
        audit('password_change', 'Password changed');
        flash('success', 'Password changed successfully.');
    }
    redirect('change-password.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Change Password — JBWizerd</title>
<link rel="icon" type="image/png" href="assets/favicon.png">
<link rel="stylesheet" href="assets/style.css">
<script src="assets/theme.js"></script>
</head>
<body>
<?php require __DIR__ . '/includes/mobile_header.php'; ?>
<?php require __DIR__ . '/includes/sidebar.php'; ?>

<main class="main center-page">
    <div class="form-card">
        <div class="form-card-head">
            <div class="form-card-icon">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <h1>Change Password</h1>
            <p class="muted">Update the password for your account</p>
        </div>

        <?php foreach (flash_out() as $flash): ?>
            <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>

        <form method="post" class="form">
            <?= csrf_field() ?>
            <label>Current Password
                <input type="password" name="current_password" required autofocus autocomplete="current-password">
            </label>
            <label>New Password
                <input type="password" name="password" required minlength="10" autocomplete="new-password">
            </label>
            <label>Confirm New Password
                <input type="password" name="confirm_password" required minlength="10" autocomplete="new-password">
            </label>
            <button type="submit" class="btn btn-primary btn-block">Update Password</button>
        </form>
    </div>

    <div class="form-card cli-guide-card">
        <div class="cli-guide-header">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
            <span>Forgot your password? Reset via CLI</span>
        </div>
        <div id="cli-guide">
            <div class="cli-guide-body">
                <p class="muted">If you're locked out, reset the password over SSH using the built-in CLI tool:</p>
                <div class="guide-step">
                    <span class="guide-num">1</span>
                    <div class="guide-content">
                        <h3>SSH into the panel server</h3>
                        <p class="muted">Connect to your hosting over SSH, then go to the panel folder:</p>
                        <code class="install-cmd">cd /path/to/JBWizerd</code>
                    </div>
                </div>
                <div class="guide-step">
                    <span class="guide-num">2</span>
                    <div class="guide-content">
                        <h3>Run the CLI reset for your username</h3>
                        <p class="muted">It will prompt for the new password (and confirm) — no web login needed:</p>
                        <code class="install-cmd">php cli-reset-password.php <?= e(current_user()['username']) ?></code>
                    </div>
                </div>
                <div class="guide-step">
                    <span class="guide-num">3</span>
                    <div class="guide-content">
                        <h3>Log in with the new password</h3>
                        <p class="muted">Returns to the login page and sign in with the password you just set.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="assets/app.js"></script>
</body>
</html>
<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (current_user() !== null) {
    redirect('index.php');
}

$error = '';

// ---- Brute-force check ----
$ip = client_ip();
$lockWindow = defined('LOGIN_LOCKOUT_WINDOW') ? (int)LOGIN_LOCKOUT_WINDOW : 900;      // 15 min
$maxAttempts = defined('LOGIN_MAX_ATTEMPTS') ? (int)LOGIN_MAX_ATTEMPTS : 5;
$failedCount = 0;
if ($ip !== '') {
    $stmt = db_query(
        'SELECT COUNT(*) AS c FROM audit_log WHERE action = "login_failed" AND ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)',
        [$ip, $lockWindow]
    );
    $failedCount = (int)$stmt->fetch()['c'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($failedCount >= $maxAttempts) {
        $error = 'Too many failed login attempts. Please wait a few minutes and try again.';
        audit('login_locked', 'Login blocked for IP ' . $ip . ' after ' . $failedCount . ' failures');
    } elseif (attempt_login($username, $password)) {
        audit('login', 'Login successful');
        redirect('index.php');
    } else {
        audit('login_failed', 'Failed login attempt for "' . $username . '"');
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — JBWizerd Panel</title>
<link rel="icon" type="image/png" href="assets/favicon.png">
<link rel="stylesheet" href="assets/style.css">
<script src="assets/theme.js"></script>
</head>
<body class="login-page">
<button type="button" class="theme-toggle theme-toggle-fixed" data-theme-toggle title="Toggle theme">☾</button>
<div class="login-card">
    <img class="login-logo" src="assets/logo.png" alt="JBWizerd">
    <p class="muted">Backup monitoring for JetBackup servers</p>

    <?php if ($error): ?>
        <div class="flash flash-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="form">
        <label>Email or Username
            <input type="text" name="username" required autofocus autocomplete="username" placeholder="name@example.com">
        </label>
        <label>Password
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button type="submit" class="btn btn-primary btn-block">Sign In</button>
    </form>
</div>


<script src="assets/app.js"></script>
</body>
</html>
<?php
// Setup wizard: creates config.php (if needed), tables, and admin user.

require_once __DIR__ . '/includes/functions.php';

$error = '';
$success = '';
$configPath = __DIR__ . '/includes/config.php';
$configExists = file_exists($configPath);
$configReady = false;

// Decide whether config.php is already properly configured
if ($configExists) {
    $content = file_get_contents($configPath);
    if (strpos($content, "CHANGE_ME") === false) {
        $configReady = true;
    }
}

// Step 1: user submitted DB credentials -> write config.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'write_config') {
    $dbHost  = trim($_POST['db_host'] ?? 'localhost');
    $dbName  = trim($_POST['db_name'] ?? '');
    $dbUser  = trim($_POST['db_user'] ?? '');
    $dbPass  = (string)($_POST['db_pass'] ?? '');
    $regKey  = generate_registration_key();
    $panelUrl = trim($_POST['panel_url'] ?? '');

    if ($dbName === '' || $dbUser === '') {
        $error = 'Database name and user are required.';
    } else {
        try {
            $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            $error = 'Database connection failed: ' . htmlspecialchars($e->getMessage());
        }
    }

    if ($error === '') {
        $cfg = <<<PHP
<?php
/**
 * JBWizerd Panel - Configuration
 */

// Production-safe error handling: never show errors to visitors, log them instead.
ini_set('display_errors', '0');
error_reporting(E_ALL);

define('DB_HOST', '{$dbHost}');
define('DB_NAME', '{$dbName}');
define('DB_USER', '{$dbUser}');
define('DB_PASS', '{$dbPass}');
define('DB_CHARSET', 'utf8mb4');
define('PANEL_URL', '{$panelUrl}');
define('REGISTRATION_KEY', '{$regKey}');
define('RETENTION_DAYS', 365);
define('WEBHOOK_LOG_RETENTION_DAYS', 90);
define('AUDIT_LOG_RETENTION_DAYS', 90);
define('WEBHOOK_MAX_ATTEMPTS', 5);
define('CONNECTED_WINDOW_HOURS', 48);
define('SESSION_NAME', 'jbpanel_session');
define('SESSION_LIFETIME', 7200);
define('TIMEZONE', 'Asia/Dhaka');
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_WINDOW', 900);
define('REGISTER_MAX_PER_HOUR', 10);
define('STUCK_BACKUP_HOURS', 24);
define('TRUSTED_PROXIES', '');
PHP;
        file_put_contents($configPath, $cfg);
        $success = 'config.php written successfully.';
        $configExists = true;
        $configReady = true;
    }
}

$tablesExist = false;
$adminExists = false;

// Load config if ready and run schema
if ($configReady) {
    require_once $configPath;
    require_once __DIR__ . '/includes/db.php';

    try {
        $tablesExist = (bool)db_query("SHOW TABLES LIKE 'admin_users'")->fetch();
        if ($tablesExist) {
            $adminExists = (bool)db_query('SELECT id FROM admin_users LIMIT 1')->fetch();
        }
    } catch (Exception $e) {
        $error = 'Database check failed: ' . htmlspecialchars($e->getMessage());
    }
}

// Once fully set up, disable setup.php — redirect to login.
if ($configReady && $adminExists) {
    redirect('login.php');
}

// Install schema (skip if tables already exist, unless forced)
$installRequested = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install') || isset($_GET['install']);
if ($configReady && $installRequested && !$tablesExist) {
    $schemaPath = __DIR__ . '/install.sql';
    if (file_exists($schemaPath)) {
        $sql = file_get_contents($schemaPath);
        $statements = array_filter(array_map('trim', explode(';', $sql)), fn($s) => $s !== '');
        foreach ($statements as $stmt) {
            db()->exec($stmt . ';');
        }
        $tablesExist = true;
        $success = 'Database tables installed.';
    }
}

// Migrate existing DB: add email/role/is_active columns if missing
if ($configReady && $tablesExist) {
    $cols = db_query("SHOW COLUMNS FROM admin_users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('email', $cols)) {
        db()->exec("ALTER TABLE admin_users ADD COLUMN email VARCHAR(190) DEFAULT NULL AFTER username");
        db()->exec("UPDATE admin_users SET email = CONCAT(LOWER(username), '@local') WHERE email IS NULL OR email = ''");
    }
    if (!in_array('role', $cols)) {
        db()->exec("ALTER TABLE admin_users ADD COLUMN role ENUM('admin','member') NOT NULL DEFAULT 'member' AFTER password_hash");
        db()->exec("UPDATE admin_users SET role = 'admin' WHERE role = 'member'");
    }
    if (!in_array('is_active', $cols)) {
        db()->exec("ALTER TABLE admin_users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role");
    }
    if (!in_array('password_history', $cols)) {
        db()->exec("ALTER TABLE admin_users ADD COLUMN password_history TEXT DEFAULT NULL AFTER password_hash");
    }
    $bCols = db_query('SHOW COLUMNS FROM backups')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['disk_used', 'disk_free', 'disk_total', 'disk_used_pct', 'duration', 'webhook_sent'] as $col) {
        if (!in_array($col, $bCols, true)) {
            $type = in_array($col, ['webhook_sent'], true) ? "TINYINT(1) NOT NULL DEFAULT 0" : (in_array($col, ['disk_used_pct'], true) ? 'VARCHAR(10)' : (in_array($col, ['duration'], true) ? 'VARCHAR(60)' : 'VARCHAR(30)'));
            db()->exec("ALTER TABLE backups ADD COLUMN {$col} {$type}");
        }
    }
    // Ensure audit_log table exists on existing installs
    db()->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED DEFAULT NULL,
        username VARCHAR(100) DEFAULT NULL,
        action VARCHAR(50) NOT NULL,
        details VARCHAR(500) DEFAULT NULL,
        ip VARCHAR(64) DEFAULT NULL,
        user_agent VARCHAR(500) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_user (user_id),
        INDEX idx_audit_created (created_at),
        INDEX idx_audit_action (action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $auditCols = db_query('SHOW COLUMNS FROM audit_log')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('user_agent', $auditCols, true)) {
        db()->exec('ALTER TABLE audit_log ADD COLUMN user_agent VARCHAR(500) DEFAULT NULL AFTER ip');
    }
}

// Create admin user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_admin') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');
    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $error = 'Please enter a valid email address.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif ($policyErr = password_policy_error($password)) {
        $error = $policyErr;
    } else {
        $exists = db_query('SELECT id FROM admin_users WHERE username = ? OR email = ? LIMIT 1', [$username, $email])->fetch();
        if ($exists) {
            $error = 'A user with that username or email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            db_query('INSERT INTO admin_users (username, email, password_hash, password_history, role, is_active, created_at) VALUES (?, ?, ?, ?, "admin", 1, NOW())', [
                $username, $email, $hash, json_encode([$hash]),
            ]);
            // Auto-disable setup.php and go straight to login
            @rename(__FILE__, __FILE__ . '.disabled');
            redirect('login.php');
        }
    }
}

if ($configReady && $adminExists && $success !== '' && !$error) {
    $step = 4;
} elseif ($configReady && $adminExists) {
    $step = 4;
} elseif ($configReady && $tablesExist) {
    $step = 3;
} elseif ($configReady) {
    $step = 2;
} else {
    $step = 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>JBWizerd — Setup</title>
<link rel="icon" type="image/png" href="assets/favicon.png">
<link rel="stylesheet" href="assets/style.css">
<script src="assets/theme.js"></script>
</head>
<body class="setup-page">
<button type="button" class="theme-toggle theme-toggle-fixed" data-theme-toggle title="Toggle theme">☾</button>
<div class="setup-card">
    <div class="setup-logo">
        <img class="login-logo" src="assets/logo.png" alt="JBWizerd">
    </div>

    <?php if ($error): ?>
        <div class="flash flash-error"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="flash flash-success"><?= $success ?></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
        <form method="post" class="form">
            <input type="hidden" name="action" value="write_config">
            <h2>Step 1: Database Connection</h2>
            <p>Create a MySQL database and user in your hosting control panel, then enter the details below.</p>
            <label>Database Host
                <input type="text" name="db_host" value="localhost" required>
            </label>
            <label>Database Name
                <input type="text" name="db_name" placeholder="jb_panel" required>
            </label>
            <label>Database User
                <input type="text" name="db_user" placeholder="jb_user" required>
            </label>
            <label>Database Password
                <input type="password" name="db_pass" placeholder="password">
            </label>
            <hr>
            <h2>Panel Settings</h2>
            <label>Panel URL <small>(auto-detected — change if needed)</small>
                <input type="url" name="panel_url" value="<?= e(detect_panel_url()) ?>" placeholder="https://panel.example.com">
            </label>
            <button type="submit" class="btn btn-primary">Save & Continue</button>
        </form>

    <?php elseif ($step === 2): ?>
        <form method="post" class="form">
            <input type="hidden" name="action" value="install">
            <h2>Step 2: Install Database</h2>
            <p>Click below to create the database tables.</p>
            <button type="submit" class="btn btn-primary">Create Tables</button>
        </form>

    <?php elseif ($step === 3): ?>
        <form method="post" class="form">
            <input type="hidden" name="action" value="create_admin">
            <h2>Step 3: Create Admin User</h2>
            <p>Create the first admin account to log into the panel.</p>
            <label>Username
                <input type="text" name="username" value="admin" required>
            </label>
            <label>Email
                <input type="email" name="email" placeholder="admin@example.com" required>
            </label>
            <label>Password
                <input type="password" name="password" required minlength="10">
            </label>
            <label>Confirm Password
                <input type="password" name="confirm_password" required minlength="10">
            </label>
            <button type="submit" class="btn btn-primary">Create Admin & Finish</button>
        </form>

    <?php elseif ($step === 4): ?>
        <div class="setup-done">
            <h2>Installation Complete</h2>
            <p><a href="login.php" class="btn btn-primary">Go to Login</a></p>
            <hr>
            <h3>Next Steps</h3>
            <ol>
                <li>Log in and go to <strong>Servers</strong> to add servers or copy the Registration Key.</li>
                <li>On each server run:
                    <code>bash &lt;(curl -sL <?= e(panel_url()) ?>/hook/install.sh) --panel-url <?= e(panel_url()) ?> --register-key <?= e(defined('REGISTRATION_KEY') ? REGISTRATION_KEY : 'YOUR_KEY') ?></code>
                </li>
                <li>Go to <strong>Webhooks</strong> to add Slack/Discord notification URLs.</li>
                <li>Add the hooks in JetBackup manually (see README).</li>
            </ol>
            <h3>Security Checklist</h3>
            <ol>
                <li><strong>Delete setup.php</strong> from the server now.</li>
                <li>Make the config read-only: <code>chmod 444 includes/config.php</code> (set it writable temporarily only when rotating the Registration Key from Servers).</li>
                <li>Serve the panel over <strong>HTTPS only</strong> — plain HTTP requests are rejected by the panel.</li>
            </ol>
            <p><a href="?install=1" class="btn btn-sm">Re-run Schema</a></p>
        </div>
    <?php endif; ?>
</div>

<script src="assets/app.js"></script>
</body>
</html>
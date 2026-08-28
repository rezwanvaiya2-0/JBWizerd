#!/usr/bin/env php
<?php
/**
 * Emergency CLI password reset for the JBWizerd Panel.
 *
 * Usage (from the panel directory, over SSH):
 *   php cli-reset-password.php <username>
 *
 * Example:
 *   php cli-reset-password.php admin
 *
 * Runs ONLY from the command line. Refuses to run when accessed over the web.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

/**
 * Read a line from STDIN without echoing it (Unix/macOS). Falls back to a
 * plain prompt if stty is unavailable.
 */
function read_hidden(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $isWindows = stripos(PHP_OS, 'win') === 0;
    if (!$isWindows) {
        exec('stty -echo 2>/dev/null');
    }
    $value = fgets(STDIN);
    if (!$isWindows) {
        exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    }
    return $value === false ? '' : rtrim($value, "\r\n");
}

$username = trim((string)($argv[1] ?? ''));

if ($username === '') {
    fwrite(STDOUT, "Usage: php cli-reset-password.php <username>\n\nUsers:\n");
    foreach (db_query('SELECT username, role FROM admin_users ORDER BY username')->fetchAll() as $u) {
        fwrite(STDOUT, "  - {$u['username']} ({$u['role']})\n");
    }
    exit(1);
}

$user = db_query('SELECT id, username FROM admin_users WHERE username = ? LIMIT 1', [$username])->fetch();
if (!$user) {
    fwrite(STDERR, "Error: user '{$username}' not found.\n");
    exit(1);
}

$pass    = read_hidden('New password: ');
$confirm = read_hidden('Confirm password: ');

if ($pass !== $confirm) {
    fwrite(STDERR, "Error: passwords do not match.\n");
    exit(1);
}

$policyErr = password_policy_error($pass);
if ($policyErr !== '') {
    fwrite(STDERR, 'Error: ' . $policyErr . "\n");
    exit(1);
}

$hash = password_hash($pass, PASSWORD_BCRYPT);

// password_history column exists on new installs; skip it on legacy ones.
$cols = db_query('SHOW COLUMNS FROM admin_users')->fetchAll(PDO::FETCH_COLUMN);
if (in_array('password_history', $cols, true)) {
    db_query('UPDATE admin_users SET password_hash = ?, password_history = ? WHERE id = ?', [$hash, json_encode([$hash]), (int)$user['id']]);
} else {
    db_query('UPDATE admin_users SET password_hash = ? WHERE id = ?', [$hash, (int)$user['id']]);
}

fwrite(STDOUT, "Password reset successfully for '{$user['username']}'.\n");

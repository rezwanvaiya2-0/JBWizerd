<?php
/**
 * Panel UI authentication (session based) + CSRF helpers.
 * API token authentication for hooks lives in authenticate_api_server().
 */

$sessionLifetime = defined('SESSION_LIFETIME') ? (int)SESSION_LIFETIME : 7200;

// Hard session limit + hardened session cookie (HttpOnly, SameSite, Secure on HTTPS)
if (PHP_SAPI !== 'cli') {
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path'     => '/',
        'httponly' => true,
        'secure'   => is_https(),
        'samesite' => 'Lax',
    ]);

    // Enforce HTTPS for the web UI (skip API endpoints and localhost dev)
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $local = $host === 'localhost' || $host === '[::1]'
        || $host === '127.0.0.1' || strpos($host, '127.0.0.1') === 0;
    $isApi = isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/') !== false;
    if (!is_https() && !$local && !$isApi) {
        header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? ''));
        exit;
    }
}

session_name(SESSION_NAME);
session_start();

function current_user(): ?array
{
    if (!empty($_SESSION['user_id'])) {
        return [
            'id'       => (int)$_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? '',
            'email'    => $_SESSION['email'] ?? '',
            'role'     => $_SESSION['role'] ?? 'member',
        ];
    }
    return null;
}

function require_login(): void
{
    if (current_user() === null) {
        redirect('login.php');
    }
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('Access denied. Admins only.');
    }
}

function attempt_login(string $identifier, string $password): bool
{
    $stmt = db_query(
        "SELECT * FROM admin_users WHERE (username = ? OR (email IS NOT NULL AND LOWER(email) = LOWER(?))) AND is_active = 1 LIMIT 1",
        [$identifier, $identifier]
    );
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];
    return true;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    } elseif (time() - (int)($_SESSION['csrf_token_time'] ?? 0) > (defined('SESSION_LIFETIME') ? (int)SESSION_LIFETIME / 2 : 3600)) {
        // Rotate the token periodically so long-lived sessions do not reuse one forever
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), (string)$sent)) {
        http_response_code(419);
        exit('Invalid CSRF token. Please go back and try again.');
    }
}

/**
 * Authenticate an API request by bearer token (used by jb_hook.py).
 * Returns server row or null.
 */
function authenticate_api_server(): ?array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    $token = null;
    if (preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
        $token = $m[1];
    } elseif (!empty($_SERVER['HTTP_X_API_TOKEN'])) {
        $token = $_SERVER['HTTP_X_API_TOKEN'];
    }
    if ($token === null) {
        return null;
    }
    $stmt = db_query('SELECT * FROM servers WHERE token_hash = ? LIMIT 1', [hash_token($token)]);
    $server = $stmt->fetch();
    if (!$server || (int)$server['is_active'] !== 1) {
        return null;
    }
    return $server;
}
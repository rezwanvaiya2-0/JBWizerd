<?php
/**
 * Generic helper functions.
 */

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = [];
    }
    return $data;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_out(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function fmt_dt($value): string
{
    if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
        return '—';
    }
    $tz = defined('TIMEZONE') ? (string)TIMEZONE : 'Asia/Dhaka';
    try {
        $dt = new DateTime('' . $value, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($tz));
        return $dt->format('Y-m-d h:i:s A');
    } catch (Exception $e) {
        return e((string)$value);
    }
}

function fmt_dt_short($value): string
{
    if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
        return '—';
    }
    $tz = defined('TIMEZONE') ? (string)TIMEZONE : 'Asia/Dhaka';
    try {
        $dt = new DateTime('' . $value, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($tz));
        return $dt->format('M j h:i A');
    } catch (Exception $e) {
        return e((string)$value);
    }
}

/**
 * Long display format, e.g. "25 Aug 2026 02:51 PM" (converted to TIMEZONE).
 */
function fmt_dt_long($value): string
{
    if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
        return '—';
    }
    $tz = defined('TIMEZONE') ? (string)TIMEZONE : 'Asia/Dhaka';
    try {
        $dt = new DateTime('' . $value, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($tz));
        return $dt->format('d M Y h:i A');
    } catch (Exception $e) {
        return e((string)$value);
    }
}

/**
 * Human label for a backup status, matching JetBackup wording.
 */
function status_label(?string $status): string
{
    return [
        'success' => 'Completed',
        'partial' => 'Partially Completed',
        'failed'  => 'Failed',
        'aborted' => 'Aborted',
        'running' => 'Running',
        'stuck'   => 'Stuck (24h+)',
    ][$status] ?? ($status ?: '—');
}
function storage_string(array $b): string
{
    $used = $b['disk_used'] ?? '';
    $total = $b['disk_total'] ?? '';
    $free = $b['disk_free'] ?? '';
    if ($used === '' || $total === '') {
        return '';
    }
    $s = $used . ' used / ' . $total . ' total';
    if ($free !== '') {
        $s .= ' (' . $free . ' free)';
    }
    return $s;
}

function duration($start, $end): string
{
    if (!$start || !$end) {
        return '—';
    }
    $a = strtotime((string)$start);
    $b = strtotime((string)$end);
    if ($a === false || $b === false || $b < $a) {
        return '—';
    }
    $diff = $b - $a;
    $h = floor($diff / 3600);
    $m = floor(($diff % 3600) / 60);
    $s = $diff % 60;
    if ($h > 0) {
        return sprintf('%dh %02dm', $h, $m);
    }
    if ($m > 0) {
        return sprintf('%dm %02ds', $m, $s);
    }
    return sprintf('%ds', $s);
}

/**
 * Human-readable duration, e.g. "3 Hours and 44 Minutes and 48 Seconds".
 */
function duration_human($start, $end): string
{
    if (!$start || !$end) {
        return '—';
    }
    $a = strtotime((string)$start);
    $b = strtotime((string)$end);
    if ($a === false || $b === false || $b < $a) {
        return '—';
    }
    $diff = $b - $a;
    $h = floor($diff / 3600);
    $m = floor(($diff % 3600) / 60);
    $s = $diff % 60;
    $parts = [];
    if ($h > 0) {
        $parts[] = $h . ' Hour' . ($h != 1 ? 's' : '');
    }
    if ($m > 0) {
        $parts[] = $m . ' Minute' . ($m != 1 ? 's' : '');
    }
    if ($s > 0 || !$parts) {
        $parts[] = $s . ' Second' . ($s != 1 ? 's' : '');
    }
    return implode(' and ', $parts);
}

function hash_token(string $token): string
{
    return hash('sha256', $token);
}

function generate_registration_key(): string
{
    return strtoupper(implode('-', str_split(bin2hex(random_bytes(10)), 5)));
}

function generate_token(int $length = 20): string
{
    return strtoupper(implode('-', str_split(bin2hex(random_bytes(intdiv($length, 2))), 5)));
}

/**
 * Password policy: at least 10 chars with mixed case and at least one digit.
 * Returns an error message string, or '' if the password is acceptable.
 */
function password_policy_error(string $password): string
{
    if (strlen($password) < 10) {
        return 'Password must be at least 10 characters long.';
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)) {
        return 'Password must contain both uppercase and lowercase letters.';
    }
    if (!preg_match('/\d/', $password)) {
        return 'Password must contain at least one number.';
    }
    return '';
}

/**
 * Load the JSON-encoded password history for a user (array of bcrypt hashes).
 */
function password_history(int $userId): array
{
    $row = db_query('SELECT password_history FROM admin_users WHERE id = ?', [$userId])->fetch();
    $history = [];
    if ($row && is_string($row['password_history']) && $row['password_history'] !== '') {
        $decoded = json_decode($row['password_history'], true);
        if (is_array($decoded)) {
            $history = array_values(array_filter($decoded, 'is_string'));
        }
    }
    return $history;
}

/**
 * Returns true if the given plaintext password was used in a previous hash
 * stored in the user's password history.
 */
function password_was_recent(int $userId, string $password, int $lookback = 3): bool
{
    $history = password_history($userId);
    $recent = array_slice($history, 0, $lookback);
    foreach ($recent as $hash) {
        if (password_verify($password, $hash)) {
            return true;
        }
    }
    return false;
}

/**
 * Store the new hash in the password history (keeps the last 3 entries,
 * most recent first) and return the updated JSON string.
 */
function push_password_history(int $userId, string $newHash): void
{
    $history = password_history($userId);
    array_unshift($history, $newHash);
    $history = array_slice($history, 0, 3);
    db_query('UPDATE admin_users SET password_history = ? WHERE id = ?', [json_encode($history), $userId]);
}

/**
 * Detect whether the current request arrived over HTTPS.
 * Handles direct TLS, port 443, and reverse-proxy termination.
 */
function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    return false;
}

/**
 * Best-effort client IP. X-Forwarded-For is trusted ONLY when the direct
 * peer is a known reverse proxy listed in TRUSTED_PROXIES (comma separated).
 * Never trust spoofable headers from the public internet.
 */
function client_ip(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remote !== '' && defined('TRUSTED_PROXIES') && TRUSTED_PROXIES !== '') {
        $proxies = array_map('trim', explode(',', TRUSTED_PROXIES));
        if (in_array($remote, $proxies, true)) {
            $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($xff !== '') {
                $parts = array_map('trim', explode(',', $xff));
                return (string)($parts[0] ?? '');
            }
        }
    }
    return $remote;
}

/**
 * Reject API requests that arrive over plain HTTP (unless from localhost).
 * Prevents server tokens / registration keys from being sniffed in transit.
 */
function enforce_https_api(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $local = $host === 'localhost' || $host === '[::1]'
        || $host === '127.0.0.1' || strpos($host, '127.0.0.1') === 0;
    if (!is_https() && !$local) {
        json_out(['ok' => false, 'error' => 'HTTPS is required'], 400);
    }
}

/**
 * Detect the panel's own URL from the current request (scheme, host, install subfolder).
 * Used as a fallback when PANEL_URL is not yet configured.
 */
function detect_panel_url(): string
{
    $scheme = is_https() ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path = rtrim((string)dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    return rtrim($scheme . '://' . $host . $path, '/');
}

/**
 * The panel's public URL — uses the configured PANEL_URL constant, or falls back
 * to auto-detection (useful before setup completes).
 */
function panel_url(): string
{
    $url = defined('PANEL_URL') ? (string)PANEL_URL : '';
    return $url !== '' ? rtrim($url, '/') : detect_panel_url();
}

function to_mysql_datetime($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        return date('Y-m-d H:i:s', (int)$value);
    }
    $ts = strtotime((string)$value);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $ts);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function to_rfc3339($value): string
{
    // Stored times are already in the panel's TIMEZONE (see to_mysql_datetime).
    // So we format them in that zone WITHOUT shifting, so Discord shows the
    // same clock time with the correct offset.
    if ($value === null || $value === '') {
        $value = 'now';
    }
    if (is_numeric($value)) {
        $dt = new DateTime('@' . (int)$value);
        $tz = defined('TIMEZONE') ? (string)TIMEZONE : 'Asia/Dhaka';
        $dt->setTimezone(new DateTimeZone($tz));
        return $dt->format('c');
    }
    $tz = defined('TIMEZONE') ? (string)TIMEZONE : 'Asia/Dhaka';
    $dt = new DateTime((string)$value, new DateTimeZone($tz));
    if ($dt === false) {
        return date('c');
    }
    return $dt->format('c');
}

/**
 * Record the last run time + result for a cron job so the panel can show
 * whether the cron is actually running. Stored in cron/cron-status.json.
 */
function update_cron_status(string $job, string $result): void
{
    $path = __DIR__ . '/../cron/cron-status.json';
    $status = [];
    if (file_exists($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) {
            $status = $decoded;
        }
    }
    $status[$job] = ['last_run' => now(), 'result' => $result];
    @file_put_contents($path, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Write an entry to the audit log. Never breaks the main flow on failure.
 */
function audit(string $action, string $details = ''): void
{
    try {
        $u = current_user();
        $ip = client_ip();
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        db_query(
            'INSERT INTO audit_log (user_id, username, action, details, ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [
                $u ? (int)$u['id'] : null,
                $u ? $u['username'] : null,
                mb_substr($action, 0, 50),
                mb_substr($details, 0, 500),
                $ip !== '' ? mb_substr($ip, 0, 64) : null,
                $ua !== '' ? mb_substr($ua, 0, 500) : null,
            ]
        );
    } catch (Exception $e) {
        // ignore — logging must never interrupt the request
    }
}
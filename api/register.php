<?php
/**
 * API endpoint for automatic server registration.
 *
 * POST /api/register.php
 * Headers: X-Registration-Key: <registration_key>   (or POST field reg_key)
 * Body (JSON):
 *   {
 *     "name": "srv01.example.com",
 *     "ip": "203.0.113.10"
 *   }
 *
 * Returns: { "ok": true, "token": "XXXX-XXXX-XXXX-XXXX", "server_id": 1 }
 * The returned token is shown ONCE — the panel stores only its hash.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}
enforce_https_api();

// Rate-limit: max 10 registration attempts per hour per IP
$ip = client_ip();
$maxReg = defined('REGISTER_MAX_PER_HOUR') ? (int)REGISTER_MAX_PER_HOUR : 10;
if ($ip !== '') {
    $stmt = db_query(
        'SELECT COUNT(*) AS c FROM audit_log WHERE action = "register_attempt" AND ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 3600 SECOND)',
        [$ip]
    );
    if ((int)$stmt->fetch()['c'] >= $maxReg) {
        json_out(['ok' => false, 'error' => 'Too many registration attempts. Try again later.'], 429);
    }
}
db_query('INSERT INTO audit_log (action, details, ip, created_at) VALUES ("register_attempt", ?, ?, NOW())', [
    'Attempt from ' . $ip, $ip,
]);

$body = request_json();
$key = $body['reg_key'] ?? $_SERVER['HTTP_X_REGISTRATION_KEY'] ?? ($_POST['reg_key'] ?? '');

if (!is_string($key) || $key === '' || !hash_equals(REGISTRATION_KEY, $key)) {
    json_out(['ok' => false, 'error' => 'Invalid registration key'], 403);
}

$name = trim($body['name'] ?? $body['hostname'] ?? '');
if ($name === '') {
    json_out(['ok' => false, 'error' => 'name (hostname) is required'], 400);
}

$ip = trim($body['ip'] ?? '');

$stmt = db_query('SELECT id FROM servers WHERE name = ? LIMIT 1', [$name]);
$existing = $stmt->fetch();

$token = generate_token();
$hash  = hash_token($token);

if ($existing) {
    db_query(
        'UPDATE servers SET token_hash = ?, ip = ?, is_active = 1, last_seen_at = NOW() WHERE id = ?',
        [$hash, $ip ?: null, (int)$existing['id']]
    );
    $serverId = (int)$existing['id'];
} else {
    db_query(
        'INSERT INTO servers (name, token_hash, ip, notes, is_active, last_seen_at, created_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())',
        [$name, $hash, $ip ?: null, 'Registered automatically']
    );
    $serverId = (int)db()->lastInsertId();
}

json_out(['ok' => true, 'server_id' => $serverId, 'token' => $token]);
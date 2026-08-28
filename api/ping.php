<?php
/**
 * API endpoint: test server connectivity with a valid token.
 *
 * POST /api/ping.php
 * Headers: Authorization: Bearer <server_token>
 *
 * Returns: { "ok": true, "server": "srv01.example.com" }
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}
enforce_https_api();

$server = authenticate_api_server();
if ($server === null) {
    json_out(['ok' => false, 'error' => 'Unauthorized'], 401);
}

db_query('UPDATE servers SET last_seen_at = NOW() WHERE id = ?', [(int)$server['id']]);

json_out(['ok' => true, 'server' => $server['name']]);
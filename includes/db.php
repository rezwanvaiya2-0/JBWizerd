<?php
/**
 * Database helper - returns a shared PDO connection.
 */

if (!defined('DB_HOST')) {
    exit('config.php is missing or not configured yet. Run setup.php first.');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Store/compare all timestamps in UTC so they match PHP's date() output.
            // Display timezone is applied separately (see TIMEZONE in config.php).
            $pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            http_response_code(500);
            exit('Database connection failed: ' . htmlspecialchars($e->getMessage()));
        }
    }
    return $pdo;
}

function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

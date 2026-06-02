<?php
// Database configuration (PDO)
// Update the constants below with your DB credentials.
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'mobile_mechanic');
define('DB_USER', 'root');
define('DB_PASS', '');

function getPDO()
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
        } catch (PDOException $e) {
            // In production, log this instead of echoing
            echo 'Database connection failed: ' . $e->getMessage();
            exit;
        }
    }
    return $pdo;
}

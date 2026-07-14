<?php
/*// Database configuration (PDO)
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
}*/ 
?>




<?php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'mobile_mechanic');
define('DB_USER', 'root');
define('DB_PASS', '');

function getPDO()
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn  = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            echo 'Database connection failed. Please try again later.';
            exit;
        }
    }
    return $pdo;
}

// ── One-time seed password fix ────────────────────────────────────────────────
// Runs once, creates reset.flag, never runs again.
// Delete reset.flag from your project root to re-trigger.
$_resetFlag = __DIR__ . '/../reset.flag';
if (!file_exists($_resetFlag)) {
    try {
        $pdo = getPDO();

        $hash_password123 = password_hash('password123', PASSWORD_BCRYPT);
        $hash_123456      = password_hash('123456',      PASSWORD_BCRYPT);

        $pdo->prepare("UPDATE users SET password = ? WHERE email IN (
            'admin@mobilemechanic.com','ahmed@example.com','faadumo@example.com',
            'mohamed@example.com','sahra@example.com','omar@example.com'
        )")->execute([$hash_password123]);

        $pdo->prepare("UPDATE users SET password = ? WHERE email IN (
            'warsama@gmail.com','admin@gmail.com','super@gmail.com'
        )")->execute([$hash_123456]);

        $pdo->prepare("UPDATE mechanics SET password = ? WHERE email IN (
            'khalid@mechanic.com','abdifatah@mechanic.com','farah@mechanic.com',
            'leyla@mechanic.com','hassan@mechanic.com'
        )")->execute([$hash_password123]);

        file_put_contents($_resetFlag, date('Y-m-d H:i:s'));
    } catch (Exception $e) {
        error_log('Seed password reset failed: ' . $e->getMessage());
    }
}

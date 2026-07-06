<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (!empty($_SESSION['user_id'])) {
    try {
        $pdo  = getPDO();
        $stmt = $pdo->prepare('UPDATE users SET last_logout = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
    } catch (Exception $e) {
        error_log($e->getMessage());
    }
}

session_unset();
session_destroy();
header('Location: ' . getBasePath() . 'super_admin/login.php');
exit;
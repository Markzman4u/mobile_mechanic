<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

// Admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

try {
    $pdo  = getPDO();
    $rows = $pdo->query(
        'SELECT id, status, current_lat, current_lng, location_updated_at,
                CASE
                    WHEN location_updated_at IS NULL THEN NULL
                    ELSE TIMESTAMPDIFF(SECOND, location_updated_at, NOW())
                END AS loc_age
         FROM mechanics
         WHERE is_deleted = FALSE'
    )->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($rows);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([]);
}
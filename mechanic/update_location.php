<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

// Must be a logged-in mechanic
if (!isset($_SESSION['mechanic_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$lat = isset($_POST['lat']) ? (float) $_POST['lat'] : null;
$lng = isset($_POST['lng']) ? (float) $_POST['lng'] : null;

if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid coordinates']);
    exit;
}

try {
    $pdo = getPDO();
    $pdo->prepare(
        'UPDATE mechanics
         SET current_lat = :lat, current_lng = :lng, location_updated_at = NOW()
         WHERE id = :id AND is_deleted = FALSE AND is_disabled = FALSE'
    )->execute([
        ':lat' => $lat,
        ':lng' => $lng,
        ':id'  => (int) $_SESSION['mechanic_id'],
    ]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    error_log($e->getMessage());
}
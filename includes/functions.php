<?php
// Common helper functions

// Ensure database connection is available
if (!function_exists('getPDO')) {
    require_once __DIR__ . '/../config/db.php';
}

function e($str)
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function statusLabel($status)
{
    $map = [
        'pending' => 'Pending',
        'assigned' => 'Assigned',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'rejected' => 'Rejected',
    ];
    return $map[$status] ?? $status;
}

function getBasePath()
{
    static $basePath = null;
    if ($basePath !== null) {
        return $basePath;
    }

    if (empty($_SERVER['DOCUMENT_ROOT'])) {
        $basePath = '/';
        return $basePath;
    }

    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT']);
    $appRoot = realpath(__DIR__ . '/../');

    if ($documentRoot !== false && $appRoot !== false && strpos($appRoot, $documentRoot) === 0) {
        $basePath = str_replace('\\', '/', substr($appRoot, strlen($documentRoot)));
        if ($basePath === '') {
            $basePath = '/';
        } elseif ($basePath[0] !== '/') {
            $basePath = '/' . $basePath;
        }
        if (substr($basePath, -1) !== '/') {
            $basePath .= '/';
        }
    } else {
        $basePath = '/';
    }

    return $basePath;
}

function createNotification($user_id, $type, $title, $message, $request_id = null)
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('
        INSERT INTO notifications (user_id, type, title, message, request_id)
        VALUES (:user_id, :type, :title, :message, :request_id)
    ');
    return $stmt->execute([
        ':user_id' => $user_id,
        ':type' => $type,
        ':title' => $title,
        ':message' => $message,
        ':request_id' => $request_id
    ]);
}

function getUnreadCount($user_id)
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT COUNT(*) as count FROM notifications WHERE user_id = :user_id AND is_read = FALSE');
    $stmt->execute([':user_id' => $user_id]);
    $result = $stmt->fetch();
    return $result['count'] ?? 0;
}

<?php
// Common helper functions

// Ensure database connection is available
if (!function_exists('getPDO')) {
    require_once __DIR__ . '/../config/db.php';
}

if (!function_exists('e')) {
    function e($str)
    {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('redirect')) {
    function redirect($url)
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('statusLabel')) {
    function statusLabel($status)
    {
        $map = [
            'pending'     => 'Pending',
            'assigned'    => 'Assigned',
            'in_progress' => 'In Progress',
            'completed'   => 'Completed',
            'rejected'    => 'Rejected',
            'cancelled'   => 'Cancelled',
        ];
        return $map[$status] ?? $status;
    }
}

if (!function_exists('getBasePath')) {
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
        $appRoot      = realpath(__DIR__ . '/../');

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
}

if (!function_exists('createNotification')) {
    function createNotification($user_id, $type, $title, $message, $request_id = null)
    {
        $pdo  = getPDO();
        $stmt = $pdo->prepare('
            INSERT INTO notifications (user_id, type, title, message, request_id)
            VALUES (:user_id, :type, :title, :message, :request_id)
        ');
        return $stmt->execute([
            ':user_id'    => $user_id,
            ':type'       => $type,
            ':title'      => $title,
            ':message'    => $message,
            ':request_id' => $request_id,
        ]);
    }
}

if (!function_exists('getUnreadCount')) {
    function getUnreadCount($user_id)
    {
        $pdo  = getPDO();
        $stmt = $pdo->prepare('
            SELECT COUNT(*) AS count
            FROM notifications
            WHERE user_id = :user_id AND is_read = FALSE
        ');
        $stmt->execute([':user_id' => $user_id]);
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    }
}
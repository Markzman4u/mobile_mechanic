<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pdo = getPDO();
$userId = $_SESSION['user_id'];
$notificationId = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int) $_GET['id'] : null;
$notification = null;
$error = '';

if (!$notificationId) {
    $error = 'Notification not found.';
} else {
    // Get the notification and verify ownership
    $stmt = $pdo->prepare('
        SELECT n.id, n.type, n.title, n.message, n.request_id, n.is_read, n.created_at
        FROM notifications n
        WHERE n.id = :id AND n.user_id = :user_id
    ');
    $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
    $notification = $stmt->fetch();
    
    if (!$notification) {
        $error = 'Notification not found or you do not have permission to view it.';
    } else {
        // If it's an invoice notification, redirect to invoice page
        if ($notification['type'] === 'invoice') {
            header('Location: ' . getBasePath() . 'customer/invoice.php?id=' . (int)$notification['request_id']);
            exit;
        }
        
        // Mark as read
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = TRUE WHERE id = :id');
        $stmt->execute([':id' => $notificationId]);
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<main>
    <div class="card">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
            <a href="<?php echo getBasePath(); ?>customer/notifications.php" 
               style="text-decoration:none;color:#666;font-size:1.5rem;padding:4px;display:inline-block;
                      border-radius:4px;transition:all 0.2s ease;line-height:1;">
                ←
            </a>
            <h2 style="margin:0;">Notification Details</h2>
        </div>

        <?php if ($error): ?>
            <div style="background:#fff4f4;border:1px solid #f5c6cb;color:#a94442;padding:16px;border-radius:6px;">
                <?php echo e($error); ?>
            </div>
        <?php elseif ($notification): ?>
            <!-- Notification Header -->
            <div style="background:#f9f9f9;padding:16px;border-radius:8px;margin-bottom:20px;border-left:4px solid #ff9800;">
                <h3 style="margin:0 0 8px 0;font-size:1.2rem;">
                    <?php echo e($notification['title']); ?>
                </h3>
                <p style="margin:0;color:var(--muted);font-size:0.95rem;">
                    <?php 
                    $createdAt = new DateTime($notification['created_at']);
                    echo $createdAt->format('F d, Y \a\t g:i A');
                    ?>
                </p>
            </div>

            <!-- Rejection Type -->
            <?php if ($notification['type'] === 'rejection'): ?>
                <?php 
                // Get rejection reason from requests table
                $stmt = $pdo->prepare('
                    SELECT id, status, rejection_reason, problem_type, created_at
                    FROM requests
                    WHERE id = :id
                ');
                $stmt->execute([':id' => $notification['request_id']]);
                $request = $stmt->fetch();
                ?>
                
                <div style="background:#ffebee;border:1px solid #ef5350;border-radius:8px;padding:16px;margin-bottom:16px;">
                    <h4 style="margin:0 0 12px 0;color:#c62828;display:flex;align-items:center;gap:8px;">
                        ⚠️ Request Rejected
                    </h4>
                    
                    <?php if ($request): ?>
                        <div style="background:white;padding:12px;border-radius:6px;margin-bottom:12px;">
                            <p style="margin:0 0 8px 0;">
                                <strong>Request ID:</strong> #<?php echo e($request['id']); ?>
                            </p>
                            <p style="margin:0 0 8px 0;">
                                <strong>Problem Type:</strong> <?php echo e($request['problem_type']); ?>
                            </p>
                            <p style="margin:0;">
                                <strong>Submitted:</strong> <?php echo date('M d, Y H:i', strtotime($request['created_at'])); ?>
                            </p>
                        </div>
                    <?php endif; ?>
                    
                    <div style="background:white;padding:12px;border-radius:6px;border-left:4px solid #ef5350;">
                        <p style="margin:0 0 8px 0;color:#666;font-size:0.9rem;">Rejection Reason:</p>
                        <p style="margin:0;color:#333;font-size:1rem;line-height:1.6;">
                            <?php echo e($request['rejection_reason'] ?? 'No reason provided'); ?>
                        </p>
                    </div>
                </div>

            <!-- Default Type -->
            <?php else: ?>
                <div style="background:#f9f9f9;padding:16px;border-radius:8px;">
                    <p style="margin:0;color:#333;line-height:1.6;">
                        <?php echo e($notification['message']); ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Back Button -->
            <div style="margin-top:24px;text-align:center;">
                <a href="<?php echo getBasePath(); ?>customer/notifications.php"
                   style="display:inline-block;padding:10px 20px;background:#f5f5f5;border:1px solid #ddd;
                          border-radius:4px;text-decoration:none;color:#333;transition:all 0.2s ease;">
                    ← Back to Notifications
                </a>
            </div>

        <?php endif; ?>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

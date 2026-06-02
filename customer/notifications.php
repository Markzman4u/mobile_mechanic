<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pdo = getPDO();
$userId = $_SESSION['user_id'];

// Handle delete notifications
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_selected' && isset($_POST['notification_ids'])) {
        $ids = array_filter(array_map('intval', $_POST['notification_ids']));
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE id IN ($placeholders) AND user_id = ?");
            $params = array_merge($ids, [$userId]);
            $stmt->execute($params);
        }
    } elseif ($_POST['action'] === 'delete_all') {
        $stmt = $pdo->prepare('DELETE FROM notifications WHERE user_id = ?');
        $stmt->execute([$userId]);
    }
    header('Location: ' . getBasePath() . 'customer/notifications.php');
    exit;
}

// Get all notifications for this user
$stmt = $pdo->prepare('
    SELECT id, type, title, message, request_id, is_read, created_at
    FROM notifications
    WHERE user_id = :user_id
    ORDER BY created_at DESC
');
$stmt->execute([':user_id' => $userId]);
$notifications = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<main>
    <div class="card">
        <h2>Notifications</h2>
        <p class="muted">Your service notifications and updates</p>

        <?php if (empty($notifications)): ?>
            <div style="text-align:center;padding:40px;color:var(--muted);">
                <p style="font-size:1.1rem;margin-bottom:8px;">📭 No notifications yet</p>
                <p>You'll see updates about your requests here.</p>
            </div>
        <?php else: ?>
            <form method="POST" id="notificationForm" style="margin-bottom:20px;">
                <!-- Clear Buttons -->
                <div style="display:flex;gap:8px;margin-bottom:16px;align-items:center;">
                    <button type="submit" name="action" value="delete_selected" 
                            onclick="return confirm('Delete selected notifications?')"
                            style="padding:8px 16px;background:#ff9800;color:white;border:none;border-radius:4px;cursor:pointer;transition:all 0.2s ease;"
                            onmouseover="this.style.background='#f57c00'"
                            onmouseout="this.style.background='#ff9800'">
                        🗑️ Delete Selected
                    </button>
                    <button type="submit" name="action" value="delete_all" 
                            onclick="return confirm('Delete all notifications? This cannot be undone.')"
                            style="padding:8px 16px;background:#d32f2f;color:white;border:none;border-radius:4px;cursor:pointer;transition:all 0.2s ease;"
                            onmouseover="this.style.background='#c62828'"
                            onmouseout="this.style.background='#d32f2f'">
                        🗑️ Clear All
                    </button>
                    <label style="margin-left:auto;display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" id="selectAllCheckbox" style="width:18px;height:18px;cursor:pointer;">
                        <span>Select All</span>
                    </label>
                </div>

                <div style="display:flex;flex-direction:column;gap:12px;">
                    <?php foreach ($notifications as $notif): ?>
                        <div style="display:flex;align-items:flex-start;gap:12px;border:2px solid <?php echo ($notif['is_read'] ? '#f0f0f0' : '#ff9800'); ?>;
                                  border-radius:8px;padding:16px;background:white;
                                  transition:all 0.2s ease;
                                  <?php if (!$notif['is_read']): ?>
                                      box-shadow:0 2px 8px rgba(255, 152, 0, 0.15);
                                  <?php endif; ?>">
                            
                            <!-- Checkbox -->
                            <input type="checkbox" name="notification_ids[]" value="<?php echo (int)$notif['id']; ?>" 
                                   class="notification-checkbox" style="width:18px;height:18px;margin-top:6px;cursor:pointer;">
                            
                            <!-- Unread Indicator -->
                            <?php if (!$notif['is_read']): ?>
                                <div style="width:12px;height:12px;background:#ff9800;border-radius:50%;margin-top:6px;flex-shrink:0;"></div>
                            <?php else: ?>
                                <div style="width:12px;height:12px;background:#e0e0e0;border-radius:50%;margin-top:6px;flex-shrink:0;"></div>
                            <?php endif; ?>
                            
                            <!-- Notification Content (Link for non-invoice) -->
                            <?php if ($notif['type'] === 'invoice'): ?>
                                <!-- Invoice: Link to invoice page -->
                                <a href="<?php echo getBasePath(); ?>customer/invoice.php?id=<?php echo (int)$notif['request_id']; ?>"
                                   style="text-decoration:none;color:inherit;flex:1;display:block;cursor:pointer;
                                          transition:all 0.2s ease;"
                                   onmouseover="this.style.opacity='0.8'"
                                   onmouseout="this.style.opacity='1'">
                            <?php else: ?>
                                <!-- Other types: Link to view notification details -->
                                <a href="<?php echo getBasePath(); ?>customer/view_notification.php?id=<?php echo (int)$notif['id']; ?>"
                                   style="text-decoration:none;color:inherit;flex:1;display:block;cursor:pointer;
                                          transition:all 0.2s ease;"
                                   onmouseover="this.style.opacity='0.8'"
                                   onmouseout="this.style.opacity='1'">
                            <?php endif; ?>
                                    <div>
                                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                                            <h3 style="margin:0;font-size:1rem;<?php echo (!$notif['is_read'] ? 'font-weight:bold;' : ''); ?>">
                                                <?php echo e($notif['title']); ?>
                                            </h3>
                                            <?php if ($notif['type'] === 'rejection'): ?>
                                                <span style="background:#ffebee;color:#c62828;padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:bold;">
                                                    REJECTED
                                                </span>
                                            <?php elseif ($notif['type'] === 'invoice'): ?>
                                                <span style="background:#e3f2fd;color:#1565c0;padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:bold;">
                                                    INVOICE
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <p style="margin:0;color:var(--muted);font-size:0.95rem;<?php echo (!$notif['is_read'] ? 'color:#333;' : ''); ?>">
                                            <?php echo e($notif['message']); ?>
                                        </p>
                                        
                                        <div style="margin-top:8px;display:flex;justify-content:space-between;align-items:center;">
                                            <small style="color:#999;font-size:0.85rem;">
                                                <?php 
                                                $createdAt = new DateTime($notif['created_at']);
                                                $now = new DateTime();
                                                $diff = $now->diff($createdAt);
                                                
                                                if ($diff->days > 7) {
                                                    echo $createdAt->format('M d, Y');
                                                } elseif ($diff->days > 0) {
                                                    echo $diff->days . ' day' . ($diff->days > 1 ? 's' : '') . ' ago';
                                                } elseif ($diff->h > 0) {
                                                    echo $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
                                                } elseif ($diff->i > 0) {
                                                    echo $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
                                                } else {
                                                    echo 'Just now';
                                                }
                                                ?>
                                            </small>
                                            <span style="color:#ff9800;font-size:0.9rem;">→</span>
                                        </div>
                                    </div>
                                </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </form>

            <script>
                // Select All functionality
                const selectAllCheckbox = document.getElementById('selectAllCheckbox');
                const notificationCheckboxes = document.querySelectorAll('.notification-checkbox');
                
                selectAllCheckbox.addEventListener('change', function() {
                    notificationCheckboxes.forEach(checkbox => {
                        checkbox.checked = selectAllCheckbox.checked;
                    });
                });
                
                // Update "Select All" when individual checkboxes change
                notificationCheckboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', function() {
                        const allChecked = Array.from(notificationCheckboxes).every(cb => cb.checked);
                        const anyChecked = Array.from(notificationCheckboxes).some(cb => cb.checked);
                        selectAllCheckbox.checked = allChecked;
                        selectAllCheckbox.indeterminate = anyChecked && !allChecked;
                    });
                });
            </script>
        <?php endif; ?>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

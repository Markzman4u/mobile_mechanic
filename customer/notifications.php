<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pdo    = getPDO();
$userId = $_SESSION['user_id'];

// ── Handle: Delete Selected / Delete All ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_selected' && isset($_POST['notification_ids'])) {
        $ids = array_filter(array_map('intval', $_POST['notification_ids']));
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM notifications WHERE id IN ($placeholders) AND user_id = ?")
                ->execute([...array_values($ids), $userId]);
        }
    } elseif ($_POST['action'] === 'delete_all') {
        $pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$userId]);
    }
    header('Location: ' . getBasePath() . 'customer/notifications.php');
    exit;
}

// ── Load notifications + resolve history_id for invoice links ─────────────────
$stmt = $pdo->prepare('
    SELECT n.id,
           n.type,
           n.title,
           n.message,
           n.request_id,
           n.is_read,
           n.created_at,
           hr.id AS history_id
    FROM notifications n
    LEFT JOIN history_records hr ON hr.request_id = n.request_id
                                 AND hr.status = "completed"
    WHERE n.user_id = :user_id
    ORDER BY n.created_at DESC
');
$stmt->execute([':user_id' => $userId]);
$notifications = $stmt->fetchAll();

// ── Mark all unread as read now that the user has seen them ───────────────────
// Fetch happens first so unread indicators still show on this page load.
// On next visit everything will appear as read.
$pdo->prepare('UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE')
    ->execute([$userId]);

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

                <!-- ── Action Buttons ────────────────────────────────────── -->
                <div style="display:flex;gap:8px;margin-bottom:16px;align-items:center;">
                    <button type="submit" name="action" value="delete_selected"
                            onclick="return confirm('Delete selected notifications?')"
                            style="padding:8px 16px;background:#ff9800;color:white;border:none;
                                   border-radius:4px;cursor:pointer;transition:all 0.2s ease;"
                            onmouseover="this.style.background='#f57c00'"
                            onmouseout="this.style.background='#ff9800'">
                        🗑️ Delete Selected
                    </button>
                    <button type="submit" name="action" value="delete_all"
                            onclick="return confirm('Delete all notifications? This cannot be undone.')"
                            style="padding:8px 16px;background:#d32f2f;color:white;border:none;
                                   border-radius:4px;cursor:pointer;transition:all 0.2s ease;"
                            onmouseover="this.style.background='#c62828'"
                            onmouseout="this.style.background='#d32f2f'">
                        🗑️ Clear All
                    </button>
                    <label style="margin-left:auto;display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" id="selectAllCheckbox" style="width:18px;height:18px;cursor:pointer;">
                        <span>Select All</span>
                    </label>
                </div>

                <!-- ── Notification Cards ────────────────────────────────── -->
                <div style="display:flex;flex-direction:column;gap:12px;">
                    <?php foreach ($notifications as $notif): ?>
                        <div style="display:flex;align-items:flex-start;gap:12px;
                                    border:2px solid <?php echo $notif['is_read'] ? '#f0f0f0' : '#ff9800'; ?>;
                                    border-radius:8px;padding:16px;background:white;
                                    transition:all 0.2s ease;
                                    <?php echo !$notif['is_read'] ? 'box-shadow:0 2px 8px rgba(255,152,0,0.15);' : ''; ?>">

                            <!-- Checkbox -->
                            <input type="checkbox" name="notification_ids[]"
                                   value="<?php echo (int)$notif['id']; ?>"
                                   class="notification-checkbox"
                                   style="width:18px;height:18px;margin-top:6px;cursor:pointer;">

                            <!-- Read/Unread dot -->
                            <div style="width:12px;height:12px;
                                        background:<?php echo $notif['is_read'] ? '#e0e0e0' : '#ff9800'; ?>;
                                        border-radius:50%;margin-top:6px;flex-shrink:0;"></div>

                            <!-- Content -->
                            <?php
                                if ($notif['type'] === 'invoice' && $notif['history_id']) {
                                    $link = getBasePath() . 'customer/invoice.php?history_id=' . (int)$notif['history_id'];
                                } else {
                                    $link = getBasePath() . 'customer/view_notification.php?id=' . (int)$notif['id'];
                                }
                            ?>
                            <a href="<?php echo $link; ?>"
                               style="text-decoration:none;color:inherit;flex:1;display:block;cursor:pointer;
                                      transition:all 0.2s ease;"
                               onmouseover="this.style.opacity='0.8'"
                               onmouseout="this.style.opacity='1'">

                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                                    <h3 style="margin:0;font-size:1rem;
                                               <?php echo !$notif['is_read'] ? 'font-weight:bold;' : ''; ?>">
                                        <?php echo e($notif['title']); ?>
                                    </h3>

                                    <?php if ($notif['type'] === 'rejection'): ?>
                                        <span style="background:#ffebee;color:#c62828;padding:2px 8px;
                                                     border-radius:4px;font-size:0.75rem;font-weight:bold;">
                                            REJECTED
                                        </span>
                                    <?php elseif ($notif['type'] === 'invoice'): ?>
                                        <span style="background:#e3f2fd;color:#1565c0;padding:2px 8px;
                                                     border-radius:4px;font-size:0.75rem;font-weight:bold;">
                                            INVOICE
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <p style="margin:0;font-size:0.95rem;
                                          color:<?php echo $notif['is_read'] ? 'var(--muted)' : '#333'; ?>;">
                                    <?php echo e($notif['message']); ?>
                                </p>

                                <?php if ($notif['type'] === 'invoice' && !$notif['history_id']): ?>
                                    <p style="margin:6px 0 0 0;font-size:0.8rem;color:#a94442;">
                                        ⚠ Invoice not yet available.
                                    </p>
                                <?php endif; ?>

                                <div style="margin-top:8px;display:flex;
                                            justify-content:space-between;align-items:center;">
                                    <small style="color:#999;font-size:0.85rem;">
                                        <?php
                                            $createdAt = new DateTime($notif['created_at']);
                                            $now       = new DateTime();
                                            $diff      = $now->diff($createdAt);

                                            if ($diff->days > 7)     echo $createdAt->format('M d, Y');
                                            elseif ($diff->days > 0) echo $diff->days . ' day'    . ($diff->days > 1 ? 's' : '') . ' ago';
                                            elseif ($diff->h > 0)    echo $diff->h    . ' hour'   . ($diff->h    > 1 ? 's' : '') . ' ago';
                                            elseif ($diff->i > 0)    echo $diff->i    . ' minute' . ($diff->i    > 1 ? 's' : '') . ' ago';
                                            else                     echo 'Just now';
                                        ?>
                                    </small>
                                    <span style="color:#ff9800;font-size:0.9rem;">→</span>
                                </div>

                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </form>

            <script>
                const selectAllCheckbox      = document.getElementById('selectAllCheckbox');
                const notificationCheckboxes = document.querySelectorAll('.notification-checkbox');

                selectAllCheckbox.addEventListener('change', function () {
                    notificationCheckboxes.forEach(cb => cb.checked = this.checked);
                });

                notificationCheckboxes.forEach(cb => {
                    cb.addEventListener('change', function () {
                        const all = Array.from(notificationCheckboxes);
                        selectAllCheckbox.checked       = all.every(c => c.checked);
                        selectAllCheckbox.indeterminate = all.some(c => c.checked) && !all.every(c => c.checked);
                    });
                });
            </script>
        <?php endif; ?>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
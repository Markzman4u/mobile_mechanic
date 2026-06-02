<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (!isAdmin()) {
    header('Location: ' . getBasePath() . 'customer/dashboard.php');
    exit;
}

$pdo = getPDO();
$message = '';

if (isset($_GET['delete']) && ctype_digit($_GET['delete'])) {
    $deleteId = (int) $_GET['delete'];
    $stmt = $pdo->prepare('DELETE FROM service_items WHERE id = :id');
    $stmt->execute([':id' => $deleteId]);
    header('Location: ' . getBasePath() . 'admin/manage_service_items.php?deleted=1');
    exit;
}

if (isset($_GET['deleted'])) {
    $message = 'Service item deleted successfully.';
}

// Query all service items linked to actual requests (through services)
$items = $pdo->query(
    'SELECT si.id, si.item_name, si.price, s.service_name, r.id AS request_id '
    . 'FROM service_items si '
    . 'JOIN services s ON si.service_id = s.id '
    . 'JOIN requests r ON s.request_id = r.id '
    . 'ORDER BY si.id DESC'
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <div class="card">
        <h2>Service Items</h2>
        <p class="muted">View and manage service items from completed requests.</p>

        <?php if ($message): ?>
            <div style="background:#e8f7e9;border:1px solid #8bc34a;color:#2f6627;padding:12px;border-radius:6px;margin-bottom:16px;">
                <?php echo e($message); ?>
            </div>
        <?php endif; ?>

        <h3>Service Items List</h3>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Item Name</th>
                    <th>Price</th>
                    <th>Service</th>
                    <th>Request</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;color:var(--muted);padding:20px;">No service items found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo e($item['id']); ?></td>
                            <td><?php echo e($item['item_name']); ?></td>
                            <td>$<?php echo number_format((float)$item['price'], 2); ?></td>
                            <td><?php echo e($item['service_name']); ?></td>
                            <td>#<?php echo e($item['request_id']); ?></td>
                            <td>
                                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?delete=<?php echo e($item['id']); ?>" onclick="return confirm('Delete this service item?');" style="color:#d9534f;text-decoration:none;">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php';

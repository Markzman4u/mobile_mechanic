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
$error = '';
$editingMechanic = null;

// Handle edit mode
if (isset($_GET['edit']) && ctype_digit($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare('SELECT id, name, phone, status FROM mechanics WHERE id = :id');
    $stmt->execute([':id' => $editId]);
    $editingMechanic = $stmt->fetch();
    if (!$editingMechanic) {
        header('Location: ' . getBasePath() . 'admin/manage_mechanics.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $mechanic_id = isset($_POST['mechanic_id']) ? (int) $_POST['mechanic_id'] : null;

    if ($name === '') {
        $error = 'Mechanic name is required.';
    } else {
        if ($mechanic_id) {
            // Update existing mechanic
            $stmt = $pdo->prepare('UPDATE mechanics SET name = :name, phone = :phone WHERE id = :id');
            $stmt->execute([':name' => $name, ':phone' => $phone, ':id' => $mechanic_id]);
            $message = 'Mechanic updated successfully.';
            header('Location: ' . getBasePath() . 'admin/manage_mechanics.php');
            exit;
        } else {
            // Add new mechanic
            $stmt = $pdo->prepare('INSERT INTO mechanics (name, phone, status) VALUES (:name, :phone, :status)');
            $stmt->execute([':name' => $name, ':phone' => $phone, ':status' => 'available']);
            $message = 'Mechanic added successfully.';
        }
    }
}

if (isset($_GET['delete']) && ctype_digit($_GET['delete'])) {
    $deleteId = (int) $_GET['delete'];
    $stmt = $pdo->prepare('DELETE FROM mechanics WHERE id = :id');
    $stmt->execute([':id' => $deleteId]);
    header('Location: ' . getBasePath() . 'admin/manage_mechanics.php?deleted=1');
    exit;
}

if (isset($_GET['deleted'])) {
    $message = 'Mechanic deleted successfully.';
}

$mechanics = $pdo->query('SELECT id, name, phone, status FROM mechanics ORDER BY name ASC')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <div class="card">
        <h2>Manage Mechanics</h2>
        <p class="muted">Add, edit, or remove mechanic records.</p>
        
        <?php if ($message): ?>
            <div style="background:#e8f7e9;border:1px solid #8bc34a;color:#2f6627;padding:12px;border-radius:6px;margin-bottom:16px;">
                <?php echo e($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div style="background:#fff4f4;border:1px solid #f5c6cb;color:#a94442;padding:12px;border-radius:6px;margin-bottom:16px;">
                <?php echo e($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="post" style="background:#f9f9f9;padding:16px;border-radius:8px;margin-bottom:20px;">
            <h3><?php echo $editingMechanic ? 'Edit Mechanic' : 'Add New Mechanic'; ?></h3>
            <?php if ($editingMechanic): ?>
                <input type="hidden" name="mechanic_id" value="<?php echo $editingMechanic['id']; ?>">
            <?php endif; ?>
            <label>Name <input type="text" name="name" placeholder="Enter mechanic name" value="<?php echo $editingMechanic ? e($editingMechanic['name']) : ''; ?>" required></label>
            <label>Phone <input type="text" name="phone" placeholder="Enter phone number" value="<?php echo $editingMechanic ? e($editingMechanic['phone']) : ''; ?>"></label>
            <button class="btn btn-primary" type="submit"><?php echo $editingMechanic ? 'Update Mechanic' : 'Add Mechanic'; ?></button>
            <?php if ($editingMechanic): ?>
                <a href="<?php echo getBasePath(); ?>admin/manage_mechanics.php" class="btn" style="margin-left:8px;">Cancel</a>
            <?php endif; ?>
        </form>
        
        <h3>Current Mechanics</h3>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($mechanics)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center;color:var(--muted);padding:20px;">No mechanics found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($mechanics as $mechanic): ?>
                        <tr>
                            <td><?php echo e($mechanic['id']); ?></td>
                            <td><?php echo e($mechanic['name']); ?></td>
                            <td><?php echo e($mechanic['phone']); ?></td>
                            <td><span class="status status-<?php echo $mechanic['status'] === 'available' ? 'assigned' : 'pending'; ?>"><?php echo ucfirst($mechanic['status']); ?></span></td>
                            <td>
                                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?edit=<?php echo e($mechanic['id']); ?>" style="color:var(--safety-orange);text-decoration:none;">Edit</a>
                                | 
                                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?delete=<?php echo e($mechanic['id']); ?>" onclick="return confirm('Delete this mechanic?');" style="color:#d9534f;text-decoration:none;">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php';

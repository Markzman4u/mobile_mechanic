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

$mechanics = $pdo->query('SELECT id, name FROM mechanics WHERE status = "available" ORDER BY name ASC')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name    = trim($_POST['full_name'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $address      = trim($_POST['address'] ?? '');
    $problem_type = trim($_POST['problem_type'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $mechanic_id  = (int) ($_POST['mechanic_id'] ?? 0);

    if ($full_name === '' || $problem_type === '') {
        $error = 'Full name and problem type are required.';
    } elseif ($mechanic_id <= 0) {
        $error = 'Please assign an available mechanic.';
    } else {
        try {
            // Create walk-in customer record
            $stmt = $pdo->prepare('INSERT INTO walkin_customers (full_name, phone, email, address) VALUES (:full_name, :phone, :email, :address)');
            $stmt->execute([':full_name' => $full_name, ':phone' => $phone, ':email' => $email, ':address' => $address]);
            $walkinId = $pdo->lastInsertId();

            // Create service request — directly assigned with mechanic
            $stmt = $pdo->prepare(
                'INSERT INTO requests (walkin_id, mechanic_id, problem_type, description, status, created_at)
                 VALUES (:walkin_id, :mechanic_id, :problem_type, :description, :status, NOW())'
            );
            $stmt->execute([
                ':walkin_id'    => $walkinId,
                ':mechanic_id'  => $mechanic_id,
                ':problem_type' => $problem_type,
                ':description'  => $description,
                ':status'       => 'assigned',
            ]);

            // Mark mechanic as busy
            $pdo->prepare('UPDATE mechanics SET status = "busy" WHERE id = :id')
                ->execute([':id' => $mechanic_id]);

            // Redirect to active jobs after successful creation
            header('Location: ' . getBasePath() . 'admin/active_jobs.php');
            exit;

        } catch (Exception $e) {
            $error = 'Error creating walk-in request: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <div class="card">
        <h2>Register Walk-in Customer</h2>
        <p class="muted">Creates a service request and immediately assigns a mechanic — no pending step needed.</p>

        <?php if ($error): ?>
            <div style="background:#fff4f4;border:1px solid #f5c6cb;color:#a94442;padding:12px;border-radius:6px;margin-bottom:16px;">
                <?php echo e($error); ?>
            </div>
        <?php endif; ?>

        <form method="post" style="background:#f9f9f9;padding:16px;border-radius:8px;">
            <h3>Customer Information</h3>
            <label>Full Name <input type="text" name="full_name" placeholder="Enter customer name" value="<?php echo e($full_name ?? ''); ?>" required></label>
            <label>Phone <input type="text" name="phone" placeholder="Enter phone number" value="<?php echo e($phone ?? ''); ?>" pattern="[0-9+\-\s()]+" title="Enter a valid phone number"></label>
            <label>Email <input type="email" name="email" placeholder="Enter email (optional)" value="<?php echo e($email ?? ''); ?>"></label>
            <label>Address <textarea name="address" placeholder="Enter address" rows="3"><?php echo e($address ?? ''); ?></textarea></label>

            <h3>Service Request</h3>
            <label>Problem Type
                <select name="problem_type" required>
                    <option value="">-- Select Problem --</option>
                    <?php
                    $problems = ['Flat Tire', 'Dead Battery', 'Brake Problem', 'Oil Leak', 'Fuel Delivery', 'Engine Overheating', 'Other'];
                    foreach ($problems as $p):
                    ?>
                        <option value="<?php echo e($p); ?>" <?php echo (isset($problem_type) && $problem_type === $p) ? 'selected' : ''; ?>>
                            <?php echo e($p); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Description <textarea name="description" placeholder="Describe the problem" rows="3"><?php echo e($description ?? ''); ?></textarea></label>

            <h3>Assign Mechanic</h3>
            <?php if (empty($mechanics)): ?>
                <div style="background:#fff3cd;border:1px solid #ffc107;color:#856404;padding:10px;border-radius:6px;margin-bottom:12px;">
                    ⚠️ No available mechanics right now. Please free up a mechanic before registering a walk-in.
                </div>
            <?php endif; ?>
            <label>Available Mechanic
                <select name="mechanic_id" required <?php echo empty($mechanics) ? 'disabled' : ''; ?>>
                    <option value="">-- Select Mechanic --</option>
                    <?php foreach ($mechanics as $mech): ?>
                        <option value="<?php echo $mech['id']; ?>" <?php echo (isset($mechanic_id) && $mechanic_id == $mech['id']) ? 'selected' : ''; ?>>
                            <?php echo e($mech['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div style="margin-top:16px;">
                <button class="btn btn-primary" type="submit" <?php echo empty($mechanics) ? 'disabled' : ''; ?>>
                    Create &amp; Assign
                </button>
                <a href="<?php echo getBasePath(); ?>admin/dashboard.php" class="btn" style="margin-left:8px;">Cancel</a>
            </div>
        </form>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
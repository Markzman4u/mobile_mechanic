<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();

$pdo     = getPDO();
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name        = trim($_POST['full_name'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $phone            = trim($_POST['phone'] ?? '');
    $password         = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $date_of_birth    = trim($_POST['date_of_birth'] ?? '');
    $gender           = $_POST['gender'] ?? '';

    $phone = preg_replace('/[^0-9+]/', '', $phone);

    if ($full_name === '' || $email === '' || $password === '' || $password_confirm === '' || $date_of_birth === '' || $gender === '') {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($password !== $password_confirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif (!DateTime::createFromFormat('Y-m-d', $date_of_birth)) {
        $error = 'Please enter a valid date of birth.';
    } elseif (!in_array($gender, ['male', 'female'], true)) {
        $error = 'Please select a valid gender.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'Email is already registered.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (full_name, email, phone, password, role, date_of_birth, gender, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
                $stmt->execute([$full_name, $email, $phone, $hash, 'admin', $date_of_birth, $gender]);
                $success = 'Staff admin account created successfully.';
                $_POST   = [];
            }
        } catch (Exception $e) {
            error_log($e->getMessage());
            $error = 'An error occurred. Please try again.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
            <div>
                <h2 style="margin:0 0 6px 0;">Register Staff Admin</h2>
                <p class="muted" style="margin:0;">Create a new staff admin account.</p>
            </div>
            <a class="btn" href="<?php echo getBasePath(); ?>super_admin/manage_staff_admin.php">Back to List</a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo e($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo e($success); ?></div>
        <?php endif; ?>

        <form method="post" style="max-width:600px;">
            <label>Full Name
                <input type="text" name="full_name" value="<?php echo e($_POST['full_name'] ?? ''); ?>" required>
            </label>
            <label>Email Address
                <input type="email" name="email" value="<?php echo e($_POST['email'] ?? ''); ?>" required>
            </label>
            <label>Phone Number
                <input type="text" name="phone" value="<?php echo e($_POST['phone'] ?? ''); ?>" placeholder="+252...">
            </label>
            <label>Date of Birth
                <input type="date" name="date_of_birth" value="<?php echo e($_POST['date_of_birth'] ?? ''); ?>" max="<?php echo date('Y-m-d'); ?>" required>
            </label>
            <label>Gender
                <select name="gender" required>
                    <option value="" disabled <?php echo empty($_POST['gender']) ? 'selected' : ''; ?>>Select gender</option>
                    <option value="male"   <?php echo (($_POST['gender'] ?? '') === 'male')   ? 'selected' : ''; ?>>Male</option>
                    <option value="female" <?php echo (($_POST['gender'] ?? '') === 'female') ? 'selected' : ''; ?>>Female</option>
                </select>
            </label>
            <label>Password
                <input type="password" name="password" placeholder="Create a strong password" required>
            </label>
            <label>Confirm Password
                <input type="password" name="password_confirm" placeholder="Confirm your password" required>
            </label>
            <button class="btn btn-primary" type="submit" style="width:100%;padding:12px;font-size:1rem;font-weight:600;margin-top:16px;">
                Create Staff Admin
            </button>
        </form>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
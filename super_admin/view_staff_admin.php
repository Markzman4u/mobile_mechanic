<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();

$pdo    = getPDO();
$userId = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int) $_GET['id'] : 0;
if ($userId <= 0) {
    header('Location: ' . getBasePath() . 'super_admin/manage_staff_admin.php');
    exit;
}

$message     = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name     = trim($_POST['full_name'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $gender        = $_POST['gender'] ?? '';

    $phone = preg_replace('/[^0-9+]/', '', $phone);

    if ($full_name === '' || $email === '' || $date_of_birth === '' || $gender === '') {
        $message     = 'All fields are required.';
        $messageType = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message     = 'Please enter a valid email address.';
        $messageType = 'error';
    } elseif (!DateTime::createFromFormat('Y-m-d', $date_of_birth)) {
        $message     = 'Please enter a valid date of birth.';
        $messageType = 'error';
    } elseif (!in_array($gender, ['male', 'female'], true)) {
        $message     = 'Please choose a valid gender.';
        $messageType = 'error';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
        $stmt->execute([':email' => $email, ':id' => $userId]);
        if ($stmt->fetch()) {
            $message     = 'Email is already used by another account.';
            $messageType = 'error';
        } else {
            $update = $pdo->prepare('UPDATE users SET full_name = :full_name, email = :email, phone = :phone, date_of_birth = :dob, gender = :gender WHERE id = :id AND role = "admin" AND is_superadmin = FALSE');
            $update->execute([
                ':full_name' => $full_name,
                ':email'     => $email,
                ':phone'     => $phone,
                ':dob'       => $date_of_birth,
                ':gender'    => $gender,
                ':id'        => $userId,
            ]);
            $message = 'Staff admin profile updated successfully.';
        }
    }
}

$stmt = $pdo->prepare('SELECT id, full_name, email, phone, profile_pic, role, is_disabled, created_at, date_of_birth, gender FROM users WHERE id = :id AND role = "admin" AND is_superadmin = FALSE');
$stmt->execute([':id' => $userId]);
$staff = $stmt->fetch();

if (!$staff) {
    header('Location: ' . getBasePath() . 'super_admin/manage_staff_admin.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
            <div>
                <h2 style="margin:0 0 6px 0;">View Staff Admin</h2>
                <p class="muted" style="margin:0;">Edit staff admin details and see account status.</p>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <a class="btn" href="<?php echo getBasePath(); ?>super_admin/manage_staff_admin.php">Back to List</a>
                <a class="btn btn-primary" href="<?php echo getBasePath(); ?>super_admin/manage_staff_admin.php?toggle_disable=<?php echo $staff['id']; ?>" onclick="return confirm('Toggle disable status for this staff admin?');">
                    <?php echo $staff['is_disabled'] ? 'Enable Account' : 'Disable Account'; ?>
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo e($messageType); ?>"><?php echo e($message); ?></div>
        <?php endif; ?>

        <form method="post">
            <label>Full Name
                <input type="text" name="full_name" value="<?php echo e($_POST['full_name'] ?? $staff['full_name']); ?>" required>
            </label>
            <label>Email Address
                <input type="email" name="email" value="<?php echo e($_POST['email'] ?? $staff['email']); ?>" required>
            </label>
            <label>Phone Number
                <input type="text" name="phone" value="<?php echo e($_POST['phone'] ?? $staff['phone']); ?>" placeholder="+252...">
            </label>
            <label>Date of Birth
                <input type="date" name="date_of_birth" value="<?php echo e($_POST['date_of_birth'] ?? $staff['date_of_birth']); ?>" required>
            </label>
            <label>Gender
                <select name="gender" required>
                    <option value="male"   <?php echo (($_POST['gender'] ?? $staff['gender']) === 'male')   ? 'selected' : ''; ?>>Male</option>
                    <option value="female" <?php echo (($_POST['gender'] ?? $staff['gender']) === 'female') ? 'selected' : ''; ?>>Female</option>
                </select>
            </label>
            <label>Created At
                <input type="text" value="<?php echo date('M d, Y g:i A', strtotime($staff['created_at'])); ?>" disabled>
            </label>
            <button class="btn btn-primary" type="submit" style="margin-top:12px;">Save Changes</button>
        </form>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
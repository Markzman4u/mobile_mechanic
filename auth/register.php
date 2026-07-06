<?php
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name        = trim($_POST['full_name'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $phone            = trim($_POST['phone'] ?? '');
    $password         = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $role             = 'user';
    $date_of_birth    = trim($_POST['date_of_birth'] ?? '');
    $gender           = $_POST['gender'] ?? '';

    $phone = preg_replace('/[^0-9+]/', '', $phone);

    if (!in_array($gender, ['male', 'female'])) {
        $gender = '';
    }

    if (empty($full_name) || empty($email) || empty($password) || empty($date_of_birth) || empty($gender)) {
        $error = 'Full name, email, password, date of birth and gender are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!empty($phone) && !preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
        $error = 'Please enter a valid phone number.';
    } elseif (!DateTime::createFromFormat('Y-m-d', $date_of_birth)) {
        $error = 'Please enter a valid date of birth.';
    } elseif ((new DateTime($date_of_birth)) > new DateTime()) {
        $error = 'Date of birth cannot be in the future.';
    } elseif ((new DateTime($date_of_birth))->diff(new DateTime())->y < 16) {
        $error = 'You must be at least 16 years old to register.';
    } elseif ($password !== $password_confirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } else {
        try {
            $pdo  = getPDO();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                $error = 'Email is already registered.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins  = $pdo->prepare('
                    INSERT INTO users (full_name, email, phone, password, role, date_of_birth, gender, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ');
                $ins->execute([$full_name, $email, $phone, $hash, $role, $date_of_birth, $gender]);
                header('Location: ' . getBasePath() . 'auth/login.php?success=1');
                exit;
            }
        } catch (Exception $e) {
            $error = 'An error occurred. Please try again.';
            error_log($e->getMessage());
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<main style="max-width:500px;">
    <div class="card" style="margin-top:40px;">

        <div style="text-align:center;margin-bottom:24px;">
            <h2 style="margin:0 0 8px 0;color:var(--charcoal);">Create Account</h2>
            <p class="muted">Join Mobile Mechanic today</p>
        </div>

        <?php if ($error): ?>
            <div style="background:#fff6eb;border:1px solid #ffb84d;border-left:4px solid var(--safety-orange);color:#b35b00;padding:12px;border-radius:6px;margin-bottom:20px;">
                <strong>Error:</strong> <?php echo e($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div style="background:#e7f5eb;border:1px solid #7dd3a3;border-left:4px solid #2dbd5e;color:#1e5e3f;padding:12px;border-radius:6px;margin-bottom:20px;">
                <strong>Success!</strong> Account created successfully.
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo getBasePath(); ?>auth/register.php" novalidate>

            <label for="full_name">Full Name
                <input type="text" id="full_name" name="full_name" value="<?php echo e($_POST['full_name'] ?? ''); ?>" placeholder="John Doe" required>
            </label>

            <label for="email">Email Address
                <input type="email" id="email" name="email" value="<?php echo e($_POST['email'] ?? ''); ?>" placeholder="your@email.com" required>
            </label>

            <label for="phone">Phone Number
                <input type="tel" id="phone" name="phone" value="<?php echo e($_POST['phone'] ?? ''); ?>" placeholder="+252634567890">
            </label>

            <label for="date_of_birth">Date of Birth
                <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo e($_POST['date_of_birth'] ?? ''); ?>" max="<?php echo date('Y-m-d'); ?>" required>
            </label>

            <label for="gender">Gender
                <select name="gender" id="gender" required>
                    <option value="" disabled <?php echo empty($_POST['gender']) ? 'selected' : ''; ?>>Select gender</option>
                    <option value="male"   <?php echo (($_POST['gender'] ?? '') === 'male')   ? 'selected' : ''; ?>>Male</option>
                    <option value="female" <?php echo (($_POST['gender'] ?? '') === 'female') ? 'selected' : ''; ?>>Female</option>
                </select>
            </label>

            <label for="password">Password
                <input type="password" id="password" name="password" placeholder="Create a strong password" required>
            </label>

            <label for="password_confirm">Confirm Password
                <input type="password" id="password_confirm" name="password_confirm" placeholder="Confirm your password" required>
            </label>

            <button class="btn btn-primary" type="submit" style="width:100%;padding:12px;font-size:1rem;font-weight:600;margin-top:10px;margin-bottom:16px;">
                Create Account
            </button>

            <div style="text-align:center;border-top:1px solid #eee;padding-top:16px;">
                <p class="muted">Already have an account?
                    <a href="<?php echo getBasePath(); ?>auth/login.php" style="color:var(--safety-orange);text-decoration:none;font-weight:600;">Sign in</a>
                </p>
            </div>

        </form>
    </div>

    <p style="text-align:center;color:var(--muted);margin-top:24px;font-size:0.9rem;">Mobile Mechanic © 2026</p>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
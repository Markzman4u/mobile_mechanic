<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Already logged in as superadmin
if (!empty($_SESSION['user_id']) && !empty($_SESSION['is_superadmin'])) {
    header('Location: ' . getBasePath() . 'super_admin/dashboard.php');
    exit;
}

// Already logged in as something else
if (!empty($_SESSION['user_id'])) {
    $role     = $_SESSION['role'] ?? 'user';
    $redirect = $role === 'admin' ? 'admin/dashboard.php' : 'customer/dashboard.php';
    header('Location: ' . getBasePath() . $redirect);
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } else {
        try {
            $pdo  = getPDO();
            $stmt = $pdo->prepare('SELECT id, full_name, role, password, is_superadmin, is_disabled FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password'])) {
                $error = 'Invalid email or password.';
            } elseif (!(bool) $user['is_superadmin']) {
                $error = 'Access denied. This account is not a super admin.';
            } elseif ((bool) $user['is_disabled']) {
                $error = 'This account has been disabled. Please contact support.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id']       = $user['id'];
                $_SESSION['full_name']     = $user['full_name'];
                $_SESSION['role']          = 'admin';
                $_SESSION['is_superadmin'] = true;
                header('Location: ' . getBasePath() . 'super_admin/dashboard.php');
                exit;
            }
        } catch (Exception $e) {
            error_log($e->getMessage());
            $error = 'An error occurred. Please try again.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<main style="max-width:420px; margin: 40px auto;">
    <div class="card">
        <div style="text-align:center;margin-bottom:24px;">
            <h2 style="margin:0 0 8px 0;color:var(--charcoal);">Super Admin Sign In</h2>
            <p class="muted">Access the super admin portal.</p>
        </div>

        <?php if ($error): ?>
            <div style="background:#fff6eb;border:1px solid #ffb84d;border-left:4px solid var(--safety-orange);color:#b35b00;padding:12px;border-radius:6px;margin-bottom:16px;">
                <strong>Error:</strong> <?php echo e($error); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo getBasePath(); ?>super_admin/login.php" novalidate>
            <label>Email Address
                <input type="email" name="email" value="<?php echo e($_POST['email'] ?? ''); ?>" placeholder="superadmin@example.com" required>
            </label>

            <label>Password
                <input type="password" name="password" placeholder="Enter your password" required>
            </label>

            <button class="btn btn-primary" type="submit" style="width:100%;padding:12px;font-size:1rem;font-weight:600;margin-bottom:16px;">
                Sign In
            </button>
        </form>

        <p style="text-align:center;color:var(--muted);margin-top:12px;font-size:0.9rem;">
            Super admin accounts are created through the database.
        </p>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
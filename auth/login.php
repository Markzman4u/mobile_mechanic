<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$error = '';
$success = isset($_GET['success']) ? 'Account created successfully! Please sign in.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } else {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare('SELECT id, password, role FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($password, $user['password'])) {
                $error = 'Invalid email or password.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                // redirect by role
                header('Location: ' . getBasePath() . ($user['role'] === 'admin' ? 'admin/dashboard.php' : 'customer/dashboard.php'));
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
<main style="max-width:420px;">
    <div class="card" style="margin-top:40px;">
        <div style="text-align:center;margin-bottom:24px;">
            <h2 style="margin:0 0 8px 0;color:var(--charcoal);">Welcome Back</h2>
            <p class="muted">Sign in to your account</p>
        </div>

        <?php if ($success): ?>
            <div style="background:#e7f5eb;border:1px solid #7dd3a3;border-left:4px solid #2dbd5e;color:#1e5e3f;padding:12px;border-radius:6px;margin-bottom:16px;">
                <strong>Success!</strong> <?php echo e($success); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div style="background:#fff6eb;border:1px solid #ffb84d;border-left:4px solid var(--safety-orange);color:#b35b00;padding:12px;border-radius:6px;margin-bottom:16px;">
                <strong>Error:</strong> <?php echo e($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="post" action="<?php echo getBasePath(); ?>auth/login.php" novalidate>
            <label>Email Address
                <input type="email" name="email" value="<?php echo e($_POST['email'] ?? ''); ?>" placeholder="your@email.com" required>
            </label>
            
            <label>Password
                <input type="password" name="password" placeholder="Enter your password" required>
            </label>
            
            <button class="btn btn-primary" type="submit" style="width:100%;padding:12px;font-size:1rem;font-weight:600;margin-bottom:16px;">Sign In</button>
            
            <div style="text-align:center;border-top:1px solid #eee;padding-top:16px;">
                <p class="muted">Don't have an account? <a href="<?php echo getBasePath(); ?>auth/register.php" style="color:var(--safety-orange);text-decoration:none;font-weight:600;">Create one</a></p>
            </div>
        </form>
    </div>
    
    <p style="text-align:center;color:var(--muted);margin-top:24px;font-size:0.9rem;">
        Mobile Mechanic © 2026
    </p>
</main>
<?php require_once __DIR__ . '/../includes/footer.php';

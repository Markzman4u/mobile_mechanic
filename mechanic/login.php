<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['mechanic_id'])) {
    header('Location: ' . getBasePath() . 'mechanic/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } else {
        try {
            $pdo = getPDO();
            
            // Check mechanic credentials and account status
            $stmt = $pdo->prepare('
                SELECT id, password, is_disabled, is_deleted
                FROM mechanics
                WHERE email = ? LIMIT 1
            ');
            $stmt->execute([$email]);
            $mechanic = $stmt->fetch();

            if (!$mechanic) {
                $error = 'Invalid email or password.';
            } elseif ($mechanic['is_deleted']) {
                // is_deleted blocks login regardless of is_disabled
                $error = 'This account has been deleted.';
            } elseif ($mechanic['is_disabled']) {
                $error = 'This account has been suspended. Please contact an administrator.';
            } elseif (!$mechanic['password']) {
                $error = 'This account does not have login access yet.';
            } elseif (!password_verify($password, $mechanic['password'])) {
                $error = 'Invalid email or password.';
            } else {
                // Update last login timestamp
                $updateStmt = $pdo->prepare('UPDATE mechanics SET last_login = CURRENT_TIMESTAMP WHERE id = ?');
                $updateStmt->execute([$mechanic['id']]);

                // Start session for mechanic
                session_regenerate_id(true);
                $_SESSION['mechanic_id'] = $mechanic['id'];
                
                header('Location: ' . getBasePath() . 'mechanic/dashboard.php');
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
            <h2 style="margin:0 0 8px 0;color:var(--charcoal);">Mechanic Portal</h2>
            <p class="muted">Sign in to your account</p>
        </div>

        <?php if ($error): ?>
            <div style="background:#fde7e7;border:1px solid #f5a3a3;border-left:4px solid #e74c3c;color:#c0392b;padding:12px;border-radius:6px;margin-bottom:16px;">
                <strong>Error:</strong> <?php echo e($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" style="display:flex;flex-direction:column;gap:12px;">
            <div>
                <label for="email" style="display:block;margin-bottom:4px;font-weight:600;font-size:14px;">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="your@email.com"
                    required
                    style="width:100%;padding:10px;border:1px solid #d0d0d0;border-radius:4px;font-size:14px;box-sizing:border-box;"
                />
            </div>

            <div>
                <label for="password" style="display:block;margin-bottom:4px;font-weight:600;font-size:14px;">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="••••••••"
                    required
                    style="width:100%;padding:10px;border:1px solid #d0d0d0;border-radius:4px;font-size:14px;box-sizing:border-box;"
                />
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px;">Sign In</button>
        </form>

        <p class="muted" style="text-align:center;margin-top:16px;font-size:13px;">
            Don't have an account? <br>
            Contact your administrator to set up access.
        </p>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

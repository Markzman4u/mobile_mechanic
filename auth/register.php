<?php
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $role = $_POST['role'] ?? 'user';

    // Clean phone number
    $phone = preg_replace('/[^0-9+]/', '', $phone);

    // Allow only user or admin
    if (!in_array($role, ['user', 'admin'])) {
        $role = 'user';
    }

    // Validation
    if (empty($full_name) || empty($email) || empty($password)) {

        $error = 'Full name, email and password are required.';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = 'Please enter a valid email address.';

    } elseif (!empty($phone) && !preg_match('/^\+?[0-9]{7,15}$/', $phone)) {

        $error = 'Please enter a valid phone number.';

    } elseif ($password !== $password_confirm) {

        $error = 'Passwords do not match.';

    } elseif (strlen($password) < 6) {

        $error = 'Password must be at least 6 characters long.';

    } else {

        try {

            $pdo = getPDO();

            // Check existing email
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);

            if ($stmt->fetch()) {

                $error = 'Email is already registered.';

            } else {

                // Hash password
                $hash = password_hash($password, PASSWORD_DEFAULT);

                // Insert user
                $ins = $pdo->prepare("
                    INSERT INTO users
                    (
                        full_name,
                        email,
                        phone,
                        password,
                        role,
                        created_at
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?, NOW()
                    )
                ");

                $ins->execute([
                    $full_name,
                    $email,
                    $phone,
                    $hash,
                    $role
                ]);

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

            <h2 style="margin:0 0 8px 0;color:var(--charcoal);">
                Create Account
            </h2>

            <p class="muted">
                Join Mobile Mechanic today
            </p>

        </div>

        <!-- ERROR MESSAGE -->
        <?php if ($error): ?>

            <div style="
                background:#fff6eb;
                border:1px solid #ffb84d;
                border-left:4px solid var(--safety-orange);
                color:#b35b00;
                padding:12px;
                border-radius:6px;
                margin-bottom:20px;
            ">
                <strong>Error:</strong>
                <?php echo e($error); ?>
            </div>

        <?php endif; ?>

        <!-- SUCCESS MESSAGE -->
        <?php if ($success): ?>

            <div style="
                background:#e7f5eb;
                border:1px solid #7dd3a3;
                border-left:4px solid #2dbd5e;
                color:#1e5e3f;
                padding:12px;
                border-radius:6px;
                margin-bottom:20px;
            ">
                <strong>Success!</strong>
                Account created successfully.
            </div>

        <?php endif; ?>

        <!-- REGISTER FORM -->
        <form 
            method="post"
            action="<?php echo getBasePath(); ?>auth/register.php"
            novalidate
        >

            <!-- FULL NAME -->
            <div class="form-group">

                <label for="full_name">
                    Full Name
                </label>

                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    value="<?php echo e($_POST['full_name'] ?? ''); ?>"
                    placeholder="John Doe"
                    required
                >

            </div>

            <!-- EMAIL -->
            <div class="form-group">

                <label for="email">
                    Email Address
                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?php echo e($_POST['email'] ?? ''); ?>"
                    placeholder="your@email.com"
                    required
                >

            </div>

            <!-- PHONE -->
            <div class="form-group">

                <label for="phone">
                    Phone Number
                </label>

                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    value="<?php echo e($_POST['phone'] ?? ''); ?>"
                    placeholder="+252634567890"
                    style="
                        width:100%;
                        padding:10px 12px;
                        border:1px solid #ddd;
                        border-radius:6px;
                        font-size:1rem;
                        box-sizing:border-box;
                    "
                >

                <small class="muted" style="display:block;margin-top:6px;">
                </small>

            </div>

            <!-- ACCOUNT TYPE -->
            <div class="form-group">

                <label for="role">
                    Account Type
                </label>

                <select
                    name="role"
                    id="role"
                    required
                >

                    <option
                        value="user"
                        <?php echo (($_POST['role'] ?? '') === 'user') ? 'selected' : ''; ?>
                    >
                        User
                    </option>

                    <option
                        value="admin"
                        <?php echo (($_POST['role'] ?? '') === 'admin') ? 'selected' : ''; ?>
                    >
                        Admin
                    </option>

                </select>

            </div>

            <!-- PASSWORD -->
            <div class="form-group">

                <label for="password">
                    Password
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Create a strong password"
                    required
                >

            </div>

            <!-- CONFIRM PASSWORD -->
            <div class="form-group">

                <label for="password_confirm">
                    Confirm Password
                </label>

                <input
                    type="password"
                    id="password_confirm"
                    name="password_confirm"
                    placeholder="Confirm your password"
                    required
                >

            </div>

            <!-- SUBMIT BUTTON -->
            <button
                class="btn btn-primary"
                type="submit"
                style="
                    width:100%;
                    padding:12px;
                    font-size:1rem;
                    font-weight:600;
                    margin-top:10px;
                    margin-bottom:16px;
                "
            >
                Create Account
            </button>

            <!-- LOGIN LINK -->
            <div style="
                text-align:center;
                border-top:1px solid #eee;
                padding-top:16px;
            ">

                <p class="muted">

                    Already have an account?

                    <a
                        href="<?php echo getBasePath(); ?>auth/login.php"
                        style="
                            color:var(--safety-orange);
                            text-decoration:none;
                            font-weight:600;
                        "
                    >
                        Sign in
                    </a>

                </p>

            </div>

        </form>

    </div>

    <p style="
        text-align:center;
        color:var(--muted);
        margin-top:24px;
        font-size:0.9rem;
    ">
        Mobile Mechanic © 2026
    </p>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
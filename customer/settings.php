<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . getBasePath() . 'admin/settings.php');
    exit;
}

$pdo    = getPDO();
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT full_name, email, phone FROM users WHERE id = ? AND role = "user"');
$stmt->execute([$userId]);
$customer = $stmt->fetch();

if (!$customer) {
    header('Location: ' . getBasePath() . 'customer/dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <div class="card">
        <h2>Settings</h2>
        <p class="muted">Manage your account and preferences.</p>

        <div style="max-width:420px;margin:24px 0;">
            <div class="card" style="border-left:4px solid var(--charcoal);">
                <h3 style="margin-top:0;">Account Information</h3>
                <p class="muted">View and update your personal information.</p>
                <p style="margin:12px 0 0 0;">
                    <a class="btn btn-primary" href="<?php echo getBasePath(); ?>customer/profile.php">
                        Manage Profile
                    </a>
                </p>
            </div>
        </div>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
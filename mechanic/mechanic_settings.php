<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Ensure mechanic is logged in
if (empty($_SESSION['mechanic_id'])) {
    header('Location: ' . getBasePath() . 'mechanic/login.php');
    exit;
}

$pdo        = getPDO();
$mechanicId = (int) $_SESSION['mechanic_id'];

// Fetch mechanic info
$stmt = $pdo->prepare(
    'SELECT name, email, phone FROM mechanics WHERE id = ? AND is_deleted = FALSE'
);
$stmt->execute([$mechanicId]);
$mechanic = $stmt->fetch();

if (!$mechanic) {
    header('Location: ' . getBasePath() . 'mechanic/login.php');
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
            <!-- Profile Card -->
            <div class="card" style="border-left:4px solid var(--charcoal);">
                <h3 style="margin-top:0;">Account Information</h3>
                <p class="muted">View and update your personal information.</p>
                <p style="margin:12px 0 0 0;">
                    <a class="btn btn-primary" href="<?php echo getBasePath(); ?>mechanic/profile.php">
                        Manage Profile
                    </a>
                </p>
            </div>
        </div>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
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

$pendingCount = $pdo->query('SELECT COUNT(*) AS count FROM requests WHERE status = "pending"')->fetch()['count'];
$activeCount = $pdo->query('SELECT COUNT(*) AS count FROM requests WHERE status IN ("assigned", "in_progress")')->fetch()['count'];
$mechanicsCount = $pdo->query('SELECT COUNT(*) AS count FROM mechanics WHERE status = "available"')->fetch()['count'];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <div class="card">
        <h2>Admin Dashboard</h2>
        <p class="muted">System overview and quick actions.</p>
        
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin:20px 0;">
            <div class="card" style="text-align:center;">
                <h3 style="color:var(--safety-orange);margin:0;"><?php echo $pendingCount; ?></h3>
                <p class="muted">Pending Requests</p>
            </div>
            <div class="card" style="text-align:center;">
                <h3 style="color:var(--charcoal);margin:0;"><?php echo $activeCount; ?></h3>
                <p class="muted">Active Jobs</p>
            </div>
            <div class="card" style="text-align:center;">
                <h3 style="color:var(--charcoal);margin:0;"><?php echo $mechanicsCount; ?></h3>
                <p class="muted">Available Mechanics</p>
            </div>
        </div>
        
        <h3>Quick Actions</h3>
        <p>
            <a class="btn btn-primary" href="<?php echo getBasePath(); ?>admin/pending_requests.php">View Pending Requests</a>
            <a class="btn" href="<?php echo getBasePath(); ?>admin/active_jobs.php" style="margin-left:8px;">Active Jobs</a>
            <a class="btn" href="<?php echo getBasePath(); ?>admin/manage_mechanics.php" style="margin-left:8px;">Manage Mechanics</a>
        </p>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php';

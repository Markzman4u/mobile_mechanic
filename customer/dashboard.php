<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pdo = getPDO();
$userId = $_SESSION['user_id'];

$activeCount = $pdo->prepare('SELECT COUNT(*) AS count FROM requests WHERE user_id = :user_id AND status IN ("pending", "assigned", "in_progress")');
$activeCount->execute([':user_id' => $userId]);
$activeCount = $activeCount->fetch()['count'];

$completedCount = $pdo->prepare('SELECT COUNT(*) AS count FROM requests WHERE user_id = :user_id AND status = "completed"');
$completedCount->execute([':user_id' => $userId]);
$completedCount = $completedCount->fetch()['count'];

$recentRequests = $pdo->prepare('SELECT id, problem_type, status, created_at FROM requests WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 10');
$recentRequests->execute([':user_id' => $userId]);
$recentRequests = $recentRequests->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <div class="card">
        <h2>My Dashboard</h2>
        <p class="muted">Manage your service requests and view history.</p>
        
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:20px 0;">
            <div class="card" style="text-align:center;">
                <h3 style="color:var(--safety-orange);margin:0;"><?php echo $activeCount; ?></h3>
                <p class="muted">Active Requests</p>
            </div>
            <div class="card" style="text-align:center;">
                <h3 style="color:var(--charcoal);margin:0;"><?php echo $completedCount; ?></h3>
                <p class="muted">Completed Services</p>
            </div>
        </div>
        
        <h3>Quick Actions</h3>
        <p>
            <a class="btn btn-primary" href="<?php echo getBasePath(); ?>customer/request_service.php">Request Emergency Service</a>
            <a class="btn" href="<?php echo getBasePath(); ?>customer/track_service.php" style="margin-left:8px;">Track Request</a>
            <a class="btn" href="<?php echo getBasePath(); ?>customer/customer_history.php" style="margin-left:8px;">View History</a>
        </p>
        
        <h3>Your Recent Requests</h3>
        <table>
            <thead>
                <tr>
                    <th>Request ID</th>
                    <th>Problem</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentRequests)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center;color:var(--muted);padding:20px;">No active requests</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentRequests as $req): ?>
                        <tr>
                            <td>#<?php echo e($req['id']); ?></td>
                            <td><?php echo e($req['problem_type']); ?></td>
                            <td><span class="status status-<?php echo e($req['status']); ?>"><?php echo statusLabel($req['status']); ?></span></td>
                            <td><?php echo date('M d, g:i A', strtotime($req['created_at'])); ?></td>
                            <td><a href="<?php echo getBasePath(); ?>customer/track_service.php?id=<?php echo $req['id']; ?>" style="color:var(--safety-orange);text-decoration:none;">Track</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php';

<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pdo    = getPDO();
$userId = $_SESSION['user_id'];

// ── Active requests count (still in requests table) ───────────────────────────
$activeStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM requests
     WHERE user_id = :uid
       AND status IN ("pending","assigned","in_progress")'
);
$activeStmt->execute([':uid' => $userId]);
$activeCount = (int) $activeStmt->fetchColumn();

// ── Completed count from history_records ──────────────────────────────────────
$completedStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM history_records
     WHERE user_id          = :uid
       AND status           = "completed"
       AND hidden_by_user   = FALSE
       AND hidden_by_admin  = FALSE'
);
$completedStmt->execute([':uid' => $userId]);
$completedCount = (int) $completedStmt->fetchColumn();

// ── Last 3 history records ────────────────────────────────────────────────────
$recentStmt = $pdo->prepare(
    'SELECT id, request_id, problem_type, status, total_amount, completed_at
     FROM history_records
     WHERE user_id         = :uid
       AND hidden_by_user  = FALSE
       AND hidden_by_admin = FALSE
     ORDER BY completed_at DESC
     LIMIT 3'
);
$recentStmt->execute([':uid' => $userId]);
$recentHistory = $recentStmt->fetchAll();

// ── Total history count (to decide whether to show "More" button) ─────────────
$totalStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM history_records
     WHERE user_id         = :uid
       AND hidden_by_user  = FALSE
       AND hidden_by_admin = FALSE'
);
$totalStmt->execute([':uid' => $userId]);
$totalHistory = (int) $totalStmt->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <div class="card">
        <h2>My Dashboard</h2>
        <p class="muted">Manage your service requests and view history.</p>

        <!-- ── Stats ──────────────────────────────────────────────────────── -->
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

        <!-- ── Quick Actions ──────────────────────────────────────────────── -->
        <h3>Quick Actions</h3>
        <p>
            <a class="btn btn-primary"
               href="<?php echo getBasePath(); ?>customer/request_service.php">
                Request Emergency Service
            </a>
            <a class="btn"
               href="<?php echo getBasePath(); ?>customer/track_service.php"
               style="margin-left:8px;">Track Request</a>
            <a class="btn"
               href="<?php echo getBasePath(); ?>customer/customer_history.php"
               style="margin-left:8px;">View History</a>
        </p>

        <!-- ── Recent History ─────────────────────────────────────────────── -->
        <div style="display:flex;align-items:center;justify-content:space-between;
                    flex-wrap:wrap;gap:8px;margin-top:24px;margin-bottom:10px;">
            <h3 style="margin:0;">Recent Service History</h3>
            <?php if ($totalHistory > 3): ?>
                <a href="<?php echo getBasePath(); ?>customer/customer_history.php"
                   style="font-size:0.85rem;color:var(--safety-orange);text-decoration:none;font-weight:600;">
                    View All (<?php echo $totalHistory; ?>) →
                </a>
            <?php endif; ?>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Request ID</th>
                    <th>Problem</th>
                    <th>Status</th>
                    <th>Completed</th>
                    <th>Total</th>
                    <th>Invoice</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentHistory)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;color:var(--muted);padding:20px;">
                            No service history yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentHistory as $rec): ?>
                        <tr>
                            <td>#<?php echo e($rec['request_id']); ?></td>
                            <td><?php echo e($rec['problem_type'] ?? '—'); ?></td>
                            <td>
                                <span class="status status-<?php echo e($rec['status']); ?>">
                                    <?php echo ucfirst(e($rec['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo $rec['completed_at']
                                    ? date('M d, g:i A', strtotime($rec['completed_at']))
                                    : '—'; ?>
                            </td>
                            <td>
                                <?php echo $rec['total_amount'] > 0
                                    ? '$' . number_format((float)$rec['total_amount'], 2)
                                    : '—'; ?>
                            </td>
                            <td>
                                <?php if ($rec['status'] === 'completed'): ?>
                                    <a href="<?php echo getBasePath(); ?>customer/invoice.php?history_id=<?php echo $rec['id']; ?>"
                                       style="color:var(--safety-orange);text-decoration:none;font-size:0.9rem;">
                                        View Invoice
                                    </a>
                                <?php else: ?>
                                    <span style="color:var(--muted);font-size:0.85rem;">N/A</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalHistory > 3): ?>
            <div style="text-align:center;margin-top:14px;">
                <a href="<?php echo getBasePath(); ?>customer/customer_history.php"
                   class="btn" style="font-size:0.875rem;">
                    View All History →
                </a>
            </div>
        <?php endif; ?>

    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
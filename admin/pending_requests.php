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
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign'])) {
    $request_id  = (int) $_POST['request_id'];
    $mechanic_id = (int) $_POST['mechanic_id'];

    if ($mechanic_id > 0) {
        // Get current mechanic if any
        $currStmt = $pdo->prepare('SELECT mechanic_id FROM requests WHERE id = :id');
        $currStmt->execute([':id' => $request_id]);
        $currReq = $currStmt->fetch();
        $oldMechanicId = $currReq['mechanic_id'] ?? null;
        
        $stmt = $pdo->prepare('UPDATE requests SET mechanic_id = :mechanic_id, status = :status WHERE id = :id');
        $stmt->execute([':mechanic_id' => $mechanic_id, ':status' => 'assigned', ':id' => $request_id]);
        
        // If reassigning from a different mechanic, free up the old one
        if ($oldMechanicId && $oldMechanicId !== $mechanic_id) {
            // Check if old mechanic has any other active jobs
            $checkStmt = $pdo->prepare(
                'SELECT COUNT(*) as cnt FROM requests WHERE mechanic_id = :id AND status IN ("assigned", "in_progress") AND id != :req_id'
            );
            $checkStmt->execute([':id' => $oldMechanicId, ':req_id' => $request_id]);
            $hasOtherJobs = $checkStmt->fetchColumn();
            
            if (!$hasOtherJobs) {
                $pdo->prepare('UPDATE mechanics SET status = "available" WHERE id = :id')
                    ->execute([':id' => $oldMechanicId]);
            }
        }
        
        // Update new mechanic status to busy
        $pdo->prepare('UPDATE mechanics SET status = "busy" WHERE id = :id')
            ->execute([':id' => $mechanic_id]);
        
        $message = 'Request assigned successfully.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject'])) {
    $request_id = (int) $_POST['request_id'];
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');

    if ($rejection_reason === '') {
        $message = 'Error: Rejection reason is required.';
    } else {
        $stmt = $pdo->prepare('SELECT user_id FROM requests WHERE id = :id');
        $stmt->execute([':id' => $request_id]);
        $req = $stmt->fetch();

        if ($req && $req['user_id']) {
            $stmt = $pdo->prepare('UPDATE requests SET status = :status, rejection_reason = :reason WHERE id = :id');
            $stmt->execute([':status' => 'rejected', ':reason' => $rejection_reason, ':id' => $request_id]);

            createNotification($req['user_id'], 'rejection', 'Request Rejected', 'Your service request has been rejected.', $request_id);

            $message = 'Request rejected successfully. Customer has been notified.';
        } else {
            $message = 'Error: Could not find request or customer.';
        }
    }
}

// Only registered-user requests (walkin_id IS NULL) should appear here
$requests = $pdo->query(
    'SELECT r.id, u.full_name, r.problem_type, u.phone, r.created_at
     FROM requests r
     LEFT JOIN users u ON r.user_id = u.id
     WHERE r.status = "pending"
       AND r.walkin_id IS NULL
     ORDER BY r.created_at DESC'
)->fetchAll();

$mechanics = $pdo->query('SELECT id, name FROM mechanics WHERE status = "available" ORDER BY name ASC')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <div class="card">
        <h2>Pending Requests</h2>
        <p class="muted">List of new requests from registered customers awaiting assignment.</p>

        <?php if ($message): ?>
            <div style="background:#e8f7e9;border:1px solid #8bc34a;color:#2f6627;padding:12px;border-radius:6px;margin-bottom:16px;">
                <?php echo e($message); ?>
            </div>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Customer</th>
                    <th>Problem</th>
                    <th>Phone</th>
                    <th>Submitted</th>
                    <th>Details</th>
                    <th>Assign</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center;color:var(--muted);padding:20px;">No pending requests found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($requests as $req): ?>
                        <tr>
                            <td><?php echo e($req['id']); ?></td>
                            <td><?php echo e($req['full_name'] ?? '-'); ?></td>
                            <td><?php echo e($req['problem_type']); ?></td>
                            <td><?php echo e($req['phone']); ?></td>
                            <td><?php echo date('M d, g:i A', strtotime($req['created_at'])); ?></td>
                            <td>
                                <a href="<?php echo getBasePath(); ?>admin/request_detail.php?id=<?php echo (int)$req['id']; ?>"
                                   class="btn"
                                   style="padding:5px 12px;font-size:0.82rem;background:#f5f5f5;
                                          border:1px solid #ddd;color:#333;border-radius:4px;
                                          text-decoration:none;display:inline-block;white-space:nowrap;">
                                    🔍 Details
                                </a>
                                <button type="button"
                                        class="btn"
                                        onclick="openRejectModal(<?php echo (int)$req['id']; ?>)"
                                        style="padding:5px 12px;font-size:0.82rem;background:#fff3cd;
                                               border:1px solid #ffc107;color:#856404;border-radius:4px;
                                               cursor:pointer;white-space:nowrap;margin-left:4px;">
                                    ✕ Reject
                                </button>
                            </td>
                            <td>
                                <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                    <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                    <select name="mechanic_id" style="padding:6px;border-radius:4px;border:1px solid #ddd;font-size:0.9rem;" required>
                                        <option value="">-- Select --</option>
                                        <?php foreach ($mechanics as $mech): ?>
                                            <option value="<?php echo $mech['id']; ?>"><?php echo e($mech['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" name="assign" value="1" class="btn btn-primary" style="padding:6px 12px;font-size:0.85rem;">Assign</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<!-- Reject Modal -->
<div id="rejectModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:white;border-radius:8px;padding:24px;max-width:500px;width:90%;box-shadow:0 4px 6px rgba(0,0,0,0.1);">
        <h3 style="margin-top:0;margin-bottom:16px;font-size:1.3rem;">Reject Request</h3>
        <form method="post" onsubmit="return validateRejectionForm();">
            <input type="hidden" name="request_id" id="rejectRequestId" value="">
            <input type="hidden" name="reject" value="1">
            <div style="margin-bottom:16px;">
                <label for="rejectionReason" style="display:block;margin-bottom:8px;font-weight:500;">Reason for Rejection:</label>
                <textarea id="rejectionReason"
                          name="rejection_reason"
                          placeholder="Enter the reason for rejecting this request..."
                          style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-family:inherit;font-size:0.95rem;min-height:100px;resize:vertical;"
                          required></textarea>
            </div>
            <div style="display:flex;gap:12px;justify-content:flex-end;">
                <button type="button"
                        onclick="closeRejectModal()"
                        style="padding:8px 16px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;cursor:pointer;font-size:0.95rem;">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary" style="padding:8px 16px;font-size:0.95rem;">
                    Confirm Rejection
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openRejectModal(requestId) {
    document.getElementById('rejectRequestId').value = requestId;
    document.getElementById('rejectionReason').value = '';
    document.getElementById('rejectModal').style.display = 'flex';
}
function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}
function validateRejectionForm() {
    const reason = document.getElementById('rejectionReason').value.trim();
    if (reason === '') {
        alert('Please enter a reason for rejection.');
        return false;
    }
    return true;
}
document.addEventListener('click', function(event) {
    const modal = document.getElementById('rejectModal');
    if (event.target === modal) closeRejectModal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
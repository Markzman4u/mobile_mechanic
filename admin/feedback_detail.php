<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (!isAdmin()) {
    header('Location: ' . getBasePath() . 'customer/dashboard.php');
    exit;
}

if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    header('Location: ' . getBasePath() . 'admin/view_feedback.php');
    exit;
}

$pdo = getPDO();
$feedbackId = (int) $_GET['id'];

// ----------------------
// FEEDBACK + JOB DETAILS
// ----------------------
$stmt = $pdo->prepare(
    'SELECT f.*, '
    . 'h.problem_type, h.description AS job_description, h.diagnosis, '
    . 'h.status AS job_status, h.rejection_reason, h.total_amount, '
    . 'h.latitude, h.longitude, h.is_walkin, '
    . 'h.request_created_at, h.completed_at '
    . 'FROM feedback f '
    . 'LEFT JOIN history_records h ON f.history_id = h.id '
    . 'WHERE f.id = :id'
);
$stmt->execute([':id' => $feedbackId]);
$feedback = $stmt->fetch();

if (!$feedback) {
    header('Location: ' . getBasePath() . 'admin/view_feedback.php');
    exit;
}

// ----------------------
// SERVICE ITEMS (snapshot, tied to the history record)
// ----------------------
$serviceItems = [];
if ($feedback['history_id']) {
    $itemsStmt = $pdo->prepare(
        'SELECT item_name, price FROM history_service_items WHERE history_id = :hid'
    );
    $itemsStmt->execute([':hid' => $feedback['history_id']]);
    $serviceItems = $itemsStmt->fetchAll();
}

// ----------------------
// SELECTED TAGS, split by category
// ----------------------
$tagsStmt = $pdo->prepare(
    'SELECT t.label, t.category, t.is_positive '
    . 'FROM feedback_tag_selections s '
    . 'JOIN feedback_tags t ON s.tag_id = t.id '
    . 'WHERE s.feedback_id = :fid '
    . 'ORDER BY t.category ASC, t.sort_order ASC'
);
$tagsStmt->execute([':fid' => $feedbackId]);
$allTags = $tagsStmt->fetchAll();

$mechanicTagList = array_filter($allTags, static fn($t) => $t['category'] === 'mechanic');
$serviceTagList  = array_filter($allTags, static fn($t) => $t['category'] === 'service');

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';

/**
 * Renders a list of tags as small pills.
 *
 * @param array $tagList
 */
function renderTagPills(array $tagList): void
{
    if (empty($tagList)) {
        echo '<span class="muted">No tags selected.</span>';
        return;
    }
    foreach ($tagList as $tag) {
        $color = $tag['is_positive'] ? '#2f6627' : '#a94442';
        $bg    = $tag['is_positive'] ? '#e8f7e9' : '#fdecea';
        echo '<span style="display:inline-block;background:' . $bg . ';color:' . $color
            . ';padding:4px 10px;border-radius:12px;margin:2px;font-size:0.9em;">'
            . e($tag['label']) . '</span>';
    }
}
?>
<main>
    <div class="card">
        <a href="<?php echo getBasePath(); ?>admin/view_feedback.php">&larr; Back to Feedback</a>
        <h2>Feedback #<?php echo e($feedback['id']); ?></h2>

        <div class="test-section" style="background:#f9f9f9;border-left:4px solid #ff6600;padding:12px;margin:16px 0;">
            <h3>Submitted By</h3>
            <p><strong>Customer:</strong> <?php echo e($feedback['customer_name'] ?? 'Deleted account'); ?></p>
            <p><strong>Date:</strong> <?php echo e(date('M j, Y g:i A', strtotime($feedback['submitted_at']))); ?></p>
            <p><strong>"Maybe Later" dismissals before submitting:</strong> <?php echo e($feedback['dismissed_count']); ?></p>
        </div>

        <div class="test-section" style="background:#f9f9f9;border-left:4px solid #ff6600;padding:12px;margin:16px 0;">
            <h3>Ratings</h3>
            <p>
                <strong>Mechanic Rating:</strong>
                <?php echo $feedback['mechanic_rating'] !== null ? str_repeat('★', (int) $feedback['mechanic_rating']) . str_repeat('☆', 5 - (int) $feedback['mechanic_rating']) . ' (' . e($feedback['mechanic_rating']) . '/5)' : 'Not rated'; ?>
            </p>
            <p>
                <strong>Service Rating:</strong>
                <?php echo $feedback['service_rating'] !== null ? str_repeat('★', (int) $feedback['service_rating']) . str_repeat('☆', 5 - (int) $feedback['service_rating']) . ' (' . e($feedback['service_rating']) . '/5)' : 'Not rated'; ?>
            </p>

            <p><strong>Mechanic feedback tags:</strong><br><?php renderTagPills($mechanicTagList); ?></p>
            <p><strong>Service feedback tags:</strong><br><?php renderTagPills($serviceTagList); ?></p>

            <?php if (!empty($feedback['comments'])): ?>
                <p><strong>Additional comments:</strong></p>
                <p style="white-space:pre-wrap;"><?php echo e($feedback['comments']); ?></p>
            <?php endif; ?>
        </div>

        <div class="test-section" style="background:#f9f9f9;border-left:4px solid #ff6600;padding:12px;margin:16px 0;">
            <h3>Mechanic</h3>
            <p><strong>Name:</strong> <?php echo e($feedback['mechanic_name'] ?? 'N/A'); ?></p>
        </div>

        <div class="test-section" style="background:#f9f9f9;border-left:4px solid #ff6600;padding:12px;margin:16px 0;">
            <h3>Request Details</h3>
            <?php if (!$feedback['history_id']): ?>
                <p class="muted">The original job record is unavailable for this feedback entry.</p>
            <?php else: ?>
                <p><strong>Customer type:</strong> <?php echo $feedback['is_walkin'] ? 'Walk-in' : 'Registered customer'; ?></p>
                <p><strong>Problem type:</strong> <?php echo e($feedback['problem_type']); ?></p>
                <p><strong>Description:</strong> <?php echo nl2br(e($feedback['job_description'])); ?></p>
                <p><strong>Diagnosis:</strong> <?php echo nl2br(e($feedback['diagnosis'] ?? '—')); ?></p>
                <p><strong>Status:</strong>
                    <span class="status status-<?php echo e($feedback['job_status']); ?>">
                        <?php echo e(ucfirst($feedback['job_status'])); ?>
                    </span>
                </p>
                <?php if ($feedback['job_status'] === 'rejected' && !empty($feedback['rejection_reason'])): ?>
                    <p><strong>Rejection reason:</strong> <?php echo e($feedback['rejection_reason']); ?></p>
                <?php endif; ?>
                <p><strong>Requested on:</strong> <?php echo $feedback['request_created_at'] ? e(date('M j, Y g:i A', strtotime($feedback['request_created_at']))) : '—'; ?></p>
                <p><strong>Completed on:</strong> <?php echo $feedback['completed_at'] ? e(date('M j, Y g:i A', strtotime($feedback['completed_at']))) : '—'; ?></p>

                <h3>Service Items &amp; Price</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($serviceItems)): ?>
                            <tr>
                                <td colspan="2" style="text-align:center;color:var(--muted);padding:12px;">No service items recorded.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($serviceItems as $item): ?>
                                <tr>
                                    <td><?php echo e($item['item_name']); ?></td>
                                    <td>$<?php echo number_format((float) $item['price'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <tr>
                            <td style="text-align:right;"><strong>Total</strong></td>
                            <td><strong>$<?php echo number_format((float) $feedback['total_amount'], 2); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php';
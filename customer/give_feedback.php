<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pdo = getPDO();
$userId = $_SESSION['user_id'];

if (!isset($_GET['history_id']) || !ctype_digit($_GET['history_id'])) {
    header('Location: ' . getBasePath() . 'customer/dashboard.php');
    exit;
}

$historyId = (int) $_GET['history_id'];

// ----------------------
// VERIFY OWNERSHIP + ELIGIBILITY
// ----------------------
$stmt = $pdo->prepare(
    'SELECT h.id, h.problem_type, h.mechanic_id, h.mechanic_name, h.completed_at, '
    . 'h.request_id, h.feedback_dismiss_count, '
    . '(SELECT COUNT(*) FROM feedback f WHERE f.history_id = h.id) AS already_submitted '
    . 'FROM history_records h '
    . 'WHERE h.id = :hid AND h.user_id = :uid AND h.status = "completed"'
);
$stmt->execute([':hid' => $historyId, ':uid' => $userId]);
$job = $stmt->fetch();

if (!$job) {
    header('Location: ' . getBasePath() . 'customer/dashboard.php');
    exit;
}

if ((int) $job['already_submitted'] > 0) {
    header('Location: ' . getBasePath() . 'customer/dashboard.php?feedback_done=1');
    exit;
}

$error = '';
const COMMENT_MAX_LENGTH = 1000;

// ----------------------
// HANDLE SUBMISSION
// ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    $mechanicRating = ctype_digit((string) ($_POST['mechanic_rating'] ?? '')) ? (int) $_POST['mechanic_rating'] : null;
    $serviceRating  = ctype_digit((string) ($_POST['service_rating'] ?? ''))  ? (int) $_POST['service_rating']  : null;
    $comments       = trim((string) ($_POST['comments'] ?? ''));
    $mechanicTagIds = array_filter(array_map('intval', $_POST['mechanic_tags'] ?? []));
    $serviceTagIds  = array_filter(array_map('intval', $_POST['service_tags'] ?? []));

    if ($mechanicRating === null && $serviceRating === null) {
        $error = 'Please provide at least one rating (mechanic or service).';
    } elseif ($mechanicRating !== null && ($mechanicRating < 1 || $mechanicRating > 5)) {
        $error = 'Mechanic rating must be between 1 and 5.';
    } elseif ($serviceRating !== null && ($serviceRating < 1 || $serviceRating > 5)) {
        $error = 'Service rating must be between 1 and 5.';
    } elseif (mb_strlen($comments) > COMMENT_MAX_LENGTH) {
        $error = 'Comments cannot exceed ' . COMMENT_MAX_LENGTH . ' characters.';
    } else {
        $nameStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = :id');
        $nameStmt->execute([':id' => $userId]);
        $customerName = $nameStmt->fetchColumn();

        $dismissedCount = (int) $job['feedback_dismiss_count'];

        $pdo->beginTransaction();
        try {
            $insertStmt = $pdo->prepare(
                'INSERT INTO feedback '
                . '(request_id, history_id, user_id, customer_name, mechanic_id, mechanic_name, '
                . 'mechanic_rating, service_rating, comments, dismissed_count) '
                . 'VALUES (:request_id, :history_id, :user_id, :customer_name, :mechanic_id, :mechanic_name, '
                . ':mechanic_rating, :service_rating, :comments, :dismissed_count)'
            );
            $insertStmt->execute([
                ':request_id'      => $job['request_id'],
                ':history_id'      => $historyId,
                ':user_id'         => $userId,
                ':customer_name'   => $customerName,
                ':mechanic_id'     => $job['mechanic_id'],
                ':mechanic_name'   => $job['mechanic_name'],
                ':mechanic_rating' => $mechanicRating,
                ':service_rating'  => $serviceRating,
                ':comments'        => $comments !== '' ? $comments : null,
                ':dismissed_count' => $dismissedCount,
            ]);

            $feedbackId = (int) $pdo->lastInsertId();

            $tagStmt = $pdo->prepare(
                'INSERT INTO feedback_tag_selections (feedback_id, tag_id) VALUES (:fid, :tid)'
            );
            foreach (array_merge($mechanicTagIds, $serviceTagIds) as $tagId) {
                $tagStmt->execute([':fid' => $feedbackId, ':tid' => $tagId]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Something went wrong while saving your feedback. Please try again.';
        }

        if (!$error) {
            header('Location: ' . getBasePath() . 'customer/dashboard.php?feedback_submitted=1');
            exit;
        }
    }
}

// ----------------------
// LOAD TAGS FOR FORM
// ----------------------
$tagsStmt = $pdo->query(
    'SELECT id, label, category FROM feedback_tags '
    . 'WHERE is_active = 1 ORDER BY category ASC, sort_order ASC'
);
$allTags = $tagsStmt->fetchAll();
$mechanicTagOptions = array_filter($allTags, static fn($t) => $t['category'] === 'mechanic');
$serviceTagOptions  = array_filter($allTags, static fn($t) => $t['category'] === 'service');

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <div class="card">
        <h2>Rate Your Experience</h2>
        <p class="muted">Job: <?php echo e($job['problem_type']); ?> — completed <?php echo e(date('M j, Y', strtotime($job['completed_at']))); ?></p>

        <?php if ($error): ?>
            <div style="background:#fdecea;border:1px solid #f5c2c0;color:#a94442;padding:12px;border-radius:6px;margin-bottom:16px;">
                <?php echo e($error); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>?history_id=<?php echo e($historyId); ?>">

            <div class="test-section" style="background:#f9f9f9;border-left:4px solid #ff6600;padding:14px;margin-bottom:16px;">
                <h3>Mechanic Performance<?php echo $job['mechanic_name'] ? ' — ' . e($job['mechanic_name']) : ''; ?></h3>
                <label>Rating
                    <select name="mechanic_rating">
                        <option value="">No rating</option>
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <option value="<?php echo $i; ?>"><?php echo str_repeat('★', $i) . str_repeat('☆', 5 - $i); ?></option>
                        <?php endfor; ?>
                    </select>
                </label>

                <p style="margin-top:10px;">What influenced your rating?</p>
                <?php foreach ($mechanicTagOptions as $tag): ?>
                    <label style="display:inline-block;font-weight:normal;margin-right:14px;">
                        <input type="checkbox" name="mechanic_tags[]" value="<?php echo e($tag['id']); ?>">
                        <?php echo e($tag['label']); ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="test-section" style="background:#f9f9f9;border-left:4px solid #ff6600;padding:14px;margin-bottom:16px;">
                <h3>Overall Service</h3>
                <label>Rating
                    <select name="service_rating">
                        <option value="">No rating</option>
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <option value="<?php echo $i; ?>"><?php echo str_repeat('★', $i) . str_repeat('☆', 5 - $i); ?></option>
                        <?php endfor; ?>
                    </select>
                </label>

                <p style="margin-top:10px;">What influenced your rating?</p>
                <?php foreach ($serviceTagOptions as $tag): ?>
                    <label style="display:inline-block;font-weight:normal;margin-right:14px;">
                        <input type="checkbox" name="service_tags[]" value="<?php echo e($tag['id']); ?>">
                        <?php echo e($tag['label']); ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <label>Additional comments (optional)
                <textarea name="comments" rows="4" maxlength="1000" placeholder="Tell us more..."></textarea>
            </label>

            <div style="margin-top:16px;">
                <button type="submit" name="submit_feedback" value="1" class="btn btn-primary">Submit Feedback</button>
            </div>
        </form>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php';
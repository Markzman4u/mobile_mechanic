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

// ----------------------
// READ FILTERS
// ----------------------
$sentiment = $_GET['sentiment'] ?? 'all';
$dateFrom  = $_GET['date_from'] ?? '';
$dateTo    = $_GET['date_to'] ?? '';

if (!in_array($sentiment, ['all', 'positive', 'negative', 'neutral'], true)) {
    $sentiment = 'all';
}

// Validate dates loosely (Y-m-d from <input type="date">)
$dateFromValid = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom);
$dateToValid   = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo);

// ----------------------
// BUILD QUERY
// ----------------------
// Sentiment classification:
//   negative -> either rating present and <= 2
//   positive -> both present ratings are >= 4 (and at least one rating exists)
//   neutral  -> anything else (e.g. a 3-star rating, or mixed without hitting the negative threshold)
$sentimentCase = "
    CASE
        WHEN (f.mechanic_rating IS NOT NULL AND f.mechanic_rating <= 2)
          OR (f.service_rating  IS NOT NULL AND f.service_rating  <= 2)
            THEN 'negative'
        WHEN (f.mechanic_rating IS NOT NULL OR f.service_rating IS NOT NULL)
         AND (f.mechanic_rating IS NULL OR f.mechanic_rating >= 4)
         AND (f.service_rating  IS NULL OR f.service_rating  >= 4)
            THEN 'positive'
        ELSE 'neutral'
    END
";

$sql = "
    SELECT
        f.id,
        f.customer_name,
        f.mechanic_name,
        f.mechanic_rating,
        f.service_rating,
        f.submitted_at,
        $sentimentCase AS sentiment
    FROM feedback f
    WHERE 1=1
";

$params = [];

if ($sentiment !== 'all') {
    $sql .= " HAVING sentiment = :sentiment";
    $params[':sentiment'] = $sentiment;
}

if ($dateFromValid) {
    $sql .= ($sentiment !== 'all' ? ' AND' : ' AND 1=1 AND') . ' DATE(f.submitted_at) >= :date_from';
    $params[':date_from'] = $dateFrom;
}

if ($dateToValid) {
    $sql .= ($sentiment !== 'all' || $dateFromValid ? ' AND' : ' AND 1=1 AND') . ' DATE(f.submitted_at) <= :date_to';
    $params[':date_to'] = $dateTo;
}

$sql .= ' ORDER BY f.submitted_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$feedbackList = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;">
            <div>
                <h2>Customer Feedback</h2>
                <p class="muted">Browse and filter feedback submitted by customers.</p>
            </div>
            <a href="<?php echo getBasePath(); ?>admin/manage_tags.php" class="btn btn-primary">Manage Feedback Tags</a>
        </div>

        <!-- Filter Bar -->
        <form method="get" action="<?php echo $_SERVER['PHP_SELF']; ?>">
            <div style="
                display: flex;
                flex-wrap: wrap;
                align-items: flex-end;
                gap: 12px;
                background: #f5f5f5;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 16px 18px;
                margin-bottom: 24px;
            ">
                <!-- Sentiment -->
                <div style="display:flex;flex-direction:column;gap:4px;min-width:140px;">
                    <label style="font-size:12px;font-weight:600;color:#555;text-transform:uppercase;letter-spacing:.5px;margin:0;">Sentiment</label>
                    <select name="sentiment" style="height:38px;padding:0 10px;border:1px solid #ccc;border-radius:6px;font-size:14px;background:#fff;">
                        <option value="all"      <?php echo $sentiment === 'all'      ? 'selected' : ''; ?>>All</option>
                        <option value="positive" <?php echo $sentiment === 'positive' ? 'selected' : ''; ?>>Positive</option>
                        <option value="negative" <?php echo $sentiment === 'negative' ? 'selected' : ''; ?>>Negative</option>
                        <option value="neutral"  <?php echo $sentiment === 'neutral'  ? 'selected' : ''; ?>>Neutral</option>
                    </select>
                </div>

                <!-- Divider -->
                <div style="width:1px;background:#d0d0d0;height:38px;align-self:flex-end;"></div>

                <!-- Date From -->
                <div style="display:flex;flex-direction:column;gap:4px;">
                    <label style="font-size:12px;font-weight:600;color:#555;text-transform:uppercase;letter-spacing:.5px;margin:0;">From</label>
                    <input type="date" name="date_from" value="<?php echo e($dateFrom); ?>"
                        style="height:38px;padding:0 10px;border:1px solid #ccc;border-radius:6px;font-size:14px;background:#fff;">
                </div>

                <!-- Date To -->
                <div style="display:flex;flex-direction:column;gap:4px;">
                    <label style="font-size:12px;font-weight:600;color:#555;text-transform:uppercase;letter-spacing:.5px;margin:0;">To</label>
                    <input type="date" name="date_to" value="<?php echo e($dateTo); ?>"
                        style="height:38px;padding:0 10px;border:1px solid #ccc;border-radius:6px;font-size:14px;background:#fff;">
                </div>

                <!-- Actions -->
                <div style="display:flex;gap:8px;align-self:flex-end;margin-left:auto;">
                    <button type="submit" class="btn btn-primary" style="height:38px;padding:0 20px;white-space:nowrap;">Apply Filters</button>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn" style="height:38px;padding:0 16px;display:inline-flex;align-items:center;white-space:nowrap;">Clear</a>
                </div>
            </div>
        </form>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Customer</th>
                    <th>Mechanic</th>
                    <th>Mechanic Rating</th>
                    <th>Service Rating</th>
                    <th>Sentiment</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($feedbackList)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;color:var(--muted);padding:20px;">No feedback found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($feedbackList as $fb): ?>
                        <tr>
                            <td><?php echo e($fb['id']); ?></td>
                            <td><?php echo e($fb['customer_name'] ?? 'Deleted account'); ?></td>
                            <td><?php echo e($fb['mechanic_name'] ?? 'N/A'); ?></td>
                            <td><?php echo $fb['mechanic_rating'] !== null ? e($fb['mechanic_rating']) . ' / 5' : '—'; ?></td>
                            <td><?php echo $fb['service_rating'] !== null ? e($fb['service_rating']) . ' / 5' : '—'; ?></td>
                            <td>
                                <span class="status status-<?php echo e($fb['sentiment']); ?>">
                                    <?php echo e(ucfirst($fb['sentiment'])); ?>
                                </span>
                            </td>
                            <td><?php echo e(date('M j, Y', strtotime($fb['submitted_at']))); ?></td>
                            <td>
                                <a href="<?php echo getBasePath(); ?>admin/feedback_detail.php?id=<?php echo e($fb['id']); ?>">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php';
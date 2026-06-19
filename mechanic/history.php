<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireMechanicLogin();

$pdo        = getPDO();
$mechanicId = getMechanicId();

// ── Date range filter ─────────────────────────────────────────────────────
// Day-level inputs, e.g. "2025-01-15" (YYYY-MM-DD)
$fromDate = trim($_GET['from'] ?? '');
$toDate   = trim($_GET['to']   ?? '');

// Validate format
$validDate = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($validDate, $fromDate)) $fromDate = '';
if (!preg_match($validDate, $toDate))   $toDate   = '';

// Search
$search = trim($_GET['search'] ?? '');

// ── Build WHERE ───────────────────────────────────────────────────────────
$where  = 'WHERE mechanic_id = :mechanic_id AND hidden_by_admin = FALSE AND status = "completed"';
$params = [':mechanic_id' => $mechanicId];

if ($fromDate !== '') {
    $where .= ' AND completed_at >= :from_date';
    $params[':from_date'] = $fromDate . ' 00:00:00';
}
if ($toDate !== '') {
    $where .= ' AND completed_at <= :to_date';
    $params[':to_date'] = $toDate . ' 23:59:59';
}
if ($search !== '') {
    $where .= ' AND (customer_name LIKE :s OR problem_type LIKE :s2)';
    $params[':s']  = '%' . $search . '%';
    $params[':s2'] = '%' . $search . '%';
}

$stmt = $pdo->prepare(
    "SELECT id, request_id, customer_name,
            problem_type, diagnosis, status,
            request_created_at, completed_at
     FROM history_records
     $where
     ORDER BY completed_at DESC"
);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Total count (unfiltered, for the header)
$totalStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM history_records
     WHERE mechanic_id = ? AND hidden_by_admin = FALSE AND status = "completed"'
);
$totalStmt->execute([$mechanicId]);
$totalCompleted = (int)$totalStmt->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* ── Filter bar ──────────────────────────────────────────────────────────── */
.filter-bar {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 20px;
    background: #f9f9f9;
    border: 1px solid #ebebeb;
    border-radius: 8px;
    padding: 14px 16px;
}
.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: flex-end;
}
.filter-row + .filter-row {
    padding-top: 10px;
    border-top: 1px solid #ebebeb;
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.filter-group.grow {
    flex: 1;
    min-width: 180px;
}
.filter-group label {
    display: block;
    height: 16px;
    line-height: 16px;
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #888;
    white-space: nowrap;
    overflow: hidden;
}
.filter-group label.spacer {
    /* Same fixed height as real labels so height is identical — text invisible */
    visibility: hidden;
    pointer-events: none;
}
.filter-group input[type="date"],
.filter-group input[type="text"] {
    height: 36px;
    padding: 0 10px;
    margin: 0;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: .88rem;
    font-family: inherit;
    background: #fff;
    min-width: 140px;
    box-sizing: border-box;
    width: 100%;
}
.filter-group input:focus {
    outline: none;
    border-color: var(--safety-orange, #ff6600);
}
.filter-actions-row {
    display: flex;
    align-items: center;
    gap: 6px;
    height: 36px;
}
/* Match button box model to the inputs above so heights line up exactly.
   !important + explicit reset of border/margin/line-height ensures the
   global .btn / .btn-primary rules (defined in style.css) can't add
   extra height via border width, margin, or a taller line-height. */
.filter-actions-row .btn,
.filter-actions-row .btn-primary {
    height: 36px !important;
    min-height: 36px !important;
    max-height: 36px !important;
    margin: 0 !important;
    padding: 0 16px !important;
    border-width: 1px !important;
    box-sizing: border-box !important;
    font-size: .88rem !important;
    line-height: 1 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    white-space: nowrap;
    vertical-align: middle;
}

/* ── Result meta ─────────────────────────────────────────────────────────── */
.result-meta {
    font-size: .82rem;
    color: var(--muted, #888);
    margin-bottom: 12px;
}
.result-meta strong { color: #333; }

/* ── Table ───────────────────────────────────────────────────────────────── */
.hist-table td, .hist-table th { vertical-align: middle; }

/* ── Diagnosis cell ──────────────────────────────────────────────────────── */
.diag-cell {
    max-width: 220px;
    font-size: .82rem;
    color: #555;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    word-break: break-word;
    white-space: normal;
}

/* ── Empty state ─────────────────────────────────────────────────────────── */
.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--muted, #aaa);
}
.empty-state .empty-icon { font-size: 2.4rem; margin-bottom: 10px; }
</style>

<main>
<div class="card">

    <a href="<?php echo getBasePath(); ?>mechanic/dashboard.php"
       style="display:inline-flex;align-items:center;gap:6px;font-size:.85rem;
              color:var(--muted,#888);text-decoration:none;margin-bottom:6px;">
        ← Back to Dashboard
    </a>
    <h2 style="margin:0 0 4px 0;">My Completed Jobs</h2>
    <p class="muted" style="margin:0 0 20px;">
        <?php echo $totalCompleted; ?> completed job<?php echo $totalCompleted !== 1 ? 's' : ''; ?> total.
    </p>

    <!-- ── Filter bar (two independent forms, stacked) ─────────────────── -->
    <div class="filter-bar">

        <!-- Row 1: Date range -->
        <form method="get" class="filter-row">
            <div class="filter-group">
                <label>From</label>
                <input type="date" name="from"
                       value="<?php echo e($fromDate); ?>">
            </div>
            <div class="filter-group">
                <label>To</label>
                <input type="date" name="to"
                       value="<?php echo e($toDate); ?>">
            </div>
            <div class="filter-group">
                <label class="spacer">Actions</label>
                <div class="filter-actions-row">
                    <button type="submit" class="btn btn-primary">Filter by Date</button>
                    <?php if ($fromDate || $toDate): ?>
                        <a href="<?php echo getBasePath(); ?>mechanic/history.php<?php echo $search ? '?search=' . urlencode($search) : ''; ?>"
                           class="btn">Clear</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <!-- Row 2: Search -->
        <form method="get" class="filter-row">
            <div class="filter-group grow">
                <label>Search</label>
                <input type="text" name="search"
                       value="<?php echo e($search); ?>"
                       placeholder="Customer or problem…">
            </div>
            <div class="filter-group">
                <label class="spacer">Actions</label>
                <div class="filter-actions-row">
                    <button type="submit" class="btn btn-primary">Search</button>
                    <?php if ($search): ?>
                        <a href="<?php echo getBasePath(); ?>mechanic/history.php<?php echo ($fromDate || $toDate) ? '?' . http_build_query(array_filter(['from' => $fromDate, 'to' => $toDate])) : ''; ?>"
                           class="btn">Clear</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

    </div>

    <!-- ── Result count ─────────────────────────────────────────────────── -->
    <?php if ($fromDate || $toDate || $search): ?>
    <p class="result-meta">
        Showing <strong><?php echo count($records); ?></strong> result<?php echo count($records) !== 1 ? 's' : ''; ?>
        <?php if ($fromDate && $toDate): ?>
            from <strong><?php echo date('M d, Y', strtotime($fromDate)); ?></strong>
            to <strong><?php echo date('M d, Y', strtotime($toDate)); ?></strong>
        <?php elseif ($fromDate): ?>
            from <strong><?php echo date('M d, Y', strtotime($fromDate)); ?></strong>
        <?php elseif ($toDate): ?>
            up to <strong><?php echo date('M d, Y', strtotime($toDate)); ?></strong>
        <?php endif; ?>
        <?php if ($search): ?>— matching "<strong><?php echo e($search); ?></strong>"<?php endif; ?>
    </p>
    <?php endif; ?>

    <!-- ── Table ────────────────────────────────────────────────────────── -->
    <?php if (empty($records)): ?>
        <div class="empty-state">
            <div class="empty-icon">📋</div>
            <p style="margin:0;font-weight:600;">No completed jobs found.</p>
            <p class="muted" style="font-size:.85rem;margin:4px 0 0;">
                <?php echo ($fromDate || $toDate || $search)
                    ? 'Try adjusting the filters.'
                    : 'Your completed jobs will appear here.'; ?>
            </p>
        </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="hist-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Customer</th>
                <th>Problem</th>
                <th>Diagnosis</th>
                <th>Completed</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($records as $r): ?>
            <tr>
                <td style="font-size:.8rem;color:var(--muted,#888);">
                    #<?php echo (int)$r['request_id']; ?>
                </td>
                <td>
                    <strong><?php echo e($r['customer_name'] ?: '—'); ?></strong>
                </td>
                <td><?php echo e($r['problem_type'] ?: '—'); ?></td>
                <td>
                    <?php if (!empty($r['diagnosis'])): ?>
                        <span class="diag-cell" title="<?php echo e($r['diagnosis']); ?>">
                            <?php echo e($r['diagnosis']); ?>
                        </span>
                    <?php else: ?>
                        <span class="muted" style="font-size:.8rem;">—</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:.8rem;color:var(--muted,#888);white-space:nowrap;">
                    <?php echo $r['completed_at']
                        ? date('M d, Y', strtotime($r['completed_at']))
                        : '—'; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

</div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (!isAdmin()) {
    header('Location: ' . getBasePath() . 'auth/login.php');
    exit;
}

$pdo     = getPDO();
$baseUrl = getBasePath();
$selfUrl = $baseUrl . 'admin/payments.php';
$adminId = $_SESSION['user_id'];
$error   = '';

// ── POST: Record Payment ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    $historyId = (int)($_POST['history_id'] ?? 0);
    $amount    = trim($_POST['amount']    ?? '');
    $method    = trim($_POST['method']    ?? '');
    $reference = trim($_POST['reference'] ?? '');
    $notes     = trim($_POST['notes']     ?? '');

    $allowedMethods = ['cash','mobile_money','bank_transfer','card','other'];

    if (!$historyId || !is_numeric($amount) || $amount <= 0 || !in_array($method, $allowedMethods)) {
        $error = 'Please fill in all required fields correctly.';
    } else {
        $stmt = $pdo->prepare("SELECT id, total_amount, payment_status FROM history_records WHERE id = :id AND status = 'completed'");
        $stmt->execute([':id' => $historyId]);
        $check = $stmt->fetch();

        if (!$check) {
            $error = 'Invalid or non-existent record.';
        } elseif ($check['payment_status'] === 'paid') {
            $error = 'This record has already been marked as paid.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    "INSERT INTO payments (history_id, amount_paid, payment_method, payment_reference, recorded_by, notes)
                     VALUES (:history_id, :amount, :method, :reference, :recorded_by, :notes)"
                );
                $stmt->execute([
                    ':history_id'  => $historyId,
                    ':amount'      => $amount,
                    ':method'      => $method,
                    ':reference'   => $reference ?: null,
                    ':recorded_by' => $adminId,
                    ':notes'       => $notes ?: null,
                ]);
                $paymentId = $pdo->lastInsertId();

                $stmt = $pdo->prepare(
                    "UPDATE history_records
                     SET payment_status    = 'paid',
                         payment_method    = :method,
                         payment_reference = :reference
                     WHERE id = :id"
                );
                $stmt->execute([
                    ':method'    => $method,
                    ':reference' => $reference ?: null,
                    ':id'        => $historyId,
                ]);

                $ledgerNote = "Payment for job #$historyId" . ($notes ? " — $notes" : '');
                $stmt = $pdo->prepare(
                    "INSERT INTO balance_ledger (type, direction, amount, reference_id, notes, created_by)
                     VALUES ('payment_in', 'in', :amount, :ref_id, :notes, :created_by)"
                );
                $stmt->execute([
                    ':amount'     => $amount,
                    ':ref_id'     => $paymentId,
                    ':notes'      => $ledgerNote,
                    ':created_by' => $adminId,
                ]);

                $pdo->commit();

                header('Location: ' . $baseUrl . 'admin/receipt.php?id=' . $historyId);
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Transaction failed. Please try again.';
            }
        }
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$activeTab    = ($_GET['tab'] ?? '') === 'paid' ? 'paid' : 'unpaid';
$filterSearch = trim($_GET['q']      ?? '');
$filterMethod = ($activeTab === 'paid') ? (trim($_GET['method'] ?? '')) : '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 15;
$offset       = ($page - 1) * $perPage;

$where  = ["hr.status = 'completed'"];
$params = [];

if ($activeTab === 'unpaid') {
    $where[] = "hr.payment_status = 'unpaid'";
} else {
    $where[] = "hr.payment_status = 'paid'";
}

if ($filterSearch !== '') {
    $where[]       = "(hr.customer_name LIKE :q1 OR hr.mechanic_name LIKE :q2 OR hr.id LIKE :q3)";
    $like          = "%$filterSearch%";
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
}

if ($filterMethod && in_array($filterMethod, ['cash','mobile_money','bank_transfer','card','other'])) {
    $where[]           = "hr.payment_method = :method";
    $params[':method'] = $filterMethod;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM history_records hr $whereSQL");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();

$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);

$stmt = $pdo->prepare(
    "SELECT hr.id, hr.customer_name, hr.customer_phone, hr.mechanic_name,
            hr.vehicle_make, hr.vehicle_model, hr.vehicle_year,
            hr.problem_type, hr.total_amount, hr.payment_status,
            hr.payment_method, hr.payment_reference, hr.completed_at,
            p.amount_paid, p.paid_at,
            u.full_name AS recorded_by_name
     FROM history_records hr
     LEFT JOIN payments p ON p.history_id = hr.id
     LEFT JOIN users    u ON u.id = p.recorded_by
     $whereSQL
     ORDER BY hr.completed_at DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$records = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT
        SUM(CASE WHEN payment_status='unpaid' THEN 1 ELSE 0 END)            AS unpaid_count,
        SUM(CASE WHEN payment_status='unpaid' THEN total_amount ELSE 0 END) AS unpaid_amount,
        SUM(CASE WHEN payment_status='paid'   THEN 1 ELSE 0 END)            AS paid_count,
        SUM(CASE WHEN payment_status='paid'   THEN total_amount ELSE 0 END) AS paid_amount
     FROM history_records
     WHERE status = 'completed'"
);
$stmt->execute();
$summary = $stmt->fetch();

$unpaidCount = (int)($summary['unpaid_count'] ?? 0);
$paidCount   = (int)($summary['paid_count']   ?? 0);
$totalCount  = $unpaidCount + $paidCount;

function tabHref(string $selfUrl, string $tab, string $search, string $method = ''): string {
    $p = ['tab' => $tab];
    if ($search !== '') $p['q'] = $search;
    if ($tab === 'paid' && $method !== '') $p['method'] = $method;
    return $selfUrl . '?' . http_build_query($p);
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<style>
/* ── Stat cards ─────────────────────────────────────────────────────────── */
.pay-stat-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(175px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}
.pay-stat-card {
    background: #fff;
    border-radius: 8px;
    padding: 18px 20px;
    box-shadow: 0 1px 4px rgba(0,0,0,.07);
    border-top: 3px solid #ff6600;
}
.pay-stat-card.green  { border-top-color: #28a745; }
.pay-stat-card.blue   { border-top-color: #1565c0; }
.pay-stat-card .stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #222;
    line-height: 1.1;
}
.pay-stat-card.accent .stat-value { color: #ff6600; }
.pay-stat-card.green  .stat-value { color: #28a745; }
.pay-stat-card .stat-label {
    font-size: .76rem;
    color: #777;
    margin-top: 4px;
    text-transform: uppercase;
    letter-spacing: .04em;
    font-weight: 600;
}
.pay-stat-card .stat-sub {
    font-size: .82rem;
    color: #999;
    margin-top: 6px;
}

/* ── Filter row ─────────────────────────────────────────────────────────── */
.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
}
.filter-search {
    flex: 1 1 200px;        /* grows, but never below 200px */
    min-width: 200px;
    display: flex;
    flex-direction: column;
}
.filter-status {
    flex: 0 0 180px;        /* fixed width, never grows or shrinks */
    display: flex;
    flex-direction: column;
}
.filter-actions {
    flex: 0 0 auto;         /* hug its content; never flex-grows into neighbours */
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
}
/* shared label sizing so all columns reach the same baseline */
.filter-search label,
.filter-status label {
    font-size: .85rem;
    font-weight: 600;
    color: #333;
    margin-bottom: 6px;
    line-height: 1.4;
    display: block;
    white-space: nowrap;
}
/* invisible spacer that matches the label height above the buttons */
.filter-actions .label-spacer {
    display: block;
    visibility: hidden;
    font-size: .85rem;
    font-weight: 600;
    margin-bottom: 6px;
    line-height: 1.4;
    white-space: nowrap;
}
.filter-actions .btn-row {
    display: flex;
    gap: 8px;
    flex-wrap: nowrap;
}

/* ── Modal overlay ──────────────────────────────────────────────────────── */
#payModal {
    position: fixed !important;
    inset: 0 !important;
    z-index: 9999 !important;
    background: rgba(0,0,0,0.55) !important;
    display: none;
    align-items: center;
    justify-content: center;
}
#payModal .modal {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 8px 40px rgba(0,0,0,0.22);
    width: 100%;
    max-width: 480px;
    animation: modalIn .18s ease;
}
@keyframes modalIn {
    from { transform: translateY(-18px); opacity: 0; }
    to   { transform: translateY(0);     opacity: 1; }
}

/* ── Tabs ───────────────────────────────────────────────────────────────── */
.pay-tabs {
    display: flex;
    gap: 0;
    border-bottom: 2px solid #e5e5e5;
    margin-bottom: 0;
}
.pay-tab {
    padding: 10px 22px;
    font-size: 0.93rem;
    font-weight: 600;
    color: #666;
    cursor: pointer;
    border: none;
    background: none;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 7px;
    transition: color .15s;
}
.pay-tab:hover { color: #ff6600; }
.pay-tab.active {
    color: #ff6600;
    border-bottom-color: #ff6600;
}
.pay-tab .tab-badge {
    border-radius: 12px;
    font-size: 0.72rem;
    padding: 1px 7px;
    font-weight: 700;
    min-width: 22px;
    text-align: center;
    color: #fff;
}
.pay-tab.active     .tab-badge { background: #ff6600; }
.pay-tab:not(.active) .tab-badge { background: #bbb; }
</style>

<div class="layout-wrapper">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main-content">

<div class="page-header">
    <div>
        <h1>Payments</h1>
        <p class="muted">Record and track customer payments for completed jobs</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo e($error); ?></div>
<?php endif; ?>

<!-- ── Summary Stat Cards ─────────────────────────────────────────────────── -->
<div class="pay-stat-row">
    <div class="pay-stat-card accent">
        <div class="stat-value"><?php echo number_format($unpaidCount); ?></div>
        <div class="stat-label">Unpaid Jobs</div>
        <div class="stat-sub">$<?php echo number_format($summary['unpaid_amount'] ?? 0, 2); ?> outstanding</div>
    </div>
    <div class="pay-stat-card green">
        <div class="stat-value"><?php echo number_format($paidCount); ?></div>
        <div class="stat-label">Paid Jobs</div>
        <div class="stat-sub">$<?php echo number_format($summary['paid_amount'] ?? 0, 2); ?> collected</div>
    </div>
    <div class="pay-stat-card blue">
        <div class="stat-value"><?php echo number_format($totalCount); ?></div>
        <div class="stat-label">Total Completed</div>
        <div class="stat-sub">all time</div>
    </div>
    <?php $collectionRate = $totalCount > 0 ? round(($paidCount / $totalCount) * 100) : 0; ?>
    <div class="pay-stat-card">
        <div class="stat-value"><?php echo $collectionRate; ?>%</div>
        <div class="stat-label">Collection Rate</div>
        <div class="stat-sub"><?php echo $paidCount; ?> of <?php echo $totalCount; ?> jobs paid</div>
    </div>
</div>

<!-- ── Filters ───────────────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:20px;">
    <form method="GET" action="<?php echo $selfUrl; ?>">
        <input type="hidden" name="tab" value="<?php echo e($activeTab); ?>">
        <div class="filter-row">

            <div class="filter-search">
                <label for="filter_q">Search</label>
                <input type="text" id="filter_q" name="q" class="form-control"
                       placeholder="Customer, mechanic, ID&hellip;"
                       value="<?php echo e($filterSearch); ?>">
            </div>

            <?php if ($activeTab === 'paid'): ?>
            <div class="filter-status">
                <label for="filter_method">Method</label>
                <select id="filter_method" name="method" class="form-control">
                    <option value="">All Methods</option>
                    <option value="cash"          <?php echo $filterMethod==='cash'          ?'selected':''; ?>>Cash</option>
                    <option value="mobile_money"  <?php echo $filterMethod==='mobile_money'  ?'selected':''; ?>>Mobile Money</option>
                    <option value="bank_transfer" <?php echo $filterMethod==='bank_transfer' ?'selected':''; ?>>Bank Transfer</option>
                    <option value="card"          <?php echo $filterMethod==='card'          ?'selected':''; ?>>Card</option>
                    <option value="other"         <?php echo $filterMethod==='other'         ?'selected':''; ?>>Other</option>
                </select>
            </div>
            <?php endif; ?>

            <div class="filter-actions">
                <span class="label-spacer" aria-hidden="true">x</span>
                <div class="btn-row">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="<?php echo $selfUrl; ?>?tab=<?php echo e($activeTab); ?>" class="btn">Reset</a>
                </div>
            </div>

        </div>
    </form>
</div>

<!-- ── Tabs + Table Card ─────────────────────────────────────────────────── -->
<div class="card" style="padding:0;overflow:hidden;">

    <div style="padding:0 20px;border-bottom:2px solid #e5e5e5;background:#fafafa;">
        <div class="pay-tabs">
            <a href="<?php echo tabHref($selfUrl, 'unpaid', $filterSearch); ?>"
               class="pay-tab <?php echo $activeTab==='unpaid'?'active':''; ?>">
                Unpaid
                <span class="tab-badge"><?php echo $unpaidCount; ?></span>
            </a>
            <a href="<?php echo tabHref($selfUrl, 'paid', $filterSearch, $filterMethod); ?>"
               class="pay-tab <?php echo $activeTab==='paid'?'active':''; ?>">
                Paid
                <span class="tab-badge"><?php echo $paidCount; ?></span>
            </a>
        </div>
    </div>

    <div style="padding:20px;">
        <?php if (empty($records)): ?>
            <p class="muted" style="text-align:center;padding:32px 0;">
                <?php echo $activeTab === 'unpaid' ? 'No unpaid jobs found.' : 'No paid jobs found.'; ?>
            </p>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Customer</th>
                        <th>Mechanic</th>
                        <th>Vehicle</th>
                        <th>Problem</th>
                        <th>Amount</th>
                        <?php if ($activeTab === 'paid'): ?>
                            <th>Method</th>
                            <th>Paid At</th>
                            <th>Recorded By</th>
                        <?php else: ?>
                            <th>Completed</th>
                        <?php endif; ?>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($records as $r): ?>
                    <tr>
                        <td><span class="muted">#<?php echo e($r['id']); ?></span></td>
                        <td>
                            <strong><?php echo e($r['customer_name']); ?></strong>
                            <?php if ($r['customer_phone']): ?>
                                <br><span class="muted" style="font-size:0.82rem;"><?php echo e($r['customer_phone']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e($r['mechanic_name'] ?: '&mdash;'); ?></td>
                        <td style="font-size:0.88rem;">
                            <?php
                                $veh = trim(($r['vehicle_make'] ?? '') . ' ' . ($r['vehicle_model'] ?? ''));
                                echo $veh
                                    ? e($veh) . ($r['vehicle_year'] ? ' <span class="muted">(' . e($r['vehicle_year']) . ')</span>' : '')
                                    : '&mdash;';
                            ?>
                        </td>
                        <td style="font-size:0.88rem;"><?php echo e($r['problem_type'] ?: '&mdash;'); ?></td>
                        <td><strong>$<?php echo number_format($r['total_amount'] ?? 0, 2); ?></strong></td>

                        <?php if ($activeTab === 'paid'): ?>
                            <td>
                                <span class="status status-completed" style="font-size:0.78rem;text-transform:capitalize;">
                                    <?php echo e(str_replace('_', ' ', $r['payment_method'] ?? '&mdash;')); ?>
                                </span>
                                <?php if ($r['payment_reference']): ?>
                                    <br><span class="muted" style="font-size:0.76rem;"><?php echo e($r['payment_reference']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.84rem;white-space:nowrap;">
                                <?php echo $r['paid_at'] ? date('d M Y', strtotime($r['paid_at'])) : '&mdash;'; ?>
                            </td>
                            <td style="font-size:0.84rem;"><?php echo e($r['recorded_by_name'] ?? '&mdash;'); ?></td>
                        <?php else: ?>
                            <td style="font-size:0.84rem;white-space:nowrap;">
                                <?php echo $r['completed_at'] ? date('d M Y', strtotime($r['completed_at'])) : '&mdash;'; ?>
                            </td>
                        <?php endif; ?>

                        <td>
                            <?php if ($activeTab === 'unpaid'): ?>
                                <button class="btn btn-primary btn-sm"
                                        onclick="openPayModal(<?php echo (int)$r['id']; ?>, <?php echo (float)$r['total_amount']; ?>, '<?php echo e(addslashes($r['customer_name'])); ?>')">
                                    Record Payment
                                </button>
                            <?php else: ?>
                                <a href="<?php echo $baseUrl; ?>admin/receipt.php?id=<?php echo (int)$r['id']; ?>"
                                   class="btn btn-primary btn-sm">
                                    View Receipt
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div style="display:flex;justify-content:center;gap:6px;margin-top:20px;flex-wrap:wrap;">
            <?php
            $qp = ['tab' => $activeTab];
            if ($filterSearch !== '')  $qp['q']      = $filterSearch;
            if ($filterMethod !== '')  $qp['method']  = $filterMethod;
            for ($i = 1; $i <= $totalPages; $i++):
                $qp['page'] = $i;
                $cls = ($i === $page) ? 'btn btn-primary btn-sm' : 'btn btn-sm';
            ?>
                <a href="<?php echo $selfUrl . '?' . http_build_query($qp); ?>" class="<?php echo $cls; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

</main>
</div>

<!-- ── Record Payment Modal ──────────────────────────────────────────────────── -->
<div id="payModal">
    <div class="modal">
        <div class="modal-header">
            <h3 style="margin:0;">Record Payment</h3>
            <button class="modal-close" onclick="closeModal()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#666;">&times;</button>
        </div>
        <form method="POST" action="<?php echo $selfUrl; ?>">
            <input type="hidden" name="action"     value="record_payment">
            <input type="hidden" name="history_id" id="pay_history_id">
            <div class="modal-body" style="padding:20px;">
                <p style="margin-bottom:16px;padding:10px 14px;background:#fff3e0;border-radius:6px;border-left:3px solid #ff6600;">
                    Customer: <strong id="pay_customer_name"></strong>
                </p>
                <div class="form-group">
                    <label class="form-label">Amount Paid <span style="color:#ff6600;">*</span></label>
                    <input type="number" name="amount" id="pay_amount" class="form-control"
                           step="0.01" min="0.01" required placeholder="0.00">
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Method <span style="color:#ff6600;">*</span></label>
                    <select name="method" class="form-control" required>
                        <option value="">&#8212; Select &#8212;</option>
                        <option value="cash">Cash</option>
                        <option value="mobile_money">Mobile Money</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="card">Card</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Reference / Transaction ID</label>
                    <input type="text" name="reference" class="form-control"
                           placeholder="e.g. EVC+ transaction ID (optional)">
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"
                              placeholder="Optional notes&hellip;"></textarea>
                </div>
            </div>
            <div class="modal-footer" style="padding:16px 20px;display:flex;justify-content:flex-end;gap:10px;border-top:1px solid #eee;">
                <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Confirm Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPayModal(historyId, amount, customerName) {
    document.getElementById('pay_history_id').value          = historyId;
    document.getElementById('pay_amount').value              = amount.toFixed(2);
    document.getElementById('pay_customer_name').textContent = customerName;

    var modal = document.getElementById('payModal');
    modal.style.display = 'flex';
    void modal.offsetWidth;
}

function closeModal() {
    document.getElementById('payModal').style.display = 'none';
}

document.getElementById('payModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
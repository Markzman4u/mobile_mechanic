<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();

$pdo    = getPDO();
$userId = $_SESSION['user_id'];
$msg    = $_GET['msg'] ?? '';
$errors = [];

// ── POST: handle top-up submission ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = isset($_POST['amount']) ? trim($_POST['amount']) : '';
    $notes  = isset($_POST['notes'])  ? trim($_POST['notes'])  : null;

    if (!is_numeric($amount) || floatval($amount) <= 0) {
        $errors[] = 'Please enter a valid amount greater than zero.';
    }

    if (empty($errors)) {
        $amount = round(floatval($amount), 2);
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO balance_ledger (type, direction, amount, reference_id, notes, created_by)
                VALUES ('top_up', 'in', :amount, NULL, :notes, :created_by)
            ");
            $stmt->execute([
                ':amount'     => $amount,
                ':notes'      => $notes ?: null,
                ':created_by' => $userId,
            ]);
            $pdo->commit();
            $baseUrl = getBasePath();
            header('Location: ' . $baseUrl . 'super_admin/topup.php?msg=topup_success&tab=topups');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Database error. Please try again.';
        }
    }
}

// ── Balance summary ──────────────────────────────────────────────────────────
$balanceRow = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN direction = 'in'  THEN amount ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) AS total_out
    FROM balance_ledger
")->fetch(PDO::FETCH_ASSOC);

$totalIn  = floatval($balanceRow['total_in']);
$totalOut = floatval($balanceRow['total_out']);
$balance  = $totalIn - $totalOut;

// ── Per-category totals (for summary bar) ───────────────────────────────────
$catTotals = $pdo->query("
    SELECT type, COALESCE(SUM(amount), 0) AS total
    FROM balance_ledger
    GROUP BY type
")->fetchAll(PDO::FETCH_KEY_PAIR);

$totalRevenue   = floatval($catTotals['payment_in']         ?? 0);
$totalTopUps    = floatval($catTotals['top_up']             ?? 0);
$totalExpenses  = floatval($catTotals['expense']            ?? 0);
$totalPayroll   = floatval($catTotals['payroll']            ?? 0);
$totalInventory = floatval($catTotals['inventory_purchase'] ?? 0);

$netProfit = $totalRevenue - ($totalExpenses + $totalPayroll + $totalInventory);

// ── Data: Top-Ups ────────────────────────────────────────────────────────────
$topups = $pdo->query("
    SELECT bl.id, bl.amount, bl.notes, bl.created_at,
           u.full_name AS recorded_by_name
    FROM balance_ledger bl
    LEFT JOIN users u ON u.id = bl.created_by
    WHERE bl.type = 'top_up'
    ORDER BY bl.created_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// ── Data: Customer Payments (revenue) ───────────────────────────────────────
$revenues = $pdo->query("
    SELECT bl.id, bl.amount, bl.created_at,
           hr.customer_name, hr.mechanic_name,
           p.payment_method, p.payment_reference,
           u.full_name AS recorded_by_name
    FROM balance_ledger bl
    LEFT JOIN payments p         ON p.id  = bl.reference_id
    LEFT JOIN history_records hr ON hr.id = p.history_id
    LEFT JOIN users u            ON u.id  = bl.created_by
    WHERE bl.type = 'payment_in'
    ORDER BY bl.created_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// ── Data: Expenses ───────────────────────────────────────────────────────────
$expenses = $pdo->query("
    SELECT bl.id, bl.amount, bl.created_at, bl.notes AS bl_notes,
           e.category, e.vendor_name, e.expense_date,
           e.payment_method, e.payment_reference, e.notes AS exp_notes,
           u.full_name AS recorded_by_name
    FROM balance_ledger bl
    LEFT JOIN expenses e ON e.id = bl.reference_id
    LEFT JOIN users u    ON u.id = bl.created_by
    WHERE bl.type = 'expense'
    ORDER BY bl.created_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// ── Data: Payroll ────────────────────────────────────────────────────────────
$payrolls = $pdo->query("
    SELECT bl.id, bl.amount, bl.created_at,
           pr.employee_name, pr.target_type,
           pr.period_start, pr.period_end,
           pr.base_salary, pr.commission_earnings, pr.deductions,
           pr.payment_method, pr.payment_reference,
           u.full_name AS recorded_by_name
    FROM balance_ledger bl
    LEFT JOIN payroll_records pr ON pr.id = bl.reference_id
    LEFT JOIN users u            ON u.id  = bl.created_by
    WHERE bl.type = 'payroll'
    ORDER BY bl.created_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// ── Data: Inventory Purchases ────────────────────────────────────────────────
$inventory = $pdo->query("
    SELECT bl.id, bl.amount, bl.created_at,
           pcl.action, pcl.quantity_change, pcl.cost_per_unit, pcl.role_at_time,
           pc.name AS part_name, pc.sku,
           u.full_name AS recorded_by_name
    FROM balance_ledger bl
    LEFT JOIN parts_catalog_log pcl ON pcl.balance_ledger_id = bl.id
    LEFT JOIN parts_catalog pc      ON pc.id  = pcl.part_id
    LEFT JOIN users u               ON u.id   = bl.created_by
    WHERE bl.type = 'inventory_purchase'
    ORDER BY bl.created_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

$activeTab = $_GET['tab'] ?? 'topups';
$self      = htmlspecialchars($_SERVER['PHP_SELF']);

$pageTitle = 'Financials';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* ── Balance Summary Bar ─────────────────────────────────────────── */
.fin-bar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 0;
    border: 1px solid #e0e0e0;
    border-radius: 10px;
    overflow: hidden;
    margin-bottom: 28px;
}
.fin-bar-item {
    padding: 18px 20px;
    background: #fff;
    position: relative;
    display: flex;
    align-items: center;
    gap: 14px;
}
.fin-bar-item + .fin-bar-item::before {
    content: '';
    position: absolute;
    left: 0; top: 14px; bottom: 14px;
    width: 1px;
    background: #e0e0e0;
}
.fin-bar-item.primary { background: #2b2b2b; }
.fin-bar-icon {
    width: 40px; height: 40px;
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
}
.fin-bar-label {
    font-size: 10px; font-weight: 700;
    letter-spacing: .07em; text-transform: uppercase;
    color: #999; margin-bottom: 3px;
}
.fin-bar-item.primary .fin-bar-label { color: rgba(255,255,255,.5); }
.fin-bar-value { font-size: 19px; font-weight: 700; line-height: 1; color: #222; }
.fin-bar-item.primary .fin-bar-value { color: #fff; }

/* ── Tabs ────────────────────────────────────────────────────────── */
.fin-tabs {
    display: flex;
    gap: 4px;
    border-bottom: 2px solid #e8e8e8;
    margin-bottom: 24px;
    flex-wrap: wrap;
}
.fin-tab {
    padding: 10px 18px;
    border: none;
    background: none;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    color: #888;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    border-radius: 6px 6px 0 0;
    transition: color .15s, border-color .15s, background .15s;
    display: flex;
    align-items: center;
    gap: 7px;
    text-decoration: none;
}
.fin-tab:hover { color: #555; background: #f5f5f5; }
.fin-tab.active { color: #ff6600; border-bottom-color: #ff6600; background: #fff8f4; }
.fin-tab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px; height: 20px;
    border-radius: 10px;
    background: #eee;
    color: #666;
    font-size: 11px;
    font-weight: 700;
    padding: 0 5px;
}
.fin-tab.active .fin-tab-badge { background: #ff6600; color: #fff; }

/* ── Tab panels ──────────────────────────────────────────────────── */
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* ── Scrollable table wrapper ────────────────────────────────────── */
.table-scroll {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border-radius: 8px;
    border: 1px solid #e8e8e8;
}
.table-scroll table {
    min-width: 100%;
    border: none;
    border-radius: 0;
    margin: 0;
}
.table-scroll.payroll-table table   { min-width: 900px; }
.table-scroll.inventory-table table { min-width: 860px; }

/* Compact columns */
.col-amount { white-space: nowrap; width: 110px; }
.col-date   { white-space: nowrap; width: 140px; font-size: 13px; }
.col-period { white-space: nowrap; width: 150px; font-size: 13px; }
.col-money  { white-space: nowrap; width: 90px; text-align: right; }
.col-method { width: 120px; }

/* ── Table helpers ───────────────────────────────────────────────── */
.amount-in  { color: #28a745; font-weight: 700; white-space: nowrap; }
.amount-out { color: #dc3545; font-weight: 700; white-space: nowrap; }
.tag-pill {
    display: inline-block;
    padding: 2px 9px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: capitalize;
}
.tag-cash          { background: #e9f7ee; color: #1e7e34; }
.tag-mobile_money  { background: #e8f0fe; color: #1a56db; }
.tag-bank_transfer { background: #f3e8ff; color: #6f2da8; }
.tag-card          { background: #fff3cd; color: #856404; }
.tag-other         { background: #f0f0f0; color: #555; }
.tag-super_admin   { background: #fff3e0; color: #e65100; }
.tag-staff_admin   { background: #e8f5e9; color: #256029; }
.tag-mechanic      { background: #e3f2fd; color: #0d47a1; }
.tag-staff         { background: #f3e5f5; color: #6a1b9a; }

.no-data { padding: 40px 0; text-align: center; color: #aaa; font-size: 15px; }

/* ── Top-up form layout ──────────────────────────────────────────── */
.topup-layout {
    display: grid;
    grid-template-columns: 340px 1fr;
    gap: 24px;
    align-items: start;
}
@media (max-width: 780px) {
    .topup-layout { grid-template-columns: 1fr; }
    .fin-bar { grid-template-columns: 1fr 1fr; }
    .fin-bar-item + .fin-bar-item::before { display: none; }
    .fin-tab { padding: 8px 12px; font-size: 13px; }
}
</style>

<main class="main-content">
<div class="card">

    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
        <div>
            <h2 style="margin:0 0 6px;">Financials</h2>
            <p class="muted" style="margin:0;">Full breakdown of money in and out of the shop.</p>
        </div>
    </div>

    <?php if ($msg === 'topup_success'): ?>
        <div class="alert alert-success">Top-up recorded successfully.</div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $err): ?><p><?php echo e($err); ?></p><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Balance Summary Bar -->
    <div class="fin-bar">
        <div class="fin-bar-item primary">
            <div class="fin-bar-icon" style="background:rgba(255,102,0,.25);">💰</div>
            <div>
                <div class="fin-bar-label">Current Balance</div>
                <div class="fin-bar-value" style="color:<?php echo $balance >= 0 ? '#4dff91' : '#ff6b6b'; ?>">
                    $<?php echo number_format($balance, 2); ?>
                </div>
            </div>
        </div>
        <div class="fin-bar-item">
            <div class="fin-bar-icon" style="background:rgba(40,167,69,.12);">📈</div>
            <div>
                <div class="fin-bar-label">Customer Revenue</div>
                <div class="fin-bar-value amount-in">$<?php echo number_format($totalRevenue, 2); ?></div>
            </div>
        </div>
        <div class="fin-bar-item">
            <div class="fin-bar-icon" style="background:rgba(100,160,255,.15);">➕</div>
            <div>
                <div class="fin-bar-label">Top-Ups</div>
                <div class="fin-bar-value amount-in">$<?php echo number_format($totalTopUps, 2); ?></div>
            </div>
        </div>
        <div class="fin-bar-item">
            <div class="fin-bar-icon" style="background:rgba(255,180,0,.15);">🧾</div>
            <div>
                <div class="fin-bar-label">Expenses</div>
                <div class="fin-bar-value amount-out">$<?php echo number_format($totalExpenses, 2); ?></div>
            </div>
        </div>
        <div class="fin-bar-item">
            <div class="fin-bar-icon" style="background:rgba(150,80,220,.12);">👷</div>
            <div>
                <div class="fin-bar-label">Payroll</div>
                <div class="fin-bar-value amount-out">$<?php echo number_format($totalPayroll, 2); ?></div>
            </div>
        </div>
        <div class="fin-bar-item">
            <div class="fin-bar-icon" style="background:rgba(220,53,69,.1);">📦</div>
            <div>
                <div class="fin-bar-label">Inventory Cost</div>
                <div class="fin-bar-value amount-out">$<?php echo number_format($totalInventory, 2); ?></div>
            </div>
        </div>
        <div class="fin-bar-item">
            <div class="fin-bar-icon" style="background:rgba(40,167,69,.15);">💵</div>
            <div>
                <div class="fin-bar-label">Net Profit</div>
                <div class="fin-bar-value" style="color:<?php echo $netProfit >= 0 ? '#28a745' : '#dc3545'; ?>">
                    $<?php echo number_format($netProfit, 2); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="fin-tabs">
        <a href="<?php echo $self; ?>?tab=topups"    class="fin-tab <?php echo $activeTab === 'topups'    ? 'active' : ''; ?>">
            ➕ Top-Ups <span class="fin-tab-badge"><?php echo count($topups); ?></span>
        </a>
        <a href="<?php echo $self; ?>?tab=revenue"   class="fin-tab <?php echo $activeTab === 'revenue'   ? 'active' : ''; ?>">
            📈 Revenue <span class="fin-tab-badge"><?php echo count($revenues); ?></span>
        </a>
        <a href="<?php echo $self; ?>?tab=expenses"  class="fin-tab <?php echo $activeTab === 'expenses'  ? 'active' : ''; ?>">
            🧾 Expenses <span class="fin-tab-badge"><?php echo count($expenses); ?></span>
        </a>
        <a href="<?php echo $self; ?>?tab=payroll"   class="fin-tab <?php echo $activeTab === 'payroll'   ? 'active' : ''; ?>">
            👷 Payroll <span class="fin-tab-badge"><?php echo count($payrolls); ?></span>
        </a>
        <a href="<?php echo $self; ?>?tab=inventory" class="fin-tab <?php echo $activeTab === 'inventory' ? 'active' : ''; ?>">
            📦 Inventory <span class="fin-tab-badge"><?php echo count($inventory); ?></span>
        </a>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- TAB: TOP-UPS                                                   -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="tab-panel <?php echo $activeTab === 'topups' ? 'active' : ''; ?>">
        <div class="topup-layout">
            <!-- Form -->
            <div class="card">
                <h3 style="margin-top:0;">Add Funds</h3>
                <form method="POST" action="<?php echo $self; ?>">
                    <label>Amount ($) <span style="color:#dc3545">*</span>
                        <input type="number" name="amount" min="0.01" step="0.01" placeholder="0.00"
                               value="<?php echo isset($_POST['amount']) ? e($_POST['amount']) : ''; ?>" required>
                    </label>
                    <label>Payment Method <span class="muted">(optional)</span>
                        <select name="notes">
                            <option value="">— Select —</option>
                            <?php foreach (['cash','mobile_money','bank_transfer','card','other'] as $opt): ?>
                                <option value="<?php echo $opt; ?>" <?php echo (($_POST['notes'] ?? '') === $opt) ? 'selected' : ''; ?>>
                                    <?php echo ucwords(str_replace('_', ' ', $opt)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="btn btn-primary" style="width:100%;margin-top:12px;">Confirm Top-Up</button>
                </form>
            </div>

            <!-- History table -->
            <div>
                <h3 style="margin-top:0;">Recent Top-Ups</h3>
                <?php if (empty($topups)): ?>
                    <p class="no-data">No top-ups recorded yet.</p>
                <?php else: ?>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Recorded By</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topups as $r): ?>
                                    <tr>
                                        <td><?php echo (int)$r['id']; ?></td>
                                        <td class="amount-in col-amount">+$<?php echo number_format($r['amount'], 2); ?></td>
                                        <td>
                                            <?php if ($r['notes']): ?>
                                                <span class="tag-pill tag-<?php echo e($r['notes']); ?>">
                                                    <?php echo ucwords(str_replace('_', ' ', e($r['notes']))); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $r['recorded_by_name'] ? e($r['recorded_by_name']) : '<span class="muted">—</span>'; ?></td>
                                        <td class="col-date"><?php echo date('M j, Y g:i A', strtotime($r['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- TAB: CUSTOMER REVENUE                                          -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="tab-panel <?php echo $activeTab === 'revenue' ? 'active' : ''; ?>">
        <h3 style="margin-top:0;">Customer Payments Received</h3>
        <?php if (empty($revenues)): ?>
            <p class="no-data">No customer payments recorded yet.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Amount</th>
                            <th>Customer</th>
                            <th>Mechanic</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Recorded By</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($revenues as $r): ?>
                            <tr>
                                <td><?php echo (int)$r['id']; ?></td>
                                <td class="amount-in col-amount">+$<?php echo number_format($r['amount'], 2); ?></td>
                                <td><?php echo $r['customer_name'] ? e($r['customer_name']) : '<span class="muted">—</span>'; ?></td>
                                <td><?php echo $r['mechanic_name'] ? e($r['mechanic_name']) : '<span class="muted">—</span>'; ?></td>
                                <td class="col-method">
                                    <?php if ($r['payment_method']): ?>
                                        <span class="tag-pill tag-<?php echo e($r['payment_method']); ?>">
                                            <?php echo ucwords(str_replace('_', ' ', e($r['payment_method']))); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $r['payment_reference'] ? e($r['payment_reference']) : '<span class="muted">—</span>'; ?></td>
                                <td><?php echo $r['recorded_by_name'] ? e($r['recorded_by_name']) : '<span class="muted">—</span>'; ?></td>
                                <td class="col-date"><?php echo date('M j, Y g:i A', strtotime($r['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- TAB: EXPENSES                                                  -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="tab-panel <?php echo $activeTab === 'expenses' ? 'active' : ''; ?>">
        <h3 style="margin-top:0;">Operating Expenses Paid</h3>
        <?php if (empty($expenses)): ?>
            <p class="no-data">No paid expenses recorded yet.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Amount</th>
                            <th>Category</th>
                            <th>Vendor</th>
                            <th>Expense Date</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Paid On</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $r): ?>
                            <tr>
                                <td><?php echo (int)$r['id']; ?></td>
                                <td class="amount-out col-amount">−$<?php echo number_format($r['amount'], 2); ?></td>
                                <td>
                                    <span class="tag-pill" style="background:#f0f4ff;color:#334;">
                                        <?php echo ucwords(e($r['category'] ?? '—')); ?>
                                    </span>
                                </td>
                                <td><?php echo $r['vendor_name'] ? e($r['vendor_name']) : '<span class="muted">—</span>'; ?></td>
                                <td class="col-date"><?php echo $r['expense_date'] ? date('M j, Y', strtotime($r['expense_date'])) : '<span class="muted">—</span>'; ?></td>
                                <td class="col-method">
                                    <?php if ($r['payment_method']): ?>
                                        <span class="tag-pill tag-<?php echo e($r['payment_method']); ?>">
                                            <?php echo ucwords(str_replace('_', ' ', e($r['payment_method']))); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $r['payment_reference'] ? e($r['payment_reference']) : '<span class="muted">—</span>'; ?></td>
                                <td class="col-date"><?php echo date('M j, Y g:i A', strtotime($r['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- TAB: PAYROLL                                                   -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="tab-panel <?php echo $activeTab === 'payroll' ? 'active' : ''; ?>">
        <h3 style="margin-top:0;">Payroll Disbursements</h3>
        <?php if (empty($payrolls)): ?>
            <p class="no-data">No payroll payments recorded yet.</p>
        <?php else: ?>
            <div class="table-scroll payroll-table">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Net Pay</th>
                            <th>Employee</th>
                            <th>Role</th>
                            <th>Period</th>
                            <th style="text-align:right;">Base</th>
                            <th style="text-align:right;">Commission</th>
                            <th style="text-align:right;">Deductions</th>
                            <th>Method</th>
                            <th>Paid On</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payrolls as $r): ?>
                            <tr>
                                <td><?php echo (int)$r['id']; ?></td>
                                <td class="amount-out col-amount">−$<?php echo number_format($r['amount'], 2); ?></td>
                                <td style="white-space:nowrap;"><?php echo $r['employee_name'] ? e($r['employee_name']) : '<span class="muted">—</span>'; ?></td>
                                <td>
                                    <span class="tag-pill tag-<?php echo e($r['target_type'] ?? 'other'); ?>">
                                        <?php echo ucwords(str_replace('_', ' ', e($r['target_type'] ?? '—'))); ?>
                                    </span>
                                </td>
                                <td class="col-period">
                                    <?php
                                        echo $r['period_start'] ? date('M j', strtotime($r['period_start'])) : '—';
                                        echo ' – ';
                                        echo $r['period_end']   ? date('M j, Y', strtotime($r['period_end'])) : '—';
                                    ?>
                                </td>
                                <td class="col-money">$<?php echo number_format($r['base_salary'] ?? 0, 2); ?></td>
                                <td class="col-money amount-in">+$<?php echo number_format($r['commission_earnings'] ?? 0, 2); ?></td>
                                <td class="col-money amount-out">−$<?php echo number_format($r['deductions'] ?? 0, 2); ?></td>
                                <td class="col-method">
                                    <?php if ($r['payment_method']): ?>
                                        <span class="tag-pill tag-<?php echo e($r['payment_method']); ?>">
                                            <?php echo ucwords(str_replace('_', ' ', e($r['payment_method']))); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-date"><?php echo date('M j, Y g:i A', strtotime($r['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- TAB: INVENTORY                                                 -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="tab-panel <?php echo $activeTab === 'inventory' ? 'active' : ''; ?>">
        <h3 style="margin-top:0;">Inventory Purchases</h3>
        <?php if (empty($inventory)): ?>
            <p class="no-data">No inventory purchases recorded yet.</p>
        <?php else: ?>
            <div class="table-scroll inventory-table">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Amount</th>
                            <th>Part</th>
                            <th>SKU</th>
                            <th>Action</th>
                            <th>Qty</th>
                            <th>Cost/Unit</th>
                            <th>By Role</th>
                            <th>Recorded By</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inventory as $r): ?>
                            <tr>
                                <td><?php echo (int)$r['id']; ?></td>
                                <td class="amount-out col-amount">−$<?php echo number_format($r['amount'], 2); ?></td>
                                <td style="white-space:nowrap;"><?php echo $r['part_name'] ? e($r['part_name']) : '<span class="muted">—</span>'; ?></td>
                                <td><span class="muted"><?php echo $r['sku'] ? e($r['sku']) : '—'; ?></span></td>
                                <td>
                                    <span class="tag-pill" style="background:#f5f0e8;color:#7a5c00;">
                                        <?php echo ucwords(str_replace('_', ' ', e($r['action'] ?? '—'))); ?>
                                    </span>
                                </td>
                                <td><?php echo $r['quantity_change'] !== null ? (int)$r['quantity_change'] : '<span class="muted">—</span>'; ?></td>
                                <td class="col-money"><?php echo $r['cost_per_unit'] !== null ? '$' . number_format($r['cost_per_unit'], 2) : '<span class="muted">—</span>'; ?></td>
                                <td>
                                    <span class="tag-pill tag-<?php echo e($r['role_at_time'] ?? 'other'); ?>">
                                        <?php echo ucwords(str_replace('_', ' ', e($r['role_at_time'] ?? '—'))); ?>
                                    </span>
                                </td>
                                <td style="white-space:nowrap;"><?php echo $r['recorded_by_name'] ? e($r['recorded_by_name']) : '<span class="muted">—</span>'; ?></td>
                                <td class="col-date"><?php echo date('M j, Y g:i A', strtotime($r['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div><!-- .card -->
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
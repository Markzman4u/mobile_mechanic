<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireSuperAdmin();

$pdo     = getPDO();
$baseUrl = getBasePath();

/* ─────────────────────────────────────────────
   POST HANDLER
───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ── ADD (creates active expense — no ledger write yet) ── */
    if ($action === 'add') {
        $category     = $_POST['category']    ?? '';
        $vendor_name  = trim($_POST['vendor_name'] ?? '');
        $amount       = (float)($_POST['amount']   ?? 0);
        $expense_date = $_POST['expense_date'] ?? '';
        $notes        = trim($_POST['notes']   ?? '');
        $recorded_by  = $_SESSION['user_id'];

        if ($category && $amount > 0 && $expense_date) {
            $stmt = $pdo->prepare("
                INSERT INTO expenses (category, vendor_name, amount, expense_date, notes, status, recorded_by)
                VALUES (?, ?, ?, ?, ?, 'active', ?)
            ");
            $ok = $stmt->execute([
                $category,
                $vendor_name ?: null,
                $amount,
                $expense_date,
                $notes ?: null,
                $recorded_by,
            ]);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=active&msg=' . ($ok ? 'added' : 'error'));
        } else {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=active&msg=invalid');
        }
        exit;
    }

    /* ── EDIT (active only — no ledger entry to update) ── */
    if ($action === 'edit') {
        $id           = (int)($_POST['id']       ?? 0);
        $category     = $_POST['category']       ?? '';
        $vendor_name  = trim($_POST['vendor_name'] ?? '');
        $amount       = (float)($_POST['amount'] ?? 0);
        $expense_date = $_POST['expense_date']   ?? '';
        $notes        = trim($_POST['notes']     ?? '');

        if ($id && $category && $amount > 0 && $expense_date) {
            /* safety: only allow editing active rows */
            $chk = $pdo->prepare("SELECT status FROM expenses WHERE id=?");
            $chk->execute([$id]);
            $existing = $chk->fetchColumn();

            if ($existing === 'active') {
                $pdo->prepare("
                    UPDATE expenses
                    SET category=?, vendor_name=?, amount=?, expense_date=?, notes=?
                    WHERE id=? AND status='active'
                ")->execute([$category, $vendor_name ?: null, $amount, $expense_date, $notes ?: null, $id]);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=active&msg=updated');
            } else {
                header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=active&msg=locked');
            }
        } else {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=active&msg=invalid');
        }
        exit;
    }

    /* ── DELETE (active only — no ledger entry exists yet) ── */
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $chk = $pdo->prepare("SELECT status FROM expenses WHERE id=?");
            $chk->execute([$id]);
            $existing = $chk->fetchColumn();

            if ($existing === 'active') {
                $pdo->prepare("DELETE FROM expenses WHERE id=? AND status='active'")->execute([$id]);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=active&msg=deleted');
            } else {
                header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=paid&msg=locked');
            }
        } else {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=active');
        }
        exit;
    }

    /* ── MARK PAID (active → paid + ledger write, inside transaction) ── */
    if ($action === 'mark_paid') {
        $id               = (int)($_POST['id']                ?? 0);
        $payment_method   = $_POST['payment_method']          ?? '';
        $payment_reference = trim($_POST['payment_reference'] ?? '');
        $recorded_by      = $_SESSION['user_id'];

        $valid_methods = ['cash','mobile_money','bank_transfer','other'];
        if ($id && in_array($payment_method, $valid_methods, true)) {
            try {
                $pdo->beginTransaction();

                /* fetch the expense to confirm it's active */
                $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id=? AND status='active' FOR UPDATE");
                $stmt->execute([$id]);
                $exp = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$exp) {
                    $pdo->rollBack();
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=active&msg=locked');
                    exit;
                }

                /* flip to paid */
                $pdo->prepare("
                    UPDATE expenses
                    SET status='paid',
                        payment_method=?,
                        payment_reference=?,
                        paid_at=NOW()
                    WHERE id=?
                ")->execute([
                    $payment_method,
                    $payment_reference ?: null,
                    $id,
                ]);

                /* write ledger entry now that cash has moved */
                $ledger_note = ucfirst($exp['category'])
                    . ($exp['vendor_name'] ? ' – ' . $exp['vendor_name'] : '');
                $pdo->prepare("
                    INSERT INTO balance_ledger
                        (type, direction, amount, reference_id, notes, created_by)
                    VALUES ('expense', 'out', ?, ?, ?, ?)
                ")->execute([$exp['amount'], $id, $ledger_note, $recorded_by]);

                $pdo->commit();
                header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=paid&msg=paid');
            } catch (Exception $e) {
                $pdo->rollBack();
                header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=active&msg=error');
            }
        } else {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=active&msg=invalid');
        }
        exit;
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/* ─────────────────────────────────────────────
   FLASH MESSAGE
───────────────────────────────────────────── */
$msgParam = $_GET['msg'] ?? '';
$flashMap = [
    'added'   => ['type' => 'success', 'text' => 'Expense recorded as active. Mark it paid when the payment is made.'],
    'updated' => ['type' => 'success', 'text' => 'Expense updated successfully.'],
    'deleted' => ['type' => 'success', 'text' => 'Active expense deleted.'],
    'paid'    => ['type' => 'success', 'text' => 'Expense marked as paid and balance ledger updated.'],
    'invalid' => ['type' => 'error',   'text' => 'Please fill in all required fields with valid values.'],
    'locked'  => ['type' => 'error',   'text' => 'Paid expenses cannot be edited or deleted.'],
    'error'   => ['type' => 'error',   'text' => 'A database error occurred. Please try again.'],
];
$flash = $flashMap[$msgParam] ?? null;

/* ─────────────────────────────────────────────
   ACTIVE TAB
───────────────────────────────────────────── */
$activeTab = in_array($_GET['tab'] ?? '', ['active','paid','all']) ? $_GET['tab'] : 'active';

/* ─────────────────────────────────────────────
   FILTERS (shared across tabs)
───────────────────────────────────────────── */
$filterCategory = $_GET['category']  ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo   = $_GET['date_to']   ?? '';
$filterSearch   = trim($_GET['search'] ?? '');

/* ─────────────────────────────────────────────
   STATS
───────────────────────────────────────────── */
$statActiveTotal = (float)$pdo->query("
    SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='active'
")->fetchColumn();
$statActiveCount = (int)$pdo->query("
    SELECT COUNT(*) FROM expenses WHERE status='active'
")->fetchColumn();
$statPaidMonth = (float)$pdo->query("
    SELECT COALESCE(SUM(amount),0) FROM expenses
    WHERE status='paid'
      AND MONTH(paid_at)=MONTH(CURDATE())
      AND YEAR(paid_at)=YEAR(CURDATE())
")->fetchColumn();
$statPaidTotal = (float)$pdo->query("
    SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='paid'
")->fetchColumn();

/* ─────────────────────────────────────────────
   CATEGORY BREAKDOWN — paid this month
───────────────────────────────────────────── */
$catBreakdown = $pdo->query("
    SELECT category, COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt
    FROM expenses
    WHERE status='paid'
      AND MONTH(paid_at)=MONTH(CURDATE())
      AND YEAR(paid_at)=YEAR(CURDATE())
    GROUP BY category
    ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* ─────────────────────────────────────────────
   MAIN QUERY
───────────────────────────────────────────── */
$where  = [];
$params = [];

/* tab filter */
if ($activeTab === 'active') {
    $where[] = "e.status = 'active'";
} elseif ($activeTab === 'paid') {
    $where[] = "e.status = 'paid'";
}

if ($filterCategory) {
    $where[]  = 'e.category = ?';
    $params[] = $filterCategory;
}
if ($filterDateFrom) {
    $where[]  = 'e.expense_date >= ?';
    $params[] = $filterDateFrom;
}
if ($filterDateTo) {
    $where[]  = 'e.expense_date <= ?';
    $params[] = $filterDateTo;
}
if ($filterSearch) {
    $where[]  = '(e.vendor_name LIKE ? OR e.notes LIKE ?)';
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$orderBy = $activeTab === 'paid'
    ? 'ORDER BY e.paid_at DESC, e.id DESC'
    : 'ORDER BY e.expense_date DESC, e.id DESC';

$stmt = $pdo->prepare("
    SELECT e.*, u.full_name AS recorder_name
    FROM expenses e
    LEFT JOIN users u ON u.id = e.recorded_by
    $whereSql
    $orderBy
");
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filteredTotal = array_sum(array_column($expenses, 'amount'));

/* ─────────────────────────────────────────────
   HELPERS
───────────────────────────────────────────── */
$categories = ['electricity','water','cleaning','rent','supplies','other'];

$categoryMeta = [
    'electricity' => ['label' => 'Electricity', 'icon' => '⚡', 'color' => '#f59e0b'],
    'water'       => ['label' => 'Water',        'icon' => '💧', 'color' => '#3b82f6'],
    'cleaning'    => ['label' => 'Cleaning',     'icon' => '🧹', 'color' => '#10b981'],
    'rent'        => ['label' => 'Rent',         'icon' => '🏢', 'color' => '#8b5cf6'],
    'supplies'    => ['label' => 'Supplies',     'icon' => '📦', 'color' => '#ff6600'],
    'other'       => ['label' => 'Other',        'icon' => '📋', 'color' => '#6b7280'],
];

$paymentMethodLabels = [
    'cash'          => 'Cash',
    'mobile_money'  => 'Mobile Money',
    'bank_transfer' => 'Bank Transfer',
    'other'         => 'Other',
];

function catLabel(string $cat): string {
    global $categoryMeta;
    return $categoryMeta[$cat]['label'] ?? ucfirst($cat);
}
function catIcon(string $cat): string {
    global $categoryMeta;
    return $categoryMeta[$cat]['icon'] ?? '📋';
}
function methodLabel(string $m): string {
    global $paymentMethodLabels;
    return $paymentMethodLabels[$m] ?? ucfirst($m);
}

/* build tab URL helper */
function tabUrl(string $tab): string {
    $p = $_GET;
    $p['tab'] = $tab;
    unset($p['msg']);
    return $_SERVER['PHP_SELF'] . '?' . http_build_query($p);
}
?>
<?php
$pageTitle = 'Manage Expenses';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* ── page layout ── */
.sa-expenses-wrap { padding: 24px; max-width: 1400px; margin: 0 auto; }

/* ── flash ── */
.flash-banner {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;
    font-size: .9rem; font-weight: 500;
}
.flash-banner.success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
.flash-banner.error   { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }

/* ── page header ── */
.page-header {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 12px; margin-bottom: 24px;
}
.page-header h1 { font-size: 1.5rem; font-weight: 700; color: #1f2937; margin: 0; }
.page-header p  { font-size: .85rem; color: #6b7280; margin: 2px 0 0; }

/* ── stat tiles ── */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px; margin-bottom: 24px;
}
.stat-tile {
    background: #fff; border-radius: 12px;
    padding: 20px 18px; border: 1px solid #e5e7eb;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.stat-tile .tile-label { font-size: .78rem; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
.stat-tile .tile-value { font-size: 1.6rem; font-weight: 800; color: #1f2937; margin: 6px 0 0; line-height: 1.1; }
.stat-tile .tile-sub   { font-size: .78rem; color: #9ca3af; margin-top: 4px; }
.stat-tile.orange .tile-value { color: #ff6600; }
.stat-tile.red    .tile-value { color: #dc2626; }
.stat-tile.green  .tile-value { color: #059669; }

/* ── breakdown pills ── */
.breakdown-grid {
    display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 24px;
}
.breakdown-pill {
    display: flex; align-items: center; gap: 8px;
    background: #fff; border: 1px solid #e5e7eb;
    border-radius: 99px; padding: 6px 14px;
    font-size: .82rem; color: #374151;
    box-shadow: 0 1px 3px rgba(0,0,0,.05);
}
.breakdown-pill .pill-icon { font-size: 1rem; }
.breakdown-pill .pill-amt  { font-weight: 700; color: #1f2937; }
.breakdown-pill .pill-cnt  { color: #9ca3af; font-size: .76rem; }
.breakdown-label { font-size: .78rem; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; align-self: center; }

/* ── tabs ── */
.tab-bar {
    display: flex; gap: 0; margin-bottom: 20px;
    border-bottom: 2px solid #e5e7eb;
}
.tab-link {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 20px; font-size: .875rem; font-weight: 600;
    color: #6b7280; text-decoration: none; border-bottom: 3px solid transparent;
    margin-bottom: -2px; transition: color .15s, border-color .15s;
    white-space: nowrap;
}
.tab-link:hover { color: #374151; }
.tab-link.active { color: #ff6600; border-bottom-color: #ff6600; }
.tab-badge {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 20px; height: 20px; padding: 0 6px;
    background: #f3f4f6; color: #6b7280; border-radius: 99px;
    font-size: .72rem; font-weight: 700;
}
.tab-link.active .tab-badge { background: #fff3e8; color: #ff6600; }

/* ── filter bar ── */
.filter-card {
    background: #fff; border-radius: 12px; border: 1px solid #e5e7eb;
    padding: 16px 20px; margin-bottom: 20px;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.filter-row {
    display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end;
}
.filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 130px; flex: 1; }
.filter-group label { font-size: .78rem; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
.filter-group input,
.filter-group select { padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: .875rem; color: #1f2937; background: #f9fafb; }
.filter-group input:focus,
.filter-group select:focus { outline: none; border-color: #ff6600; background: #fff; }
.filter-actions { display: flex; gap: 8px; align-items: flex-end; }

/* ── results bar ── */
.results-bar {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 10px; margin-bottom: 14px;
}
.results-bar .results-info { font-size: .85rem; color: #6b7280; }
.results-bar .results-info strong { color: #1f2937; }
.results-bar .filtered-total { font-size: .9rem; font-weight: 700; color: #dc2626; }

/* ── table ── */
.table-card {
    background: #fff; border-radius: 12px; border: 1px solid #e5e7eb;
    overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.expense-table { width: 100%; border-collapse: collapse; }
.expense-table thead th {
    background: #f8f9fa; padding: 12px 16px;
    font-size: .75rem; font-weight: 700; color: #6b7280;
    text-transform: uppercase; letter-spacing: .05em;
    text-align: left; border-bottom: 1px solid #e5e7eb;
    white-space: nowrap;
}
.expense-table tbody tr { border-bottom: 1px solid #f3f4f6; transition: background .15s; }
.expense-table tbody tr:last-child { border-bottom: none; }
.expense-table tbody tr:hover { background: #fafafa; }
.expense-table td { padding: 13px 16px; font-size: .875rem; color: #374151; vertical-align: middle; }

/* ── badges ── */
.cat-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 99px;
    font-size: .76rem; font-weight: 600;
    background: #f3f4f6; color: #374151;
}
.status-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 99px;
    font-size: .75rem; font-weight: 700;
}
.status-badge.active { background: #fff3e8; color: #c2410c; }
.status-badge.paid   { background: #d1fae5; color: #065f46; }
.method-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; border-radius: 6px;
    font-size: .73rem; font-weight: 600;
    background: #eff6ff; color: #1d4ed8;
}

/* ── amount cell ── */
.amount-cell { font-weight: 700; color: #dc2626; font-size: .9rem; }

/* ── action buttons ── */
.btn-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 32px; height: 32px; border-radius: 8px; border: 1px solid transparent;
    cursor: pointer; transition: background .15s, border-color .15s; font-size: .85rem;
    background: none; text-decoration: none;
}
.btn-icon.edit   { color: #2563eb; border-color: #dbeafe; background: #eff6ff; }
.btn-icon.edit:hover  { background: #dbeafe; }
.btn-icon.del    { color: #dc2626; border-color: #fee2e2; background: #fef2f2; }
.btn-icon.del:hover   { background: #fee2e2; }
.btn-icon.pay    { color: #059669; border-color: #a7f3d0; background: #ecfdf5; }
.btn-icon.pay:hover   { background: #a7f3d0; }
.actions-cell { display: flex; gap: 6px; }

/* ── empty state ── */
.empty-state {
    text-align: center; padding: 60px 20px; color: #9ca3af;
}
.empty-state .empty-icon { font-size: 3rem; margin-bottom: 12px; }
.empty-state p { font-size: .95rem; margin: 0; }

/* ── buttons ── */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border-radius: 8px; font-size: .875rem; font-weight: 600; border: none; cursor: pointer; transition: opacity .15s, transform .1s; text-decoration: none; }
.btn:hover { opacity: .88; transform: translateY(-1px); }
.btn-primary  { background: #ff6600; color: #fff; }
.btn-secondary{ background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }
.btn-danger   { background: #dc2626; color: #fff; }
.btn-success  { background: #059669; color: #fff; }
.btn-sm { padding: 7px 14px; font-size: .82rem; }

/* ── modals ── */
.modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.45);
    z-index: 1000; display: none; align-items: center; justify-content: center;
    padding: 16px;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: #fff; border-radius: 14px; width: 100%; max-width: 520px;
    box-shadow: 0 20px 60px rgba(0,0,0,.2); overflow: hidden;
    animation: modalIn .18s ease;
}
@keyframes modalIn { from { opacity:0; transform:translateY(-12px); } to { opacity:1; transform:none; } }
.modal-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px; border-bottom: 1px solid #f3f4f6;
}
.modal-head h3 { font-size: 1.05rem; font-weight: 700; color: #1f2937; margin: 0; }
.modal-close {
    width: 30px; height: 30px; border-radius: 6px; border: none;
    background: #f3f4f6; color: #6b7280; font-size: 1.1rem;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
}
.modal-close:hover { background: #e5e7eb; }
.modal-body { padding: 22px; }
.modal-foot {
    display: flex; justify-content: flex-end; gap: 10px;
    padding: 16px 22px; border-top: 1px solid #f3f4f6;
    background: #fafafa;
}

/* ── form fields ── */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-group { display: flex; flex-direction: column; gap: 5px; }
.form-group.full { grid-column: 1 / -1; }
.form-group label { font-size: .8rem; font-weight: 600; color: #374151; }
.form-group input,
.form-group select,
.form-group textarea {
    padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 8px;
    font-size: .875rem; color: #1f2937; background: #f9fafb;
    transition: border-color .15s;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus { outline: none; border-color: #ff6600; background: #fff; }
.form-group textarea { resize: vertical; min-height: 72px; }
.form-group .field-hint { font-size: .75rem; color: #9ca3af; }

/* ── info boxes ── */
.info-box {
    border-radius: 8px; padding: 14px 16px; margin-bottom: 16px;
}
.info-box.warn  { background: #fffbeb; border: 1px solid #fde68a; }
.info-box.green { background: #f0fdf4; border: 1px solid #bbf7d0; }
.info-box.red   { background: #fef2f2; border: 1px solid #fee2e2; }

.info-row { display: flex; gap: 8px; margin-bottom: 6px; font-size: .875rem; }
.info-row:last-child { margin-bottom: 0; }
.info-label { color: #9ca3af; min-width: 90px; }
.info-value { color: #1f2937; font-weight: 600; }

.warn-text { font-size: .85rem; color: #b45309; font-weight: 500; display: flex; align-items: center; gap: 6px; }
.danger-text { font-size: .85rem; color: #dc2626; font-weight: 500; display: flex; align-items: center; gap: 6px; }

/* ── locked notice ── */
.locked-notice {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: .78rem; color: #9ca3af; font-style: italic;
}
</style>

<div class="sa-expenses-wrap">

    <?php if ($flash): ?>
    <div class="flash-banner <?= $flash['type'] ?>">
        <?= $flash['type'] === 'success' ? '✅' : '❌' ?>
        <?= e($flash['text']) ?>
    </div>
    <?php endif; ?>

    <!-- ── Page header ── -->
    <div class="page-header">
        <div>
            <h1>💸 Expenses</h1>
            <p>Record operational costs. Active expenses can be edited. Marking an expense paid deducts from the shop balance.</p>
        </div>
        <button class="btn btn-primary" onclick="openAddModal()">+ Record Expense</button>
    </div>

    <!-- ── Stat tiles ── -->
    <div class="stat-grid">
        <div class="stat-tile orange">
            <div class="tile-label">Active (Unpaid)</div>
            <div class="tile-value">$<?= number_format($statActiveTotal, 2) ?></div>
            <div class="tile-sub"><?= $statActiveCount ?> pending expense<?= $statActiveCount !== 1 ? 's' : '' ?></div>
        </div>
        <div class="stat-tile red">
            <div class="tile-label">Paid This Month</div>
            <div class="tile-value">$<?= number_format($statPaidMonth, 2) ?></div>
            <div class="tile-sub"><?= date('F Y') ?></div>
        </div>
        <div class="stat-tile">
            <div class="tile-label">All-Time Paid</div>
            <div class="tile-value">$<?= number_format($statPaidTotal, 2) ?></div>
            <div class="tile-sub">Total ledger outflow</div>
        </div>
        <div class="stat-tile green">
            <div class="tile-label">Categories</div>
            <div class="tile-value"><?= count($categories) ?></div>
            <div class="tile-sub">Types tracked</div>
        </div>
    </div>

    <!-- ── Category breakdown (paid this month) ── -->
    <?php if ($catBreakdown): ?>
    <div class="breakdown-grid">
        <span class="breakdown-label">Paid this month:</span>
        <?php foreach ($catBreakdown as $row): ?>
        <div class="breakdown-pill">
            <span class="pill-icon"><?= catIcon($row['category']) ?></span>
            <span><?= catLabel($row['category']) ?></span>
            <span class="pill-amt">$<?= number_format($row['total'], 2) ?></span>
            <span class="pill-cnt">(<?= $row['cnt'] ?>)</span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Tabs ── -->
    <div class="tab-bar">
        <a href="<?= tabUrl('active') ?>" class="tab-link <?= $activeTab === 'active' ? 'active' : '' ?>">
            🟠 Active
            <span class="tab-badge"><?= $statActiveCount ?></span>
        </a>
        <a href="<?= tabUrl('paid') ?>" class="tab-link <?= $activeTab === 'paid' ? 'active' : '' ?>">
            ✅ Paid History
        </a>
        <a href="<?= tabUrl('all') ?>" class="tab-link <?= $activeTab === 'all' ? 'active' : '' ?>">
            📋 All
        </a>
    </div>

    <!-- ── Filter bar ── -->
    <div class="filter-card">
        <form method="GET" action="<?= $_SERVER['PHP_SELF'] ?>">
            <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
            <div class="filter-row">
                <div class="filter-group">
                    <label>Category</label>
                    <select name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>>
                            <?= catIcon($cat) ?> <?= catLabel($cat) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>From</label>
                    <input type="date" name="date_from" value="<?= e($filterDateFrom) ?>">
                </div>
                <div class="filter-group">
                    <label>To</label>
                    <input type="date" name="date_to" value="<?= e($filterDateTo) ?>">
                </div>
                <div class="filter-group" style="flex: 2;">
                    <label>Search vendor / notes</label>
                    <input type="text" name="search" placeholder="Type to search…" value="<?= e($filterSearch) ?>">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <a href="<?= $_SERVER['PHP_SELF'] ?>?tab=<?= $activeTab ?>" class="btn btn-secondary btn-sm">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <!-- ── Results bar ── -->
    <div class="results-bar">
        <div class="results-info">
            Showing <strong><?= count($expenses) ?></strong> record<?= count($expenses) !== 1 ? 's' : '' ?>
            <?= ($filterCategory || $filterSearch) ? ' (filtered)' : '' ?>
        </div>
        <?php if ($expenses): ?>
        <div class="filtered-total">Total: $<?= number_format($filteredTotal, 2) ?></div>
        <?php endif; ?>
    </div>

    <!-- ── Table ── -->
    <div class="table-card">
        <?php if (empty($expenses)): ?>
        <div class="empty-state">
            <div class="empty-icon"><?= $activeTab === 'paid' ? '✅' : '📭' ?></div>
            <p>
                <?php if ($activeTab === 'active'): ?>
                    No active expenses. Record one to get started.
                <?php elseif ($activeTab === 'paid'): ?>
                    No paid expenses yet.
                <?php else: ?>
                    No expenses found<?= ($filterCategory || $filterSearch || $filterDateFrom) ? ' matching your filters' : '' ?>.
                <?php endif; ?>
            </p>
            <?php if ($activeTab !== 'paid' && !$filterCategory && !$filterSearch): ?>
            <button class="btn btn-primary" style="margin-top:16px;" onclick="openAddModal()">Record First Expense</button>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <table class="expense-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Category</th>
                    <th>Vendor</th>
                    <th>Amount</th>
                    <?php if ($activeTab !== 'active'): ?>
                    <th>Payment</th>
                    <th>Paid At</th>
                    <?php endif; ?>
                    <?php if ($activeTab === 'all'): ?>
                    <th>Status</th>
                    <?php endif; ?>
                    <th>Notes</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expenses as $row): ?>
                <tr>
                    <td style="color:#9ca3af; font-size:.8rem;"><?= $row['id'] ?></td>
                    <td style="white-space:nowrap; font-weight:600;">
                        <?= date('M j, Y', strtotime($row['expense_date'])) ?>
                    </td>
                    <td>
                        <span class="cat-badge">
                            <?= catIcon($row['category']) ?> <?= catLabel($row['category']) ?>
                        </span>
                    </td>
                    <td style="max-width:160px;">
                        <?= $row['vendor_name'] ? e($row['vendor_name']) : '<span style="color:#d1d5db;">—</span>' ?>
                    </td>
                    <td class="amount-cell">$<?= number_format($row['amount'], 2) ?></td>

                    <?php if ($activeTab !== 'active'): ?>
                    <td>
                        <?php if ($row['status'] === 'paid'): ?>
                            <span class="method-badge"><?= methodLabel($row['payment_method'] ?? '') ?></span>
                            <?php if ($row['payment_reference']): ?>
                            <div style="font-size:.72rem; color:#6b7280; margin-top:3px;">
                                <?= e($row['payment_reference']) ?>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#d1d5db; font-size:.8rem;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.82rem; white-space:nowrap; color:#6b7280;">
                        <?= $row['paid_at'] ? date('M j, Y', strtotime($row['paid_at'])) : '<span style="color:#d1d5db;">—</span>' ?>
                    </td>
                    <?php endif; ?>

                    <?php if ($activeTab === 'all'): ?>
                    <td>
                        <span class="status-badge <?= $row['status'] ?>">
                            <?= $row['status'] === 'paid' ? '✅ Paid' : '🟠 Active' ?>
                        </span>
                    </td>
                    <?php endif; ?>

                    <td style="max-width:180px; font-size:.82rem; color:#6b7280;">
                        <?php if ($row['notes']): ?>
                            <?= mb_strlen($row['notes']) > 55
                                ? e(mb_substr($row['notes'], 0, 55)) . '…'
                                : e($row['notes']) ?>
                        <?php else: ?>
                            <span style="color:#d1d5db;">—</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if ($row['status'] === 'active'): ?>
                        <div class="actions-cell">
                            <button class="btn-icon pay" title="Mark Paid"
                                onclick="openPayModal(<?= htmlspecialchars(json_encode([
                                    'id'           => $row['id'],
                                    'category'     => catLabel($row['category']),
                                    'vendor_name'  => $row['vendor_name'] ?? '',
                                    'amount'       => number_format($row['amount'], 2),
                                    'expense_date' => date('M j, Y', strtotime($row['expense_date'])),
                                ]), ENT_QUOTES) ?>)">
                                💳
                            </button>
                            <button class="btn-icon edit" title="Edit"
                                onclick="openEditModal(<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)">
                                ✏️
                            </button>
                            <button class="btn-icon del" title="Delete"
                                onclick="openDeleteModal(<?= (int)$row['id'] ?>, '<?= e(catLabel($row['category'])) ?>', '<?= e($row['vendor_name'] ?? '') ?>', '<?= number_format($row['amount'],2) ?>', '<?= date('M j, Y', strtotime($row['expense_date'])) ?>')">
                                🗑️
                            </button>
                        </div>
                        <?php else: ?>
                        <span class="locked-notice">🔒 Paid — locked</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div><!-- /sa-expenses-wrap -->

<!-- ═══════════════════════════════════════
     ADD MODAL
═══════════════════════════════════════ -->
<div class="modal-overlay" id="addModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3>+ Record New Expense</h3>
            <button class="modal-close" onclick="closeModal('addModal')">✕</button>
        </div>
        <form method="POST" action="<?= $_SERVER['PHP_SELF'] ?>">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="info-box warn" style="margin-bottom:16px;">
                    <span class="warn-text">⏳ This expense will be saved as <strong>Active</strong>. The shop balance is only deducted when you mark it paid.</span>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Category <span style="color:#dc2626">*</span></label>
                        <select name="category" required>
                            <option value="">— Select —</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat ?>"><?= catIcon($cat) ?> <?= catLabel($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount ($) <span style="color:#dc2626">*</span></label>
                        <input type="number" name="amount" min="0.01" step="0.01" placeholder="0.00" required>
                    </div>
                    <div class="form-group">
                        <label>Date <span style="color:#dc2626">*</span></label>
                        <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Vendor / Supplier</label>
                        <input type="text" name="vendor_name" placeholder="e.g. SomaliPower Co.">
                        <span class="field-hint">Optional — who receives the payment</span>
                    </div>
                    <div class="form-group full">
                        <label>Notes</label>
                        <textarea name="notes" placeholder="Any additional details…"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save as Active</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════
     MARK PAID MODAL
═══════════════════════════════════════ -->
<div class="modal-overlay" id="payModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3>💳 Mark Expense as Paid</h3>
            <button class="modal-close" onclick="closeModal('payModal')">✕</button>
        </div>
        <form method="POST" action="<?= $_SERVER['PHP_SELF'] ?>">
            <input type="hidden" name="action" value="mark_paid">
            <input type="hidden" name="id" id="payId">
            <div class="modal-body">
                <!-- Expense summary -->
                <div class="info-box green">
                    <div class="info-row"><span class="info-label">Category</span><span class="info-value" id="payCat">—</span></div>
                    <div class="info-row"><span class="info-label">Vendor</span><span class="info-value" id="payVendor">—</span></div>
                    <div class="info-row"><span class="info-label">Amount</span><span class="info-value" id="payAmount" style="color:#dc2626;">—</span></div>
                    <div class="info-row"><span class="info-label">Date</span><span class="info-value" id="payDate">—</span></div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Payment Method <span style="color:#dc2626">*</span></label>
                        <select name="payment_method" id="payMethod" required>
                            <option value="">— Select —</option>
                            <option value="cash">Cash</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Reference / Transaction ID</label>
                        <input type="text" name="payment_reference" id="payRef" placeholder="Optional">
                        <span class="field-hint">e.g. Waafi transaction ID</span>
                    </div>
                </div>

                <p class="danger-text" style="margin-top:14px;">
                    ⚠️ This will deduct <strong id="payAmountWarn">$0.00</strong> from the shop balance and lock this expense permanently.
                </p>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-secondary" onclick="closeModal('payModal')">Cancel</button>
                <button type="submit" class="btn btn-success">Confirm Payment</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════
     EDIT MODAL
═══════════════════════════════════════ -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3>✏️ Edit Active Expense</h3>
            <button class="modal-close" onclick="closeModal('editModal')">✕</button>
        </div>
        <form method="POST" action="<?= $_SERVER['PHP_SELF'] ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Category <span style="color:#dc2626">*</span></label>
                        <select name="category" id="editCategory" required>
                            <option value="">— Select —</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat ?>"><?= catIcon($cat) ?> <?= catLabel($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount ($) <span style="color:#dc2626">*</span></label>
                        <input type="number" name="amount" id="editAmount" min="0.01" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Date <span style="color:#dc2626">*</span></label>
                        <input type="date" name="expense_date" id="editExpenseDate" required>
                    </div>
                    <div class="form-group">
                        <label>Vendor / Supplier</label>
                        <input type="text" name="vendor_name" id="editVendorName" placeholder="Optional">
                    </div>
                    <div class="form-group full">
                        <label>Notes</label>
                        <textarea name="notes" id="editNotes"></textarea>
                    </div>
                </div>
                <p style="font-size:.78rem; color:#9ca3af; margin-top:10px;">
                    Changes are saved immediately. No ledger impact until you mark this expense paid.
                </p>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════
     DELETE MODAL
═══════════════════════════════════════ -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box" style="max-width: 420px;">
        <div class="modal-head">
            <h3>🗑️ Delete Active Expense</h3>
            <button class="modal-close" onclick="closeModal('deleteModal')">✕</button>
        </div>
        <form method="POST" action="<?= $_SERVER['PHP_SELF'] ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId">
            <div class="modal-body">
                <div class="info-box red">
                    <div class="info-row"><span class="info-label">Category</span><span class="info-value" id="deleteCat">—</span></div>
                    <div class="info-row"><span class="info-label">Vendor</span><span class="info-value" id="deleteVendor">—</span></div>
                    <div class="info-row"><span class="info-label">Amount</span><span class="info-value" id="deleteAmount">—</span></div>
                    <div class="info-row"><span class="info-label">Date</span><span class="info-value" id="deleteDate">—</span></div>
                </div>
                <p class="danger-text">⚠️ This expense is active and has not hit the ledger. Deleting simply removes the record. This cannot be undone.</p>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" class="btn btn-danger">Yes, Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.modal-overlay').forEach(function(el) {
    el.addEventListener('click', function(e) { if (e.target === el) closeModal(el.id); });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(function(el) {
            el.classList.remove('open');
        });
    }
});

function openAddModal() { openModal('addModal'); }

function openPayModal(data) {
    document.getElementById('payId').value          = data.id;
    document.getElementById('payCat').textContent   = data.category;
    document.getElementById('payVendor').textContent = data.vendor_name || '—';
    document.getElementById('payAmount').textContent = '$' + data.amount;
    document.getElementById('payDate').textContent   = data.expense_date;
    document.getElementById('payAmountWarn').textContent = '$' + data.amount;
    document.getElementById('payMethod').value = '';
    document.getElementById('payRef').value    = '';
    openModal('payModal');
}

function openEditModal(row) {
    document.getElementById('editId').value          = row.id;
    document.getElementById('editCategory').value    = row.category;
    document.getElementById('editAmount').value      = row.amount;
    document.getElementById('editExpenseDate').value = row.expense_date;
    document.getElementById('editVendorName').value  = row.vendor_name || '';
    document.getElementById('editNotes').value       = row.notes || '';
    openModal('editModal');
}

function openDeleteModal(id, cat, vendor, amount, date) {
    document.getElementById('deleteId').value             = id;
    document.getElementById('deleteCat').textContent      = cat;
    document.getElementById('deleteVendor').textContent   = vendor || '—';
    document.getElementById('deleteAmount').textContent   = '$' + amount;
    document.getElementById('deleteDate').textContent     = date;
    openModal('deleteModal');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
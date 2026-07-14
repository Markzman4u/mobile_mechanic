<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

// Staff admin only — super admin uses super_admin/inventory.php
if (isSuperAdmin()) {
    header('Location: ' . getBasePath() . 'super_admin/inventory.php');
    exit;
}

$pdo     = getPDO();
$baseUrl = getBasePath();
$selfUrl = $baseUrl . 'admin/inventory.php';
$userId  = $_SESSION['user_id'];

const STAFF_MAX_QTY = 10; // hard cap per transaction for staff admins

// ─── Flash messages ────────────────────────────────────────────────────────────
$success = $_SESSION['inv_success'] ?? null;
$error   = $_SESSION['inv_error']   ?? null;
unset($_SESSION['inv_success'], $_SESSION['inv_error']);

// ─── Helper: fetch live shop balance ──────────────────────────────────────────
function getLiveBalance($pdo) {
    return floatval($pdo->query(
        "SELECT
            COALESCE(SUM(CASE WHEN direction='in'  THEN amount ELSE 0 END), 0) -
            COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END), 0) AS balance
         FROM balance_ledger"
    )->fetchColumn());
}

// ─── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Emergency add — new part not in catalog ───────────────────────────────
    if ($action === 'add') {
        $name      = trim($_POST['name']               ?? '');
        $sku       = trim($_POST['sku']                ?? '') ?: null;
        $desc      = trim($_POST['description']        ?? '') ?: null;
        $cost      = floatval($_POST['cost_price']     ?? 0);
        $sell      = floatval($_POST['sell_price']     ?? 0);
        $stock     = max(0, intval($_POST['stock_quantity'] ?? 0));
        $threshold = max(0, intval($_POST['low_stock_threshold'] ?? 0));

        if ($name === '') {
            $_SESSION['inv_error'] = 'Part name is required.';
            header('Location: ' . $selfUrl);
            exit;
        }

        if ($stock > STAFF_MAX_QTY) {
            $_SESSION['inv_error'] = 'You can add a maximum of ' . STAFF_MAX_QTY . ' units per transaction. '
                . 'Ask the super admin to add larger quantities.';
            header('Location: ' . $selfUrl);
            exit;
        }

        if ($cost <= 0) {
            $_SESSION['inv_error'] = 'Cost price must be greater than zero.';
            header('Location: ' . $selfUrl);
            exit;
        }

        if ($stock > 0) {
            $totalCost = round($cost * $stock, 2);
            $freshBal  = getLiveBalance($pdo);

            if ($freshBal <= 0) {
                $_SESSION['inv_error'] = 'Shop balance is $0.00. The super admin must top up the balance before stock can be added.';
                header('Location: ' . $selfUrl);
                exit;
            }
            if ($totalCost > $freshBal) {
                $_SESSION['inv_error'] = 'Insufficient balance. Adding ' . $stock . ' x $'
                    . number_format($cost, 2) . ' costs $' . number_format($totalCost, 2)
                    . ' but the shop balance is only $' . number_format($freshBal, 2) . '.';
                header('Location: ' . $selfUrl);
                exit;
            }
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO parts_catalog
                    (name, sku, description, cost_price, sell_price,
                     stock_quantity, low_stock_threshold, is_active, created_by)
                 VALUES (:name, :sku, :desc, :cost, :sell, :stock, :threshold, 1, :by)"
            );
            $stmt->execute([
                ':name'      => $name,
                ':sku'       => $sku,
                ':desc'      => $desc,
                ':cost'      => $cost,
                ':sell'      => $sell,
                ':stock'     => $stock,
                ':threshold' => $threshold,
                ':by'        => $userId,
            ]);
            $partId = (int)$pdo->lastInsertId();

            $ledgerId  = null;
            $totalCost = 0;

            if ($stock > 0) {
                $totalCost = round($cost * $stock, 2);

                $ledger = $pdo->prepare(
                    "INSERT INTO balance_ledger
                        (type, direction, amount, notes, created_by)
                     VALUES ('inventory_purchase', 'out', :amount, :notes, :by)"
                );
                $ledger->execute([
                    ':amount' => $totalCost,
                    ':notes'  => '[Staff] New part: ' . $name . ' (' . $stock . ' units @ $' . number_format($cost, 2) . ')',
                    ':by'     => $userId,
                ]);
                $ledgerId = (int)$pdo->lastInsertId();

                $log = $pdo->prepare(
                    "INSERT INTO parts_catalog_log
                        (part_id, action, quantity_change, cost_per_unit, total_cost,
                         balance_ledger_id, performed_by, role_at_time)
                     VALUES (:part, 'add_new', :qty, :cpu, :total, :lid, :by, 'staff_admin')"
                );
                $log->execute([
                    ':part'  => $partId,
                    ':qty'   => $stock,
                    ':cpu'   => $cost,
                    ':total' => $totalCost,
                    ':lid'   => $ledgerId,
                    ':by'    => $userId,
                ]);
            }

            $pdo->commit();

            $balNote = $stock > 0
                ? ' $' . number_format($totalCost, 2) . ' deducted from shop balance.'
                : '';
            $_SESSION['inv_success'] = 'Part <strong>' . e($name) . '</strong> added successfully.' . $balNote;

        } catch (Exception $ex) {
            $pdo->rollBack();
            $_SESSION['inv_error'] = 'Failed to add part: ' . $ex->getMessage();
        }

        header('Location: ' . $selfUrl);
        exit;
    }

    // ── Emergency restock ─────────────────────────────────────────────────────
    if ($action === 'restock') {
        $id  = intval($_POST['id']  ?? 0);
        $qty = intval($_POST['qty'] ?? 0);

        if ($id < 1 || $qty < 1) {
            $_SESSION['inv_error'] = 'Invalid restock data.';
            header('Location: ' . $selfUrl);
            exit;
        }

        if ($qty > STAFF_MAX_QTY) {
            $_SESSION['inv_error'] = 'You can restock a maximum of ' . STAFF_MAX_QTY . ' units per transaction. '
                . 'Ask the super admin to add larger quantities.';
            header('Location: ' . $selfUrl);
            exit;
        }

        $partStmt = $pdo->prepare(
            "SELECT name, cost_price FROM parts_catalog WHERE id = :id AND is_active = 1"
        );
        $partStmt->execute([':id' => $id]);
        $part = $partStmt->fetch(PDO::FETCH_ASSOC);

        if (!$part) {
            $_SESSION['inv_error'] = 'Part not found or is inactive.';
            header('Location: ' . $selfUrl);
            exit;
        }

        $cost      = floatval($part['cost_price']);
        $totalCost = round($cost * $qty, 2);
        $freshBal  = getLiveBalance($pdo);

        if ($freshBal <= 0) {
            $_SESSION['inv_error'] = 'Shop balance is $0.00. The super admin must top up the balance before restocking.';
            header('Location: ' . $selfUrl);
            exit;
        }
        if ($totalCost > $freshBal) {
            $_SESSION['inv_error'] = 'Insufficient balance. Restocking ' . $qty . ' x $'
                . number_format($cost, 2) . ' costs $' . number_format($totalCost, 2)
                . ' but the shop balance is only $' . number_format($freshBal, 2) . '.';
            header('Location: ' . $selfUrl);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $upd = $pdo->prepare(
                "UPDATE parts_catalog SET stock_quantity = stock_quantity + :qty WHERE id = :id"
            );
            $upd->execute([':qty' => $qty, ':id' => $id]);

            $ledger = $pdo->prepare(
                "INSERT INTO balance_ledger
                    (type, direction, amount, notes, created_by)
                 VALUES ('inventory_purchase', 'out', :amount, :notes, :by)"
            );
            $ledger->execute([
                ':amount' => $totalCost,
                ':notes'  => '[Staff] Restock: ' . $part['name'] . ' (' . $qty . ' units @ $' . number_format($cost, 2) . ')',
                ':by'     => $userId,
            ]);
            $ledgerId = (int)$pdo->lastInsertId();

            $log = $pdo->prepare(
                "INSERT INTO parts_catalog_log
                    (part_id, action, quantity_change, cost_per_unit, total_cost,
                     balance_ledger_id, performed_by, role_at_time)
                 VALUES (:part, 'restock', :qty, :cpu, :total, :lid, :by, 'staff_admin')"
            );
            $log->execute([
                ':part'  => $id,
                ':qty'   => $qty,
                ':cpu'   => $cost,
                ':total' => $totalCost,
                ':lid'   => $ledgerId,
                ':by'    => $userId,
            ]);

            $pdo->commit();
            $_SESSION['inv_success'] = 'Restocked <strong>' . e($part['name']) . '</strong> by '
                . $qty . ' units. $' . number_format($totalCost, 2) . ' deducted from shop balance.';

        } catch (Exception $ex) {
            $pdo->rollBack();
            $_SESSION['inv_error'] = 'Restock failed: ' . $ex->getMessage();
        }

        header('Location: ' . $selfUrl);
        exit;
    }
}

// ─── Filters ───────────────────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? 'all';
$filterSearch = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];

if ($filterStatus === 'active')    { $where[] = 'p.is_active = 1'; }
if ($filterStatus === 'inactive')  { $where[] = 'p.is_active = 0'; }
if ($filterStatus === 'low_stock') { $where[] = 'p.low_stock_threshold > 0 AND p.stock_quantity <= p.low_stock_threshold'; }

if ($filterSearch !== '') {
    $where[]       = '(p.name LIKE :q1 OR p.sku LIKE :q2)';
    $params[':q1'] = '%' . $filterSearch . '%';
    $params[':q2'] = '%' . $filterSearch . '%';
}

$whereSQL = implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT p.*
     FROM   parts_catalog p
     WHERE  {$whereSQL}
     ORDER  BY p.is_active DESC, p.name ASC"
);
$stmt->execute($params);
$parts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── Summary counts ────────────────────────────────────────────────────────────
$summary = $pdo->query(
    "SELECT
        COUNT(*)                                                               AS total,
        SUM(is_active = 1)                                                     AS active,
        SUM(low_stock_threshold > 0 AND stock_quantity <= low_stock_threshold
            AND is_active = 1)                                                 AS low_stock,
        SUM(stock_quantity = 0 AND is_active = 1)                              AS out_of_stock
     FROM parts_catalog"
)->fetch(PDO::FETCH_ASSOC);

$shopBalance = getLiveBalance($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Inventory - Mobile Mechanic</title>
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>assets/css/style.css">
    <style>
        /* ── Tiles ── */
        .inv-tiles {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }
        .inv-tile {
            background: #fff;
            border-radius: 10px;
            padding: 16px 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
        }
        .inv-tile .tile-label {
            font-size: .72rem;
            color: #888;
            text-transform: uppercase;
            letter-spacing: .05em;
            margin-bottom: 4px;
        }
        .inv-tile .tile-value {
            font-size: 1.55rem;
            font-weight: 700;
            color: #2d2d2d;
        }
        .tile-orange .tile-value { color: #ff6600; }
        .tile-red    .tile-value { color: #dc3545; }
        .tile-green  .tile-value { color: #28a745; }
        .tile-blue   .tile-value { color: #1565c0; font-size: 1.2rem; }

        /* ── Staff notice banner ── */
        .staff-notice {
            background: #fff8f0;
            border: 1.5px solid #ff6600;
            border-radius: 10px;
            padding: 12px 16px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 20px;
            font-size: .88rem;
            color: #2d2d2d;
            line-height: 1.5;
        }
        .staff-notice .notice-icon { font-size: 1.3rem; flex-shrink: 0; margin-top: 1px; }
        .staff-notice strong { color: #e65100; }

        /* ── Filter row ── */
        .filter-row {
            display: flex;
            flex-wrap: nowrap;
            gap: 12px;
            margin-bottom: 16px;
            align-items: flex-end;
        }
        .filter-row .filter-search {
            flex: 1;
            min-width: 0;
        }
        .filter-row .filter-status {
            width: 170px;
            flex-shrink: 0;
        }
        .filter-row .filter-actions {
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            gap: 0;
        }
        .filter-row .filter-btn-group {
            display: flex;
            gap: 8px;
        }
        .filter-label {
            display: block;
            font-size: .85rem;
            font-weight: 600;
            color: #555;
            margin-bottom: 5px;
        }
        .filter-row select,
        .filter-row input[type=text] {
            width: 100%;
            padding: 8px 10px;
            border: 1.5px solid #ddd;
            border-radius: 8px;
            font-size: .88rem;
            box-sizing: border-box;
            margin: 0;
        }
        .filter-row select:focus,
        .filter-row input[type=text]:focus { outline: none; border-color: #ff6600; }

        @media (max-width: 600px) {
            .filter-row { flex-wrap: wrap; }
            .filter-row .filter-search,
            .filter-row .filter-status { width: 100%; }
            .filter-row .filter-actions { width: 100%; }
            .filter-row .filter-btn-group { width: 100%; }
            .filter-row .filter-btn-group .btn { flex: 1; text-align: center; }
        }

        /* ── Scrollable table wrapper ── */
        .table-scroll-wrap {
            position: relative;
            border-radius: 8px;
            border: 1px solid #e9e9e9;
            overflow: hidden;
        }
        .table-scroll-wrap::after {
            content: '';
            position: absolute;
            top: 0; right: 0; bottom: 0;
            width: 32px;
            background: linear-gradient(to right, transparent, rgba(255,255,255,.85));
            pointer-events: none;
            opacity: 1;
            transition: opacity .2s;
        }
        .table-scroll-wrap.no-overflow::after { opacity: 0; }

        .table-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: #ccc #f5f5f5;
        }
        .table-scroll::-webkit-scrollbar       { height: 6px; }
        .table-scroll::-webkit-scrollbar-track { background: #f5f5f5; }
        .table-scroll::-webkit-scrollbar-thumb { background: #ccc; border-radius: 3px; }
        .table-scroll::-webkit-scrollbar-thumb:hover { background: #aaa; }

        table { margin: 0; border-radius: 0; border: none; width: max-content; min-width: 100%; }
        table th {
            background: #2d2d2d;
            color: #fff;
            font-weight: 600;
            white-space: nowrap;
            padding: 11px 14px;
        }
        table td { vertical-align: middle; padding: 10px 14px; white-space: nowrap; }
        table tbody tr:last-child td { border-bottom: none; }

        table th:nth-child(1), table td:nth-child(1) {
            position: sticky; left: 0; z-index: 2; background: #2d2d2d;
        }
        table td:nth-child(1) {
            background: #fff;
            border-right: 1px solid #e9e9e9;
            box-shadow: 2px 0 4px rgba(0,0,0,.06);
        }
        table tbody tr:hover td:nth-child(1) { background: #f9f9f9; }

        table th:nth-child(2), table td:nth-child(2) {
            position: sticky; left: 46px; z-index: 2; background: #2d2d2d;
        }
        table td:nth-child(2) {
            background: #fff;
            border-right: 2px solid #e2e2e2;
            box-shadow: 3px 0 6px rgba(0,0,0,.07);
            min-width: 170px;
        }
        table tbody tr:hover td:nth-child(2) { background: #f9f9f9; }

        .part-name { font-weight: 600; color: #2d2d2d; }
        .part-sku  { font-size: .78rem; color: #999; margin-top: 2px; }

        td.desc-cell {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #777;
            font-size: .82rem;
        }

        .price-cell { text-align: right; }
        .stock-cell { text-align: center; }

        .stock-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-weight: 700;
            font-size: .85rem;
        }
        .stock-ok   { background: #e8f5e9; color: #2e7d32; }
        .stock-low  { background: #fff3e0; color: #e65100; }
        .stock-zero { background: #fce4ec; color: #c62828; }

        .badge-active   { background: #e8f5e9; color: #2e7d32; padding: 3px 10px; border-radius: 12px; font-size:.8rem; }
        .badge-inactive { background: #f5f5f5; color: #999;    padding: 3px 10px; border-radius: 12px; font-size:.8rem; }

        .btn-restock {
            font-size: .82rem;
            font-weight: 600;
            color: #1565c0;
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
        }
        .btn-restock:hover { text-decoration: underline; }
        .btn-restock:disabled { color: #bbb; cursor: not-allowed; text-decoration: none; }

        .scroll-hint {
            font-size: .75rem;
            color: #aaa;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* ── Modals ── */
        .modal-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: #fff;
            border-radius: 12px;
            padding: 28px 30px;
            width: 100%;
            max-width: 480px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 8px 32px rgba(0,0,0,.2);
        }
        .modal-box h3 {
            margin: 0 0 6px;
            font-size: 1.1rem;
            color: #2d2d2d;
            border-bottom: 2px solid #ff6600;
            padding-bottom: 10px;
        }
        .modal-box .modal-subtitle { font-size: .82rem; color: #888; margin: 0 0 18px; }
        .form-row { margin-bottom: 14px; }
        .form-row label { display: block; font-size: .85rem; font-weight: 600; color: #555; margin-bottom: 5px; }
        .form-row input,
        .form-row textarea,
        .form-row select { width: 100%; box-sizing: border-box; }
        .form-row textarea { resize: vertical; min-height: 70px; }
        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
        .modal-actions .btn-cancel { pointer-events: auto !important; opacity: 1 !important; cursor: pointer !important; }

        .cap-bar {
            background: #fff3e0;
            border: 1.5px solid #ffcc80;
            border-radius: 8px;
            padding: 9px 13px;
            font-size: .83rem;
            color: #e65100;
            margin-bottom: 14px;
        }
        .cap-bar strong { color: #bf360c; }

        .balance-row {
            background: #f9f9f9;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: .85rem;
            color: #555;
            margin-bottom: 14px;
        }
        .balance-row .bal-amount { font-weight: 700; color: #1565c0; }
        .balance-row.bal-zero .bal-amount { color: #c62828; }

        .balance-zero-warning {
            background: #fce4ec;
            border: 1.5px solid #ef9a9a;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: .85rem;
            color: #c62828;
            margin-bottom: 14px;
            display: none;
        }
        .balance-zero-warning strong { color: #b71c1c; }

        .cost-preview {
            background: #fff3e0;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: .88rem;
            color: #555;
            margin-top: 6px;
            display: none;
        }
        .cost-preview .preview-amount { font-size: 1.05rem; font-weight: 700; color: #c62828; }
        .cost-preview.insufficient { background: #fce4ec; border: 1.5px solid #ef9a9a; }

        .flash { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; }
        .flash-success { background: #e8f5e9; color: #2e7d32; border-left: 4px solid #43a047; }
        .flash-error   { background: #fce4ec; color: #c62828; border-left: 4px solid #e53935; }

        @media (max-width: 768px) {
            .form-grid-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/navbar.php'; ?>

<div style="display:flex; min-height:calc(100vh - 60px);">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main style="flex:1; padding:28px 24px; min-width:0;">
        <div class="card">

            <!-- Page header -->
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
                <div>
                    <h2 style="margin:0 0 6px;">Parts Inventory</h2>
                    <p class="muted" style="margin:0;">View stock levels and perform emergency restocks.</p>
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button class="btn btn-primary" onclick="openAddModal()">+ Emergency Add</button>
                    <a class="btn" href="<?php echo $baseUrl; ?>admin/dashboard.php">Back to Dashboard</a>
                </div>
            </div>

            <!-- Staff permission notice -->
            <div class="staff-notice">
                <span class="notice-icon">&#9888;&#65039;</span>
                <div>
                    <strong>Limited inventory access.</strong>
                    You can add new parts or restock existing parts up to
                    <strong><?php echo STAFF_MAX_QTY; ?> units per transaction</strong> for emergency use.
                    Prices, descriptions, and active status can only be changed by the super admin.
                    All stock additions are deducted from the shop balance immediately.
                </div>
            </div>

            <!-- Flash messages -->
            <?php if ($success): ?>
                <div class="flash flash-success"><?php echo $success; ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="flash flash-error"><?php echo e($error); ?></div>
            <?php endif; ?>

            <!-- Summary tiles -->
            <div class="inv-tiles">
                <div class="inv-tile">
                    <div class="tile-label">Total Parts</div>
                    <div class="tile-value"><?php echo number_format($summary['total'] ?? 0); ?></div>
                </div>
                <div class="inv-tile tile-green">
                    <div class="tile-label">Active Parts</div>
                    <div class="tile-value"><?php echo number_format($summary['active'] ?? 0); ?></div>
                </div>
                <div class="inv-tile tile-red">
                    <div class="tile-label">Low / Near Zero</div>
                    <div class="tile-value"><?php echo number_format($summary['low_stock'] ?? 0); ?></div>
                </div>
                <div class="inv-tile tile-red">
                    <div class="tile-label">Out of Stock</div>
                    <div class="tile-value"><?php echo number_format($summary['out_of_stock'] ?? 0); ?></div>
                </div>
                <div class="inv-tile tile-blue">
                    <div class="tile-label">Shop Balance</div>
                    <div class="tile-value" style="color:<?php echo $shopBalance > 0 ? '#1565c0' : '#c62828'; ?>;">
                        $<?php echo number_format($shopBalance, 2); ?>
                    </div>
                </div>
            </div>

            <!-- Filter form -->
            <form method="GET" action="<?php echo $selfUrl; ?>" style="margin-bottom:18px;">
                <div class="filter-row">

                    <div class="filter-search">
                        <span class="filter-label">Search</span>
                        <input type="text" name="q"
                               value="<?php echo e($filterSearch); ?>"
                               placeholder="Name or SKU...">
                    </div>

                    <div class="filter-status">
                        <span class="filter-label">Status</span>
                        <select name="status">
                            <option value="all"<?php       echo $filterStatus === 'all'       ? ' selected' : ''; ?>>All</option>
                            <option value="active"<?php    echo $filterStatus === 'active'    ? ' selected' : ''; ?>>Active</option>
                            <option value="inactive"<?php  echo $filterStatus === 'inactive'  ? ' selected' : ''; ?>>Inactive</option>
                            <option value="low_stock"<?php echo $filterStatus === 'low_stock' ? ' selected' : ''; ?>>&#9888; Low Stock</option>
                        </select>
                    </div>

                    <!-- invisible spacer label aligns buttons flush with inputs -->
                    <div class="filter-actions">
                        <span class="filter-label" aria-hidden="true" style="visibility:hidden;">&nbsp;</span>
                        <div class="filter-btn-group">
                            <button class="btn btn-primary" type="submit">Filter</button>
                            <?php if ($filterSearch || $filterStatus !== 'all'): ?>
                                <a class="btn" href="<?php echo $selfUrl; ?>">Clear</a>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </form>

            <!-- Scroll hint -->
            <div class="scroll-hint" id="scrollHint" style="display:none;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="9 18 15 12 9 6"></polyline>
                </svg>
                Scroll right to see all columns
            </div>

            <!-- Parts table -->
            <div class="table-scroll-wrap" id="tableWrap">
                <div class="table-scroll" id="tableScroll">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Part Name / SKU</th>
                                <th>Description</th>
                                <th style="text-align:right;">Sell Price</th>
                                <th style="text-align:center;">Stock&nbsp;/&nbsp;Alert</th>
                                <th style="text-align:center;">Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($parts)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding:40px; color:#999; white-space:normal;">
                                    No parts found<?php echo $filterSearch ? ' for "' . e($filterSearch) . '"' : ''; ?>.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($parts as $p):
                                $isLow      = $p['low_stock_threshold'] > 0 && $p['stock_quantity'] <= $p['low_stock_threshold'];
                                $isZero     = $p['stock_quantity'] <= 0;
                                $stockClass = $isZero ? 'stock-zero' : ($isLow ? 'stock-low' : 'stock-ok');
                                $canRestock = $p['is_active'] == 1;
                            ?>
                            <tr<?php echo !$p['is_active'] ? ' style="opacity:.55;"' : ''; ?>>
                                <td><?php echo $p['id']; ?></td>
                                <td>
                                    <div class="part-name"><?php echo e($p['name']); ?></div>
                                    <?php if ($p['sku']): ?>
                                        <div class="part-sku">SKU: <?php echo e($p['sku']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="desc-cell" title="<?php echo e($p['description'] ?? ''); ?>">
                                    <?php echo $p['description'] ? e($p['description']) : '<span style="color:#ccc;">&#8212;</span>'; ?>
                                </td>
                                <td class="price-cell">$<?php echo number_format($p['sell_price'], 2); ?></td>
                                <td class="stock-cell">
                                    <span class="stock-badge <?php echo $stockClass; ?>">
                                        <?php echo $p['stock_quantity']; ?>
                                        <?php if ($p['low_stock_threshold'] > 0): ?>
                                            <small style="font-weight:400; opacity:.75;">/ <?php echo $p['low_stock_threshold']; ?></small>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <?php if ($p['is_active']): ?>
                                        <span class="badge-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($canRestock): ?>
                                        <button class="btn-restock"
                                                onclick="openRestockModal(
                                                    <?php echo (int)$p['id']; ?>,
                                                    <?php echo htmlspecialchars(json_encode($p['name']), ENT_QUOTES); ?>,
                                                    <?php echo (int)$p['stock_quantity']; ?>,
                                                    <?php echo floatval($p['cost_price']); ?>
                                                ); return false;">
                                            Restock
                                        </button>
                                    <?php else: ?>
                                        <span style="font-size:.82rem; color:#bbb;">Inactive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="muted" style="margin-top:10px; font-size:.82rem;">
                Showing <?php echo count($parts); ?> part<?php echo count($parts) !== 1 ? 's' : ''; ?>.
                Inactive parts are shown but cannot be restocked. Cost prices are set by the super admin.
            </p>

        </div><!-- /.card -->
    </main>
</div>

<!-- ============================================================
     EMERGENCY ADD MODAL
============================================================ -->
<div class="modal-overlay" id="addModal">
    <div class="modal-box">
        <h3>Emergency Add - New Part</h3>
        <p class="modal-subtitle">Use this only when a required part is not in the catalog and the super admin is unavailable.</p>

        <div class="cap-bar">
            Maximum quantity: <strong><?php echo STAFF_MAX_QTY; ?> units</strong> per transaction.
        </div>

        <div class="balance-row <?php echo $shopBalance <= 0 ? 'bal-zero' : ''; ?>" id="add_balance_row">
            Shop balance: <span class="bal-amount">$<?php echo number_format($shopBalance, 2); ?></span>
        </div>

        <div class="balance-zero-warning" id="add_zero_warning">
            <strong>Insufficient balance.</strong>
            The shop balance is $0.00 -- stock cannot be added until the super admin tops up the balance.
            Parts with <strong>0 initial stock</strong> can still be added to the catalog.
        </div>

        <form method="POST" action="<?php echo $selfUrl; ?>" id="addPartForm">
            <input type="hidden" name="action" value="add">

            <div class="form-row">
                <label>Part Name <span style="color:#dc3545;">*</span></label>
                <input type="text" name="name" id="add_name" required placeholder="e.g. Brake Pad Set">
            </div>
            <div class="form-row">
                <label>SKU / Part Number</label>
                <input type="text" name="sku" placeholder="e.g. BP-002 (optional)">
            </div>
            <div class="form-row">
                <label>Description</label>
                <textarea name="description" placeholder="Optional notes..."></textarea>
            </div>
            <div class="form-grid-2">
                <div class="form-row">
                    <label>Cost Price ($) <span style="color:#dc3545;">*</span></label>
                    <input type="number" name="cost_price" id="add_cost"
                           step="0.01" min="0.01" value="" placeholder="0.00"
                           required oninput="updateAddPreview()">
                </div>
                <div class="form-row">
                    <label>Sell Price ($)</label>
                    <input type="number" name="sell_price"
                           step="0.01" min="0" value="" placeholder="0.00">
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-row">
                    <label>Quantity (max <?php echo STAFF_MAX_QTY; ?>)</label>
                    <input type="number" name="stock_quantity" id="add_stock"
                           min="0" max="<?php echo STAFF_MAX_QTY; ?>" value="1"
                           oninput="updateAddPreview()">
                </div>
                <div class="form-row">
                    <label>Low Stock Alert &lt;=</label>
                    <input type="number" name="low_stock_threshold" min="0" value="0" placeholder="0 = no alert">
                </div>
            </div>

            <div class="cost-preview" id="add_preview">
                Will deduct from balance:
                <span class="preview-amount" id="add_preview_amount">$0.00</span>
                <span id="add_preview_detail" style="color:#888; font-size:.82rem;"></span>
                <div id="add_insufficient_msg" style="display:none; margin-top:6px; color:#c62828; font-size:.82rem; font-weight:600;">
                    &#9888; This exceeds the current shop balance.
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-cancel" id="add_cancel_btn"
                        onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="add_submit_btn"
                        onclick="return confirmAdd()">Add Part</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     RESTOCK MODAL
============================================================ -->
<div class="modal-overlay" id="restockModal">
    <div class="modal-box">
        <h3>Emergency Restock</h3>
        <p class="modal-subtitle">Adds units to an existing part. Max <?php echo STAFF_MAX_QTY; ?> units per transaction.</p>

        <div class="cap-bar">
            Maximum quantity: <strong><?php echo STAFF_MAX_QTY; ?> units</strong> per transaction.
        </div>

        <div class="balance-row <?php echo $shopBalance <= 0 ? 'bal-zero' : ''; ?>" id="restock_balance_row">
            Shop balance: <span class="bal-amount">$<?php echo number_format($shopBalance, 2); ?></span>
        </div>

        <div class="balance-zero-warning" id="restock_zero_warning">
            <strong>Insufficient balance.</strong>
            The shop balance is $0.00 -- restocking is not possible until the super admin tops up the balance.
        </div>

        <form method="POST" action="<?php echo $selfUrl; ?>">
            <input type="hidden" name="action" value="restock">
            <input type="hidden" name="id"     id="restock_id">

            <div class="form-row">
                <label>Part</label>
                <input type="text" id="restock_name_display" disabled style="background:#f5f5f5; color:#555;">
            </div>
            <div class="form-grid-2">
                <div class="form-row">
                    <label>Current Stock</label>
                    <input type="text" id="restock_current_display" disabled style="background:#f5f5f5; color:#555;">
                </div>
                <div class="form-row">
                    <label>Cost Price / unit</label>
                    <input type="text" id="restock_cost_display" disabled style="background:#f5f5f5; color:#555;">
                </div>
            </div>
            <div class="form-row">
                <label>Quantity to Add (max <?php echo STAFF_MAX_QTY; ?>) <span style="color:#dc3545;">*</span></label>
                <input type="number" name="qty" id="restock_qty"
                       min="1" max="<?php echo STAFF_MAX_QTY; ?>" value="1"
                       oninput="updateRestockPreview()">
            </div>

            <div class="cost-preview" id="restock_preview">
                Will deduct from balance:
                <span class="preview-amount" id="restock_preview_amount">$0.00</span>
                <span id="restock_preview_detail" style="color:#888; font-size:.82rem;"></span>
                <div id="restock_insufficient_msg" style="display:none; margin-top:6px; color:#c62828; font-size:.82rem; font-weight:600;">
                    &#9888; This exceeds the current shop balance.
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-cancel" id="restock_cancel_btn"
                        onclick="closeModal('restockModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="restock_submit_btn"
                        onclick="return confirm('This will deduct the restock cost from the shop balance. Proceed?')">
                    Confirm Restock
                </button>
            </div>
        </form>
    </div>
</div>

<script>
var SHOP_BALANCE        = <?php echo json_encode($shopBalance); ?>;
var _restockCostPerUnit = 0;

// Scroll overflow detection
(function () {
    var wrap   = document.getElementById('tableWrap');
    var scroll = document.getElementById('tableScroll');
    var hint   = document.getElementById('scrollHint');
    function check() {
        var overflows = scroll.scrollWidth > scroll.clientWidth;
        hint.style.display = overflows ? 'flex' : 'none';
        wrap.classList.toggle('no-overflow', !overflows);
    }
    scroll.addEventListener('scroll', function () {
        if (scroll.scrollLeft > 10) wrap.classList.add('no-overflow');
        else check();
    });
    check();
    window.addEventListener('resize', check);
})();

function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// Add modal
function openAddModal() {
    document.getElementById('addModal').classList.add('open');
    updateAddPreview();
}

function updateAddPreview() {
    var cost      = parseFloat(document.getElementById('add_cost').value)    || 0;
    var stock     = parseInt(document.getElementById('add_stock').value, 10) || 0;
    var total     = cost * stock;
    var preview   = document.getElementById('add_preview');
    var zeroWarn  = document.getElementById('add_zero_warning');
    var insuffMsg = document.getElementById('add_insufficient_msg');
    var submitBtn = document.getElementById('add_submit_btn');
    document.getElementById('add_cancel_btn').disabled = false;

    if (stock > 0 && cost > 0) {
        if (SHOP_BALANCE <= 0) {
            zeroWarn.style.display  = 'block';
            preview.style.display   = 'none';
            submitBtn.disabled      = true;
            return;
        }
        zeroWarn.style.display = 'none';
        document.getElementById('add_preview_amount').textContent = '$' + total.toFixed(2);
        document.getElementById('add_preview_detail').textContent = ' (' + stock + ' x $' + cost.toFixed(2) + ')';
        preview.style.display = 'block';
        if (total > SHOP_BALANCE) {
            preview.classList.add('insufficient');
            insuffMsg.style.display = 'block';
            submitBtn.disabled      = true;
        } else {
            preview.classList.remove('insufficient');
            insuffMsg.style.display = 'none';
            submitBtn.disabled      = false;
        }
    } else {
        zeroWarn.style.display  = 'none';
        preview.style.display   = 'none';
        submitBtn.disabled      = false;
    }
}

function confirmAdd() {
    var stock = parseInt(document.getElementById('add_stock').value, 10) || 0;
    if (stock > 0) return confirm('This will deduct the inventory cost from the shop balance. Proceed?');
    return true;
}

// Restock modal
function openRestockModal(id, name, currentStock, costPerUnit) {
    _restockCostPerUnit = parseFloat(costPerUnit) || 0;
    document.getElementById('restock_id').value              = id;
    document.getElementById('restock_name_display').value    = name;
    document.getElementById('restock_current_display').value = currentStock + ' units';
    document.getElementById('restock_cost_display').value    = '$' + _restockCostPerUnit.toFixed(2);
    document.getElementById('restock_qty').value             = 1;
    document.getElementById('restock_cancel_btn').disabled   = false;

    var zeroWarn  = document.getElementById('restock_zero_warning');
    var submitBtn = document.getElementById('restock_submit_btn');
    if (SHOP_BALANCE <= 0) {
        zeroWarn.style.display = 'block';
        submitBtn.disabled     = true;
    } else {
        zeroWarn.style.display = 'none';
        submitBtn.disabled     = false;
    }
    updateRestockPreview();
    document.getElementById('restockModal').classList.add('open');
}

function updateRestockPreview() {
    var qty       = parseInt(document.getElementById('restock_qty').value, 10) || 0;
    var total     = _restockCostPerUnit * qty;
    var preview   = document.getElementById('restock_preview');
    var insuffMsg = document.getElementById('restock_insufficient_msg');
    var submitBtn = document.getElementById('restock_submit_btn');
    document.getElementById('restock_cancel_btn').disabled = false;

    if (SHOP_BALANCE <= 0) { preview.style.display = 'none'; submitBtn.disabled = true; return; }

    if (qty > 0 && _restockCostPerUnit > 0) {
        document.getElementById('restock_preview_amount').textContent = '$' + total.toFixed(2);
        document.getElementById('restock_preview_detail').textContent = ' (' + qty + ' x $' + _restockCostPerUnit.toFixed(2) + ')';
        preview.style.display = 'block';
        if (total > SHOP_BALANCE) {
            preview.classList.add('insufficient');
            insuffMsg.style.display = 'block';
            submitBtn.disabled      = true;
        } else {
            preview.classList.remove('insufficient');
            insuffMsg.style.display = 'none';
            submitBtn.disabled      = false;
        }
    } else {
        preview.style.display = 'none';
        submitBtn.disabled    = false;
    }
}

document.querySelectorAll('.modal-overlay').forEach(function(o) {
    o.addEventListener('click', function(e) { if (e.target === o) o.classList.remove('open'); });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
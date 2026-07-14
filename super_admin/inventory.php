<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();

$pdo     = getPDO();
$baseUrl = getBasePath();

$selfUrl = $baseUrl . 'super_admin/inventory.php';

// ─── Flash messages ────────────────────────────────────────────────────────────
$success = $_SESSION['inv_success'] ?? null;
$error   = $_SESSION['inv_error']   ?? null;
unset($_SESSION['inv_success'], $_SESSION['inv_error']);

// ─── Current shop balance (needed for POST guard too) ─────────────────────────
$balRow = $pdo->query(
    "SELECT
        COALESCE(SUM(CASE WHEN direction='in'  THEN amount ELSE 0 END), 0) -
        COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END), 0) AS balance
     FROM balance_ledger"
)->fetch(PDO::FETCH_ASSOC);
$shopBalance = floatval($balRow['balance'] ?? 0);

// ─── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Add new part ──
    if ($action === 'add') {
        $name      = trim($_POST['name']               ?? '');
        $sku       = trim($_POST['sku']                ?? '') ?: null;
        $desc      = trim($_POST['description']        ?? '') ?: null;
        $cost      = floatval($_POST['cost_price']     ?? 0);
        $sell      = floatval($_POST['sell_price']     ?? 0);
        $stock     = intval($_POST['stock_quantity']   ?? 0);
        $threshold = intval($_POST['low_stock_threshold'] ?? 0);

        if ($name === '') {
            $_SESSION['inv_error'] = 'Part name is required.';
            header('Location: ' . $selfUrl); exit;
        }

        if ($stock > 0) {
            $totalCost = round($cost * $stock, 2);

            $freshBal = floatval($pdo->query(
                "SELECT COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE 0 END),0)
                      - COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END),0)
                 FROM balance_ledger"
            )->fetchColumn());

            if ($freshBal <= 0) {
                $_SESSION['inv_error'] = 'Shop balance is $0.00. Top up the balance before adding stock.';
                header('Location: ' . $selfUrl); exit;
            }
            if ($totalCost > $freshBal) {
                $_SESSION['inv_error'] = 'Insufficient balance. Adding ' . $stock . ' × $'
                    . number_format($cost, 2) . ' costs $' . number_format($totalCost, 2)
                    . ' but balance is only $' . number_format($freshBal, 2) . '.';
                header('Location: ' . $selfUrl); exit;
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
                ':by'        => $_SESSION['user_id'],
            ]);
            $partId = (int)$pdo->lastInsertId();

            if ($stock > 0) {
                $totalCost = round($cost * $stock, 2);

                $ledger = $pdo->prepare(
                    "INSERT INTO balance_ledger
                        (type, direction, amount, notes, created_by)
                     VALUES ('inventory_purchase', 'out', :amount, :notes, :by)"
                );
                $ledger->execute([
                    ':amount' => $totalCost,
                    ':notes'  => '[Super Admin] New part: ' . $name . ' (' . $stock . ' units @ $' . number_format($cost, 2) . ')',
                    ':by'     => $_SESSION['user_id'],
                ]);
                $ledgerId = (int)$pdo->lastInsertId();

                $log = $pdo->prepare(
                    "INSERT INTO parts_catalog_log
                        (part_id, action, quantity_change, cost_per_unit, total_cost,
                         balance_ledger_id, performed_by, role_at_time)
                     VALUES (:part, 'add_new', :qty, :cpu, :total, :lid, :by, 'super_admin')"
                );
                $log->execute([
                    ':part'  => $partId,
                    ':qty'   => $stock,
                    ':cpu'   => $cost,
                    ':total' => $totalCost,
                    ':lid'   => $ledgerId,
                    ':by'    => $_SESSION['user_id'],
                ]);

                $balNote = ' $' . number_format($totalCost, 2) . ' deducted from shop balance.';
            } else {
                $balNote = '';
            }

            $pdo->commit();
            $_SESSION['inv_success'] = 'Part <strong>' . e($name) . '</strong> added successfully.' . $balNote;

        } catch (Exception $ex) {
            $pdo->rollBack();
            $_SESSION['inv_error'] = 'Failed to add part: ' . $ex->getMessage();
        }

        header('Location: ' . $selfUrl);
        exit;
    }

    // ── Edit part ──
    if ($action === 'edit') {
        $id        = intval($_POST['id']               ?? 0);
        $name      = trim($_POST['name']               ?? '');
        $sku       = trim($_POST['sku']                ?? '') ?: null;
        $desc      = trim($_POST['description']        ?? '') ?: null;
        $cost      = floatval($_POST['cost_price']     ?? 0);
        $sell      = floatval($_POST['sell_price']     ?? 0);
        $stock     = intval($_POST['stock_quantity']   ?? 0);
        $threshold = intval($_POST['low_stock_threshold'] ?? 0);
        $active    = isset($_POST['is_active']) ? 1 : 0;

        if ($id < 1 || $name === '') {
            $_SESSION['inv_error'] = 'Invalid data submitted.';
        } else {
            $stmt = $pdo->prepare(
                "UPDATE parts_catalog
                 SET name = :name, sku = :sku, description = :desc,
                     cost_price = :cost, sell_price = :sell,
                     stock_quantity = :stock, low_stock_threshold = :threshold,
                     is_active = :active
                 WHERE id = :id"
            );
            $stmt->execute([
                ':name'      => $name,
                ':sku'       => $sku,
                ':desc'      => $desc,
                ':cost'      => $cost,
                ':sell'      => $sell,
                ':stock'     => $stock,
                ':threshold' => $threshold,
                ':active'    => $active,
                ':id'        => $id,
            ]);
            $_SESSION['inv_success'] = 'Part <strong>' . e($name) . '</strong> updated successfully.';
        }
        header('Location: ' . $selfUrl);
        exit;
    }

    // ── Toggle active/inactive ──
    if ($action === 'toggle') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE parts_catalog SET is_active = NOT is_active WHERE id = :id");
            $stmt->execute([':id' => $id]);
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
    "SELECT p.*, u.full_name AS created_by_name
     FROM   parts_catalog p
     LEFT JOIN users u ON u.id = p.created_by
     WHERE  {$whereSQL}
     ORDER  BY p.name ASC"
);
$stmt->execute($params);
$parts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── Summary counts ────────────────────────────────────────────────────────────
$summary = $pdo->query(
    "SELECT
        COUNT(*)                                                               AS total,
        SUM(is_active = 1)                                                     AS active,
        SUM(is_active = 0)                                                     AS inactive,
        SUM(low_stock_threshold > 0 AND stock_quantity <= low_stock_threshold) AS low_stock,
        SUM(stock_quantity * cost_price)                                       AS inventory_value
     FROM parts_catalog"
)->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Inventory – Mobile Mechanic</title>
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
        .tile-orange .tile-value { color: #ff6600; font-size: 1.2rem; }
        .tile-red    .tile-value { color: #dc3545; }
        .tile-green  .tile-value { color: #28a745; }
        .tile-blue   .tile-value { color: #1565c0; font-size: 1.2rem; }

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
            gap: 8px;
            align-items: flex-end;
        }
        .filter-row .filter-label {
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
        }
        .filter-row select:focus,
        .filter-row input[type=text]:focus { outline: none; border-color: #ff6600; }

        @media (max-width: 600px) {
            .filter-row { flex-wrap: wrap; }
            .filter-row .filter-search,
            .filter-row .filter-status { width: 100%; }
            .filter-row .filter-actions { width: 100%; }
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
        .table-scroll-wrap.no-overflow::after {
            opacity: 0;
        }

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

        table th:nth-child(1),
        table td:nth-child(1) {
            position: sticky;
            left: 0;
            z-index: 2;
            background: #2d2d2d;
        }
        table td:nth-child(1) {
            background: #fff;
            border-right: 1px solid #e9e9e9;
            box-shadow: 2px 0 4px rgba(0,0,0,.06);
        }
        table tbody tr:hover td:nth-child(1) { background: #f9f9f9; }

        table th:nth-child(2),
        table td:nth-child(2) {
            position: sticky;
            left: 46px;
            z-index: 2;
            background: #2d2d2d;
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
            max-width: 180px;
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

        .action-links { display: flex; align-items: center; gap: 6px; }
        .action-link  { font-size: .82rem; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap; }
        .action-sep   { color: #ddd; }

        .scroll-hint {
            font-size: .75rem;
            color: #aaa;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .scroll-hint svg { flex-shrink: 0; }

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
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 8px 32px rgba(0,0,0,.2);
        }
        .modal-box h3 {
            margin: 0 0 20px;
            font-size: 1.1rem;
            color: #2d2d2d;
            border-bottom: 2px solid #ff6600;
            padding-bottom: 10px;
        }
        .form-row { margin-bottom: 14px; }
        .form-row label { display: block; font-size: .85rem; font-weight: 600; color: #555; margin-bottom: 5px; }
        .form-row input,
        .form-row textarea,
        .form-row select { width: 100%; box-sizing: border-box; }
        .form-row textarea { resize: vertical; min-height: 70px; }
        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
        .checkbox-row { display: flex; align-items: center; gap: 8px; }
        .checkbox-row input[type=checkbox] { width: auto; }
        .checkbox-row label { margin: 0; font-size: .9rem; font-weight: 500; color: #2d2d2d; }

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
            background: #fce4ec;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: .88rem;
            color: #555;
            margin-top: 6px;
            display: none;
        }
        .cost-preview .preview-amount {
            font-size: 1.05rem;
            font-weight: 700;
            color: #c62828;
        }
        .cost-preview.insufficient {
            background: #fce4ec;
            border: 1.5px solid #ef9a9a;
        }

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

            <!-- ── Page header ── -->
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
                <div>
                    <h2 style="margin:0 0 6px;">Parts Inventory</h2>
                    <p class="muted" style="margin:0;">Manage catalog parts, prices, and stock levels.</p>
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button class="btn btn-primary" onclick="openAddModal()">+ Add Part</button>
                    <a class="btn" href="<?php echo $baseUrl; ?>super_admin/dashboard.php">Back to Dashboard</a>
                </div>
            </div>

            <!-- ── Flash messages ── -->
            <?php if ($success): ?>
                <div class="flash flash-success"><?php echo $success; ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="flash flash-error"><?php echo e($error); ?></div>
            <?php endif; ?>

            <!-- ── Summary tiles ── -->
            <div class="inv-tiles">
                <div class="inv-tile">
                    <div class="tile-label">Total Parts</div>
                    <div class="tile-value"><?php echo number_format($summary['total'] ?? 0); ?></div>
                </div>
                <div class="inv-tile tile-green">
                    <div class="tile-label">Active</div>
                    <div class="tile-value"><?php echo number_format($summary['active'] ?? 0); ?></div>
                </div>
                <div class="inv-tile">
                    <div class="tile-label">Inactive</div>
                    <div class="tile-value" style="color:#888;"><?php echo number_format($summary['inactive'] ?? 0); ?></div>
                </div>
                <div class="inv-tile tile-red">
                    <div class="tile-label">Low / Out of Stock</div>
                    <div class="tile-value"><?php echo number_format($summary['low_stock'] ?? 0); ?></div>
                </div>
                <div class="inv-tile tile-orange">
                    <div class="tile-label">Inventory Value (Cost)</div>
                    <div class="tile-value">$<?php echo number_format($summary['inventory_value'] ?? 0, 2); ?></div>
                </div>
                <div class="inv-tile tile-blue">
                    <div class="tile-label">Shop Balance</div>
                    <div class="tile-value" style="color:<?php echo $shopBalance > 0 ? '#1565c0' : '#c62828'; ?>;">
                        $<?php echo number_format($shopBalance, 2); ?>
                    </div>
                </div>
            </div>

            <!-- ── Filter form ── -->
            <form method="GET" action="<?php echo $selfUrl; ?>" style="margin-bottom:18px;">
                <div class="filter-row">
                    <div class="filter-search">
                        <span class="filter-label">Search</span>
                        <input type="text" name="q"
                               value="<?php echo e($filterSearch); ?>"
                               placeholder="Name or SKU…">
                    </div>
                    <div class="filter-status">
                        <span class="filter-label">Status</span>
                        <select name="status">
                            <option value="all"<?php       echo $filterStatus === 'all'       ? ' selected' : ''; ?>>All</option>
                            <option value="active"<?php    echo $filterStatus === 'active'    ? ' selected' : ''; ?>>Active</option>
                            <option value="inactive"<?php  echo $filterStatus === 'inactive'  ? ' selected' : ''; ?>>Inactive</option>
                            <option value="low_stock"<?php echo $filterStatus === 'low_stock' ? ' selected' : ''; ?>>⚠ Low Stock</option>
                        </select>
                    </div>
                    <div class="filter-actions">
                        <button class="btn btn-primary" type="submit">Filter</button>
                        <?php if ($filterSearch || $filterStatus !== 'all'): ?>
                            <a class="btn" href="<?php echo $selfUrl; ?>">Clear</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <!-- ── Scroll hint ── -->
            <div class="scroll-hint" id="scrollHint" style="display:none;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="9 18 15 12 9 6"></polyline>
                </svg>
                Scroll right to see all columns
            </div>

            <!-- ── Parts table ── -->
            <div class="table-scroll-wrap" id="tableWrap">
                <div class="table-scroll" id="tableScroll">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Part Name / SKU</th>
                                <th>Description</th>
                                <th style="text-align:right;">Cost Price</th>
                                <th style="text-align:right;">Sell Price</th>
                                <th style="text-align:right;">Margin</th>
                                <th style="text-align:center;">Stock&nbsp;/&nbsp;Alert</th>
                                <th style="text-align:center;">Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($parts)): ?>
                            <tr>
                                <td colspan="9" style="text-align:center; padding:40px; color:#999; white-space:normal;">
                                    No parts found<?php echo $filterSearch ? ' for "' . e($filterSearch) . '"' : ''; ?>.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($parts as $p):
                                $margin     = $p['sell_price'] - $p['cost_price'];
                                $marginPct  = $p['sell_price'] > 0 ? round(($margin / $p['sell_price']) * 100) : 0;
                                $isLow      = $p['low_stock_threshold'] > 0 && $p['stock_quantity'] <= $p['low_stock_threshold'];
                                $isZero     = $p['stock_quantity'] <= 0;
                                $stockClass = $isZero ? 'stock-zero' : ($isLow ? 'stock-low' : 'stock-ok');
                            ?>
                            <tr>
                                <td><?php echo $p['id']; ?></td>
                                <td>
                                    <div class="part-name"><?php echo e($p['name']); ?></div>
                                    <?php if ($p['sku']): ?>
                                        <div class="part-sku">SKU: <?php echo e($p['sku']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="desc-cell" title="<?php echo e($p['description'] ?? ''); ?>">
                                    <?php echo $p['description'] ? e($p['description']) : '<span style="color:#ccc;">—</span>'; ?>
                                </td>
                                <td class="price-cell">$<?php echo number_format($p['cost_price'], 2); ?></td>
                                <td class="price-cell">$<?php echo number_format($p['sell_price'], 2); ?></td>
                                <td class="price-cell">
                                    <span style="color:<?php echo $margin >= 0 ? '#2e7d32' : '#c62828'; ?>; font-weight:600;">
                                        $<?php echo number_format($margin, 2); ?>
                                        <small style="font-weight:400;">(<?php echo $marginPct; ?>%)</small>
                                    </span>
                                </td>
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
                                    <div class="action-links">
                                        <a class="action-link" style="color:#ff6600;"
                                           href="#" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($p), ENT_QUOTES); ?>); return false;'>
                                            Edit
                                        </a>
                                        <span class="action-sep">|</span>
                                        <form method="POST" action="<?php echo $selfUrl; ?>" style="display:inline; margin:0;"
                                              onsubmit="return confirm('Toggle active status for this part?')">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id"     value="<?php echo $p['id']; ?>">
                                            <button type="submit" class="action-link"
                                                    style="background:none; border:none; padding:0; cursor:pointer;
                                                           color:<?php echo $p['is_active'] ? '#e67e22' : '#4caf50'; ?>;
                                                           font-size:.82rem; font-weight:600;">
                                                <?php echo $p['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                            </button>
                                        </form>
                                    </div>
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
                Inactive parts are hidden from new invoices but preserved in history.
            </p>

        </div><!-- /.card -->
    </main>
</div>

<!-- ════════════════════════════════════════════════════════════
     ADD PART MODAL
════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="addModal">
    <div class="modal-box">
        <h3>Add New Part</h3>

        <div class="balance-row <?php echo $shopBalance <= 0 ? 'bal-zero' : ''; ?>" id="add_balance_row">
            Shop balance:
            <span class="bal-amount">$<?php echo number_format($shopBalance, 2); ?></span>
        </div>

        <div class="balance-zero-warning" id="add_zero_warning">
            <strong>Insufficient balance.</strong>
            The shop balance is $0.00 — you cannot add stock until the balance is topped up.
            Parts with <strong>0 initial stock</strong> can still be added to the catalog.
        </div>

        <form method="POST" action="<?php echo $selfUrl; ?>" id="addPartForm">
            <input type="hidden" name="action" value="add">

            <div class="form-row">
                <label>Part Name <span style="color:#dc3545;">*</span></label>
                <input type="text" name="name" required placeholder="e.g. Oil Filter">
            </div>
            <div class="form-row">
                <label>SKU / Part Number</label>
                <input type="text" name="sku" placeholder="e.g. OF-001">
            </div>
            <div class="form-row">
                <label>Description</label>
                <textarea name="description" placeholder="Optional details…"></textarea>
            </div>
            <div class="form-grid-2">
                <div class="form-row">
                    <label>Cost Price ($)</label>
                    <input type="number" name="cost_price" id="add_cost"
                           step="0.01" min="0" value="0.00"
                           oninput="updateAddPreview()">
                </div>
                <div class="form-row">
                    <label>Sell Price ($)</label>
                    <input type="number" name="sell_price"
                           step="0.01" min="0" value="0.00">
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-row">
                    <label>Initial Stock Qty</label>
                    <input type="number" name="stock_quantity" id="add_stock"
                           min="0" value="0"
                           oninput="updateAddPreview()">
                </div>
                <div class="form-row">
                    <label>Low Stock Alert ≤</label>
                    <input type="number" name="low_stock_threshold" min="0" value="0" placeholder="0 = no alert">
                </div>
            </div>

            <div class="cost-preview" id="add_preview">
                Will deduct from balance:
                <span class="preview-amount" id="add_preview_amount">$0.00</span>
                <span id="add_preview_detail" style="color:#888; font-size:.82rem;"></span>
                <div id="add_insufficient_msg" style="display:none; margin-top:6px; color:#c62828; font-size:.82rem; font-weight:600;">
                    ⚠ This exceeds the current balance.
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="add_submit_btn"
                        onclick="return confirmAdd()">
                    Add Part
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     EDIT PART MODAL
════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <h3>Edit Part</h3>
        <form method="POST" action="<?php echo $selfUrl; ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id"     id="edit_id">

            <div class="form-row">
                <label>Part Name <span style="color:#dc3545;">*</span></label>
                <input type="text" name="name" id="edit_name" required>
            </div>
            <div class="form-row">
                <label>SKU / Part Number</label>
                <input type="text" name="sku" id="edit_sku">
            </div>
            <div class="form-row">
                <label>Description</label>
                <textarea name="description" id="edit_desc"></textarea>
            </div>
            <div class="form-grid-2">
                <div class="form-row">
                    <label>Cost Price ($)</label>
                    <input type="number" name="cost_price" id="edit_cost" step="0.01" min="0">
                </div>
                <div class="form-row">
                    <label>Sell Price ($)</label>
                    <input type="number" name="sell_price" id="edit_sell" step="0.01" min="0">
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-row">
                    <label>Stock Qty</label>
                    <input type="number" name="stock_quantity" id="edit_stock" min="0">
                </div>
                <div class="form-row">
                    <label>Low Stock Alert ≤</label>
                    <input type="number" name="low_stock_threshold" id="edit_threshold" min="0">
                </div>
            </div>
            <div class="form-row">
                <div class="checkbox-row">
                    <input type="checkbox" name="is_active" id="edit_active" value="1">
                    <label for="edit_active">Active (visible on invoices)</label>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
var SHOP_BALANCE = <?php echo json_encode($shopBalance); ?>;

// ── Scroll overflow detection ──────────────────────────────────────────────────
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

// ── Modals ────────────────────────────────────────────────────────────────────
function openAddModal() {
    document.getElementById('addModal').classList.add('open');
    updateAddPreview();
}

function openEditModal(part) {
    document.getElementById('edit_id').value        = part.id;
    document.getElementById('edit_name').value      = part.name;
    document.getElementById('edit_sku').value       = part.sku || '';
    document.getElementById('edit_desc').value      = part.description || '';
    document.getElementById('edit_cost').value      = parseFloat(part.cost_price).toFixed(2);
    document.getElementById('edit_sell').value      = parseFloat(part.sell_price).toFixed(2);
    document.getElementById('edit_stock').value     = part.stock_quantity;
    document.getElementById('edit_threshold').value = part.low_stock_threshold;
    document.getElementById('edit_active').checked  = part.is_active == 1;
    document.getElementById('editModal').classList.add('open');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) overlay.classList.remove('open');
    });
});

// ── Add modal cost preview ────────────────────────────────────────────────────
function updateAddPreview() {
    var cost    = parseFloat(document.getElementById('add_cost').value)    || 0;
    var stock   = parseInt(document.getElementById('add_stock').value, 10) || 0;
    var total   = cost * stock;

    var preview     = document.getElementById('add_preview');
    var zeroWarning = document.getElementById('add_zero_warning');
    var insuffMsg   = document.getElementById('add_insufficient_msg');
    var submitBtn   = document.getElementById('add_submit_btn');

    if (stock > 0) {
        if (SHOP_BALANCE <= 0) {
            zeroWarning.style.display = 'block';
            preview.style.display     = 'none';
            submitBtn.disabled        = true;
            return;
        }

        zeroWarning.style.display = 'none';
        document.getElementById('add_preview_amount').textContent = '$' + total.toFixed(2);
        document.getElementById('add_preview_detail').textContent =
            ' (' + stock + ' × $' + cost.toFixed(2) + ')';
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
        zeroWarning.style.display = 'none';
        preview.style.display     = 'none';
        submitBtn.disabled        = false;
    }
}

function confirmAdd() {
    var stock = parseInt(document.getElementById('add_stock').value, 10) || 0;
    if (stock > 0) {
        return confirm('This will deduct the inventory cost from the shop balance. Proceed?');
    }
    return true;
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
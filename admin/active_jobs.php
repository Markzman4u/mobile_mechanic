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
$expandJobId = null;

// ── Helper: get request_id from a service_item id ─────────────────────────
function getRequestIdFromItem($pdo, $itemId) {
    $stmt = $pdo->prepare(
        'SELECT s.request_id FROM service_items si
         JOIN services s ON si.service_id = s.id
         WHERE si.id = :id'
    );
    $stmt->execute([':id' => $itemId]);
    $row = $stmt->fetch();
    return $row ? (int) $row['request_id'] : null;
}

// ── Helper: get status for a request ──────────────────────────────────────
function getRequestStatus($pdo, $requestId) {
    $stmt = $pdo->prepare('SELECT status FROM requests WHERE id = :id');
    $stmt->execute([':id' => $requestId]);
    $row = $stmt->fetch();
    return $row ? $row['status'] : null;
}

// ── Helper: get or create service record for a request ────────────────────
function getOrCreateService($pdo, $requestId) {
    $stmt = $pdo->prepare('SELECT id FROM services WHERE request_id = :rid');
    $stmt->execute([':rid' => $requestId]);
    $svc = $stmt->fetch();
    if (!$svc) {
        $ins = $pdo->prepare('INSERT INTO services (request_id, service_name, total_amount) VALUES (:rid, :sn, 0)');
        $ins->execute([':rid' => $requestId, ':sn' => 'Service']);
        return (int) $pdo->lastInsertId();
    }
    return (int) $svc['id'];
}

// ── Helper: determine actor role for parts_catalog_log ────────────────────
function actorRole() {
    return isSuperAdmin() ? 'super_admin' : 'staff_admin';
}

// ── Load active catalog parts (for dropdown) ──────────────────────────────
$catalogParts = $pdo->query(
    'SELECT id, name, sku, sell_price, cost_price, stock_quantity
     FROM parts_catalog
     WHERE is_active = 1 AND stock_quantity > 0
     ORDER BY name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

// Build a keyed map for JS injection (id → data)
$catalogMap = [];
foreach ($catalogParts as $cp) {
    $catalogMap[$cp['id']] = [
        'name'           => $cp['name'],
        'sell_price'     => (float) $cp['sell_price'],
        'stock_quantity' => (int)   $cp['stock_quantity'],
    ];
}

// ── Handle: Add service item ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $expandJobId = (int) $_POST['request_id'];

    $currentStatus = getRequestStatus($pdo, $expandJobId);
    if ($currentStatus !== 'in_progress') {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?expand=' . $expandJobId . '&msg=' . urlencode('Service items can only be added once the job is in progress.'));
        exit;
    }

    $type      = in_array($_POST['item_type'] ?? '', ['labor','part','fee','other']) ? $_POST['item_type'] : 'other';
    $catalogId = ($type === 'part' && !empty($_POST['catalog_id']) && ctype_digit((string)$_POST['catalog_id']))
                    ? (int) $_POST['catalog_id'] : null;
    $itemName  = trim($_POST['item_name'] ?? '');
    $price     = isset($_POST['price']) ? (float) $_POST['price'] : 0;
    $quantity  = max(1, (int) ($_POST['quantity'] ?? 1));
    $costPrice = null;
    $errMsg    = '';

    if ($type === 'part' && $catalogId) {
        $partStmt = $pdo->prepare(
            'SELECT id, name, sell_price, cost_price, stock_quantity
             FROM parts_catalog WHERE id = :id AND is_active = 1'
        );
        $partStmt->execute([':id' => $catalogId]);
        $part = $partStmt->fetch();

        if (!$part) {
            $errMsg = 'Selected part is no longer available or has been disabled.';
        } elseif ($part['stock_quantity'] < $quantity) {
            $errMsg = 'Insufficient stock. Available: ' . $part['stock_quantity'] . ' unit(s).';
        } else {
            $itemName  = $part['name'];
            $costPrice = (float) $part['cost_price'];
            if ($price <= 0) {
                $price = (float) $part['sell_price'];
            }
        }
    }

    if (!$errMsg) {
        if (!$itemName) {
            $errMsg = 'Item name is required.';
        } elseif ($price <= 0) {
            $errMsg = 'Price must be greater than zero.';
        }
    }

    if ($errMsg) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?expand=' . $expandJobId . '&msg=' . urlencode($errMsg));
        exit;
    }

    try {
        $pdo->beginTransaction();

        $serviceId = getOrCreateService($pdo, $expandJobId);

        $pdo->prepare(
            'INSERT INTO service_items (service_id, catalog_id, item_name, quantity, price, cost_price, type)
             VALUES (:sid, :cid, :name, :qty, :price, :cost, :type)'
        )->execute([
            ':sid'   => $serviceId,
            ':cid'   => $catalogId,
            ':name'  => $itemName,
            ':qty'   => $quantity,
            ':price' => $price,
            ':cost'  => $costPrice,
            ':type'  => $type,
        ]);

        if ($type === 'part' && $catalogId) {
            $pdo->prepare(
                'UPDATE parts_catalog SET stock_quantity = stock_quantity - :qty WHERE id = :id'
            )->execute([':qty' => $quantity, ':id' => $catalogId]);

            $pdo->prepare(
                'INSERT INTO parts_catalog_log
                    (part_id, action, quantity_change, cost_per_unit, total_cost,
                     balance_ledger_id, performed_by, role_at_time, notes)
                 VALUES (:pid, "used", :qc, NULL, NULL, NULL, :by, :role, :notes)'
            )->execute([
                ':pid'   => $catalogId,
                ':qc'    => -$quantity,
                ':by'    => $_SESSION['user_id'],
                ':role'  => actorRole(),
                ':notes' => 'Used on job #' . $expandJobId,
            ]);
        }

        $pdo->commit();
        header('Location: ' . $_SERVER['PHP_SELF'] . '?expand=' . $expandJobId . '&msg=' . urlencode('Item added successfully.'));
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        header('Location: ' . $_SERVER['PHP_SELF'] . '?expand=' . $expandJobId . '&msg=' . urlencode('Failed to add item. Please try again.'));
        exit;
    }
}

// ── Handle: Update service item ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    $itemId   = (int) $_POST['item_id'];
    $itemName = trim($_POST['item_name'] ?? '');
    $price    = isset($_POST['price']) ? (float) $_POST['price'] : 0;

    $expandJobId = getRequestIdFromItem($pdo, $itemId);

    $currentStatus = $expandJobId ? getRequestStatus($pdo, $expandJobId) : null;
    if ($currentStatus !== 'in_progress') {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?expand=' . $expandJobId . '&msg=' . urlencode('Service items can only be edited once the job is in progress.'));
        exit;
    }

    if ($itemName && $price > 0) {
        $typeStmt = $pdo->prepare('SELECT type, catalog_id FROM service_items WHERE id = :id');
        $typeStmt->execute([':id' => $itemId]);
        $si = $typeStmt->fetch();

        if ($si && $si['catalog_id']) {
            $pdo->prepare('UPDATE service_items SET price = :price WHERE id = :id')
                ->execute([':price' => $price, ':id' => $itemId]);
        } else {
            $pdo->prepare('UPDATE service_items SET item_name = :name, price = :price WHERE id = :id')
                ->execute([':name' => $itemName, ':price' => $price, ':id' => $itemId]);
        }
        $message = 'Item updated successfully.';
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?expand=' . $expandJobId . '&msg=' . urlencode($message ?: 'Nothing to update.'));
    exit;
}

// ── Handle: Delete service item (GET) ─────────────────────────────────────
if (isset($_GET['delete_item']) && ctype_digit($_GET['delete_item'])) {
    $itemId      = (int) $_GET['delete_item'];
    $expandJobId = getRequestIdFromItem($pdo, $itemId);

    $currentStatus = $expandJobId ? getRequestStatus($pdo, $expandJobId) : null;
    if ($currentStatus !== 'in_progress') {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?expand=' . $expandJobId . '&msg=' . urlencode('Service items can only be deleted once the job is in progress.'));
        exit;
    }

    $siStmt = $pdo->prepare('SELECT catalog_id, quantity, type FROM service_items WHERE id = :id');
    $siStmt->execute([':id' => $itemId]);
    $si = $siStmt->fetch();

    try {
        $pdo->beginTransaction();

        $pdo->prepare('DELETE FROM service_items WHERE id = :id')->execute([':id' => $itemId]);

        if ($si && $si['type'] === 'part' && $si['catalog_id']) {
            $pdo->prepare(
                'UPDATE parts_catalog SET stock_quantity = stock_quantity + :qty WHERE id = :id'
            )->execute([':qty' => $si['quantity'], ':id' => $si['catalog_id']]);

            $pdo->prepare(
                'INSERT INTO parts_catalog_log
                    (part_id, action, quantity_change, cost_per_unit, total_cost,
                     balance_ledger_id, performed_by, role_at_time, notes)
                 VALUES (:pid, "adjusted", :qc, NULL, NULL, NULL, :by, :role, :notes)'
            )->execute([
                ':pid'   => $si['catalog_id'],
                ':qc'    => $si['quantity'],
                ':by'    => $_SESSION['user_id'],
                ':role'  => actorRole(),
                ':notes' => 'Returned to stock — item removed from job #' . $expandJobId,
            ]);
        }

        $pdo->commit();
        $message = 'Item deleted successfully.';

    } catch (Exception $e) {
        $pdo->rollBack();
        $message = 'Failed to delete item.';
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?expand=' . $expandJobId . '&msg=' . urlencode($message));
    exit;
}

// ── Handle: Complete job ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_job'])) {
    $requestId     = (int) $_POST['request_id'];
    $currentStatus = getRequestStatus($pdo, $requestId);

    if ($currentStatus === 'in_progress') {
        header('Location: ' . getBasePath() . 'admin/complete_service.php?id=' . $requestId);
        exit;
    } else {
        $expandJobId = $requestId;
        $message = 'This job cannot be completed yet — it must be in progress first.';
    }
}

// ── Expand from GET param (after redirects) ────────────────────────────────
if ($expandJobId === null && isset($_GET['expand']) && ctype_digit($_GET['expand'])) {
    $expandJobId = (int) $_GET['expand'];
}
if (isset($_GET['msg']) && !$message) {
    $message = htmlspecialchars($_GET['msg']);
}

// ── Search & filter params ─────────────────────────────────────────────────
$search       = trim($_GET['search'] ?? '');
$filterStatus = $_GET['status'] ?? '';
if (!in_array($filterStatus, ['', 'assigned', 'in_progress'])) {
    $filterStatus = '';
}

// ── Build jobs query with search & filter ─────────────────────────────────
$whereClauses = ["r.status IN ('assigned', 'in_progress')"];
$params       = [];

if ($filterStatus !== '') {
    $whereClauses[] = 'r.status = :status';
    $params[':status'] = $filterStatus;
}

if ($search !== '') {
    // Match request ID (exact), customer name (partial), or mechanic name (partial)
    $whereClauses[] = '(
        r.id = :search_id
        OR COALESCE(u.full_name, w.full_name, "") LIKE :search_name
        OR m.name LIKE :search_mech
    )';
    $searchId = ctype_digit($search) ? (int) $search : -1;
    $params[':search_id']   = $searchId;
    $params[':search_name'] = '%' . $search . '%';
    $params[':search_mech'] = '%' . $search . '%';
}

$whereSQL = 'WHERE ' . implode(' AND ', $whereClauses);

$jobsStmt = $pdo->prepare(
    "SELECT r.id,
            r.mechanic_id,
            COALESCE(u.full_name, w.full_name, 'Unknown') AS customer_name,
            CASE WHEN r.walkin_id IS NOT NULL THEN 1 ELSE 0 END AS is_walkin,
            r.problem_type,
            r.diagnosis,
            m.name AS mechanic_name,
            r.status,
            r.created_at
     FROM requests r
     LEFT JOIN users u            ON r.user_id    = u.id
     LEFT JOIN walkin_customers w ON r.walkin_id  = w.id
     LEFT JOIN mechanics m        ON r.mechanic_id = m.id
     $whereSQL
     ORDER BY r.created_at DESC"
);
$jobsStmt->execute($params);
$jobs = $jobsStmt->fetchAll();

// ── Helper: get service items for a request ───────────────────────────────
function getServiceItemsForRequest($pdo, $requestId) {
    $stmt = $pdo->prepare(
        'SELECT si.id, si.item_name, si.price, si.quantity, si.type, si.catalog_id
         FROM service_items si
         JOIN services s ON si.service_id = s.id
         WHERE s.request_id = :rid'
    );
    $stmt->execute([':rid' => $requestId]);
    return $stmt->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <div class="card">
        <h2>Active Jobs</h2>
        <p class="muted">Currently active repair jobs.</p>

        <?php if ($message): ?>
            <div style="background:#e8f7e9;border:1px solid #8bc34a;color:#2f6627;padding:12px;border-radius:6px;margin-bottom:16px;">
                <?php echo e($message); ?>
            </div>
        <?php endif; ?>

        <style>
            /* ── Filter bar ─────────────────────────────────────────────── */
            .filter-bar {
                display: flex;
                gap: 10px;
                align-items: flex-end;
                margin-bottom: 18px;
                flex-wrap: wrap;
            }
            .filter-group {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .filter-group label {
                font-size: 0.74rem;
                font-weight: 600;
                color: #666;
                white-space: nowrap;
            }
            .filter-group input[type="text"],
            .filter-group select {
                padding: 7px 10px;
                border: 1px solid #ccc;
                border-radius: 4px;
                font-size: 0.87rem;
                background: #fff;
                color: #333;
                height: 36px;
                box-sizing: border-box;
            }
            .filter-group input[type="text"] { width: 230px; }
            .filter-group select             { width: 160px; }
            .filter-group input[type="text"]:focus,
            .filter-group select:focus {
                outline: none;
                border-color: #ff6600;
                box-shadow: 0 0 0 2px rgba(255,102,0,.12);
            }
            .filter-actions {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .filter-actions .label-spacer {
                font-size: 0.74rem;
                visibility: hidden;
            }
            .filter-btn-row {
                display: flex;
                gap: 6px;
                align-items: center;
            }
            .btn-filter {
                padding: 0 18px;
                height: 36px;
                background: #ff6600;
                color: #fff;
                border: none;
                border-radius: 4px;
                font-size: 0.87rem;
                font-weight: 700;
                cursor: pointer;
                white-space: nowrap;
            }
            .btn-filter:hover { background: #e55a00; }
            .btn-clear {
                padding: 0 14px;
                height: 36px;
                background: #eee;
                color: #555;
                border: 1px solid #ccc;
                border-radius: 4px;
                font-size: 0.87rem;
                font-weight: 600;
                cursor: pointer;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                white-space: nowrap;
            }
            .btn-clear:hover { background: #ddd; color: #333; }

            /* ── Results summary ────────────────────────────────────────── */
            .results-summary {
                font-size: 0.82rem;
                color: #777;
                margin-bottom: 12px;
            }
            .results-summary strong { color: #333; }
            .search-highlight {
                background: #fff3cd;
                border-radius: 2px;
                padding: 0 2px;
            }

            /* ── Row expand ─────────────────────────────────────────────── */
            .job-row-expandable { cursor: pointer; }
            .job-row-expandable:hover { background: #f9f9f9; }
            .expand-icon { display: inline-block; transition: transform 0.2s; }
            .expand-icon.expanded { transform: rotate(90deg); }

            /* ── Drawer ─────────────────────────────────────────────────── */
            .items-section { background: #f9f9f9; padding: 16px; border-top: 1px solid #eee; }
            .items-list { margin-bottom: 16px; }

            /* ── Item rows ──────────────────────────────────────────────── */
            .item-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #e0e0e0; }
            .item-row:last-child { border-bottom: none; }
            .item-info { flex: 1; }
            .item-name { font-weight: 600; color: #333; }
            .item-actions { display: flex; gap: 4px; }

            /* ── Type badge ─────────────────────────────────────────────── */
            .type-badge {
                display: inline-block;
                font-size: 0.68rem;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .03em;
                padding: 1px 6px;
                border-radius: 3px;
                margin-left: 6px;
                vertical-align: middle;
            }
            .type-badge-labor  { background: #e3f2fd; color: #1565c0; }
            .type-badge-part   { background: #e8f5e9; color: #2e7d32; }
            .type-badge-fee    { background: #fff8e1; color: #f57f17; }
            .type-badge-other  { background: #f3e5f5; color: #6a1b9a; }

            /* ── Catalog tag ────────────────────────────────────────────── */
            .catalog-tag {
                display: inline-block;
                font-size: 0.67rem;
                background: #e0f2f1;
                color: #00695c;
                border: 1px solid #b2dfdb;
                border-radius: 3px;
                padding: 1px 5px;
                margin-left: 5px;
                vertical-align: middle;
            }

            /* ── Buttons ────────────────────────────────────────────────── */
            .item-btn { padding: 4px 8px; font-size: 0.75rem; border: none; border-radius: 3px; cursor: pointer; text-decoration: none; display: inline-block; }
            .item-btn-edit   { background: #4CAF50; color: white; }
            .item-btn-edit:hover   { background: #45a049; }
            .item-btn-delete { background: #d9534f; color: white; }
            .item-btn-delete:hover { background: #c0392b; }

            /* ── Inline edit form ───────────────────────────────────────── */
            .item-edit-form { display: none; margin-top: 8px; padding: 10px 12px; background: white; border: 1px solid #ddd; border-radius: 4px; }
            .item-edit-form.show { display: block; }
            .item-edit-form form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
            .item-edit-form input { padding: 6px 8px; border: 1px solid #ddd; border-radius: 3px; font-size: 0.85rem; }
            .item-edit-form input[type="text"]   { flex: 1; min-width: 120px; }
            .item-edit-form input[type="number"] { width: 90px; }
            .item-edit-form button { padding: 6px 12px; border: none; border-radius: 3px; cursor: pointer; font-size: 0.85rem; color: white; }
            .item-edit-form button[type="submit"] { background: #4CAF50; }
            .item-edit-form button[type="submit"]:hover { background: #45a049; }
            .item-edit-form button.cancel { background: #999; }
            .item-edit-form button.cancel:hover { background: #777; }
            .catalog-note { font-size: 0.78rem; color: #888; font-style: italic; }

            /* ── Add item panel ─────────────────────────────────────────── */
            .add-item-panel {
                margin-top: 14px;
                padding-top: 14px;
                border-top: 1px solid #ddd;
            }
            .add-item-panel h5 {
                margin: 0 0 10px 0;
                font-size: 0.88rem;
                color: #555;
                font-weight: 600;
            }

            .add-type-row {
                display: flex;
                gap: 8px;
                align-items: center;
                margin-bottom: 12px;
                flex-wrap: wrap;
            }
            .add-type-row > label {
                font-size: 0.82rem;
                font-weight: 600;
                color: #444;
                white-space: nowrap;
            }
            .type-radio-group { display: flex; gap: 6px; flex-wrap: wrap; }
            .type-radio-btn { display: none; }
            .type-radio-label {
                padding: 4px 12px;
                border: 1.5px solid #ddd;
                border-radius: 4px;
                font-size: 0.8rem;
                cursor: pointer;
                color: #555;
                font-weight: 600;
                transition: all .15s;
                user-select: none;
            }
            .type-radio-btn:checked + .type-radio-label {
                border-color: var(--safety-orange, #ff6600);
                background: #fff5ee;
                color: var(--safety-orange, #ff6600);
            }

            .add-fields-grid {
                display: grid;
                grid-template-columns: 1fr 110px 70px auto;
                gap: 8px;
                align-items: end;
            }
            .add-field-group {
                display: flex;
                flex-direction: column;
                gap: 4px;
                min-width: 0;
            }
            .add-field-group > label {
                font-size: 0.74rem;
                font-weight: 600;
                color: #666;
                white-space: nowrap;
            }
            .add-field-group input,
            .add-field-group select {
                width: 100%;
                box-sizing: border-box;
                padding: 7px 8px;
                border: 1px solid #ccc;
                border-radius: 4px;
                font-size: 0.85rem;
                background: #fff;
            }
            .add-field-group input:focus,
            .add-field-group select:focus {
                outline: none;
                border-color: var(--safety-orange, #ff6600);
                box-shadow: 0 0 0 2px rgba(255,102,0,.12);
            }
            .add-field-submit button {
                width: 100%;
                padding: 7px 14px;
                background: var(--safety-orange, #ff6600);
                color: white;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 0.85rem;
                font-weight: 700;
                white-space: nowrap;
            }
            .add-field-submit button:hover { background: #e55a00; }

            .stock-hint {
                font-size: 0.73rem;
                color: #666;
                margin-top: 3px;
                min-height: 1em;
            }
            .stock-hint.low { color: #e65100; font-weight: 600; }
            .no-catalog-notice {
                font-size: 0.82rem;
                color: #888;
                font-style: italic;
            }

            /* ── Total line ─────────────────────────────────────────────── */
            .total-line { padding-top: 12px; border-top: 1px solid #ddd; color: var(--charcoal); font-weight: 700; font-size: 0.95rem; }

            /* ── Items locked notice ────────────────────────────────────── */
            .items-locked-notice {
                display: flex;
                align-items: center;
                gap: 10px;
                background: #fff8f0;
                border: 1px dashed #ffcc80;
                border-radius: 6px;
                padding: 12px 16px;
                color: #b45309;
                font-size: 0.88rem;
                margin-top: 8px;
            }
            .items-locked-notice .lock-icon { font-size: 1.1rem; flex-shrink: 0; }

            /* ── Walk-in badge ──────────────────────────────────────────── */
            .badge-walkin {
                display: inline-block;
                background: #fff3e0;
                color: #e65100;
                border: 1px solid #ffcc80;
                border-radius: 4px;
                font-size: 0.7rem;
                padding: 1px 5px;
                margin-left: 5px;
                vertical-align: middle;
                font-weight: 600;
            }

            /* ── Clickable cell links ───────────────────────────────────── */
            .cell-link { color: inherit; text-decoration: none; font-weight: 600; }
            .cell-link:hover { color: var(--safety-orange, #ff6600); text-decoration: underline; }
            .cell-link-mechanic { color: #3a5bbd; }
            .cell-link-mechanic:hover { color: var(--safety-orange, #ff6600); }

            /* ── Diagnosis column ───────────────────────────────────────── */
            th.col-diagnosis, td.col-diagnosis { width: 150px; min-width: 110px; max-width: 150px; }
            .diagnosis-cell {
                display: -webkit-box;
                -webkit-line-clamp: 3;
                -webkit-box-orient: vertical;
                overflow: hidden;
                font-size: 0.82rem;
                color: #555;
                line-height: 1.4;
                word-break: break-word;
                white-space: normal;
                cursor: default;
            }
            .diagnosis-cell.empty { color: #bbb; font-style: italic; }

            /* ── Actions column ─────────────────────────────────────────── */
            th.col-actions, td.col-actions { width: 140px; min-width: 140px; }
            .awaiting-label {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                font-size: 0.8rem;
                color: #888;
                font-style: italic;
            }
            .btn-complete-job {
                width: 100%;
                padding: 6px 0;
                font-size: 0.82rem;
                background: #4CAF50;
                color: white;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-weight: 600;
            }
            .btn-complete-job:hover { background: #45a049; }

            /* ── No results ─────────────────────────────────────────────── */
            .no-results-msg {
                text-align: center;
                padding: 32px 20px;
                color: #999;
            }
            .no-results-msg .no-results-icon { font-size: 2rem; margin-bottom: 8px; }
            .no-results-msg p { margin: 4px 0; font-size: 0.9rem; }
            .no-results-msg a { color: #ff6600; text-decoration: none; font-weight: 600; }
            .no-results-msg a:hover { text-decoration: underline; }
        </style>

        <!-- ── Search & Filter Bar ────────────────────────────────────── -->
        <form method="get" action="<?php echo $_SERVER['PHP_SELF']; ?>">
            <div class="filter-bar">

                <div class="filter-group">
                    <label for="search-input">Search</label>
                    <input type="text"
                           id="search-input"
                           name="search"
                           placeholder="Request ID, customer or mechanic…"
                           value="<?php echo e($search); ?>">
                </div>

                <div class="filter-group">
                    <label for="status-filter">Status</label>
                    <select id="status-filter" name="status">
                        <option value="">All Active</option>
                        <option value="assigned"    <?php echo $filterStatus === 'assigned'    ? 'selected' : ''; ?>>Assigned</option>
                        <option value="in_progress" <?php echo $filterStatus === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    </select>
                </div>

                <div class="filter-actions">
                    <span class="label-spacer">x</span>
                    <div class="filter-btn-row">
                        <button type="submit" class="btn-filter">Filter</button>
                        <?php if ($search !== '' || $filterStatus !== ''): ?>
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn-clear">✕ Clear</a>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </form>

        <!-- ── Results summary ────────────────────────────────────────── -->
        <?php
        $totalJobs = count($jobs);
        $assigned  = count(array_filter($jobs, fn($j) => $j['status'] === 'assigned'));
        $inProg    = count(array_filter($jobs, fn($j) => $j['status'] === 'in_progress'));
        $hasFilter = $search !== '' || $filterStatus !== '';
        ?>
        <div class="results-summary">
            <?php if ($hasFilter): ?>
                Showing <strong><?php echo $totalJobs; ?></strong> result<?php echo $totalJobs !== 1 ? 's' : ''; ?>
                <?php if ($search !== ''): ?>
                    for <span class="search-highlight"><?php echo e($search); ?></span>
                <?php endif; ?>
                <?php if ($filterStatus !== ''): ?>
                    — status: <strong><?php echo statusLabel($filterStatus); ?></strong>
                <?php endif; ?>
            <?php else: ?>
                <strong><?php echo $totalJobs; ?></strong> active job<?php echo $totalJobs !== 1 ? 's' : ''; ?>
                &nbsp;·&nbsp; <?php echo $assigned; ?> assigned &nbsp;·&nbsp; <?php echo $inProg; ?> in progress
            <?php endif; ?>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:30px;"></th>
                    <th>ID</th>
                    <th>Customer</th>
                    <th>Problem</th>
                    <th>Mechanic</th>
                    <th class="col-diagnosis">Diagnosis</th>
                    <th>Status</th>
                    <th>Started</th>
                    <th class="col-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($jobs)): ?>
                    <tr>
                        <td colspan="9">
                            <div class="no-results-msg">
                                <div class="no-results-icon">🔍</div>
                                <?php if ($hasFilter): ?>
                                    <p><strong>No jobs match your search.</strong></p>
                                    <p>Try a different keyword or <a href="<?php echo $_SERVER['PHP_SELF']; ?>">clear the filter</a>.</p>
                                <?php else: ?>
                                    <p>No active jobs found.</p>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($jobs as $job):
                        $items        = getServiceItemsForRequest($pdo, $job['id']);
                        $totalItems   = array_sum(array_map(fn($i) => (float)$i['price'] * (int)$i['quantity'], $items));
                        $isExpanded   = ($expandJobId === $job['id']);
                        $isInProgress = ($job['status'] === 'in_progress');
                        $diagTooltip  = !empty($job['diagnosis']) ? e($job['diagnosis']) : '';
                        $formId       = 'add-form-' . $job['id'];
                    ?>
                        <!-- ── Job Row ──────────────────────────────────── -->
                        <tr class="job-row-expandable" onclick="toggleItems(this)" data-job-id="<?php echo $job['id']; ?>">
                            <td><span class="expand-icon <?php echo $isExpanded ? 'expanded' : ''; ?>">▶</span></td>
                            <td><?php echo e($job['id']); ?></td>

                            <td onclick="event.stopPropagation();">
                                <a href="<?php echo getBasePath(); ?>admin/request_detail.php?id=<?php echo (int)$job['id']; ?>"
                                   class="cell-link" title="View request details">
                                    <?php echo e($job['customer_name']); ?>
                                </a>
                                <?php if ($job['is_walkin']): ?>
                                    <span class="badge-walkin">Walk-in</span>
                                <?php endif; ?>
                            </td>

                            <td><?php echo e($job['problem_type']); ?></td>

                            <td onclick="event.stopPropagation();">
                                <?php if (!empty($job['mechanic_name']) && !empty($job['mechanic_id'])): ?>
                                    <a href="<?php echo getBasePath(); ?>admin/track_mechanics.php?mechanic_id=<?php echo (int)$job['mechanic_id']; ?>"
                                       class="cell-link cell-link-mechanic"
                                       title="Track <?php echo e($job['mechanic_name']); ?> on map">
                                        <?php echo e($job['mechanic_name']); ?>
                                        <span style="font-size:.72rem;opacity:.7;">📍</span>
                                    </a>
                                <?php else: ?>
                                    <span style="color:#bbb;">—</span>
                                <?php endif; ?>
                            </td>

                            <td class="col-diagnosis" onclick="event.stopPropagation();">
                                <?php if (!empty($job['diagnosis'])): ?>
                                    <span class="diagnosis-cell" title="<?php echo $diagTooltip; ?>">
                                        <?php echo e($job['diagnosis']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="diagnosis-cell empty">No diagnosis</span>
                                <?php endif; ?>
                            </td>

                            <td><span class="status status-<?php echo e($job['status']); ?>"><?php echo statusLabel($job['status']); ?></span></td>
                            <td><?php echo date('M d, g:i A', strtotime($job['created_at'])); ?></td>

                            <td class="col-actions" onclick="event.stopPropagation();">
                                <?php if ($job['status'] === 'assigned'): ?>
                                    <span class="awaiting-label">⏳ Awaiting mechanic</span>
                                <?php elseif ($isInProgress): ?>
                                    <form method="post">
                                        <input type="hidden" name="request_id" value="<?php echo $job['id']; ?>">
                                        <button type="submit" name="complete_job" class="btn-complete-job"
                                                onclick="return confirm('Mark this job as completed and proceed to finalize service items?');">
                                            ✓ Complete Job
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <!-- ── Items Drawer ────────────────────────────── -->
                        <tr class="items-row" data-job-id="<?php echo $job['id']; ?>"
                            style="display:<?php echo $isExpanded ? 'table-row' : 'none'; ?>;">
                            <td colspan="9">
                                <div class="items-section">
                                    <h4 style="margin:0 0 12px 0;">
                                        Service Items — Request #<?php echo e($job['id']); ?>
                                        &nbsp;<span style="font-size:0.8rem;font-weight:400;color:var(--muted);"><?php echo e($job['customer_name']); ?></span>
                                    </h4>

                                    <?php if (!$isInProgress): ?>
                                        <div class="items-locked-notice">
                                            <span class="lock-icon">🔒</span>
                                            <span>Service items can only be added once the mechanic starts the job (<strong>In Progress</strong>).</span>
                                        </div>

                                    <?php else: ?>
                                        <?php if (empty($items)): ?>
                                            <p style="color:var(--muted);font-size:0.9rem;margin:0 0 12px 0;">No items added yet.</p>
                                        <?php else: ?>
                                            <div class="items-list">
                                                <?php foreach ($items as $item):
                                                    $isCatalog = !empty($item['catalog_id']);
                                                    $typeClass = 'type-badge-' . $item['type'];
                                                    $lineTotal = (float)$item['price'] * (int)$item['quantity'];
                                                ?>
                                                    <div class="item-row" data-item-id="<?php echo $item['id']; ?>">
                                                        <div class="item-info">
                                                            <div class="item-name">
                                                                <?php echo e($item['item_name']); ?>
                                                                <span class="type-badge <?php echo $typeClass; ?>"><?php echo ucfirst($item['type']); ?></span>
                                                                <?php if ($isCatalog): ?>
                                                                    <span class="catalog-tag">📦 Catalog</span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div style="font-size:0.83rem;color:var(--muted);">
                                                                $<?php echo number_format((float)$item['price'], 2); ?>
                                                                <?php if ((int)$item['quantity'] > 1): ?>
                                                                    × <?php echo (int)$item['quantity']; ?>
                                                                    &nbsp;=&nbsp;
                                                                    <strong>$<?php echo number_format($lineTotal, 2); ?></strong>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <div class="item-actions">
                                                            <button type="button" class="item-btn item-btn-edit"
                                                                    onclick="toggleEditForm(event, <?php echo $item['id']; ?>)">Edit</button>
                                                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>?delete_item=<?php echo $item['id']; ?>"
                                                               class="item-btn item-btn-delete"
                                                               onclick="return confirm(<?php echo json_encode('Delete this item?' . ($isCatalog ? ' Stock will be returned to inventory.' : '')); ?>);">Delete</a>
                                                        </div>
                                                    </div>
                                                    <div class="item-edit-form" id="edit-form-<?php echo $item['id']; ?>">
                                                        <form method="post" onclick="event.stopPropagation();">
                                                            <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                            <?php if (!$isCatalog): ?>
                                                                <input type="text" name="item_name"
                                                                       value="<?php echo e($item['item_name']); ?>"
                                                                       placeholder="Item name" required>
                                                            <?php else: ?>
                                                                <input type="hidden" name="item_name" value="<?php echo e($item['item_name']); ?>">
                                                                <span class="catalog-note">Catalog part — name is fixed</span>
                                                            <?php endif; ?>
                                                            <input type="number" name="price"
                                                                   value="<?php echo number_format((float)$item['price'], 2); ?>"
                                                                   step="0.01" min="0" placeholder="Price" required>
                                                            <button type="submit" name="update_item">Save</button>
                                                            <button type="button" class="cancel"
                                                                    onclick="toggleEditForm(event, <?php echo $item['id']; ?>)">Cancel</button>
                                                        </form>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="total-line">
                                                Total: $<?php echo number_format($totalItems, 2); ?>
                                            </div>
                                        <?php endif; ?>

                                        <!-- ── Add new item panel ───────── -->
                                        <div class="add-item-panel">
                                            <h5>+ Add Service Item</h5>
                                            <form method="post" id="<?php echo $formId; ?>" onclick="event.stopPropagation();">
                                                <input type="hidden" name="request_id" value="<?php echo $job['id']; ?>">
                                                <input type="hidden" name="add_item" value="1">

                                                <div class="add-type-row">
                                                    <label>Type:</label>
                                                    <div class="type-radio-group">
                                                        <?php
                                                        $types = [
                                                            'labor' => '🔧 Labor',
                                                            'part'  => '📦 Part',
                                                            'fee'   => '💰 Fee',
                                                            'other' => '📄 Other',
                                                        ];
                                                        foreach ($types as $tval => $tlabel):
                                                        ?>
                                                            <input type="radio" class="type-radio-btn"
                                                                   name="item_type"
                                                                   id="type-<?php echo $job['id']; ?>-<?php echo $tval; ?>"
                                                                   value="<?php echo $tval; ?>"
                                                                   <?php echo ($tval === 'labor') ? 'checked' : ''; ?>
                                                                   onchange="onTypeChange('<?php echo $job['id']; ?>')">
                                                            <label class="type-radio-label"
                                                                   for="type-<?php echo $job['id']; ?>-<?php echo $tval; ?>">
                                                                <?php echo $tlabel; ?>
                                                            </label>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>

                                                <div class="add-fields-grid">

                                                    <div class="add-field-group">
                                                        <label id="label-name-<?php echo $job['id']; ?>">Item Name</label>

                                                        <div id="field-name-<?php echo $job['id']; ?>">
                                                            <input type="text" name="item_name"
                                                                   placeholder="e.g., Oil Change Labor"
                                                                   style="width:100%;box-sizing:border-box;">
                                                        </div>

                                                        <div id="field-catalog-<?php echo $job['id']; ?>" style="display:none;">
                                                            <?php if (empty($catalogParts)): ?>
                                                                <p class="no-catalog-notice">No active in-stock parts in catalog.</p>
                                                                <input type="hidden" name="catalog_id" value="">
                                                            <?php else: ?>
                                                                <select name="catalog_id"
                                                                        id="catalog-select-<?php echo $job['id']; ?>"
                                                                        onchange="onCatalogSelect('<?php echo $job['id']; ?>')"
                                                                        style="width:100%;box-sizing:border-box;">
                                                                    <option value="">— Custom / Free-text part —</option>
                                                                    <?php foreach ($catalogParts as $cp): ?>
                                                                        <option value="<?php echo $cp['id']; ?>"
                                                                                data-price="<?php echo (float)$cp['sell_price']; ?>"
                                                                                data-stock="<?php echo (int)$cp['stock_quantity']; ?>">
                                                                            <?php echo e($cp['name']); ?>
                                                                            <?php if ($cp['sku']): ?>(<?php echo e($cp['sku']); ?>)<?php endif; ?>
                                                                            — $<?php echo number_format((float)$cp['sell_price'], 2); ?>
                                                                            — Stock: <?php echo (int)$cp['stock_quantity']; ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <div class="stock-hint" id="stock-hint-<?php echo $job['id']; ?>"></div>
                                                            <?php endif; ?>
                                                        </div>

                                                        <div id="field-custom-name-<?php echo $job['id']; ?>" style="display:none;">
                                                            <input type="text"
                                                                   id="custom-name-<?php echo $job['id']; ?>"
                                                                   placeholder="e.g., Brake Pad"
                                                                   style="width:100%;box-sizing:border-box;">
                                                        </div>
                                                    </div>

                                                    <div class="add-field-group">
                                                        <label>Price ($)</label>
                                                        <input type="number" name="price"
                                                               id="price-<?php echo $job['id']; ?>"
                                                               step="0.01" min="0" placeholder="0.00" required>
                                                    </div>

                                                    <div class="add-field-group">
                                                        <label>Qty</label>
                                                        <input type="number" name="quantity"
                                                               id="qty-<?php echo $job['id']; ?>"
                                                               min="1" value="1" required>
                                                    </div>

                                                    <div class="add-field-group add-field-submit">
                                                        <label>&nbsp;</label>
                                                        <button type="button"
                                                                onclick="submitAddItem('<?php echo $job['id']; ?>', '<?php echo $formId; ?>')">
                                                            + Add
                                                        </button>
                                                    </div>

                                                </div><!-- /.add-fields-grid -->
                                            </form>
                                        </div><!-- /.add-item-panel -->

                                    <?php endif; /* isInProgress */ ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<script>
// ── Catalog data map from PHP ──────────────────────────────────────────────
const catalogMap = <?php echo json_encode($catalogMap, JSON_HEX_TAG); ?>;

// ── Toggle expand/collapse ─────────────────────────────────────────────────
function toggleItems(row) {
    const jobId  = row.getAttribute('data-job-id');
    const drawer = document.querySelector('.items-row[data-job-id="' + jobId + '"]');
    const icon   = row.querySelector('.expand-icon');
    if (!drawer) return;
    const opening = drawer.style.display === 'none' || drawer.style.display === '';
    drawer.style.display = opening ? 'table-row' : 'none';
    icon.classList.toggle('expanded', opening);
}

// ── Toggle inline edit form ────────────────────────────────────────────────
function toggleEditForm(event, itemId) {
    event.preventDefault();
    event.stopPropagation();
    const form = document.getElementById('edit-form-' + itemId);
    if (!form) return;
    const opening = !form.classList.contains('show');
    form.classList.toggle('show', opening);
    if (opening) {
        const tf = form.querySelector('input[type="text"]');
        if (tf) tf.focus();
    }
}

// ── Handle type radio change ───────────────────────────────────────────────
function onTypeChange(jobId) {
    const selected = document.querySelector(
        'input[name="item_type"]:checked[id^="type-' + jobId + '-"]'
    );
    if (!selected) return;
    const type = selected.value;

    const fieldName   = document.getElementById('field-name-'        + jobId);
    const fieldCat    = document.getElementById('field-catalog-'     + jobId);
    const fieldCustom = document.getElementById('field-custom-name-' + jobId);
    const labelName   = document.getElementById('label-name-'        + jobId);

    if (type === 'part') {
        fieldName.style.display   = 'none';
        fieldCat.style.display    = '';
        const sel = document.getElementById('catalog-select-' + jobId);
        if (sel) {
            onCatalogSelect(jobId);
        } else {
            fieldCustom.style.display = '';
            if (labelName) labelName.textContent = 'Part Name';
        }
    } else {
        fieldName.style.display   = '';
        fieldCat.style.display    = 'none';
        fieldCustom.style.display = 'none';
        if (labelName) labelName.textContent = 'Item Name';
        const sel = document.getElementById('catalog-select-' + jobId);
        if (sel) sel.value = '';
    }
}

// ── Handle catalog dropdown change ────────────────────────────────────────
function onCatalogSelect(jobId) {
    const sel       = document.getElementById('catalog-select-' + jobId);
    const hint      = document.getElementById('stock-hint-'     + jobId);
    const priceEl   = document.getElementById('price-'          + jobId);
    const customDiv = document.getElementById('field-custom-name-' + jobId);
    const nameDiv   = document.getElementById('field-name-'     + jobId);
    const labelName = document.getElementById('label-name-'     + jobId);

    if (!sel) return;

    const catId = sel.value;

    if (!catId) {
        customDiv.style.display = '';
        nameDiv.style.display   = 'none';
        if (labelName) labelName.textContent = 'Part Name';
        if (hint) hint.textContent = '';
        if (priceEl) priceEl.value = '';
        return;
    }

    customDiv.style.display = 'none';
    nameDiv.style.display   = 'none';
    if (labelName) labelName.textContent = 'Part (from catalog)';

    const opt   = sel.options[sel.selectedIndex];
    const price = parseFloat(opt.getAttribute('data-price') || 0);
    const stock = parseInt(opt.getAttribute('data-stock') || 0);

    if (priceEl) priceEl.value = price.toFixed(2);

    if (hint) {
        const isLow = stock <= 3;
        hint.className = 'stock-hint' + (isLow ? ' low' : '');
        hint.textContent = 'In stock: ' + stock + ' unit(s)' + (isLow ? ' — running low' : '');
    }
}

// ── Submit add item form with validation ──────────────────────────────────
function submitAddItem(jobId, formId) {
    const form = document.getElementById(formId);
    if (!form) return;

    const typeInput = document.querySelector(
        'input[name="item_type"]:checked[id^="type-' + jobId + '-"]'
    );
    const type = typeInput ? typeInput.value : 'other';

    if (type === 'part') {
        const sel   = document.getElementById('catalog-select-' + jobId);
        const catId = sel ? sel.value : '';

        const nameInput = document.querySelector(
            '#field-name-' + jobId + ' input[name="item_name"]'
        );

        if (!catId) {
            const customNameEl = document.getElementById('custom-name-' + jobId);
            const customName   = customNameEl ? customNameEl.value.trim() : '';
            if (!customName) {
                alert('Please enter a part name.');
                customNameEl && customNameEl.focus();
                return;
            }
            if (nameInput) nameInput.value = customName;
        } else {
            if (nameInput) nameInput.value = '';

            const qtyEl = document.getElementById('qty-' + jobId);
            const qty   = parseInt(qtyEl ? qtyEl.value : 1) || 1;
            const opt   = sel.options[sel.selectedIndex];
            const stock = parseInt(opt.getAttribute('data-stock') || 0);
            if (qty > stock) {
                alert('Quantity (' + qty + ') exceeds available stock (' + stock + ').');
                qtyEl && qtyEl.focus();
                return;
            }
        }
    } else {
        const nameInput = document.querySelector(
            '#field-name-' + jobId + ' input[name="item_name"]'
        );
        if (!nameInput || !nameInput.value.trim()) {
            alert('Please enter an item name.');
            nameInput && nameInput.focus();
            return;
        }
    }

    const priceEl = document.getElementById('price-' + jobId);
    if (!priceEl || parseFloat(priceEl.value) <= 0) {
        alert('Please enter a valid price greater than zero.');
        priceEl && priceEl.focus();
        return;
    }

    form.submit();
}

// ── Auto-expand the target row on page load ────────────────────────────────
<?php if ($expandJobId): ?>
(function () {
    const jobId  = '<?php echo $expandJobId; ?>';
    const drawer = document.querySelector('.items-row[data-job-id="' + jobId + '"]');
    const jobRow = document.querySelector('.job-row-expandable[data-job-id="' + jobId + '"]');
    if (drawer) drawer.style.display = 'table-row';
    if (jobRow) jobRow.querySelector('.expand-icon').classList.add('expanded');
})();
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (!isAdmin()) {
    header('Location: ' . getBasePath() . 'customer/dashboard.php');
    exit;
}

$pdo     = getPDO();
$message = '';
$error   = '';
$request = null;

// ── Get request ID (GET or POST) ──────────────────────────────────────────
$requestId = null;
if (isset($_GET['id']) && ctype_digit($_GET['id'])) {
    $requestId = (int) $_GET['id'];
} elseif (isset($_POST['request_id']) && ctype_digit($_POST['request_id'])) {
    $requestId = (int) $_POST['request_id'];
}

// ── Helper: actor role for parts_catalog_log ──────────────────────────────
function actorRole() {
    return isSuperAdmin() ? 'super_admin' : 'staff_admin';
}

// ── Load active catalog parts (dropdown) ──────────────────────────────────
$catalogParts = $pdo->query(
    'SELECT id, name, sku, sell_price, cost_price, stock_quantity
     FROM parts_catalog
     WHERE is_active = 1 AND stock_quantity > 0
     ORDER BY name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

// ═══════════════════════════════════════════════════════════════════════════
// HANDLE: Add Item
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $reqId     = (int) ($_POST['request_id'] ?? 0);
    $type      = in_array($_POST['item_type'] ?? '', ['labor','part','fee','other'])
                    ? $_POST['item_type'] : 'other';
    $catalogId = ($type === 'part' && !empty($_POST['catalog_id']) && ctype_digit((string)$_POST['catalog_id']))
                    ? (int) $_POST['catalog_id'] : null;
    $itemName  = trim($_POST['item_name'] ?? '');
    $price     = isset($_POST['price']) ? (float) $_POST['price'] : 0;
    $quantity  = max(1, (int) ($_POST['quantity'] ?? 1));
    $costPrice = null;
    $errMsg    = '';

    // Catalog part branch
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
            if ($price <= 0) $price = (float) $part['sell_price'];
        }
    }

    if (!$errMsg && !$itemName) $errMsg = 'Item name is required.';
    if (!$errMsg && $price <= 0)  $errMsg = 'Price must be greater than zero.';

    if ($errMsg) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $reqId . '&msg_err=' . urlencode($errMsg));
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Get or create service record
        $stmt = $pdo->prepare('SELECT id FROM services WHERE request_id = :rid');
        $stmt->execute([':rid' => $reqId]);
        $svc = $stmt->fetch();
        if (!$svc) {
            $pdo->prepare('INSERT INTO services (request_id, service_name, total_amount) VALUES (:rid, :sn, 0)')
                ->execute([':rid' => $reqId, ':sn' => 'Service']);
            $serviceId = (int) $pdo->lastInsertId();
        } else {
            $serviceId = (int) $svc['id'];
        }

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

        // Decrement stock and log for catalog parts
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
                ':notes' => 'Used on job #' . $reqId,
            ]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $reqId . '&msg_err=' . urlencode('Failed to add item. Please try again.'));
        exit;
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $reqId . '&msg=' . urlencode('Item added.'));
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// HANDLE: Delete Item
// ═══════════════════════════════════════════════════════════════════════════
if (isset($_GET['delete_item'], $_GET['id']) && ctype_digit($_GET['delete_item'])) {
    $itemId = (int) $_GET['delete_item'];
    $backId = (int) $_GET['id'];

    $siStmt = $pdo->prepare('SELECT service_id, catalog_id, quantity, type FROM service_items WHERE id = :id');
    $siStmt->execute([':id' => $itemId]);
    $si = $siStmt->fetch();

    try {
        $pdo->beginTransaction();

        $pdo->prepare('DELETE FROM service_items WHERE id = :id')->execute([':id' => $itemId]);

        // Restore stock for catalog parts
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
                ':notes' => 'Returned to stock — item removed from job #' . $backId,
            ]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $backId . '&msg_err=' . urlencode('Failed to delete item.'));
        exit;
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $backId . '&msg=' . urlencode('Item deleted.'));
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// HANDLE: Update Item
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    $itemId   = (int) ($_POST['item_id']     ?? 0);
    $reqId    = (int) ($_POST['request_id']  ?? 0);
    $itemName = trim($_POST['item_name']      ?? '');
    $price    = (float) ($_POST['price']     ?? 0);

    if ($itemId && $price > 0) {
        $typeStmt = $pdo->prepare('SELECT type, catalog_id FROM service_items WHERE id = :id');
        $typeStmt->execute([':id' => $itemId]);
        $si = $typeStmt->fetch();

        if ($si && $si['catalog_id']) {
            // Catalog part: only price override allowed
            $pdo->prepare('UPDATE service_items SET price = :price WHERE id = :id')
                ->execute([':price' => $price, ':id' => $itemId]);
        } else {
            $pdo->prepare('UPDATE service_items SET item_name = :name, price = :price WHERE id = :id')
                ->execute([':name' => $itemName, ':price' => $price, ':id' => $itemId]);
        }
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $reqId . '&msg=' . urlencode('Item updated.'));
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// HANDLE: Finalize / Complete Service
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalize_service'])) {
    $reqId       = (int) ($_POST['request_id'] ?? 0);
    $serviceName = trim($_POST['service_name'] ?? '');

    if (!$serviceName) {
        $error = 'Please enter a service name before finalizing.';
        $requestId = $reqId; // keep page loaded
    } else {

        // 1. Ensure service record exists with a name
        $stmt = $pdo->prepare('SELECT id FROM services WHERE request_id = :rid');
        $stmt->execute([':rid' => $reqId]);
        $svc = $stmt->fetch();

        if ($svc) {
            $pdo->prepare('UPDATE services SET service_name = :sn WHERE id = :id')
                ->execute([':sn' => $serviceName, ':id' => $svc['id']]);
            $serviceId = $svc['id'];
        } else {
            $pdo->prepare('INSERT INTO services (request_id, service_name, total_amount) VALUES (:rid, :sn, 0)')
                ->execute([':rid' => $reqId, ':sn' => $serviceName]);
            $serviceId = $pdo->lastInsertId();
        }

        // 2. Calculate total (price × quantity)
        $totStmt = $pdo->prepare('SELECT SUM(price * quantity) AS total FROM service_items WHERE service_id = :sid');
        $totStmt->execute([':sid' => $serviceId]);
        $totalAmount = (float)($totStmt->fetchColumn() ?? 0);

        $pdo->prepare('UPDATE services SET total_amount = :total WHERE id = :id')
            ->execute([':total' => $totalAmount, ':id' => $serviceId]);

        // 3. Load full request snapshot
        $snapStmt = $pdo->prepare(
            'SELECT r.*,
                    COALESCE(u.full_name,       w.full_name)       AS customer_name,
                    COALESCE(u.email,            w.email)           AS customer_email,
                    COALESCE(u.phone,            w.phone)           AS customer_phone,
                    COALESCE(u.gender,           w.gender)          AS customer_gender,
                    COALESCE(u.date_of_birth,    w.date_of_birth)   AS customer_dob,
                    w.address                                        AS customer_address,
                    m.name                                           AS mechanic_name,
                    m.gender                                         AS mechanic_gender,
                    ab.full_name                                     AS assigned_by_name
             FROM requests r
             LEFT JOIN users            u  ON r.user_id    = u.id
             LEFT JOIN walkin_customers w  ON r.walkin_id  = w.id
             LEFT JOIN mechanics        m  ON r.mechanic_id = m.id
             LEFT JOIN users            ab ON r.assigned_by = ab.id
             WHERE r.id = :id'
        );
        $snapStmt->execute([':id' => $reqId]);
        $snap = $snapStmt->fetch();

        // Get completing admin name
        $cbStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = :id');
        $cbStmt->execute([':id' => $_SESSION['user_id']]);
        $completedByName = $cbStmt->fetchColumn() ?: null;

        // 4. Load service items snapshot
        $itemsStmt = $pdo->prepare(
            'SELECT catalog_id, item_name, quantity, price, cost_price, type
             FROM service_items WHERE service_id = :sid'
        );
        $itemsStmt->execute([':sid' => $serviceId]);
        $itemsSnapshot = $itemsStmt->fetchAll();

        // 5. Mark request as completed
        $completedAt = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE requests SET status = "completed", completed_by = :cb WHERE id = :id')
            ->execute([':cb' => $_SESSION['user_id'], ':id' => $reqId]);

        // 6. Free up mechanic
        if ($snap['mechanic_id']) {
            $pdo->prepare('UPDATE mechanics SET status = "available" WHERE id = :id')
                ->execute([':id' => $snap['mechanic_id']]);
        }

        // 7. Insert into history_records
        $isWalkin = !empty($snap['walkin_id']) ? 1 : 0;
        $pdo->prepare(
            'INSERT INTO history_records (
                request_id,
                user_id,          walkin_id,        is_walkin,
                customer_name,    customer_email,   customer_phone,
                customer_address, customer_gender,  customer_dob,
                mechanic_id,      mechanic_name,    mechanic_gender,
                vehicle_make,     vehicle_model,    vehicle_year,
                problem_type,     description,
                image,            latitude,         longitude,
                diagnosis,
                status,           rejection_reason,
                total_amount,
                assigned_by_name, completed_by_name,
                request_created_at, completed_at
             ) VALUES (
                :request_id,
                :user_id,         :walkin_id,       :is_walkin,
                :customer_name,   :customer_email,  :customer_phone,
                :customer_address,:customer_gender, :customer_dob,
                :mechanic_id,     :mechanic_name,   :mechanic_gender,
                :vehicle_make,    :vehicle_model,   :vehicle_year,
                :problem_type,    :description,
                :image,           :latitude,        :longitude,
                :diagnosis,
                "completed",      NULL,
                :total_amount,
                :assigned_by_name,:completed_by_name,
                :request_created_at, :completed_at
             )'
        )->execute([
            ':request_id'          => $reqId,
            ':user_id'             => $snap['user_id'],
            ':walkin_id'           => $snap['walkin_id'],
            ':is_walkin'           => $isWalkin,
            ':customer_name'       => $snap['customer_name'],
            ':customer_email'      => $snap['customer_email'],
            ':customer_phone'      => $snap['customer_phone'],
            ':customer_address'    => $snap['customer_address'],
            ':customer_gender'     => $snap['customer_gender'],
            ':customer_dob'        => $snap['customer_dob'],
            ':mechanic_id'         => $snap['mechanic_id'],
            ':mechanic_name'       => $snap['mechanic_name'],
            ':mechanic_gender'     => $snap['mechanic_gender'],
            ':vehicle_make'        => $snap['vehicle_make'],
            ':vehicle_model'       => $snap['vehicle_model'],
            ':vehicle_year'        => $snap['vehicle_year'],
            ':problem_type'        => $snap['problem_type'],
            ':description'         => $snap['description'],
            ':image'               => $snap['image'],
            ':latitude'            => $snap['latitude'],
            ':longitude'           => $snap['longitude'],
            ':diagnosis'           => $snap['diagnosis'],
            ':total_amount'        => $totalAmount,
            ':assigned_by_name'    => $snap['assigned_by_name'],
            ':completed_by_name'   => $completedByName,
            ':request_created_at'  => $snap['created_at'],
            ':completed_at'        => $completedAt,
        ]);

        $historyId = $pdo->lastInsertId();

        // 8. Snapshot service items into history_service_items
        $itemInsert = $pdo->prepare(
            'INSERT INTO history_service_items
                (history_id, catalog_id, item_name, quantity, price, cost_price, type)
             VALUES (:hid, :catalog_id, :item_name, :quantity, :price, :cost_price, :type)'
        );
        foreach ($itemsSnapshot as $item) {
            $itemInsert->execute([
                ':hid'        => $historyId,
                ':catalog_id' => $item['catalog_id'],
                ':item_name'  => $item['item_name'],
                ':quantity'   => $item['quantity'],
                ':price'      => $item['price'],
                ':cost_price' => $item['cost_price'],
                ':type'       => $item['type'],
            ]);
        }

        // 9. Notify customer
        if ($snap['user_id']) {
            createNotification(
                $snap['user_id'],
                'invoice',
                'Invoice Ready',
                'Your service invoice is ready.',
                $reqId
            );
        }

        header('Location: ' . getBasePath() . 'admin/history.php');
        exit;
    }
}

// ── Flash messages from redirects ─────────────────────────────────────────
if (!$message && isset($_GET['msg']))     $message = htmlspecialchars($_GET['msg']);
if (!$error   && isset($_GET['msg_err'])) $error   = htmlspecialchars($_GET['msg_err']);

// ── Load request data ─────────────────────────────────────────────────────
if ($requestId) {
    $stmt = $pdo->prepare(
        'SELECT r.*,
                u.full_name  AS customer_name,
                u.email      AS customer_email,
                u.phone      AS customer_phone,
                wc.full_name AS walkin_name,
                wc.email     AS walkin_email,
                wc.phone     AS walkin_phone,
                wc.address   AS walkin_address,
                m.name       AS mechanic_name
         FROM requests r
         LEFT JOIN users            u  ON r.user_id   = u.id
         LEFT JOIN walkin_customers wc ON r.walkin_id  = wc.id
         LEFT JOIN mechanics        m  ON r.mechanic_id = m.id
         WHERE r.id = :id'
    );
    $stmt->execute([':id' => $requestId]);
    $request = $stmt->fetch();

    if (!$request) $error = 'Request not found.';
}

// ── Load existing service items ───────────────────────────────────────────
$existingService = null;
$existingItems   = [];
$itemsTotal      = 0;

if ($requestId && $request) {
    $stmt = $pdo->prepare('SELECT * FROM services WHERE request_id = :rid LIMIT 1');
    $stmt->execute([':rid' => $requestId]);
    $existingService = $stmt->fetch();

    if ($existingService) {
        $stmt = $pdo->prepare('SELECT * FROM service_items WHERE service_id = :sid ORDER BY id ASC');
        $stmt->execute([':sid' => $existingService['id']]);
        $existingItems = $stmt->fetchAll();
        $itemsTotal    = array_sum(
            array_map(fn($i) => (float)$i['price'] * (int)$i['quantity'], $existingItems)
        );
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
<div class="card">
    <h2>Complete Service</h2>
    <p class="muted">Finalize the job and generate the customer invoice.</p>

    <?php if ($message): ?>
        <div style="background:#e8f7e9;border:1px solid #8bc34a;color:#2f6627;padding:12px;border-radius:6px;margin-bottom:16px;">
            <?php echo e($message); ?>
        </div>
    <?php endif; ?>

    <?php if ($error && !$request): ?>
        <div style="background:#fff4f4;border:1px solid #f5c6cb;color:#a94442;padding:12px;border-radius:6px;margin-bottom:16px;">
            <?php echo e($error); ?>
        </div>
    <?php endif; ?>

    <?php if (!$request): ?>
        <p style="text-align:center;color:var(--muted);">Select a request from active jobs to complete.</p>
        <div style="text-align:center;">
            <a href="<?php echo getBasePath(); ?>admin/active_jobs.php" class="btn btn-primary">View Active Jobs</a>
        </div>

    <?php else:
        $custName    = $request['user_id']  ? $request['customer_name'] : $request['walkin_name'];
        $custEmail   = $request['user_id']  ? $request['customer_email'] : $request['walkin_email'];
        $custPhone   = $request['user_id']  ? $request['customer_phone'] : $request['walkin_phone'];
        $custAddress = $request['walkin_address'] ?? '—';
        $custType    = $request['user_id']  ? 'Registered' : 'Walk-in';

        $vehicleMake  = $request['vehicle_make_other'] ?: $request['vehicle_make'];
        $vehicleModel = $request['vehicle_model'];
        $vehicleYear  = $request['vehicle_year'];
        $vehicleStr   = trim(implode(' ', array_filter([$vehicleYear, $vehicleMake, $vehicleModel])));
    ?>

    <style>
        /* ── Info grid ──────────────────────────────────────────────────── */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }
        .info-box {
            background: #f9f9f9;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            padding: 14px 16px;
        }
        .info-box h4 {
            margin: 0 0 10px 0;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
        }
        .info-box p { margin: 4px 0; font-size: 0.88rem; color: #333; }
        .info-box p strong { color: #555; }
        .badge-walkin {
            display: inline-block;
            background: #fff3e0;
            color: #e65100;
            border: 1px solid #ffcc80;
            border-radius: 4px;
            font-size: 0.7rem;
            padding: 1px 5px;
            font-weight: 600;
        }

        /* ── Items section ──────────────────────────────────────────────── */
        .items-section {
            background: #f9f9f9;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .items-section h4 { margin: 0 0 14px 0; font-size: 1rem; color: var(--charcoal); }

        /* ── Item rows ──────────────────────────────────────────────────── */
        .item-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .item-row:last-of-type { border-bottom: none; }
        .item-info  { flex: 1; }
        .item-name  { font-weight: 600; color: #333; font-size: 0.9rem; }
        .item-meta  { font-size: 0.82rem; color: var(--muted); margin-top: 2px; }
        .item-actions { display: flex; gap: 5px; flex-shrink: 0; }

        /* ── Type badge ─────────────────────────────────────────────────── */
        .type-badge {
            display: inline-block;
            font-size: 0.67rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .03em;
            padding: 1px 6px;
            border-radius: 3px;
            margin-left: 5px;
            vertical-align: middle;
        }
        .type-badge-labor  { background: #e3f2fd; color: #1565c0; }
        .type-badge-part   { background: #e8f5e9; color: #2e7d32; }
        .type-badge-fee    { background: #fff8e1; color: #f57f17; }
        .type-badge-other  { background: #f3e5f5; color: #6a1b9a; }

        /* ── Catalog tag ────────────────────────────────────────────────── */
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

        /* ── Item action buttons ────────────────────────────────────────── */
        .item-btn { padding: 4px 10px; font-size: 0.77rem; border: none; border-radius: 3px; cursor: pointer; text-decoration: none; display: inline-block; font-weight: 600; }
        .item-btn-edit   { background: #4CAF50; color: white; }
        .item-btn-edit:hover   { background: #45a049; }
        .item-btn-delete { background: #d9534f; color: white; }
        .item-btn-delete:hover { background: #c0392b; }

        /* ── Inline edit form ───────────────────────────────────────────── */
        .item-edit-form { display: none; margin-top: 8px; padding: 10px 12px; background: #fff; border: 1px solid #ddd; border-radius: 5px; }
        .item-edit-form.show { display: block; }
        .item-edit-form form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .item-edit-form input { padding: 6px 8px; border: 1px solid #ddd; border-radius: 3px; font-size: 0.85rem; }
        .item-edit-form input[type="text"]   { flex: 1; min-width: 130px; }
        .item-edit-form input[type="number"] { width: 95px; }
        .item-edit-form button { padding: 6px 12px; border: none; border-radius: 3px; cursor: pointer; font-size: 0.85rem; color: white; font-weight: 600; }
        .item-edit-form button[type="submit"] { background: #4CAF50; }
        .item-edit-form button[type="submit"]:hover { background: #45a049; }
        .item-edit-form button.cancel { background: #999; }
        .item-edit-form button.cancel:hover { background: #777; }
        .catalog-note { font-size: 0.78rem; color: #888; font-style: italic; }

        /* ── Total bar ──────────────────────────────────────────────────── */
        .total-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--charcoal, #2d2d2d);
            color: #fff;
            padding: 12px 16px;
            border-radius: 7px;
            margin-bottom: 20px;
            font-size: 1rem;
        }
        .total-bar .total-amount { font-size: 1.3rem; font-weight: 700; color: var(--safety-orange); }

        /* ── Add item panel ─────────────────────────────────────────────── */
        .add-item-panel {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #ddd;
        }
        .add-item-panel h5 { margin: 0 0 12px 0; font-size: 0.9rem; color: #444; font-weight: 600; }

        /* Type selector row */
        .add-type-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 12px;
            align-items: center;
        }
        .add-type-row > label { font-size: 0.82rem; font-weight: 600; color: #444; white-space: nowrap; }
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

        /* ── Add fields: grid layout — no overlapping ───────────────────── */
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
            min-width: 0; /* prevents grid blowout */
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
            padding: 7px 9px;
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

        /* Submit button */
        .add-field-submit button {
            width: 100%;
            padding: 7px 18px;
            background: var(--safety-orange, #ff6600);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            white-space: nowrap;
            font-weight: 700;
        }
        .add-field-submit button:hover { background: #e55a00; }

        /* Stock hint below catalog select */
        .stock-hint { font-size: 0.73rem; color: #666; margin-top: 3px; min-height: 1em; }
        .stock-hint.low { color: #e65100; font-weight: 600; }
        .no-catalog-notice { font-size: 0.82rem; color: #888; font-style: italic; }

        /* ── Finalize form ──────────────────────────────────────────────── */
        .finalize-form {
            background: #f9f9f9;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            padding: 16px;
        }
        .finalize-form label { display: block; margin-bottom: 14px; font-size: 0.9rem; font-weight: 500; }
        .finalize-form input { margin-top: 4px; }

        @media (max-width: 768px) {
            .info-grid { grid-template-columns: 1fr 1fr; }
            .add-fields-grid { grid-template-columns: 1fr 1fr; }
            .add-field-submit { grid-column: span 2; }
        }
        @media (max-width: 500px) {
            .info-grid { grid-template-columns: 1fr; }
            .add-fields-grid { grid-template-columns: 1fr; }
            .add-field-submit { grid-column: span 1; }
        }
    </style>

    <?php if ($error): ?>
        <div style="background:#fff4f4;border:1px solid #f5c6cb;color:#a94442;padding:12px;border-radius:6px;margin-bottom:16px;">
            <?php echo e($error); ?>
        </div>
    <?php endif; ?>

    <!-- ── Info Grid ──────────────────────────────────────────────────────── -->
    <div class="info-grid">

        <!-- Customer -->
        <div class="info-box">
            <h4>Customer</h4>
            <p>
                <strong><?php echo e($custName ?? '—'); ?></strong>
                <?php if (!$request['user_id']): ?>
                    <span class="badge-walkin">Walk-in</span>
                <?php endif; ?>
            </p>
            <?php if ($custPhone): ?><p>📞 <?php echo e($custPhone); ?></p><?php endif; ?>
            <?php if ($custEmail): ?><p>✉️ <?php echo e($custEmail); ?></p><?php endif; ?>
            <?php if (!$request['user_id'] && $custAddress && $custAddress !== '—'): ?>
                <p>📍 <?php echo e($custAddress); ?></p>
            <?php endif; ?>
        </div>

        <!-- Vehicle -->
        <div class="info-box">
            <h4>Vehicle</h4>
            <?php if ($vehicleStr): ?>
                <p style="font-weight:600;font-size:0.95rem;"><?php echo e($vehicleStr); ?></p>
            <?php else: ?>
                <p style="color:#bbb;font-style:italic;">No vehicle info</p>
            <?php endif; ?>
            <?php if ($request['problem_type']): ?>
                <p style="margin-top:8px;"><strong>Problem:</strong> <?php echo e($request['problem_type']); ?></p>
            <?php endif; ?>
            <?php if ($request['description']): ?>
                <p style="color:#555;font-size:0.82rem;margin-top:4px;"><?php echo e($request['description']); ?></p>
            <?php endif; ?>
        </div>

        <!-- Job -->
        <div class="info-box">
            <h4>Job Info</h4>
            <p><strong>Request #:</strong> <?php echo e($request['id']); ?></p>
            <p><strong>Mechanic:</strong> <?php echo e($request['mechanic_name'] ?? '—'); ?></p>
            <p><strong>Status:</strong> <span class="status status-<?php echo e($request['status']); ?>"><?php echo statusLabel($request['status']); ?></span></p>
            <p><strong>Date:</strong> <?php echo date('M d, Y H:i', strtotime($request['created_at'])); ?></p>
            <?php if ($request['diagnosis']): ?>
                <p style="margin-top:6px;font-size:0.82rem;color:#555;"><strong>Diagnosis:</strong> <?php echo e($request['diagnosis']); ?></p>
            <?php endif; ?>
        </div>

    </div>

    <!-- ── Service Items ───────────────────────────────────────────────────── -->
    <div class="items-section">
        <h4>Service Items</h4>

        <?php if (empty($existingItems)): ?>
            <p style="color:var(--muted);font-size:0.9rem;margin:0 0 4px 0;">No items added yet.</p>
        <?php else: ?>
            <?php foreach ($existingItems as $item):
                $isCatalog = !empty($item['catalog_id']);
                $typeClass = 'type-badge-' . ($item['type'] ?? 'other');
                $lineTotal = (float)$item['price'] * (int)$item['quantity'];
            ?>
                <div class="item-row">
                    <div class="item-info">
                        <div class="item-name">
                            <?php echo e($item['item_name']); ?>
                            <span class="type-badge <?php echo $typeClass; ?>"><?php echo ucfirst($item['type'] ?? 'other'); ?></span>
                            <?php if ($isCatalog): ?>
                                <span class="catalog-tag">📦 Catalog</span>
                            <?php endif; ?>
                        </div>
                        <div class="item-meta">
                            $<?php echo number_format((float)$item['price'], 2); ?>
                            <?php if ((int)$item['quantity'] > 1): ?>
                                × <?php echo (int)$item['quantity']; ?>
                                &nbsp;= <strong>$<?php echo number_format($lineTotal, 2); ?></strong>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="item-actions">
                        <button type="button" class="item-btn item-btn-edit"
                                onclick="toggleEditForm(event, <?php echo $item['id']; ?>)">Edit</button>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>?id=<?php echo $requestId; ?>&delete_item=<?php echo $item['id']; ?>"
                           class="item-btn item-btn-delete"
                           onclick="return confirm(<?php echo json_encode('Delete this item?' . ($isCatalog ? ' Stock will be returned to inventory.' : '')); ?>);">Delete</a>
                    </div>
                </div>

                <!-- Inline edit form -->
                <div class="item-edit-form" id="edit-form-<?php echo $item['id']; ?>">
                    <form method="post">
                        <input type="hidden" name="request_id" value="<?php echo $requestId; ?>">
                        <input type="hidden" name="item_id"    value="<?php echo $item['id']; ?>">
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
        <?php endif; ?>

        <!-- ── Add Item Panel ───────────────────────────────────────────── -->
        <div class="add-item-panel">
            <h5>+ Add Service Item</h5>
            <form method="post" id="add-item-form">
                <input type="hidden" name="request_id" value="<?php echo $requestId; ?>">
                <input type="hidden" name="add_item" value="1">

                <!-- Row 1: Type selector -->
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
                                   id="type-<?php echo $tval; ?>"
                                   value="<?php echo $tval; ?>"
                                   <?php echo ($tval === 'labor') ? 'checked' : ''; ?>
                                   onchange="onTypeChange()">
                            <label class="type-radio-label" for="type-<?php echo $tval; ?>">
                                <?php echo $tlabel; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Row 2: Fields grid — Name | Price | Qty | Add -->
                <div class="add-fields-grid">

                    <!-- Col 1: Name / Catalog / Custom-part name (stacked, shown/hidden) -->
                    <div class="add-field-group">
                        <label id="label-field-name">Item Name</label>

                        <!-- Free-text name (labor / fee / other) -->
                        <div id="field-name">
                            <input type="text" name="item_name"
                                   placeholder="e.g., Oil Change Labor"
                                   style="width:100%;box-sizing:border-box;">
                        </div>

                        <!-- Catalog dropdown (part type) -->
                        <div id="field-catalog" style="display:none;">
                            <?php if (empty($catalogParts)): ?>
                                <p class="no-catalog-notice">No active in-stock parts in catalog.</p>
                                <input type="hidden" name="catalog_id" value="">
                            <?php else: ?>
                                <select name="catalog_id" id="catalog-select"
                                        onchange="onCatalogSelect()"
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
                                <div class="stock-hint" id="stock-hint"></div>
                            <?php endif; ?>
                        </div>

                        <!-- Custom part name (part type + custom/free-text selected) -->
                        <div id="field-custom-name" style="display:none;">
                            <input type="text" id="custom-name"
                                   placeholder="e.g., Brake Pad"
                                   style="width:100%;box-sizing:border-box;">
                        </div>
                    </div>

                    <!-- Col 2: Price -->
                    <div class="add-field-group">
                        <label>Price ($)</label>
                        <input type="number" name="price" id="price-input"
                               step="0.01" min="0" placeholder="0.00" required>
                    </div>

                    <!-- Col 3: Quantity -->
                    <div class="add-field-group">
                        <label>Qty</label>
                        <input type="number" name="quantity" id="qty-input"
                               min="1" value="1" required>
                    </div>

                    <!-- Col 4: Submit -->
                    <div class="add-field-group add-field-submit">
                        <label>&nbsp;</label>
                        <button type="button" onclick="submitAddItem()">+ Add</button>
                    </div>

                </div><!-- /.add-fields-grid -->
            </form>
        </div>
    </div>

    <!-- ── Total Bar ──────────────────────────────────────────────────────── -->
    <div class="total-bar">
        <span>Total Amount</span>
        <span class="total-amount">$<?php echo number_format($itemsTotal, 2); ?></span>
    </div>

    <!-- ── Finalize Form ──────────────────────────────────────────────────── -->
    <form method="post" class="finalize-form">
        <input type="hidden" name="request_id" value="<?php echo $requestId; ?>">
        <label>
            Service Summary Name <span style="color:#d9534f;">*</span>
            <input type="text" name="service_name"
                   value="<?php echo e($existingService['service_name'] ?? ''); ?>"
                   placeholder="e.g., Battery Replacement &amp; Oil Change"
                   required>
        </label>
        <?php if (empty($existingItems)): ?>
            <p style="color:#a94442;font-size:0.85rem;margin-bottom:12px;">
                ⚠ Add at least one service item before finalizing.
            </p>
        <?php endif; ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <button class="btn btn-primary" type="submit" name="finalize_service"
                    <?php echo empty($existingItems) ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''; ?>
                    <?php echo !empty($existingItems) ? 'onclick="return confirm(\'Mark this job as complete and generate the invoice?\');"' : ''; ?>>
                ✓ Complete Service &amp; Generate Invoice
            </button>
            <a href="<?php echo getBasePath(); ?>admin/active_jobs.php" class="btn">← Back to Active Jobs</a>
        </div>
    </form>

    <?php endif; ?>
</div>
</main>

<script>
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

// ── Handle type radio change ──────────────────────────────────────────────
function onTypeChange() {
    const selected = document.querySelector('input[name="item_type"]:checked');
    if (!selected) return;
    const type = selected.value;

    const fieldName   = document.getElementById('field-name');
    const fieldCatalog = document.getElementById('field-catalog');
    const fieldCustom = document.getElementById('field-custom-name');
    const labelEl     = document.getElementById('label-field-name');

    if (type === 'part') {
        fieldName.style.display    = 'none';
        fieldCatalog.style.display = '';
        const sel = document.getElementById('catalog-select');
        if (sel) {
            onCatalogSelect();
        } else {
            // No catalog parts — show free-text custom name
            fieldCustom.style.display = '';
            if (labelEl) labelEl.textContent = 'Part Name';
        }
    } else {
        fieldName.style.display    = '';
        fieldCatalog.style.display = 'none';
        fieldCustom.style.display  = 'none';
        if (labelEl) labelEl.textContent = 'Item Name';
        const sel = document.getElementById('catalog-select');
        if (sel) sel.value = '';
    }
}

// ── Handle catalog dropdown change ────────────────────────────────────────
function onCatalogSelect() {
    const sel       = document.getElementById('catalog-select');
    const hint      = document.getElementById('stock-hint');
    const priceEl   = document.getElementById('price-input');
    const customDiv = document.getElementById('field-custom-name');
    const nameDiv   = document.getElementById('field-name');
    const labelEl   = document.getElementById('label-field-name');
    if (!sel) return;

    const catId = sel.value;

    if (!catId) {
        // Custom / free-text part
        customDiv.style.display = '';
        nameDiv.style.display   = 'none';
        if (labelEl) labelEl.textContent = 'Part Name';
        if (hint) hint.textContent = '';
        if (priceEl) priceEl.value = '';
        return;
    }

    // Catalog part selected
    customDiv.style.display = 'none';
    nameDiv.style.display   = 'none';
    if (labelEl) labelEl.textContent = 'Part (from catalog)';

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

// ── Submit add item with validation ──────────────────────────────────────
function submitAddItem() {
    const form      = document.getElementById('add-item-form');
    const typeInput = document.querySelector('input[name="item_type"]:checked');
    const type      = typeInput ? typeInput.value : 'other';

    if (type === 'part') {
        const sel   = document.getElementById('catalog-select');
        const catId = sel ? sel.value : '';
        const nameEl = document.querySelector('#field-name input[name="item_name"]');

        if (!catId) {
            // Custom part — pull name from custom field
            const customNameEl = document.getElementById('custom-name');
            const customName   = customNameEl ? customNameEl.value.trim() : '';
            if (!customName) {
                alert('Please enter a part name.');
                if (customNameEl) customNameEl.focus();
                return;
            }
            if (nameEl) nameEl.value = customName;
        } else {
            // Catalog part — name resolved server-side
            if (nameEl) nameEl.value = '';
            const qtyEl = document.getElementById('qty-input');
            const qty   = parseInt(qtyEl ? qtyEl.value : 1) || 1;
            const opt   = sel.options[sel.selectedIndex];
            const stock = parseInt(opt.getAttribute('data-stock') || 0);
            if (qty > stock) {
                alert('Quantity (' + qty + ') exceeds available stock (' + stock + ').');
                if (qtyEl) qtyEl.focus();
                return;
            }
        }
    } else {
        // Non-part: validate free-text name
        const nameInput = document.querySelector('#field-name input[name="item_name"]');
        if (!nameInput || !nameInput.value.trim()) {
            alert('Please enter an item name.');
            if (nameInput) nameInput.focus();
            return;
        }
    }

    // Validate price
    const priceEl = document.getElementById('price-input');
    if (!priceEl || parseFloat(priceEl.value) <= 0) {
        alert('Please enter a valid price greater than zero.');
        if (priceEl) priceEl.focus();
        return;
    }

    form.submit();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
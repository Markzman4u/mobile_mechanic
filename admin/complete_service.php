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
$error = '';
$request = null;

// Get request ID
$requestId = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int) $_GET['id'] :
             (isset($_POST['request_id']) && ctype_digit($_POST['request_id']) ? (int) $_POST['request_id'] : null);

// ── Handle: Add Item ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $reqId    = (int) ($_POST['request_id'] ?? 0);
    $itemName = trim($_POST['item_name'] ?? '');
    $price    = isset($_POST['price']) ? (float) $_POST['price'] : 0;

    if ($itemName && $price > 0) {
        $stmt = $pdo->prepare('SELECT id FROM services WHERE request_id = :rid');
        $stmt->execute([':rid' => $reqId]);
        $svc = $stmt->fetch();

        if (!$svc) {
            $ins = $pdo->prepare('INSERT INTO services (request_id, service_name, total_amount) VALUES (:rid, :sn, 0)');
            $ins->execute([':rid' => $reqId, ':sn' => 'Service']);
            $serviceId = $pdo->lastInsertId();
        } else {
            $serviceId = $svc['id'];
        }

        $pdo->prepare('INSERT INTO service_items (service_id, item_name, price) VALUES (:sid, :in, :p)')
            ->execute([':sid' => $serviceId, ':in' => $itemName, ':p' => $price]);

        $tot = $pdo->prepare('SELECT SUM(si.price) AS t FROM service_items si WHERE si.service_id = :sid');
        $tot->execute([':sid' => $serviceId]);
        $total = $tot->fetchColumn();
        $pdo->prepare('UPDATE services SET total_amount = :t WHERE id = :sid')
            ->execute([':t' => $total, ':sid' => $serviceId]);

        $message = 'Item added.';
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $reqId);
    exit;
}

// ── Handle: Delete Item ───────────────────────────────────────────────────────
if (isset($_GET['delete_item'], $_GET['id']) && ctype_digit($_GET['delete_item'])) {
    $itemId = (int) $_GET['delete_item'];
    $backId = (int) $_GET['id'];

    $row = $pdo->prepare('SELECT service_id FROM service_items WHERE id = :id');
    $row->execute([':id' => $itemId]);
    $serviceRow = $row->fetch();

    $pdo->prepare('DELETE FROM service_items WHERE id = :id')->execute([':id' => $itemId]);

    if ($serviceRow) {
        $tot = $pdo->prepare('SELECT SUM(price) FROM service_items WHERE service_id = :sid');
        $tot->execute([':sid' => $serviceRow['service_id']]);
        $total = $tot->fetchColumn() ?: 0;
        $pdo->prepare('UPDATE services SET total_amount = :t WHERE id = :sid')
            ->execute([':t' => $total, ':sid' => $serviceRow['service_id']]);
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $backId);
    exit;
}

// ── Handle: Update Item ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    $itemId   = (int) ($_POST['item_id'] ?? 0);
    $reqId    = (int) ($_POST['request_id'] ?? 0);
    $itemName = trim($_POST['item_name'] ?? '');
    $price    = (float) ($_POST['price'] ?? 0);

    if ($itemId && $itemName && $price > 0) {
        $pdo->prepare('UPDATE service_items SET item_name = :n, price = :p WHERE id = :id')
            ->execute([':n' => $itemName, ':p' => $price, ':id' => $itemId]);

        $svc = $pdo->prepare('SELECT s.id FROM services s JOIN service_items si ON si.service_id = s.id WHERE si.id = :iid');
        $svc->execute([':iid' => $itemId]);
        $serviceRow = $svc->fetch();
        if ($serviceRow) {
            $tot = $pdo->prepare('SELECT SUM(price) FROM service_items WHERE service_id = :sid');
            $tot->execute([':sid' => $serviceRow['id']]);
            $total = $tot->fetchColumn() ?: 0;
            $pdo->prepare('UPDATE services SET total_amount = :t WHERE id = :sid')
                ->execute([':t' => $total, ':sid' => $serviceRow['id']]);
        }
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $reqId);
    exit;
}

// ── Handle: Finalize / Complete Service ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalize_service'])) {
    $reqId       = (int) ($_POST['request_id'] ?? 0);
    $serviceName = trim($_POST['service_name'] ?? '');

    if (!$serviceName) {
        $error = 'Please enter a service name before finalizing.';
    } else {

        // ── 1. Ensure service record exists with a name ───────────────────────
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

        // ── 2. Calculate total from service items ─────────────────────────────
        $totStmt = $pdo->prepare('SELECT SUM(price) AS total FROM service_items WHERE service_id = :sid');
        $totStmt->execute([':sid' => $serviceId]);
        $totalAmount = (float)($totStmt->fetchColumn() ?? 0);

        $pdo->prepare('UPDATE services SET total_amount = :total WHERE id = :id')
            ->execute([':total' => $totalAmount, ':id' => $serviceId]);

        // ── 3. Load full request snapshot before status change ────────────────
        $snapStmt = $pdo->prepare(
            'SELECT r.*,
                    COALESCE(u.full_name,  w.full_name)  AS customer_name,
                    COALESCE(u.phone,      w.phone)       AS customer_phone,
                    m.name                                AS mechanic_name
             FROM requests r
             LEFT JOIN users              u  ON r.user_id    = u.id
             LEFT JOIN walkin_customers   w  ON r.walkin_id  = w.id
             LEFT JOIN mechanics          m  ON r.mechanic_id = m.id
             WHERE r.id = :id'
        );
        $snapStmt->execute([':id' => $reqId]);
        $snap = $snapStmt->fetch();

        // ── 4. Load service items snapshot ────────────────────────────────────
        $itemsStmt = $pdo->prepare('SELECT item_name, price FROM service_items WHERE service_id = :sid');
        $itemsStmt->execute([':sid' => $serviceId]);
        $itemsSnapshot = $itemsStmt->fetchAll();

        // ── 5. Mark request as completed ──────────────────────────────────────
        $completedAt = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE requests SET status = :s WHERE id = :id')
            ->execute([':s' => 'completed', ':id' => $reqId]);

        // ── 6. Free up mechanic ───────────────────────────────────────────────
        if ($snap['mechanic_id']) {
            $pdo->prepare('UPDATE mechanics SET status = "available" WHERE id = :id')
                ->execute([':id' => $snap['mechanic_id']]);
        }

        // ── 7. Insert into history_records ────────────────────────────────────
        $pdo->prepare(
            'INSERT INTO history_records (
                request_id,
                user_id,        walkin_id,
                customer_name,  customer_phone,
                mechanic_id,    mechanic_name,
                problem_type,   description,
                image,          latitude,       longitude,
                diagnosis,
                status,         rejection_reason,
                total_amount,
                request_created_at,
                completed_at
             ) VALUES (
                :request_id,
                :user_id,       :walkin_id,
                :customer_name, :customer_phone,
                :mechanic_id,   :mechanic_name,
                :problem_type,  :description,
                :image,         :latitude,      :longitude,
                :diagnosis,
                "completed",    NULL,
                :total_amount,
                :request_created_at,
                :completed_at
             )'
        )->execute([
            ':request_id'         => $reqId,
            ':user_id'            => $snap['user_id'],
            ':walkin_id'          => $snap['walkin_id'],
            ':customer_name'      => $snap['customer_name'],
            ':customer_phone'     => $snap['customer_phone'],
            ':mechanic_id'        => $snap['mechanic_id'],
            ':mechanic_name'      => $snap['mechanic_name'],
            ':problem_type'       => $snap['problem_type'],
            ':description'        => $snap['description'],
            ':image'              => $snap['image'],
            ':latitude'           => $snap['latitude'],
            ':longitude'          => $snap['longitude'],
            ':diagnosis'          => $snap['diagnosis'],
            ':total_amount'       => $totalAmount,
            ':request_created_at' => $snap['created_at'],
            ':completed_at'       => $completedAt,
        ]);

        $historyId = $pdo->lastInsertId();

        // ── 8. Insert snapshot of service items into history_service_items ────
        $itemInsert = $pdo->prepare(
            'INSERT INTO history_service_items (history_id, item_name, price)
             VALUES (:hid, :item_name, :price)'
        );
        foreach ($itemsSnapshot as $item) {
            $itemInsert->execute([
                ':hid'       => $historyId,
                ':item_name' => $item['item_name'],
                ':price'     => $item['price'],
            ]);
        }

        // ── 9. Notify customer ────────────────────────────────────────────────
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

// ── Load request data ─────────────────────────────────────────────────────────
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
         LEFT JOIN users              u  ON r.user_id   = u.id
         LEFT JOIN walkin_customers   wc ON r.walkin_id  = wc.id
         LEFT JOIN mechanics          m  ON r.mechanic_id = m.id
         WHERE r.id = :id'
    );
    $stmt->execute([':id' => $requestId]);
    $request = $stmt->fetch();

    if (!$request) {
        $error = 'Request not found.';
    }
}

// ── Load existing service items ───────────────────────────────────────────────
$existingService = null;
$existingItems   = [];
$itemsTotal      = 0;

if ($requestId) {
    $stmt = $pdo->prepare('SELECT * FROM services WHERE request_id = :rid LIMIT 1');
    $stmt->execute([':rid' => $requestId]);
    $existingService = $stmt->fetch();

    if ($existingService) {
        $stmt = $pdo->prepare('SELECT * FROM service_items WHERE service_id = :sid ORDER BY id ASC');
        $stmt->execute([':sid' => $existingService['id']]);
        $existingItems = $stmt->fetchAll();
        $itemsTotal    = array_sum(array_column($existingItems, 'price'));
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

    <?php if ($error): ?>
        <div style="background:#fff4f4;border:1px solid #f5c6cb;color:#a94442;padding:12px;border-radius:6px;margin-bottom:16px;">
            <?php echo e($error); ?>
        </div>
    <?php endif; ?>

    <?php if (!$request): ?>
        <p style="text-align:center;color:var(--muted);">Select a request from active jobs to complete.</p>
        <div style="text-align:center;">
            <a href="<?php echo getBasePath(); ?>admin/active_jobs.php" class="btn btn-primary">View Active Jobs</a>
        </div>

    <?php else: ?>

    <style>
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
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
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
        }
        .info-box p { margin: 4px 0; font-size: 0.9rem; color: #333; }
        .info-box p strong { color: #555; }

        .items-section {
            background: #f9f9f9;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .items-section h4 { margin: 0 0 14px 0; font-size: 1rem; color: var(--charcoal); }

        .item-row {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 0;
            border-bottom: 1px solid #e8e8e8;
        }
        .item-row:last-of-type { border-bottom: none; }
        .item-row .item-name  { flex: 1; font-weight: 600; color: #333; font-size: 0.9rem; }
        .item-row .item-price { width: 80px; text-align: right; color: var(--safety-orange); font-weight: 600; font-size: 0.9rem; }
        .item-row .item-actions { display: flex; gap: 6px; }

        .item-row.editing .item-name-display,
        .item-row.editing .item-price-display,
        .item-row.editing .btn-edit { display: none; }
        .item-row:not(.editing) .item-name-input,
        .item-row:not(.editing) .item-price-input,
        .item-row:not(.editing) .btn-save,
        .item-row:not(.editing) .btn-cancel-edit { display: none; }
        .item-name-input  { flex: 1; padding: 5px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.85rem; }
        .item-price-input { width: 80px; padding: 5px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.85rem; }

        .add-item-form {
            display: flex;
            gap: 8px;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid #ddd;
        }
        .add-item-form input[type="text"]   { flex: 1; padding: 7px 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 0.85rem; }
        .add-item-form input[type="number"] { width: 100px; padding: 7px 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 0.85rem; }
        .add-item-form button { padding: 7px 14px; background: var(--safety-orange); color: #fff; border: none; border-radius: 5px; cursor: pointer; font-size: 0.85rem; white-space: nowrap; }
        .add-item-form button:hover { background: #e55a00; }

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

        .finalize-form {
            background: #f9f9f9;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            padding: 16px;
        }
        .finalize-form label { display: block; margin-bottom: 12px; font-size: 0.9rem; font-weight: 500; }
        .finalize-form input { margin-top: 4px; }

        @media (max-width: 640px) {
            .info-grid { grid-template-columns: 1fr; }
        }
    </style>

    <?php
        $custName    = $request['customer_name']  ?? $request['walkin_name']  ?? '—';
        $custEmail   = $request['customer_email'] ?? $request['walkin_email'] ?? '—';
        $custPhone   = $request['customer_phone'] ?? $request['walkin_phone'] ?? '—';
        $custAddress = $request['walkin_address'] ?? '—';
        $custType    = $request['user_id'] ? 'Registered Customer' : 'Walk-in Customer';
    ?>

    <div class="info-grid">
        <div class="info-box">
            <h4>Customer Info</h4>
            <p><strong>Name:</strong> <?php echo e($custName); ?></p>
            <p><strong>Phone:</strong> <?php echo e($custPhone); ?></p>
            <p><strong>Email:</strong> <?php echo e($custEmail); ?></p>
            <?php if ($request['walkin_id']): ?>
                <p><strong>Address:</strong> <?php echo e($custAddress); ?></p>
            <?php endif; ?>
            <p><strong>Type:</strong> <?php echo $custType; ?></p>
        </div>
        <div class="info-box">
            <h4>Request Info</h4>
            <p><strong>Request #:</strong> <?php echo e($request['id']); ?></p>
            <p><strong>Problem:</strong> <?php echo e($request['problem_type']); ?></p>
            <p><strong>Mechanic:</strong> <?php echo e($request['mechanic_name'] ?? '—'); ?></p>
            <p><strong>Status:</strong> <span class="status status-<?php echo e($request['status']); ?>"><?php echo statusLabel($request['status']); ?></span></p>
            <p><strong>Date:</strong> <?php echo date('M d, Y H:i', strtotime($request['created_at'])); ?></p>
        </div>
    </div>

    <div class="items-section">
        <h4>Service Items</h4>

        <?php if (empty($existingItems)): ?>
            <p style="color:var(--muted);font-size:0.9rem;margin:0 0 8px 0;">No items added yet.</p>
        <?php else: ?>
            <?php foreach ($existingItems as $item): ?>
                <div class="item-row" id="item-row-<?php echo $item['id']; ?>">
                    <span class="item-name item-name-display"><?php echo e($item['item_name']); ?></span>
                    <span class="item-price item-price-display">$<?php echo number_format((float)$item['price'], 2); ?></span>
                    <div class="item-actions">
                        <button type="button" class="btn btn-edit" style="padding:4px 10px;font-size:0.8rem;"
                                onclick="toggleEdit(<?php echo $item['id']; ?>)">Edit</button>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>?id=<?php echo $requestId; ?>&delete_item=<?php echo $item['id']; ?>"
                           class="btn" style="padding:4px 10px;font-size:0.8rem;background:#d9534f;color:#fff;"
                           onclick="return confirm('Delete this item?');">Delete</a>
                    </div>
                    <form method="post" style="display:contents;">
                        <input type="hidden" name="request_id" value="<?php echo $requestId; ?>">
                        <input type="hidden" name="item_id"    value="<?php echo $item['id']; ?>">
                        <input type="text"   name="item_name"  class="item-name-input"  value="<?php echo e($item['item_name']); ?>" required>
                        <input type="number" name="price"      class="item-price-input" value="<?php echo (float)$item['price']; ?>" step="0.01" min="0" required>
                        <div class="item-actions">
                            <button type="submit" name="update_item" class="btn btn-save btn-primary"
                                    style="padding:4px 10px;font-size:0.8rem;">Save</button>
                            <button type="button" class="btn btn-cancel-edit"
                                    style="padding:4px 10px;font-size:0.8rem;"
                                    onclick="toggleEdit(<?php echo $item['id']; ?>)">Cancel</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <form method="post" class="add-item-form">
            <input type="hidden" name="request_id" value="<?php echo $requestId; ?>">
            <input type="text"   name="item_name" placeholder="Item name (e.g., Battery)" required>
            <input type="number" name="price" step="0.01" min="0.01" placeholder="Price" required>
            <button type="submit" name="add_item">+ Add Item</button>
        </form>
    </div>

    <div class="total-bar">
        <span>Total Amount (from items)</span>
        <span class="total-amount">$<?php echo number_format($itemsTotal, 2); ?></span>
    </div>

    <form method="post" class="finalize-form">
        <input type="hidden" name="request_id" value="<?php echo $requestId; ?>">
        <label>
            Service Name <span style="color:#d9534f;">*</span>
            <input type="text" name="service_name"
                   value="<?php echo e($existingService['service_name'] ?? ''); ?>"
                   placeholder="e.g., Battery Replacement"
                   required>
        </label>
        <?php if (empty($existingItems)): ?>
            <p style="color:#a94442;font-size:0.85rem;margin-bottom:12px;">
                ⚠ Please add at least one service item before finalizing.
            </p>
        <?php endif; ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="btn btn-primary" type="submit" name="finalize_service"
                <?php echo empty($existingItems) ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''; ?>>
                ✓ Complete Service &amp; Generate Invoice
            </button>
           <!-- check it is for  cancel button  <a href="<php echo getBasePath(); >admin/active_jobs.php" class="btn">Cancel</a>-->
        </div>
    </form>

    <script>
        function toggleEdit(itemId) {
            document.getElementById('item-row-' + itemId).classList.toggle('editing');
        }
    </script>

    <?php endif; ?>
</div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
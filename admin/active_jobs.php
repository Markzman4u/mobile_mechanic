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

// ── Handle: Add service item ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $expandJobId = (int) $_POST['request_id'];
    $itemName    = trim($_POST['item_name'] ?? '');
    $price       = isset($_POST['price']) ? (float) $_POST['price'] : 0;

    if ($itemName && $price > 0) {
        $stmt = $pdo->prepare('SELECT id FROM services WHERE request_id = :rid');
        $stmt->execute([':rid' => $expandJobId]);
        $svc = $stmt->fetch();

        if (!$svc) {
            $ins = $pdo->prepare('INSERT INTO services (request_id, service_name, total_amount) VALUES (:rid, :sn, 0)');
            $ins->execute([':rid' => $expandJobId, ':sn' => 'Service']);
            $serviceId = $pdo->lastInsertId();
        } else {
            $serviceId = $svc['id'];
        }

        $pdo->prepare('INSERT INTO service_items (service_id, item_name, price) VALUES (:sid, :name, :price)')
            ->execute([':sid' => $serviceId, ':name' => $itemName, ':price' => $price]);

        header('Location: ' . $_SERVER['PHP_SELF'] . '?expand=' . $expandJobId . '&msg=' . urlencode('Item added successfully.'));
        exit;
    }
}

// ── Handle: Update service item ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    $itemId   = (int) $_POST['item_id'];
    $itemName = trim($_POST['item_name'] ?? '');
    $price    = isset($_POST['price']) ? (float) $_POST['price'] : 0;

    $expandJobId = getRequestIdFromItem($pdo, $itemId);

    if ($itemName && $price > 0) {
        $pdo->prepare('UPDATE service_items SET item_name = :name, price = :price WHERE id = :id')
            ->execute([':name' => $itemName, ':price' => $price, ':id' => $itemId]);
        $message = 'Item updated successfully.';
    }
}

// ── Handle: Delete service item (GET) ─────────────────────────────────────
if (isset($_GET['delete_item']) && ctype_digit($_GET['delete_item'])) {
    $itemId      = (int) $_GET['delete_item'];
    $expandJobId = getRequestIdFromItem($pdo, $itemId);

    $pdo->prepare('DELETE FROM service_items WHERE id = :id')->execute([':id' => $itemId]);
    $message = 'Item deleted successfully.';

    header('Location: ' . $_SERVER['PHP_SELF'] . '?expand=' . $expandJobId . '&msg=' . urlencode($message));
    exit;
}

// ── Handle: Status update ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $requestId = (int) $_POST['request_id'];
    $newStatus = $_POST['new_status'] ?? '';
    $expandJobId = $requestId;

    $validStatuses = ['assigned', 'in_progress', 'completed'];
    if (in_array($newStatus, $validStatuses)) {
        $pdo->prepare('UPDATE requests SET status = :status WHERE id = :id')
            ->execute([':status' => $newStatus, ':id' => $requestId]);

        if ($newStatus === 'completed') {
            header('Location: ' . getBasePath() . 'admin/complete_service.php?id=' . $requestId);
            exit;
        }
        $message = 'Status updated successfully.';
    }
}

// ── Expand from GET param (after delete redirect) ─────────────────────────
if ($expandJobId === null && isset($_GET['expand']) && ctype_digit($_GET['expand'])) {
    $expandJobId = (int) $_GET['expand'];
}
if (isset($_GET['msg']) && !$message) {
    $message = htmlspecialchars($_GET['msg']);
}

// ── Helper: get service items for a request ───────────────────────────────
function getServiceItemsForRequest($pdo, $requestId) {
    $stmt = $pdo->prepare(
        'SELECT si.id, si.item_name, si.price
         FROM service_items si
         JOIN services s ON si.service_id = s.id
         WHERE s.request_id = :rid'
    );
    $stmt->execute([':rid' => $requestId]);
    return $stmt->fetchAll();
}

// ── Load active jobs ───────────────────────────────────────────────────────
$jobs = $pdo->query(
    'SELECT r.id,
            r.mechanic_id,
            COALESCE(u.full_name, w.full_name, "Unknown") AS customer_name,
            CASE WHEN r.walkin_id IS NOT NULL THEN 1 ELSE 0 END AS is_walkin,
            r.problem_type,
            r.diagnosis,
            m.name AS mechanic_name,
            r.status,
            r.created_at
     FROM requests r
     LEFT JOIN users u            ON r.user_id   = u.id
     LEFT JOIN walkin_customers w ON r.walkin_id  = w.id
     LEFT JOIN mechanics m        ON r.mechanic_id = m.id
     WHERE r.status IN ("assigned", "in_progress")
     ORDER BY r.created_at DESC'
)->fetchAll();

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
            .job-row-expandable { cursor: pointer; }
            .job-row-expandable:hover { background: #f9f9f9; }
            .expand-icon { display: inline-block; transition: transform 0.2s; }
            .expand-icon.expanded { transform: rotate(90deg); }

            .items-section { background: #f9f9f9; padding: 16px; border-top: 1px solid #eee; }
            .items-list { margin-bottom: 16px; }

            .item-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #e0e0e0; }
            .item-row:last-child { border-bottom: none; }
            .item-info { flex: 1; }
            .item-name { font-weight: 600; color: #333; }
            .item-actions { display: flex; gap: 4px; }

            .item-btn { padding: 4px 8px; font-size: 0.75rem; border: none; border-radius: 3px; cursor: pointer; text-decoration: none; display: inline-block; }
            .item-btn-edit   { background: #4CAF50; color: white; }
            .item-btn-edit:hover   { background: #45a049; }
            .item-btn-delete { background: #d9534f; color: white; }
            .item-btn-delete:hover { background: #c0392b; }

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

            .add-item-form { display: flex; gap: 8px; margin-top: 12px; padding-top: 12px; border-top: 1px solid #ddd; flex-wrap: wrap; }
            .add-item-form input { flex: 1; min-width: 120px; padding: 6px 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 0.85rem; }
            .add-item-form input[type="number"] { flex: 0 0 90px; }
            .add-item-form button { padding: 6px 14px; background: var(--safety-orange); color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85rem; white-space: nowrap; }
            .add-item-form button:hover { background: #e55a00; }

            .total-line { padding-top: 12px; border-top: 1px solid #ddd; color: var(--charcoal); font-weight: 700; font-size: 0.95rem; }

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

            /* ── Clickable cell links ────────────────────────────────────── */
            .cell-link {
                color: inherit;
                text-decoration: none;
                font-weight: 600;
            }
            .cell-link:hover {
                color: var(--safety-orange, #ff6600);
                text-decoration: underline;
            }
            .cell-link-mechanic {
                color: #3a5bbd;
            }
            .cell-link-mechanic:hover {
                color: var(--safety-orange, #ff6600);
            }

            /* ── Diagnosis column ───────────────────────────────────────── */
            th.col-diagnosis,
            td.col-diagnosis {
                width: 150px;
                min-width: 110px;
                max-width: 150px;
            }

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
            .diagnosis-cell.empty {
                color: #bbb;
                font-style: italic;
            }

            /* ── Actions column ─────────────────────────────────────────── */
            /* Stack select + button vertically so neither gets cut off      */
            th.col-actions,
            td.col-actions {
                width: 130px;
                min-width: 130px;
            }

            .action-form {
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
            .action-form select {
                width: 100%;
                padding: 4px 6px;
                font-size: 0.82rem;
                border: 1px solid #ccc;
                border-radius: 4px;
                box-sizing: border-box;
            }
            .action-form button {
                width: 100%;
                padding: 4px 0;
                font-size: 0.82rem;
            }
        </style>

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
                        <td colspan="9" style="text-align:center;color:var(--muted);padding:20px;">No active jobs found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($jobs as $job):
                        $items      = getServiceItemsForRequest($pdo, $job['id']);
                        $totalItems = array_sum(array_column($items, 'price'));
                        $isExpanded = ($expandJobId === $job['id']);
                        $diagTooltip = !empty($job['diagnosis']) ? e($job['diagnosis']) : '';
                    ?>
                        <!-- ── Job Row ──────────────────────────────────── -->
                        <tr class="job-row-expandable" onclick="toggleItems(this)" data-job-id="<?php echo $job['id']; ?>">
                            <td><span class="expand-icon <?php echo $isExpanded ? 'expanded' : ''; ?>">▶</span></td>
                            <td><?php echo e($job['id']); ?></td>

                            <!-- Customer name → request detail page -->
                            <td onclick="event.stopPropagation();">
                                <a href="<?php echo getBasePath(); ?>admin/request_detail.php?id=<?php echo (int)$job['id']; ?>"
                                   class="cell-link"
                                   title="View request details">
                                    <?php echo e($job['customer_name']); ?>
                                </a>
                                <?php if ($job['is_walkin']): ?>
                                    <span class="badge-walkin">Walk-in</span>
                                <?php endif; ?>
                            </td>

                            <td><?php echo e($job['problem_type']); ?></td>

                            <!-- Mechanic name → track mechanic page -->
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

                            <!-- Diagnosis — wraps to 3 lines max, full text on hover -->
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

                            <!-- Actions — select stacked above button -->
                            <td class="col-actions" onclick="event.stopPropagation();">
                                <form method="post" class="action-form">
                                    <input type="hidden" name="request_id" value="<?php echo $job['id']; ?>">
                                    <select name="new_status">
                                        <option value="">— Status —</option>
                                        <?php if ($job['status'] === 'assigned'): ?>
                                            <option value="in_progress">Start Job</option>
                                        <?php endif; ?>
                                        <option value="completed">Complete</option>
                                    </select>
                                    <button type="submit" name="update_status" class="btn">Update</button>
                                </form>
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

                                    <?php if (empty($items)): ?>
                                        <p style="color:var(--muted);font-size:0.9rem;margin:0 0 12px 0;">No items added yet.</p>
                                    <?php else: ?>
                                        <div class="items-list">
                                            <?php foreach ($items as $item): ?>
                                                <div class="item-row" data-item-id="<?php echo $item['id']; ?>">
                                                    <div class="item-info">
                                                        <div class="item-name"><?php echo e($item['item_name']); ?></div>
                                                        <div style="font-size:0.85rem;color:var(--muted);">
                                                            $<?php echo number_format((float)$item['price'], 2); ?>
                                                        </div>
                                                    </div>
                                                    <div class="item-actions">
                                                        <button type="button" class="item-btn item-btn-edit"
                                                                onclick="toggleEditForm(event, <?php echo $item['id']; ?>)">Edit</button>
                                                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>?delete_item=<?php echo $item['id']; ?>"
                                                           class="item-btn item-btn-delete"
                                                           onclick="return confirm(<?php echo json_encode('Delete this item?'); ?>);">Delete</a>
                                                    </div>
                                                </div>
                                                <!-- Inline edit form -->
                                                <div class="item-edit-form" id="edit-form-<?php echo $item['id']; ?>">
                                                    <form method="post" onclick="event.stopPropagation();">
                                                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                        <input type="text"   name="item_name" value="<?php echo e($item['item_name']); ?>" placeholder="Item name" required>
                                                        <input type="number" name="price"     value="<?php echo number_format((float)$item['price'], 2); ?>" step="0.01" min="0" placeholder="Price" required>
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

                                    <!-- Add new item form -->
                                    <form method="post" class="add-item-form" onclick="event.stopPropagation();">
                                        <input type="hidden" name="request_id" value="<?php echo $job['id']; ?>">
                                        <input type="text"   name="item_name" placeholder="Item name (e.g., Battery)" required>
                                        <input type="number" name="price" step="0.01" min="0" placeholder="Price" required>
                                        <button type="submit" name="add_item">+ Add Item</button>
                                    </form>
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
    // ── Toggle expand/collapse ─────────────────────────────────────────────
    function toggleItems(row) {
        const jobId   = row.getAttribute('data-job-id');
        const drawer  = document.querySelector('.items-row[data-job-id="' + jobId + '"]');
        const icon    = row.querySelector('.expand-icon');
        if (!drawer) return;

        const opening = drawer.style.display === 'none' || drawer.style.display === '';
        drawer.style.display = opening ? 'table-row' : 'none';
        icon.classList.toggle('expanded', opening);
    }

    // ── Toggle inline edit form ────────────────────────────────────────────
    function toggleEditForm(event, itemId) {
        event.preventDefault();
        event.stopPropagation();
        const form = document.getElementById('edit-form-' + itemId);
        if (!form) return;
        const opening = !form.classList.contains('show');
        form.classList.toggle('show', opening);
        if (opening) {
            form.querySelector('input[type="text"]').focus();
        }
    }

    // ── Auto-expand the target row on page load ────────────────────────────
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
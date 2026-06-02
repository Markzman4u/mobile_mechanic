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
$error   = '';

// ── Handle: Delete Selected Services ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected'])) {
    $ids = $_POST['selected_ids'] ?? [];
    // Filter to valid integers only
    $ids = array_filter(array_map('intval', (array) $ids));

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // service_items cascade from services, so deleting services is enough
        $pdo->prepare("DELETE FROM services WHERE id IN ($placeholders)")->execute(array_values($ids));
        $message = count($ids) . ' service(s) deleted successfully.';
    } else {
        $error = 'No services selected.';
    }
}

// ── Handle: Delete All Services ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all'])) {
    $pdo->exec('DELETE FROM services');
    $message = 'All service history cleared.';
}

// ── Load services ─────────────────────────────────────────────────────────────
$services = $pdo->query(
    'SELECT s.id, s.service_name, s.total_amount, s.created_at,
            r.id AS request_id, r.problem_type, r.status,
            COALESCE(u.full_name, w.full_name) AS customer_name,
            m.name AS mechanic_name
     FROM services s
     JOIN requests r ON s.request_id = r.id
     LEFT JOIN users u ON r.user_id = u.id
     LEFT JOIN walkin_customers w ON r.walkin_id = w.id
     LEFT JOIN mechanics m ON r.mechanic_id = m.id
     WHERE r.status = "completed"
     ORDER BY s.created_at DESC'
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
<div class="card">
    <h2>Service History</h2>
    <p class="muted">Completed services and invoices.</p>

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

    <style>
        .toolbar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .toolbar input[type="search"] {
            flex: 1;
            min-width: 180px;
        }
        .btn-danger {
            background: #d9534f;
            color: #fff;
            border: none;
            padding: 8px 14px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.875rem;
            white-space: nowrap;
        }
        .btn-danger:hover { background: #c0392b; }
        .btn-danger:disabled { opacity: 0.45; cursor: not-allowed; }

        .btn-outline-danger {
            background: transparent;
            color: #d9534f;
            border: 1.5px solid #d9534f;
            padding: 7px 14px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.875rem;
            white-space: nowrap;
        }
        .btn-outline-danger:hover { background: #fdf0ef; }

        #selection-bar {
            display: none;
            align-items: center;
            gap: 10px;
            background: #fff8f0;
            border: 1px solid #ffd8b0;
            border-radius: 6px;
            padding: 8px 14px;
            margin-bottom: 12px;
            font-size: 0.9rem;
            color: #555;
        }
        #selection-bar.visible { display: flex; }
        #selected-count { font-weight: 700; color: var(--safety-orange, #ff6600); }

        tbody tr { transition: background 0.1s; }
        tbody tr.selected-row { background: #fff3e8 !important; }
        input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; accent-color: var(--safety-orange, #ff6600); }
    </style>

    <form method="post" id="history-form">

        <!-- ── Toolbar ─────────────────────────────────────────────────── -->
        <div class="toolbar">
            <input type="search" id="search-input" placeholder="Search by customer, problem, or request ID">
            <!-- Delete Selected -->
            <button type="submit" name="delete_selected" id="btn-delete-selected"
                    class="btn-danger" disabled
                    onclick="return confirm('Delete the selected service(s)? This cannot be undone.')">
                🗑 Delete Selected
            </button>
            <!-- Delete All -->
            <button type="submit" name="delete_all"
                    class="btn-outline-danger"
                    <?php echo empty($services) ? 'disabled' : ''; ?>
                    onclick="return confirm('Delete ALL service history? This cannot be undone.')">
                ✕ Clear All
            </button>
        </div>

        <!-- ── Selection feedback bar ─────────────────────────────────── -->
        <div id="selection-bar">
            <span><span id="selected-count">0</span> row(s) selected</span>
            <button type="button" class="btn" style="padding:4px 10px;font-size:0.8rem;" onclick="clearSelection()">Deselect All</button>
        </div>

        <!-- ── Table ──────────────────────────────────────────────────── -->
        <table id="history-table">
            <thead>
                <tr>
                    <th style="width:36px;">
                        <input type="checkbox" id="check-all" title="Select all">
                    </th>
                    <th>Request ID</th>
                    <th>Customer</th>
                    <th>Problem</th>
                    <th>Mechanic</th>
                    <th>Service Date</th>
                    <th>Total Amount</th>
                    <th>View</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($services)): ?>
                    <tr id="empty-row">
                        <td colspan="8" style="text-align:center;color:var(--muted);padding:20px;">No history found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($services as $srv): ?>
                        <tr data-search="<?php echo strtolower(e($srv['customer_name']) . ' ' . e($srv['problem_type']) . ' ' . $srv['request_id']); ?>">
                            <td>
                                <input type="checkbox" name="selected_ids[]"
                                       value="<?php echo $srv['id']; ?>"
                                       class="row-check"
                                       onclick="updateSelection()">
                            </td>
                            <td><?php echo e($srv['request_id']); ?></td>
                            <td><?php echo e($srv['customer_name']); ?></td>
                            <td><?php echo e($srv['problem_type']); ?></td>
                            <td><?php echo e($srv['mechanic_name']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($srv['created_at'])); ?></td>
                            <td>$<?php echo number_format((float)$srv['total_amount'], 2); ?></td>
                            <td><a href="<?php echo getBasePath(); ?>admin/invoice.php?id=<?php echo $srv['request_id']; ?>" class="btn btn-primary" style="padding:4px 12px;font-size:0.82rem;">View Invoice</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

    </form><!-- end #history-form -->
</div>
</main>

<script>
    // ── Select All toggle ──────────────────────────────────────────────────
    document.getElementById('check-all').addEventListener('change', function () {
        const boxes = document.querySelectorAll('.row-check:not([style*="display:none"])');
        boxes.forEach(cb => {
            cb.checked = this.checked;
            cb.closest('tr').classList.toggle('selected-row', this.checked);
        });
        updateSelection();
    });

    // ── Update selection state ─────────────────────────────────────────────
    function updateSelection() {
        const checked = document.querySelectorAll('.row-check:checked');
        const bar     = document.getElementById('selection-bar');
        const btnDel  = document.getElementById('btn-delete-selected');
        const count   = checked.length;

        document.getElementById('selected-count').textContent = count;
        bar.classList.toggle('visible', count > 0);
        btnDel.disabled = count === 0;

        // Highlight rows
        document.querySelectorAll('.row-check').forEach(cb => {
            cb.closest('tr').classList.toggle('selected-row', cb.checked);
        });

        // Sync check-all state
        const all = document.querySelectorAll('.row-check:not([style*="display:none"])');
        document.getElementById('check-all').indeterminate = count > 0 && count < all.length;
        document.getElementById('check-all').checked = all.length > 0 && count === all.length;
    }

    function clearSelection() {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = false);
        document.getElementById('check-all').checked = false;
        updateSelection();
    }

    // ── Live search filter ─────────────────────────────────────────────────
    document.getElementById('search-input').addEventListener('input', function () {
        const q = this.value.toLowerCase().trim();
        document.querySelectorAll('#history-table tbody tr[data-search]').forEach(row => {
            const match = !q || row.dataset.search.includes(q);
            row.style.display = match ? '' : 'none';
        });
        // Uncheck hidden rows so they aren't submitted
        document.querySelectorAll('#history-table tbody tr[data-search][style*="display:none"] .row-check').forEach(cb => {
            cb.checked = false;
        });
        updateSelection();
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
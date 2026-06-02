<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . getBasePath() . 'admin/history.php');
    exit;
}

$pdo    = getPDO();
$userId = $_SESSION['user_id'];
$message = '';
$error   = '';

// ── Handle: Delete Selected ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected'])) {
    $ids = array_filter(array_map('intval', (array)($_POST['selected_ids'] ?? [])));

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // Delete service_items first (grandchild rows)
        $pdo->prepare(
            "DELETE si FROM service_items si
             JOIN services s ON si.service_id = s.id
             JOIN requests r ON s.request_id = r.id
             WHERE r.user_id = ? AND r.id IN ($placeholders)"
        )->execute([$userId, ...$ids]);

        // Delete services (child rows)
        $pdo->prepare(
            "DELETE s FROM services s
             JOIN requests r ON s.request_id = r.id
             WHERE r.user_id = ? AND r.id IN ($placeholders)"
        )->execute([$userId, ...$ids]);

        // Delete requests
        $pdo->prepare(
            "DELETE FROM requests WHERE id IN ($placeholders) AND user_id = ?"
        )->execute([...$ids, $userId]);

        $message = count($ids) . ' record(s) removed from your history.';
    } else {
        $error = 'No records selected.';
    }
}

// ── Handle: Clear All ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all'])) {
    // Delete service_items first
    $pdo->prepare(
        "DELETE si FROM service_items si
         JOIN services s ON si.service_id = s.id
         JOIN requests r ON s.request_id = r.id
         WHERE r.user_id = ? AND r.status = 'completed'"
    )->execute([$userId]);

    // Delete services
    $pdo->prepare(
        "DELETE s FROM services s
         JOIN requests r ON s.request_id = r.id
         WHERE r.user_id = ? AND r.status = 'completed'"
    )->execute([$userId]);

    // Delete completed requests
    $pdo->prepare(
        "DELETE FROM requests WHERE user_id = ? AND status = 'completed'"
    )->execute([$userId]);

    $message = 'Your service history has been cleared.';
}

// ── Load completed requests for this user ─────────────────────────────────────
// Only show completed jobs that have pricing information (billing completed)
$stmt = $pdo->prepare(
    "SELECT r.id          AS request_id,
            r.problem_type,
            r.status,
            r.created_at  AS request_date,
            m.name        AS mechanic_name,
            s.id          AS service_id,
            s.service_name,
            s.total_amount,
            s.created_at  AS display_date
     FROM requests r
     INNER JOIN services  s ON s.request_id = r.id
     LEFT JOIN mechanics m ON r.mechanic_id = m.id
     WHERE r.user_id = :uid
       AND r.status   = 'completed'
       AND s.total_amount IS NOT NULL
     ORDER BY display_date DESC"
);
$stmt->execute([':uid' => $userId]);
$serviceList = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
<div class="card">
    <h2>Service History</h2>
    <p class="muted">All your completed service requests and invoices.</p>

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
            gap: 8px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .toolbar input[type="search"] {
            flex: 1;
            min-width: 200px;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 0.9rem;
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
        .btn-danger:hover    { background: #c0392b; }
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
        .btn-outline-danger:hover    { background: #fdf0ef; }
        .btn-outline-danger:disabled { opacity: 0.45; cursor: not-allowed; }

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

        tbody tr.selected-row { background: #fff3e8 !important; }
        input[type="checkbox"] {
            width: 16px; height: 16px;
            cursor: pointer;
            accent-color: var(--safety-orange, #ff6600);
        }

        /* Search highlight */
        mark { background: #ffe082; border-radius: 2px; padding: 0 2px; }
    </style>

    <form method="post" id="history-form">

        <!-- ── Toolbar ───────────────────────────────────────────────── -->
        <div class="toolbar">
            <input type="search" id="search-input"
                   placeholder="Search by request ID, problem, mechanic or date…">

            <button type="submit" name="delete_selected" id="btn-delete-selected"
                    class="btn-danger" disabled
                    onclick="return confirm('Remove selected records from your history?')">
                🗑 Remove Selected
            </button>

            <button type="submit" name="delete_all"
                    class="btn-outline-danger"
                    <?php echo empty($serviceList) ? 'disabled' : ''; ?>
                    onclick="return confirm('Clear your entire service history? This cannot be undone.')">
                ✕ Clear All
            </button>
        </div>

        <!-- ── Selection bar ─────────────────────────────────────────── -->
        <div id="selection-bar">
            <span><span id="selected-count">0</span> record(s) selected</span>
            <button type="button" class="btn" style="padding:4px 10px;font-size:0.8rem;"
                    onclick="clearSelection()">Deselect All</button>
        </div>

        <!-- ── Table ─────────────────────────────────────────────────── -->
        <table id="history-table">
            <thead>
                <tr>
                    <th style="width:36px;">
                        <input type="checkbox" id="check-all" title="Select all">
                    </th>
                    <th>Request ID</th>
                    <th>Problem</th>
                    <th>Service</th>
                    <th>Mechanic</th>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Invoice</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($serviceList)): ?>
                    <tr id="empty-row">
                        <td colspan="8" style="text-align:center;color:var(--muted);padding:24px;">
                            No service history found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($serviceList as $srv):
                        $searchData = strtolower(
                            $srv['request_id'] . ' ' .
                            ($srv['problem_type']   ?? '') . ' ' .
                            ($srv['service_name']   ?? '') . ' ' .
                            ($srv['mechanic_name']  ?? '') . ' ' .
                            date('M d Y', strtotime($srv['display_date'])) . ' ' .
                            date('m/d/Y', strtotime($srv['display_date']))
                        );
                    ?>
                        <tr data-search="<?php echo e($searchData); ?>">
                            <td>
                                <input type="checkbox" name="selected_ids[]"
                                       value="<?php echo $srv['request_id']; ?>"
                                       class="row-check"
                                       onclick="updateSelection()">
                            </td>
                            <td>#<?php echo e($srv['request_id']); ?></td>
                            <td class="col-problem"><?php echo e($srv['problem_type'] ?? '—'); ?></td>
                            <td><?php echo e($srv['service_name'] ?? '—'); ?></td>
                            <td class="col-mechanic"><?php echo e($srv['mechanic_name'] ?? '—'); ?></td>
                            <td class="col-date"><?php echo date('M d, Y', strtotime($srv['display_date'])); ?></td>
                            <td>$<?php echo number_format((float)$srv['total_amount'], 2); ?></td>
                            <td>
                                <a href="<?php echo getBasePath(); ?>customer/invoice.php?id=<?php echo $srv['request_id']; ?>"
                                   class="btn btn-primary" style="padding:4px 12px;font-size:0.82rem;">
                                    View Invoice
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <p class="muted" style="margin-top:12px;font-size:0.85rem;" id="result-count">
            <?php echo count($serviceList); ?> record(s) found.
        </p>

    </form>
</div>
</main>

<script>
    // ── Select All ────────────────────────────────────────────────────────────
    document.getElementById('check-all').addEventListener('change', function () {
        document.querySelectorAll('.row-check:not([style*="display:none"])').forEach(cb => {
            cb.checked = this.checked;
            cb.closest('tr').classList.toggle('selected-row', this.checked);
        });
        updateSelection();
    });

    // ── Update selection state ────────────────────────────────────────────────
    function updateSelection() {
        const checked  = document.querySelectorAll('.row-check:checked');
        const allBoxes = document.querySelectorAll('#history-table tbody tr:not([style*="display:none"]) .row-check');
        const count    = checked.length;

        document.getElementById('selected-count').textContent = count;
        document.getElementById('selection-bar').classList.toggle('visible', count > 0);
        document.getElementById('btn-delete-selected').disabled = count === 0;

        document.querySelectorAll('.row-check').forEach(cb => {
            cb.closest('tr').classList.toggle('selected-row', cb.checked);
        });

        const checkAll = document.getElementById('check-all');
        checkAll.indeterminate = count > 0 && count < allBoxes.length;
        checkAll.checked = allBoxes.length > 0 && count === allBoxes.length;
    }

    function clearSelection() {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = false);
        document.getElementById('check-all').checked = false;
        updateSelection();
    }

    // ── Live search ───────────────────────────────────────────────────────────
    document.getElementById('search-input').addEventListener('input', function () {
        const q    = this.value.toLowerCase().trim();
        let visible = 0;

        document.querySelectorAll('#history-table tbody tr[data-search]').forEach(row => {
            const match = !q || row.dataset.search.includes(q);
            row.style.display = match ? '' : 'none';

            if (match) {
                visible++;
                ['col-problem', 'col-mechanic', 'col-date'].forEach(cls => {
                    const cell = row.querySelector('.' + cls);
                    if (!cell) return;
                    const raw  = cell.dataset.raw ?? (cell.dataset.raw = cell.textContent);
                    if (q) {
                        cell.innerHTML = raw.replace(
                            new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi'),
                            '<mark>$1</mark>'
                        );
                    } else {
                        cell.textContent = raw;
                    }
                });
            } else {
                const cb = row.querySelector('.row-check');
                if (cb) { cb.checked = false; }
            }
        });

        document.getElementById('result-count').textContent = visible + ' record(s) found.';
        updateSelection();
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
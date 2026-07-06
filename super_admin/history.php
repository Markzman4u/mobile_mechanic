<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (!isSuperAdmin()) {
    header('Location: ' . getBasePath() . 'customer/dashboard.php');
    exit;
}

$pdo     = getPDO();
$message = '';
$error   = '';

// ── Handle: Hide Selected ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hide_selected'])) {
    $ids = array_filter(array_map('intval', (array)($_POST['selected_ids'] ?? [])));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE history_records SET hidden_by_admin = TRUE WHERE id IN ($placeholders)")
            ->execute(array_values($ids));
        $message = count($ids) . ' record(s) hidden successfully.';
    } else {
        $error = 'No records selected.';
    }
}

// ── Load history records (active only) ───────────────────────────────────────
$records = $pdo->query(
    "SELECT hr.id,
            hr.request_id,
            hr.customer_name,
            hr.mechanic_name,
            hr.problem_type,
            hr.status,
            hr.total_amount,
            hr.completed_at,
            hr.hidden_by_admin,
            hr.walkin_id,
            CASE WHEN hr.walkin_id IS NOT NULL THEN 1 ELSE 0 END AS is_walkin
     FROM history_records hr
     WHERE hr.hidden_by_admin = FALSE
     ORDER BY hr.completed_at DESC"
)->fetchAll();

// ── Count per tab for badges ──────────────────────────────────────────────────
$countAll       = count($records);
$countCompleted = 0;
$countRejected  = 0;
$countWalkin    = 0;
foreach ($records as $rec) {
    if ($rec['status']    === 'completed') $countCompleted++;
    if ($rec['status']    === 'rejected')  $countRejected++;
    if ($rec['is_walkin'] == 1)            $countWalkin++;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
<div class="card">

    <div style="margin-bottom:6px;">
        <h2>Service History</h2>
        <p class="muted">Archive of completed and rejected jobs.</p>
    </div>

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
        .filter-tabs {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .filter-tab {
            padding: 6px 14px;
            border-radius: 20px;
            border: 1px solid #ddd;
            background: #f5f5f5;
            color: #555;
            font-size: 0.85rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
            user-select: none;
        }
        .filter-tab:hover { border-color: #ff6600; color: #ff6600; background: #fff8f0; }
        .filter-tab.active {
            background: #ff6600;
            color: #fff;
            border-color: #ff6600;
            font-weight: 600;
        }
        .filter-tab .tab-count {
            background: rgba(0,0,0,0.12);
            border-radius: 10px;
            padding: 1px 7px;
            font-size: 0.78rem;
            font-weight: 700;
        }
        .filter-tab.active .tab-count { background: rgba(255,255,255,0.3); }

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

        .status-completed { background:#e8f7e9; color:#2f6627; padding:3px 10px; border-radius:12px; font-size:0.8rem; font-weight:600; }
        .status-rejected  { background:#fff4f4; color:#a94442; padding:3px 10px; border-radius:12px; font-size:0.8rem; font-weight:600; }

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

        #no-filter-results {
            display: none;
            text-align: center;
            color: var(--muted);
            padding: 20px;
        }
    </style>

    <!-- ── Filter Tabs ──────────────────────────────────────────────────── -->
    <div class="filter-tabs">
        <span class="filter-tab active" data-filter="all">
            All <span class="tab-count"><?php echo $countAll; ?></span>
        </span>
        <span class="filter-tab" data-filter="completed">
            ✅ Completed <span class="tab-count"><?php echo $countCompleted; ?></span>
        </span>
        <span class="filter-tab" data-filter="rejected">
            ❌ Rejected <span class="tab-count"><?php echo $countRejected; ?></span>
        </span>
        <span class="filter-tab" data-filter="walkin">
            🚶 Walk-in Customers <span class="tab-count"><?php echo $countWalkin; ?></span>
        </span>
    </div>

    <form method="post" id="history-form">

        <!-- ── Toolbar ──────────────────────────────────────────────────── -->
        <div class="toolbar">
            <input type="search" id="search-input"
                   placeholder="Search by customer, mechanic, problem, or request ID…">

            <button type="submit" name="hide_selected" id="btn-action"
                    class="btn-danger" disabled
                    onclick="return confirm('Hide the selected record(s)? You can restore them from the database.')">
                🗄 Hide Selected
            </button>
        </div>

        <!-- ── Selection feedback bar ───────────────────────────────────── -->
        <div id="selection-bar">
            <span><span id="selected-count">0</span> row(s) selected</span>
            <button type="button" class="btn"
                    style="padding:4px 10px;font-size:0.8rem;"
                    onclick="clearSelection()">Deselect All</button>
        </div>

        <!-- ── Table ────────────────────────────────────────────────────── -->
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
                    <th>Status</th>
                    <th>Completed</th>
                    <th>Total</th>
                    <th>Invoice</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr id="empty-row">
                        <td colspan="9" style="text-align:center;color:var(--muted);padding:20px;">
                            No history records found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $rec): ?>
                        <tr data-search="<?php echo strtolower(
                                e($rec['customer_name']) . ' ' .
                                e($rec['mechanic_name']) . ' ' .
                                e($rec['problem_type'])  . ' ' .
                                $rec['request_id']
                            ); ?>"
                            data-status="<?php echo e($rec['status']); ?>"
                            data-walkin="<?php echo $rec['is_walkin'] ? '1' : '0'; ?>">
                            <td>
                                <input type="checkbox" name="selected_ids[]"
                                       value="<?php echo $rec['id']; ?>"
                                       class="row-check"
                                       onclick="updateSelection()">
                            </td>
                            <td><?php echo e($rec['request_id']); ?></td>
                            <td>
                                <?php echo e($rec['customer_name']); ?>
                                <?php if ($rec['is_walkin']): ?>
                                    <span class="badge-walkin">Walk-in</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo e($rec['problem_type']); ?></td>
                            <td><?php echo e($rec['mechanic_name'] ?? '—'); ?></td>
                            <td>
                                <span class="status-<?php echo $rec['status']; ?>">
                                    <?php echo ucfirst(e($rec['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo $rec['completed_at']
                                    ? date('M d, Y', strtotime($rec['completed_at']))
                                    : '—'; ?>
                            </td>
                            <td>
                                <?php echo $rec['total_amount'] > 0
                                    ? '$' . number_format((float)$rec['total_amount'], 2)
                                    : '—'; ?>
                            </td>
                            <td>
                                <?php if ($rec['status'] === 'completed'): ?>
                                    <a href="<?php echo getBasePath(); ?>admin/invoice.php?history_id=<?php echo $rec['id']; ?>"
                                       class="btn btn-primary"
                                       style="padding:4px 12px;font-size:0.82rem;">
                                        View Invoice
                                    </a>
                                <?php else: ?>
                                    <span style="color:var(--muted);font-size:0.82rem;">N/A</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="no-filter-results" style="display:none;">
                        <td colspan="9" style="text-align:center;color:var(--muted);padding:20px;">
                            No records match the selected filter.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

    </form>
</div>
</main>

<script>
    let activeFilter = 'all';
    let searchQuery  = '';

    function applyFilters() {
        const rows    = document.querySelectorAll('#history-table tbody tr[data-status]');
        let   visible = 0;

        rows.forEach(row => {
            const matchesFilter =
                activeFilter === 'all'       ? true :
                activeFilter === 'completed' ? row.dataset.status === 'completed' :
                activeFilter === 'rejected'  ? row.dataset.status === 'rejected'  :
                activeFilter === 'walkin'    ? row.dataset.walkin === '1'         :
                true;

            const matchesSearch = !searchQuery || row.dataset.search.includes(searchQuery);
            const show = matchesFilter && matchesSearch;
            row.style.display = show ? '' : 'none';
            if (!show) {
                const cb = row.querySelector('.row-check');
                if (cb) cb.checked = false;
            }
            if (show) visible++;
        });

        const emptyRow = document.getElementById('no-filter-results');
        if (emptyRow) emptyRow.style.display = visible === 0 ? 'table-row' : 'none';

        updateSelection();
    }

    document.querySelectorAll('.filter-tab').forEach(tab => {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            activeFilter = this.dataset.filter;
            applyFilters();
        });
    });

    document.getElementById('search-input').addEventListener('input', function () {
        searchQuery = this.value.toLowerCase().trim();
        applyFilters();
    });

    document.getElementById('check-all').addEventListener('change', function () {
        const boxes = document.querySelectorAll('#history-table tbody tr[data-status]:not([style*="display:none"]) .row-check');
        boxes.forEach(cb => {
            cb.checked = this.checked;
            cb.closest('tr').classList.toggle('selected-row', this.checked);
        });
        updateSelection();
    });

    function updateSelection() {
        const checked = document.querySelectorAll('.row-check:checked');
        const bar     = document.getElementById('selection-bar');
        const btnAct  = document.getElementById('btn-action');
        const count   = checked.length;

        document.getElementById('selected-count').textContent = count;
        bar.classList.toggle('visible', count > 0);
        if (btnAct) btnAct.disabled = count === 0;

        document.querySelectorAll('.row-check').forEach(cb => {
            cb.closest('tr').classList.toggle('selected-row', cb.checked);
        });

        const all = document.querySelectorAll('#history-table tbody tr[data-status]:not([style*="display:none"]) .row-check');
        document.getElementById('check-all').indeterminate = count > 0 && count < all.length;
        document.getElementById('check-all').checked = all.length > 0 && count === all.length;
    }

    function clearSelection() {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = false);
        document.getElementById('check-all').checked = false;
        updateSelection();
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
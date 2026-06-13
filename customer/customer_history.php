<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . getBasePath() . 'admin/history.php');
    exit;
}

$pdo     = getPDO();
$userId  = $_SESSION['user_id'];
$message = '';
$error   = '';

// ── Handle: Hide Selected ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hide_selected'])) {
    $ids = array_filter(array_map('intval', (array)($_POST['selected_ids'] ?? [])));

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // Only allow hiding records that belong to this user.
        // hidden_by_admin is intentionally NOT checked here:
        // admin soft-deletes are admin-side only and do not affect the customer view.
        $pdo->prepare(
            "UPDATE history_records
             SET hidden_by_user = TRUE
             WHERE id IN ($placeholders)
               AND user_id = ?"
        )->execute([...array_values($ids), $userId]);

        $message = count($ids) . ' record(s) removed from your history.';
    } else {
        $error = 'No records selected.';
    }
}

// ── Handle: Hide All ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hide_all'])) {
    // Same here: admin's hidden_by_admin flag is irrelevant to customer hide-all.
    $pdo->prepare(
        "UPDATE history_records
         SET hidden_by_user = TRUE
         WHERE user_id = ?
           AND hidden_by_user = FALSE"
    )->execute([$userId]);

    $message = 'Your service history has been cleared.';
}

// ── Load history records for this user ───────────────────────────────────────
// NOTE: hidden_by_admin is deliberately excluded from this filter.
// Admin soft-deletes only affect the admin's history view, not the customer's.
// Only hidden_by_user controls visibility here.
$stmt = $pdo->prepare(
    "SELECT hr.id,
            hr.request_id,
            hr.problem_type,
            hr.status,
            hr.mechanic_name,
            hr.total_amount,
            hr.completed_at,
            hr.request_created_at
     FROM history_records hr
     WHERE hr.user_id       = :uid
       AND hr.hidden_by_user = FALSE
     ORDER BY hr.completed_at DESC"
);
$stmt->execute([':uid' => $userId]);
$records = $stmt->fetchAll();

// ── Count per tab ─────────────────────────────────────────────────────────────
$countAll       = count($records);
$countCompleted = 0;
$countRejected  = 0;
foreach ($records as $rec) {
    if ($rec['status'] === 'completed') $countCompleted++;
    if ($rec['status'] === 'rejected')  $countRejected++;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
<div class="card">
    <h2>Service History</h2>
    <p class="muted">All your completed and rejected service requests.</p>

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
        /* ── Filter tabs ───────────────────────────────────────────────── */
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

        /* ── Toolbar ───────────────────────────────────────────────────── */
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

        /* ── Selection bar ─────────────────────────────────────────────── */
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

        /* ── Status badges ─────────────────────────────────────────────── */
        .status-completed { background:#e8f7e9; color:#2f6627; padding:3px 10px; border-radius:12px; font-size:0.8rem; font-weight:600; }
        .status-rejected  { background:#fff4f4; color:#a94442; padding:3px 10px; border-radius:12px; font-size:0.8rem; font-weight:600; }

        mark { background: #ffe082; border-radius: 2px; padding: 0 2px; }
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
    </div>

    <form method="post" id="history-form">

        <!-- ── Toolbar ───────────────────────────────────────────────── -->
        <div class="toolbar">
            <input type="search" id="search-input"
                   placeholder="Search by request ID, problem, mechanic or date…">

            <!-- Hide Selected -->
            <button type="submit" name="hide_selected" id="btn-hide-selected"
                    class="btn-danger" disabled
                    onclick="return confirm('Hide selected record(s) from your history? This cannot be undone from your account.')">
                🗄 Remove Selected
            </button>

            <!-- Hide All -->
            <button type="submit" name="hide_all"
                    class="btn-outline-danger"
                    <?php echo empty($records) ? 'disabled' : ''; ?>
                    onclick="return confirm('Clear your entire service history view? This cannot be undone from your account.')">
                ✕ Clear All
            </button>
        </div>

        <!-- ── Selection bar ─────────────────────────────────────────── -->
        <div id="selection-bar">
            <span><span id="selected-count">0</span> record(s) selected</span>
            <button type="button" class="btn"
                    style="padding:4px 10px;font-size:0.8rem;"
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
                    <th>Mechanic</th>
                    <th>Status</th>
                    <th>Completed</th>
                    <th>Amount</th>
                    <th>Invoice</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr id="empty-row">
                        <td colspan="8" style="text-align:center;color:var(--muted);padding:24px;">
                            No service history found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $rec):
                        $searchData = strtolower(
                            $rec['request_id']                           . ' ' .
                            ($rec['problem_type']  ?? '')                . ' ' .
                            ($rec['mechanic_name'] ?? '')                . ' ' .
                            $rec['status']                               . ' ' .
                            ($rec['completed_at']
                                ? date('M d Y', strtotime($rec['completed_at']))
                                : '')
                        );
                    ?>
                        <tr data-search="<?php echo e($searchData); ?>"
                            data-status="<?php echo e($rec['status']); ?>">
                            <td>
                                <input type="checkbox" name="selected_ids[]"
                                       value="<?php echo $rec['id']; ?>"
                                       class="row-check"
                                       onclick="updateSelection()">
                            </td>
                            <td>#<?php echo e($rec['request_id']); ?></td>
                            <td class="col-problem"><?php echo e($rec['problem_type'] ?? '—'); ?></td>
                            <td class="col-mechanic"><?php echo e($rec['mechanic_name'] ?? '—'); ?></td>
                            <td>
                                <span class="status-<?php echo $rec['status']; ?>">
                                    <?php echo ucfirst(e($rec['status'])); ?>
                                </span>
                            </td>
                            <td class="col-date">
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
                                    <a href="<?php echo getBasePath(); ?>customer/invoice.php?history_id=<?php echo $rec['id']; ?>"
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
                    <!-- Shown when filter/search yields no visible rows -->
                    <tr id="no-results-row" style="display:none;">
                        <td colspan="8" style="text-align:center;color:var(--muted);padding:24px;">
                            No records match the selected filter.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <p class="muted" style="margin-top:12px;font-size:0.85rem;" id="result-count">
            <?php echo $countAll; ?> record(s) found.
        </p>

    </form>
</div>
</main>

<script>
    // ── State ──────────────────────────────────────────────────────────────
    let activeFilter = 'all';
    let searchQuery  = '';

    // ── Apply filter + search together ────────────────────────────────────
    function applyFilters() {
        const rows    = document.querySelectorAll('#history-table tbody tr[data-status]');
        let   visible = 0;

        rows.forEach(row => {
            const matchesFilter =
                activeFilter === 'all'       ? true :
                activeFilter === 'completed' ? row.dataset.status === 'completed' :
                activeFilter === 'rejected'  ? row.dataset.status === 'rejected'  :
                true;

            const matchesSearch = !searchQuery || row.dataset.search.includes(searchQuery);
            const show = matchesFilter && matchesSearch;

            row.style.display = show ? '' : 'none';

            if (show) {
                visible++;
                // Highlight matching text in key columns
                ['col-problem', 'col-mechanic', 'col-date'].forEach(cls => {
                    const cell = row.querySelector('.' + cls);
                    if (!cell) return;
                    const raw = cell.dataset.raw ?? (cell.dataset.raw = cell.textContent);
                    cell.innerHTML = searchQuery
                        ? raw.replace(
                            new RegExp('(' + searchQuery.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi'),
                            '<mark>$1</mark>'
                          )
                        : raw;
                });
            } else {
                const cb = row.querySelector('.row-check');
                if (cb) cb.checked = false;
            }
        });

        // Empty state row
        const noResults = document.getElementById('no-results-row');
        if (noResults) noResults.style.display = visible === 0 ? 'table-row' : 'none';

        document.getElementById('result-count').textContent = visible + ' record(s) found.';
        updateSelection();
    }

    // ── Filter tab clicks ─────────────────────────────────────────────────
    document.querySelectorAll('.filter-tab').forEach(tab => {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            activeFilter = this.dataset.filter;
            applyFilters();
        });
    });

    // ── Search ────────────────────────────────────────────────────────────
    document.getElementById('search-input').addEventListener('input', function () {
        searchQuery = this.value.toLowerCase().trim();
        applyFilters();
    });

    // ── Select all (visible rows only) ────────────────────────────────────
    document.getElementById('check-all').addEventListener('change', function () {
        document.querySelectorAll('#history-table tbody tr[data-status]:not([style*="display:none"]) .row-check')
            .forEach(cb => {
                cb.checked = this.checked;
                cb.closest('tr').classList.toggle('selected-row', this.checked);
            });
        updateSelection();
    });

    function updateSelection() {
        const checked  = document.querySelectorAll('.row-check:checked');
        const allBoxes = document.querySelectorAll('#history-table tbody tr[data-status]:not([style*="display:none"]) .row-check');
        const count    = checked.length;

        document.getElementById('selected-count').textContent = count;
        document.getElementById('selection-bar').classList.toggle('visible', count > 0);
        document.getElementById('btn-hide-selected').disabled = count === 0;

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
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
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

$statusFilter  = $_GET['status']   ?? 'all';
$customerType  = $_GET['ctype']    ?? 'all';   // 'all' | 'walkin'
$searchQuery   = trim($_GET['q']   ?? '');



// ── Count badges (exclude completed) ─────────────────────────────────────────
$counts = [];
foreach (['all', 'pending', 'assigned', 'in_progress', 'rejected'] as $s) {
    if ($s === 'all') {
        $counts[$s] = $pdo->query("SELECT COUNT(*) FROM requests WHERE status != 'completed'")->fetchColumn();
    } else {
        $counts[$s] = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE status = ?")->execute([$s])
                     ? $pdo->prepare("SELECT COUNT(*) FROM requests WHERE status = ?")->execute([$s]) : 0;
    }
}
// Simpler count query
$countStmt = $pdo->query("SELECT status, COUNT(*) AS c FROM requests WHERE status != 'completed' GROUP BY status");
$rawCounts = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$counts = [
    'all'         => array_sum($rawCounts),
    'pending'     => $rawCounts['pending']     ?? 0,
    'assigned'    => $rawCounts['assigned']    ?? 0,
    'in_progress' => $rawCounts['in_progress'] ?? 0,
    'rejected'    => $rawCounts['rejected']    ?? 0,
];

// ── Build main query ──────────────────────────────────────────────────────────
$where  = ["r.status != 'completed'"];
$params = [];

if ($statusFilter !== 'all') {
    $where[]          = 'r.status = :status';
    $params[':status'] = $statusFilter;
}

if ($customerType === 'walkin') {
    $where[] = 'r.walkin_id IS NOT NULL';
}

if ($searchQuery !== '') {
    $where[] = '(
        COALESCE(u.full_name, w.full_name) LIKE :q
        OR COALESCE(u.phone, w.phone)      LIKE :q
        OR m.name                          LIKE :q
        OR r.problem_type                  LIKE :q
    )';
    $params[':q'] = '%' . $searchQuery . '%';
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT r.id,
               COALESCE(u.full_name, w.full_name) AS customer_name,
               COALESCE(u.phone,     w.phone)      AS customer_phone,
               CASE WHEN r.walkin_id IS NOT NULL THEN 1 ELSE 0 END AS is_walkin,
               r.problem_type,
               m.name      AS mechanic_name,
               r.status,
               r.rejection_reason,
               r.created_at
        FROM requests r
        LEFT JOIN users              u ON r.user_id   = u.id
        LEFT JOIN walkin_customers   w ON r.walkin_id  = w.id
        LEFT JOIN mechanics          m ON r.mechanic_id = m.id
        $whereClause
        ORDER BY r.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';

// Helper to build filter URLs preserving other params
function filterUrl(array $overrides = []): string {
    global $statusFilter, $customerType, $searchQuery;
    $base = [
        'status' => $statusFilter,
        'ctype'  => $customerType,
        'q'      => $searchQuery,
    ];
    $merged = array_merge($base, $overrides);
    // Drop empty values
    $merged = array_filter($merged, fn($v) => $v !== '' && $v !== 'all' || ($v === 'all'));
    return $_SERVER['PHP_SELF'] . '?' . http_build_query($merged);
}
?>
<main>
<div class="card">
    <h2>All Requests</h2>
    <p class="muted">View and manage all active service requests.</p>

    <?php if ($message): ?>
        <div style="background:#e8f7e9;border:1px solid #8bc34a;color:#2f6627;padding:12px;border-radius:6px;margin-bottom:16px;">
            <?php echo e($message); ?>
        </div>
    <?php endif; ?>

    <style>
        /* ── Filter bar ─────────────────────────── */
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 12px;
        }
        .filter-bar a.btn {
            font-size: 0.82rem;
            padding: 5px 12px;
            position: relative;
        }
        .filter-bar a.btn .badge {
            display: inline-block;
            background: rgba(0,0,0,0.15);
            border-radius: 10px;
            padding: 1px 6px;
            font-size: 0.72rem;
            margin-left: 5px;
            line-height: 1.4;
        }
        .filter-bar a.btn.btn-primary .badge { background: rgba(255,255,255,0.3); }

        /* ── Search + walkin row ────────────────── */
        .search-row {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            flex-wrap: wrap;
            align-items: center;
        }
        .search-row input[type="search"] {
            flex: 1;
            min-width: 200px;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 0.9rem;
        }
        .walkin-toggle {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 14px;
            border-radius: 5px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            border: 1.5px solid var(--safety-orange, #ff6600);
            color: var(--safety-orange, #ff6600);
            background: #fff;
            white-space: nowrap;
            transition: background 0.15s, color 0.15s;
        }
        .walkin-toggle.active {
            background: var(--safety-orange, #ff6600);
            color: #fff;
        }
        .walkin-toggle:hover { opacity: 0.85; }

        /* ── Walk-in badge in table ─────────────── */
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

        /* ── Highlight search matches ───────────── */
        mark { background: #ffe082; border-radius: 2px; padding: 0 2px; }
    </style>

    <!-- ── Status filter tabs ──────────────────────────────────────── -->
    <div class="filter-bar">
        <?php
        $statuses = [
            'all'         => 'All',
            'pending'     => 'Pending',
            'assigned'    => 'Assigned',
            'in_progress' => 'In Progress',
            'rejected'    => 'Rejected',
        ];
        foreach ($statuses as $key => $label):
            $active = $statusFilter === $key ? 'btn-primary' : '';
        ?>
            <a href="<?php echo filterUrl(['status' => $key]); ?>" class="btn <?php echo $active; ?>">
                <?php echo $label; ?>
                <span class="badge"><?php echo $counts[$key]; ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- ── Search + Walk-in toggle ─────────────────────────────────── -->
    <div class="search-row">
        <form method="get" style="display:contents;">
            <!-- Preserve existing filters -->
            <input type="hidden" name="status" value="<?php echo e($statusFilter); ?>">
            <input type="hidden" name="ctype"  value="<?php echo e($customerType); ?>">
            <input type="search" name="q"
                   value="<?php echo e($searchQuery); ?>"
                   placeholder="Search by name, phone, mechanic or problem…"
                   oninput="this.form.submit()">
        </form>

        <a href="<?php echo filterUrl(['ctype' => $customerType === 'walkin' ? 'all' : 'walkin']); ?>"
           class="walkin-toggle <?php echo $customerType === 'walkin' ? 'active' : ''; ?>">
            🚶 Walk-in Only<?php echo $customerType === 'walkin' ? ' ✓' : ''; ?>
        </a>
    </div>

    <!-- ── Table ───────────────────────────────────────────────────── -->
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Customer</th>
                <th>Problem</th>
                <th>Phone</th>
                <th>Mechanic</th>
                <th>Status</th>
                <th>Submitted</th>
                <th><?php echo $statusFilter === 'rejected' ? 'Rejection Reason' : 'Action'; ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($requests)): ?>
                <tr>
                    <td colspan="8" style="text-align:center;color:var(--muted);padding:24px;">
                        No requests found<?php echo $searchQuery ? ' for "' . e($searchQuery) . '"' : ''; ?>.
                    </td>
                </tr>
            <?php else: ?>
                <?php
                // Highlight helper
                function hl(string $text, string $q): string {
                    if ($q === '') return e($text);
                    return preg_replace('/(' . preg_quote(htmlspecialchars($q), '/') . ')/i', '<mark>$1</mark>', e($text));
                }
                ?>
                <?php foreach ($requests as $req): ?>
                    <tr>
                        <td><?php echo e($req['id']); ?></td>
                        <td>
                            <?php echo hl($req['customer_name'] ?? '—', $searchQuery); ?>
                            <?php if ($req['is_walkin']): ?>
                                <span class="badge-walkin">Walk-in</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo hl($req['problem_type'] ?? '—', $searchQuery); ?></td>
                        <td><?php echo hl($req['customer_phone'] ?? '—', $searchQuery); ?></td>
                        <td><?php echo hl($req['mechanic_name'] ?? '—', $searchQuery); ?></td>
                        <td>
                            <span class="status status-<?php echo e($req['status']); ?>">
                                <?php echo statusLabel($req['status']); ?>
                            </span>
                        </td>
                        <td><?php echo date('M d, g:i A', strtotime($req['created_at'])); ?></td>
                        <td>
                            <?php if ($req['status'] === 'rejected'): ?>
                                <span style="color:#c0392b;font-size:0.88rem;">
                                    <?php echo e($req['rejection_reason'] ?: 'No reason provided.'); ?>
                                </span>
                            <?php elseif ($req['status'] === 'pending'): ?>
                                <a href="<?php echo getBasePath(); ?>admin/pending_requests.php#request-<?php echo $req['id']; ?>"
                                   class="btn btn-primary" style="padding:4px 12px;font-size:0.82rem;">
                                    Manage →
                                </a>
                            <?php else: ?>
                                <span style="color:#999;font-size:0.88rem;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Result count -->
    <p class="muted" style="margin-top:12px;font-size:0.85rem;">
        Showing <?php echo count($requests); ?> request(s)<?php echo $searchQuery ? ' matching "' . e($searchQuery) . '"' : ''; ?>.
    </p>
</div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
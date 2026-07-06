<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();

$pdo = getPDO();

$staffCount       = $pdo->query('SELECT COUNT(*) AS count FROM users WHERE role = "admin" AND is_superadmin = FALSE')->fetch()['count'];
$staffDisabled    = $pdo->query('SELECT COUNT(*) AS count FROM users WHERE role = "admin" AND is_superadmin = FALSE AND is_disabled = TRUE')->fetch()['count'];
$customerCount    = $pdo->query('SELECT COUNT(*) AS count FROM users WHERE role = "user"')->fetch()['count'];
$customerDisabled = $pdo->query('SELECT COUNT(*) AS count FROM users WHERE role = "user" AND is_disabled = TRUE')->fetch()['count'];
$mechanicCount    = $pdo->query('SELECT COUNT(*) AS count FROM mechanics WHERE is_deleted = FALSE')->fetch()['count'];
$mechanicDisabled = $pdo->query('SELECT COUNT(*) AS count FROM mechanics WHERE is_deleted = FALSE AND is_disabled = TRUE')->fetch()['count'];
$totalHistory     = $pdo->query('SELECT COUNT(*) AS count FROM history_records')->fetch()['count'];
$totalFeedback    = $pdo->query('SELECT COUNT(*) AS count FROM feedback')->fetch()['count'];

// Unified people list: customers, staff admins, mechanics
$allPeople = [];

$customers = $pdo->query(
    'SELECT id, full_name, email, created_at, is_disabled, "customer" AS account_type
     FROM users WHERE role = "user"
     ORDER BY created_at DESC'
)->fetchAll();

$staffAdmins = $pdo->query(
    'SELECT id, full_name, email, created_at, is_disabled, "staff_admin" AS account_type
     FROM users WHERE role = "admin" AND is_superadmin = FALSE
     ORDER BY created_at DESC'
)->fetchAll();

$mechanics = $pdo->query(
    'SELECT id, name AS full_name, email, created_at, is_disabled, "mechanic" AS account_type
     FROM mechanics WHERE is_deleted = FALSE
     ORDER BY created_at DESC'
)->fetchAll();

// Merge and sort by created_at desc
$allPeople = array_merge($customers, $staffAdmins, $mechanics);
usort($allPeople, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <div class="card">
        <div style="margin-bottom:20px;">
            <h2 style="margin:0 0 6px 0;">Super Admin Dashboard</h2>
            <p class="muted" style="margin:0;">Overview of all accounts and service activity.</p>
        </div>

        <!-- Stats -->
        <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:24px;">
            <div class="card" style="text-align:center;">
                <h3 style="color:var(--charcoal);margin:0 0 2px 0;"><?php echo $customerCount; ?></h3>
                <p class="muted" style="margin:0;font-size:13px;">Customers</p>
                <?php if ($customerDisabled > 0): ?>
                    <p style="margin:4px 0 0;font-size:12px;color:#e74c3c;"><?php echo $customerDisabled; ?> disabled</p>
                <?php endif; ?>
            </div>
            <div class="card" style="text-align:center;">
                <h3 style="color:var(--charcoal);margin:0 0 2px 0;"><?php echo $staffCount; ?></h3>
                <p class="muted" style="margin:0;font-size:13px;">Staff Admins</p>
                <?php if ($staffDisabled > 0): ?>
                    <p style="margin:4px 0 0;font-size:12px;color:#e74c3c;"><?php echo $staffDisabled; ?> disabled</p>
                <?php endif; ?>
            </div>
            <div class="card" style="text-align:center;">
                <h3 style="color:var(--charcoal);margin:0 0 2px 0;"><?php echo $mechanicCount; ?></h3>
                <p class="muted" style="margin:0;font-size:13px;">Mechanics</p>
                <?php if ($mechanicDisabled > 0): ?>
                    <p style="margin:4px 0 0;font-size:12px;color:#e74c3c;"><?php echo $mechanicDisabled; ?> disabled</p>
                <?php endif; ?>
            </div>
            <div class="card" style="text-align:center;">
                <h3 style="color:var(--charcoal);margin:0 0 2px 0;"><?php echo $totalHistory; ?></h3>
                <p class="muted" style="margin:0;font-size:13px;">History Records</p>
                <p style="margin:4px 0 0;font-size:12px;color:var(--charcoal);"><?php echo $totalFeedback; ?> feedback</p>
            </div>
        </div>

        <!-- Accounts Table with Filter -->
        <div class="card" style="margin-top:8px;">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
                <h3 style="margin:0;">All Accounts</h3>
                <div style="display:flex;gap:6px;flex-wrap:wrap;" id="filter-tabs">
                    <button class="filter-tab active" data-filter="all" onclick="filterTable('all')">All (<?php echo count($allPeople); ?>)</button>
                    <button class="filter-tab" data-filter="customer" onclick="filterTable('customer')">Customers (<?php echo $customerCount; ?>)</button>
                    <button class="filter-tab" data-filter="staff_admin" onclick="filterTable('staff_admin')">Staff Admins (<?php echo $staffCount; ?>)</button>
                    <button class="filter-tab" data-filter="mechanic" onclick="filterTable('mechanic')">Mechanics (<?php echo $mechanicCount; ?>)</button>
                </div>
            </div>

            <style>
                .filter-tab {
                    padding: 5px 14px;
                    font-size: 13px;
                    border: 1px solid #ddd;
                    border-radius: 20px;
                    background: #fff;
                    cursor: pointer;
                    color: #555;
                    transition: background 0.15s, color 0.15s;
                }
                .filter-tab.active,
                .filter-tab:hover {
                    background: var(--safety-orange, #ff6600);
                    color: #fff;
                    border-color: var(--safety-orange, #ff6600);
                }
                .badge {
                    display: inline-block;
                    padding: 2px 8px;
                    border-radius: 12px;
                    font-size: 11px;
                    font-weight: 600;
                    text-transform: uppercase;
                    letter-spacing: 0.3px;
                }
                .badge-customer  { background:#e8f4fd; color:#1a6fa8; }
                .badge-staff     { background:#fef3e2; color:#b85c00; }
                .badge-mechanic  { background:#e8f8f0; color:#1a7a45; }
                .badge-disabled  { background:#fdecea; color:#c0392b; }
                .badge-active    { background:#eafaf1; color:#1e8449; }
            </style>

            <?php if (empty($allPeople)): ?>
                <p class="muted">No accounts found.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table id="accounts-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Joined</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allPeople as $person): ?>
                                <?php
                                    $type      = $person['account_type'];
                                    $badgeClass = $type === 'customer' ? 'badge-customer'
                                               : ($type === 'staff_admin' ? 'badge-staff' : 'badge-mechanic');
                                    $typeLabel  = $type === 'customer' ? 'Customer'
                                               : ($type === 'staff_admin' ? 'Staff Admin' : 'Mechanic');
                                ?>
                                <tr data-type="<?php echo $type; ?>">
                                    <td><?php echo e($person['full_name']); ?></td>
                                    <td><?php echo e($person['email'] ?? '—'); ?></td>
                                    <td><span class="badge <?php echo $badgeClass; ?>"><?php echo $typeLabel; ?></span></td>
                                    <td>
                                        <?php if ($person['is_disabled']): ?>
                                            <span class="badge badge-disabled">Disabled</span>
                                        <?php else: ?>
                                            <span class="badge badge-active">Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($person['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
function filterTable(type) {
    document.querySelectorAll('#filter-tabs .filter-tab').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.filter === type);
    });
    document.querySelectorAll('#accounts-table tbody tr').forEach(row => {
        row.style.display = (type === 'all' || row.dataset.type === type) ? '' : 'none';
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
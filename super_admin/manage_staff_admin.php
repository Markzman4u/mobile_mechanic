<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();

$pdo = getPDO();

// Handle toggle — redirect immediately after to prevent re-fire on refresh
if (isset($_GET['toggle_disable']) && ctype_digit($_GET['toggle_disable'])) {
    $toggleId = (int) $_GET['toggle_disable'];

    if ($toggleId === $_SESSION['user_id']) {
        header('Location: ' . getBasePath() . 'super_admin/manage_staff_admin.php?msg=self');
        exit;
    }

    $stmt = $pdo->prepare('SELECT is_disabled FROM users WHERE id = :id AND role = "admin" AND is_superadmin = FALSE');
    $stmt->execute([':id' => $toggleId]);
    $target = $stmt->fetch();

    if ($target) {
        $pdo->prepare('UPDATE users SET is_disabled = NOT is_disabled WHERE id = :id')->execute([':id' => $toggleId]);
        $action = $target['is_disabled'] ? 'enabled' : 'suspended';
        header('Location: ' . getBasePath() . 'super_admin/manage_staff_admin.php?msg=' . $action);
    } else {
        header('Location: ' . getBasePath() . 'super_admin/manage_staff_admin.php?msg=not_found');
    }
    exit;
}

// Resolve message from redirect
$message     = '';
$messageType = 'success';
$msgMap = [
    'suspended' => ['Staff admin suspended successfully.',          'success'],
    'enabled'   => ['Staff admin re-enabled successfully.',         'success'],
    'not_found' => ['Staff admin not found.',                       'error'],
    'self'      => ['You cannot suspend your own account.',         'error'],
];
if (isset($_GET['msg']) && array_key_exists($_GET['msg'], $msgMap)) {
    [$message, $messageType] = $msgMap[$_GET['msg']];
}

$search   = trim($_GET['search'] ?? '');
$status   = $_GET['status'] ?? 'all';
$fromDate = $_GET['from_date'] ?? '';
$toDate   = $_GET['to_date'] ?? '';
$where    = ['role = "admin"', 'is_superadmin = FALSE'];
$params   = [];

if ($search !== '') {
    $where[]           = '(full_name LIKE :search OR email LIKE :search OR phone LIKE :search)';
    $params[':search']  = '%' . $search . '%';
}
if (in_array($status, ['active', 'disabled'], true)) {
    $where[] = $status === 'active' ? 'is_disabled = FALSE' : 'is_disabled = TRUE';
}
if ($fromDate !== '' && DateTime::createFromFormat('Y-m-d', $fromDate)) {
    $where[]             = 'created_at >= :from_date';
    $params[':from_date'] = $fromDate . ' 00:00:00';
}
if ($toDate !== '' && DateTime::createFromFormat('Y-m-d', $toDate)) {
    $where[]           = 'created_at <= :to_date';
    $params[':to_date'] = $toDate . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare(
    'SELECT id, full_name, email, phone, is_disabled, created_at
     FROM users
     WHERE ' . $whereSql . '
     ORDER BY created_at DESC'
);
$stmt->execute($params);
$staffAdmins = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<style>
.filter-row {display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px;}
.filter-row label{flex:1;min-width:180px;}
.action-links a{margin-right:10px;}
</style>
<main>
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
            <div>
                <h2 style="margin:0 0 6px 0;">Manage Staff Admins</h2>
                <p class="muted" style="margin:0;">Search, filter, and suspend staff admins.</p>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <a class="btn btn-primary" href="<?php echo getBasePath(); ?>super_admin/create_staff_admin.php">Register Staff Admin</a>
                <a class="btn" href="<?php echo getBasePath(); ?>super_admin/dashboard.php">Back to Dashboard</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo e($messageType); ?>"><?php echo e($message); ?></div>
        <?php endif; ?>

        <form method="get" style="margin-bottom:18px;">
            <div class="filter-row">
                <label>
                    Search
                    <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Name, email, or phone">
                </label>
                <label>
                    Status
                    <select name="status">
                        <option value="all"<?php echo $status === 'all' ? ' selected' : ''; ?>>All</option>
                        <option value="active"<?php echo $status === 'active' ? ' selected' : ''; ?>>Active</option>
                        <option value="disabled"<?php echo $status === 'disabled' ? ' selected' : ''; ?>>Suspended</option>
                    </select>
                </label>
                <label>
                    From Date
                    <input type="date" name="from_date" value="<?php echo e($fromDate); ?>">
                </label>
                <label>
                    To Date
                    <input type="date" name="to_date" value="<?php echo e($toDate); ?>">
                </label>
                <div style="align-self:end;">
                    <button class="btn btn-primary" type="submit" style="min-width:120px;">Filter</button>
                </div>
            </div>
        </form>

        <?php if (empty($staffAdmins)): ?>
            <p class="muted">No staff admins match the current filter.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($staffAdmins as $staff): ?>
                            <?php
                            $confirmMsg = $staff['is_disabled'] ? 'Re-enable this staff admin?' : 'Suspend this staff admin?';
                            $toggleUrl  = getBasePath() . 'super_admin/manage_staff_admin.php?toggle_disable=' . $staff['id'];
                            ?>
                            <tr>
                                <td><?php echo e($staff['full_name']); ?></td>
                                <td><?php echo e($staff['email']); ?></td>
                                <td><?php echo e($staff['phone'] ?: '—'); ?></td>
                                <td>
                                    <?php if ($staff['is_disabled']): ?>
                                        <span style="font-size:.75rem;font-weight:700;color:#e74c3c;">Suspended</span>
                                    <?php else: ?>
                                        <span style="font-size:.75rem;font-weight:700;color:#4caf50;">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($staff['created_at'])); ?></td>
                                <td class="action-links">
                                    <a class="action-link" style="color:var(--safety-orange,#ff6600);"
                                       href="<?php echo getBasePath(); ?>super_admin/view_staff_admin.php?id=<?php echo $staff['id']; ?>">View</a>

                                    <span class="action-sep" style="color:#ddd;margin:0 4px;">|</span>

                                    <a class="action-link"
                                       style="color:<?php echo $staff['is_disabled'] ? '#4caf50' : '#e67e22'; ?>;"
                                       href="<?php echo e($toggleUrl); ?>"
                                       onclick="return confirm(<?php echo json_encode($confirmMsg); ?>);">
                                        <?php echo $staff['is_disabled'] ? 'Enable' : 'Suspend'; ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
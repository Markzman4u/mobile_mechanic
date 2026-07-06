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
$message     = '';
$messageType = 'success'; // 'success' | 'error'

// Handle soft delete
if (isset($_GET['delete']) && ctype_digit($_GET['delete'])) {
    $deleteId = (int) $_GET['delete'];
    $pdo->prepare('UPDATE mechanics SET is_deleted = TRUE, is_disabled = TRUE WHERE id = :id')
        ->execute([':id' => $deleteId]);
    header('Location: ' . getBasePath() . 'admin/manage_mechanics.php?msg=deleted');
    exit;
}

// Handle disable toggle
if (isset($_GET['toggle_disable']) && ctype_digit($_GET['toggle_disable'])) {
    $toggleId = (int) $_GET['toggle_disable'];

    // Fetch current state first so we can show the right message
    $stmt = $pdo->prepare('SELECT is_disabled FROM mechanics WHERE id = :id AND is_deleted = FALSE');
    $stmt->execute([':id' => $toggleId]);
    $current = $stmt->fetch();

    if ($current) {
        $pdo->prepare('UPDATE mechanics SET is_disabled = NOT is_disabled WHERE id = :id AND is_deleted = FALSE')
            ->execute([':id' => $toggleId]);
        $action = $current['is_disabled'] ? 'enabled' : 'suspended';
        header('Location: ' . getBasePath() . 'admin/manage_mechanics.php?msg=' . $action);
    } else {
        header('Location: ' . getBasePath() . 'admin/manage_mechanics.php?msg=not_found');
    }
    exit;
}

// Resolve message from query param
$msgMap = [
    'deleted'   => ['Mechanic removed successfully.',         'success'],
    'suspended' => ['Mechanic suspended successfully.',       'success'],
    'enabled'   => ['Mechanic re-enabled successfully.',      'success'],
    'not_found' => ['Mechanic not found or already removed.', 'error'],
];
if (isset($_GET['msg']) && array_key_exists($_GET['msg'], $msgMap)) {
    [$message, $messageType] = $msgMap[$_GET['msg']];
}

// Fetch all non-deleted mechanics
$mechanics = $pdo->query(
    'SELECT id, name, phone, email, profile_pic, address, status,
            is_disabled, is_deleted, last_login, last_logout, created_at
     FROM mechanics
     WHERE is_deleted = FALSE
     ORDER BY name ASC'
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.page-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}
.mech-table th, .mech-table td {
    vertical-align: middle;
}
.mech-avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #eee;
}
.mech-avatar-placeholder {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: #f0f0f0;
    border: 2px solid #eee;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    color: #bbb;
}
.action-link {
    font-size: .82rem;
    font-weight: 600;
    text-decoration: none;
    padding: 3px 0;
    display: inline-block;
}
.action-link:hover { text-decoration: underline; }
.action-sep { color: #ddd; margin: 0 4px; }

.stat-bar {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}
.stat-box {
    flex: 1;
    min-width: 120px;
    background: #fff;
    border: 1px solid #e8e8e8;
    border-radius: 10px;
    padding: 14px 18px;
    text-align: center;
}
.stat-box .stat-num {
    font-size: 1.6rem;
    font-weight: 800;
    color: var(--charcoal, #2d2d2d);
    line-height: 1;
}
.stat-box .stat-lbl {
    font-size: .75rem;
    color: var(--muted, #888);
    margin-top: 4px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
}

/* Alert banners */
.alert {
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 16px;
    font-size: .9rem;
    font-weight: 600;
}
.alert-success {
    background: #e8f7e9;
    border: 1px solid #8bc34a;
    color: #2f6627;
}
.alert-error {
    background: #fdecea;
    border: 1px solid #e57373;
    color: #b71c1c;
}
</style>

<main>
<div class="card">

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
        <div>
            <h2 style="margin:0 0 4px 0;">Manage Mechanics</h2>
            <p class="muted" style="margin:0;">Overview of all registered mechanics.</p>
        </div>
        <div class="page-actions">
            <a href="<?php echo getBasePath(); ?>admin/register_mechanic.php"
               class="btn btn-primary">+ Register Mechanic</a>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <?php echo e($message); ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <?php
    $total     = count($mechanics);
    $available = count(array_filter($mechanics, fn($m) => $m['status'] === 'available' && !$m['is_disabled']));
    $busy      = count(array_filter($mechanics, fn($m) => $m['status'] === 'busy'));
    $disabled  = count(array_filter($mechanics, fn($m) => $m['is_disabled']));
    ?>
    <div class="stat-bar">
        <div class="stat-box">
            <div class="stat-num"><?php echo $total; ?></div>
            <div class="stat-lbl">Total</div>
        </div>
        <div class="stat-box">
            <div class="stat-num" style="color:#4caf50;"><?php echo $available; ?></div>
            <div class="stat-lbl">Available</div>
        </div>
        <div class="stat-box">
            <div class="stat-num" style="color:var(--safety-orange,#ff6600);"><?php echo $busy; ?></div>
            <div class="stat-lbl">Busy</div>
        </div>
        <div class="stat-box">
            <div class="stat-num" style="color:#e74c3c;"><?php echo $disabled; ?></div>
            <div class="stat-lbl">Suspended</div>
        </div>
    </div>

    <!-- Table -->
    <?php if (empty($mechanics)): ?>
        <p class="muted" style="text-align:center;padding:30px 0;">
            No mechanics registered yet.
            <a href="<?php echo getBasePath(); ?>admin/register_mechanic.php"
               style="color:var(--safety-orange);text-decoration:none;font-weight:600;">Register one →</a>
        </p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="mech-table">
        <thead>
            <tr>
                <th>Photo</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Email</th>
                <th>Status</th>
                <th>Account</th>
                <th>Last Login</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($mechanics as $m): ?>
            <?php
            $suspendUrl = getBasePath() . 'admin/manage_mechanics.php?toggle_disable=' . (int)$m['id'];
            $deleteUrl  = getBasePath() . 'admin/manage_mechanics.php?delete='         . (int)$m['id'];
            $confirmSuspend = $m['is_disabled'] ? 'Re-enable this mechanic?' : 'Suspend this mechanic?';
            ?>
            <tr>
                <td>
                    <?php if (!empty($m['profile_pic'])): ?>
                        <img src="<?php echo getBasePath() . e($m['profile_pic']); ?>"
                             alt="" class="mech-avatar">
                    <?php else: ?>
                        <span class="mech-avatar-placeholder">👤</span>
                    <?php endif; ?>
                </td>
                <td><strong><?php echo e($m['name']); ?></strong></td>
                <td><?php echo e($m['phone'] ?: '—'); ?></td>
                <td><?php echo e($m['email'] ?: '—'); ?></td>
                <td>
                    <span class="status status-<?php echo $m['status'] === 'available' ? 'assigned' : 'in_progress'; ?>">
                        <?php echo ucfirst($m['status']); ?>
                    </span>
                </td>
                <td>
                    <?php if ($m['is_disabled']): ?>
                        <span style="font-size:.75rem;font-weight:700;color:#e74c3c;">Suspended</span>
                    <?php else: ?>
                        <span style="font-size:.75rem;font-weight:700;color:#4caf50;">Active</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:.8rem;color:var(--muted,#888);">
                    <?php echo $m['last_login']
                        ? date('M d, Y g:i A', strtotime($m['last_login']))
                        : '—'; ?>
                </td>
                <td>
                    <a href="<?php echo getBasePath(); ?>admin/register_mechanic.php?edit=<?php echo (int)$m['id']; ?>"
                       class="action-link" style="color:var(--safety-orange,#ff6600);">Edit</a>

                    <span class="action-sep">|</span>

                    <a href="<?php echo getBasePath(); ?>admin/track_mechanics.php?id=<?php echo (int)$m['id']; ?>"
                       class="action-link" style="color:#3a5bbd;">Track</a>

                    <span class="action-sep">|</span>

                    <a href="<?php echo e($suspendUrl); ?>"
                       onclick="return confirm(<?php echo json_encode($confirmSuspend); ?>);"
                       class="action-link"
                       style="color:<?php echo $m['is_disabled'] ? '#4caf50' : '#e67e22'; ?>;">
                        <?php echo $m['is_disabled'] ? 'Enable' : 'Suspend'; ?>
                    </a>

                    <span class="action-sep">|</span>

                    <a href="<?php echo e($deleteUrl); ?>"
                       onclick="return confirm('Remove this mechanic? This cannot be undone.');"
                       class="action-link" style="color:#e74c3c;">Remove</a>
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
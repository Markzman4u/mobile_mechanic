<?php
if (!function_exists('isLoggedIn')) {
    require_once __DIR__ . '/auth.php';
}
if (!function_exists('getUnreadCount')) {
    require_once __DIR__ . '/functions.php';
}

$currentScript   = basename($_SERVER['SCRIPT_NAME']);
$isDashboardPage = ($currentScript === 'dashboard.php');

// Mechanic session is keyed by mechanic_id, NOT user_id.
// isLoggedIn() only checks user_id, so we resolve the mechanic
// case separately before anything else.
$mechanicLoggedIn = isset($_SESSION['mechanic_id']);
?>
<nav>
    <ul>
        <?php if ($mechanicLoggedIn): ?>
            <!-- ── Mechanic logged in ─────────────────────────────────── -->
            <?php if (!$isDashboardPage): ?>
                <li><a href="<?php echo getBasePath(); ?>mechanic/dashboard.php">Dashboard</a></li>
            <?php endif; ?>
            <li><a href="<?php echo getBasePath(); ?>mechanic/logout.php">Logout</a></li>

        <?php elseif (isLoggedIn()): ?>
            <!-- ── Customer / Admin logged in ───────────────────────────── -->
            <?php if (!$isDashboardPage): ?>
                <?php if (isAdmin()): ?>
                    <li><a href="<?php echo getBasePath(); ?>admin/dashboard.php">Dashboard</a></li>
                <?php else: ?>
                    <li><a href="<?php echo getBasePath(); ?>customer/dashboard.php">Dashboard</a></li>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Notification bell — customers only, not on dashboard -->
            <?php if (!isAdmin() && !$isDashboardPage): ?>
                <li style="position:relative;">
                    <a href="<?php echo getBasePath(); ?>customer/notifications.php"
                       style="display:flex;align-items:center;gap:8px;">
                        🔔
                        <?php
                        $unread = getUnreadCount($_SESSION['user_id']);
                        if ($unread > 0):
                        ?>
                            <span style="background:#ff9800;color:white;border-radius:50%;
                                         width:20px;height:20px;display:flex;align-items:center;
                                         justify-content:center;font-size:0.75rem;font-weight:bold;
                                         margin-left:2px;">
                                <?php echo $unread; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endif; ?>

            <li><a href="<?php echo getBasePath(); ?>auth/logout.php">Logout</a></li>

        <?php else: ?>
            <!-- ── Not logged in ────────────────────────────────────────── -->
            <?php $isMechanicLogin = (strpos($_SERVER['SCRIPT_NAME'], 'mechanic/login.php') !== false); ?>
            <?php if ($currentScript !== 'index.php'): ?>
                <li><a href="<?php echo getBasePath(); ?>">Home</a></li>
            <?php endif; ?>
            <?php if ($currentScript !== 'login.php'): ?>
                <li><a href="<?php echo getBasePath(); ?>auth/login.php">Sign In</a></li>
            <?php endif; ?>
            <?php if ($currentScript !== 'register.php' && !$isMechanicLogin): ?>
                <li><a href="<?php echo getBasePath(); ?>auth/register.php">Register</a></li>
            <?php endif; ?>

        <?php endif; ?>
    </ul>
</nav>
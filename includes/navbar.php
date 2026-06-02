<?php
if (!function_exists('isLoggedIn')) {
    require_once __DIR__ . '/auth.php';
}
if (!function_exists('getUnreadCount')) {
    require_once __DIR__ . '/functions.php';
}

// Get current page
$currentScript = basename($_SERVER['SCRIPT_NAME']);
?>
<nav>
    <ul>
        <?php if (isLoggedIn()): ?>
            <?php if (!isAdmin()): ?>
                <li><a href="<?php echo getBasePath(); ?>customer/dashboard.php">Dashboard</a></li>
            <?php else: ?>
                <li><a href="<?php echo getBasePath(); ?>admin/dashboard.php">Dashboard</a></li>
            <?php endif; ?>
        <?php elseif ($currentScript !== 'index.php'): ?>
            <li><a href="<?php echo getBasePath(); ?>">Home</a></li>
        <?php endif; ?>
        <?php if (!isLoggedIn()): ?>
            <?php if ($currentScript !== 'login.php'): ?>
                <li><a href="<?php echo getBasePath(); ?>auth/login.php">Sign In</a></li>
            <?php endif; ?>
            <?php if ($currentScript !== 'register.php'): ?>
                <li><a href="<?php echo getBasePath(); ?>auth/register.php">Register</a></li>
            <?php endif; ?>
        <?php else: ?>
            <?php if (isLoggedIn() && !isAdmin()): ?>
                <li style="position:relative;">
                    <a href="<?php echo getBasePath(); ?>customer/notifications.php" style="display:flex;align-items:center;gap:8px;">
                        🔔
                        <?php 
                        $unread = getUnreadCount($_SESSION['user_id']);
                        if ($unread > 0): 
                        ?>
                            <span style="background:#ff9800;color:white;border-radius:50%;width:20px;height:20px;display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:bold;margin-left:2px;">
                                <?php echo $unread; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endif; ?>
            <li><a href="<?php echo getBasePath(); ?>auth/logout.php">Logout</a></li>
        <?php endif; ?>
    </ul>
</nav>

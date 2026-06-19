<?php
$sidebarOpened = true;
if (!function_exists('isLoggedIn')) {
    require_once __DIR__ . '/auth.php';
}
$basePath = getBasePath();
$currentScript = basename($_SERVER['SCRIPT_NAME']);

$adminLinks = [
    ['label' => 'Dashboard', 'href' => $basePath . 'admin/dashboard.php'],
    ['label' => 'Pending Requests', 'href' => $basePath . 'admin/pending_requests.php'],
    ['label' => 'Active Jobs', 'href' => $basePath . 'admin/active_jobs.php'],
    ['label' => 'All Requests', 'href' => $basePath . 'admin/all_requests.php'],
    ['label' => 'Manage Mechanics', 'href' => $basePath . 'admin/manage_mechanics.php'],
    ['label' => 'Register Walk-in', 'href' => $basePath . 'admin/create_walkin.php'],
    ['label' => 'History', 'href' => $basePath . 'admin/history.php'],
    ['label' => 'Settings', 'href' => $basePath . 'admin/settings.php'],
];

$mechanicLinks = [
    ['label' => 'Dashboard', 'href' => $basePath . 'mechanic/dashboard.php'],
    ['label' => 'History', 'href' => $basePath . 'mechanic/history.php'],
    ['label' => 'Settings', 'href' => $basePath . 'mechanic/mechanic_settings.php'],
];

$customerLinks = [
    ['label' => 'Dashboard', 'href' => $basePath . 'customer/dashboard.php'],
    ['label' => 'Request Service', 'href' => $basePath . 'customer/request_service.php'],
    ['label' => 'Track Service', 'href' => $basePath . 'customer/track_service.php'],
    ['label' => 'History', 'href' => $basePath . 'customer/customer_history.php'],
    ['label' => 'Settings', 'href' => $basePath . 'customer/settings.php'],
];

if (isAdmin()) {
    $links = $adminLinks;
    $sidebarTitle = 'Admin Menu';
} elseif (isMechanic()) {
    $links = $mechanicLinks;
    $sidebarTitle = 'Mechanic Menu';
} else {
    $links = $customerLinks;
    $sidebarTitle = 'Customer Menu';
}
?>
<div class="page-layout">
<aside>
    <div class="sidebar-nav">
        <h2><?php echo $sidebarTitle; ?></h2>
        <ul>
            <?php foreach ($links as $link): ?>
                <?php $active = ($currentScript === basename($link['href'])) ? 'active' : ''; ?>
                <li class="<?php echo $active; ?>">
                    <a href="<?php echo $link['href']; ?>"><?php echo $link['label']; ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</aside>

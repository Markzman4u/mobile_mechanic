<?php
if (!function_exists('getBasePath')) {
    require_once __DIR__ . '/functions.php';
}
$baseUrl = getBasePath();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Mobile Mechanic</title>
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>assets/css/style.css">
    <base href="<?php echo $baseUrl; ?>">
</head>
<body>
<header>
    <h1>Mobile Mechanic</h1>
    <?php require_once __DIR__ . '/navbar.php'; ?>
</header>

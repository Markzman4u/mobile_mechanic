<?php
// Only start session if one hasn't been started already
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('getBasePath')) {
    require_once __DIR__ . '/functions.php';
}

function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}

function requireLogin()
{
    if (!isLoggedIn()) {
        header('Location: ' . getBasePath() . 'auth/login.php');
        exit;
    }
}

function isAdmin()
{
    return (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
}

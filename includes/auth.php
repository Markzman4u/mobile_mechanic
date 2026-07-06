<?php
// Only start session if one hasn't been started already
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('getBasePath')) {
    require_once __DIR__ . '/functions.php';
}

if (!function_exists('isLoggedIn')) {
    function isLoggedIn()
    {
        return isset($_SESSION['user_id']);
    }
}

if (!function_exists('requireLogin')) {
    function requireLogin()
    {
        if (!isLoggedIn()) {
            header('Location: ' . getBasePath() . 'auth/login.php');
            exit;
        }
    }
}

if (!function_exists('isAdmin')) {
    function isAdmin()
    {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }
}

// ── Super admin helpers ───────────────────────────────────────────────────────
// isSuperAdmin()     : returns true only if the logged-in user has is_superadmin = 1.
// requireSuperAdmin(): hard-gates a page to super admins only.
//                      Staff admins (role=admin, is_superadmin=0) are redirected
//                      back to the regular admin dashboard.
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('isSuperAdmin')) {
    function isSuperAdmin()
    {
        return isset($_SESSION['is_superadmin']) && (bool)$_SESSION['is_superadmin'] === true;
    }
}

if (!function_exists('requireSuperAdmin')) {
    function requireSuperAdmin()
    {
        if (!isLoggedIn()) {
            header('Location: ' . getBasePath() . 'auth/login.php');
            exit;
        }
        if (!isSuperAdmin()) {
            header('Location: ' . getBasePath() . 'admin/dashboard.php');
            exit;
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('isMechanic')) {
    function isMechanic()
    {
        return isset($_SESSION['mechanic_id']) && $_SESSION['mechanic_id'] !== null;
    }
}

if (!function_exists('requireMechanicLogin')) {
    function requireMechanicLogin()
    {
        if (!isMechanic()) {
            header('Location: ' . getBasePath() . 'mechanic/login.php');
            exit;
        }
    }
}

if (!function_exists('getMechanicId')) {
    function getMechanicId()
    {
        return $_SESSION['mechanic_id'] ?? null;
    }
}
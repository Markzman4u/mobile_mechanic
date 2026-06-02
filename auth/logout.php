<?php
session_start();
session_unset();
session_destroy();
require_once __DIR__ . '/../includes/functions.php';
header('Location: ' . getBasePath());
exit;

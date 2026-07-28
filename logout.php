<?php
require_once __DIR__ . '/includes/functions.php';
logActivity('logout', 'User logged out');
session_regenerate_id(true);
$_SESSION = [];
session_destroy();
header('Location: ' . BASE_URL . '/login.php');
exit;

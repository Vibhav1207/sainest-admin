<?php
require_once __DIR__ . '/includes/functions.php';
logActivity('logout', 'User logged out');
$_SESSION = [];
session_destroy();
header('Location: ' . BASE_URL . '/login.php');
exit;

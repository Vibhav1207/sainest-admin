<?php
require_once __DIR__ . '/includes/functions.php';
redirect(!empty($_SESSION['user_id']) ? 'dashboard.php' : 'login.php');

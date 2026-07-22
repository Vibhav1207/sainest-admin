<?php
/**
 * Shared layout header. Every protected page must:
 *   1. require_once includes/auth.php
 *   2. call requireLogin() or requireRole([...])
 *   3. set $pageTitle and optionally $activeNav
 *   4. include this file
 */
$user = currentUser();
$hotelName = getSetting('hotel_name', 'Hotel Sai Nest');
$pageTitle = $pageTitle ?? 'Dashboard';
$activeNav = $activeNav ?? '';

$navItems = [
  'dashboard'    => ['icon' => '🏠', 'label' => 'Dashboard',    'href' => 'dashboard.php',    'roles' => ['admin','manager','frontdesk','housekeeping']],
  'checkin'      => ['icon' => '🛎️', 'label' => 'Check-In',     'href' => 'checkin.php',      'roles' => ['admin','manager','frontdesk']],
  'advance_booking' => ['icon' => '📅', 'label' => 'Advance Booking', 'href' => 'advance_booking.php', 'roles' => ['admin','manager','frontdesk']],
  'checkout'     => ['icon' => '🧾', 'label' => 'Check-Out',    'href' => 'checkout.php',     'roles' => ['admin','manager','frontdesk']],
  'bookings'     => ['icon' => '📋', 'label' => 'Bookings',     'href' => 'bookings.php',     'roles' => ['admin','manager','frontdesk']],
  'rooms'        => ['icon' => '🚪', 'label' => 'Rooms',        'href' => 'rooms.php',        'roles' => ['admin','manager','frontdesk','housekeeping']],
  'housekeeping' => ['icon' => '🧹', 'label' => 'Housekeeping', 'href' => 'housekeeping.php', 'roles' => ['admin','manager','frontdesk','housekeeping']],
  'guests'       => ['icon' => '👥', 'label' => 'Guests',       'href' => 'guests.php',       'roles' => ['admin','manager','frontdesk']],
  'reports'      => ['icon' => '📊', 'label' => 'Reports',      'href' => 'reports.php',      'roles' => ['admin','manager']],
  'users'        => ['icon' => '🔑', 'label' => 'Staff Users',  'href' => 'users.php',        'roles' => ['admin']],
  'settings'     => ['icon' => '⚙️', 'label' => 'Settings',     'href' => 'settings.php',     'roles' => ['admin']],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> · <?= e($hotelName) ?> HMS</title>
<link rel="icon" href="<?= BASE_URL ?>/assets/images/logo.png">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="app-shell">

  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <img src="<?= BASE_URL ?>/assets/images/logo.png" alt="<?= e($hotelName) ?> logo">
      <div>
        <div class="bname"><?= e($hotelName) ?></div>
        <div class="bsub">Management System</div>
      </div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-section-label">Main Menu</div>
      <?php foreach ($navItems as $key => $item):
        if (!in_array($_SESSION['role'] ?? '', $item['roles'], true)) continue; ?>
        <a href="<?= BASE_URL ?>/<?= $item['href'] ?>" class="<?= $activeNav === $key ? 'active' : '' ?>">
          <span class="ic"><?= $item['icon'] ?></span> <?= e($item['label']) ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div style="height:80px;"></div>
    <div class="sidebar-foot">
      <?= e(getSetting('hotel_address', '')) ?><br>
      📞 <?= e(getSetting('hotel_phone', '')) ?><br>
      ✉️ <?= e(getSetting('hotel_email', '')) ?>
    </div>
  </aside>

  <div class="main-area">
    <header class="topbar">
      <div style="display:flex;align-items:center;gap:14px;">
        <button class="menu-toggle" id="menuToggle">☰</button>
        <h1><?= e($pageTitle) ?></h1>
      </div>
      <div class="topbar-right">
        <div class="user-chip" onclick="document.getElementById('userMenu').classList.toggle('open')">
          <div class="avatar"><?= e(strtoupper(substr($user['full_name'] ?? 'U', 0, 1))) ?></div>
          <div>
            <div class="uname"><?= e($user['full_name'] ?? '') ?></div>
            <div class="urole"><?= e(roleLabel($user['role'] ?? '')) ?></div>
          </div>
        </div>
      </div>
    </header>

    <div id="userMenu" class="modal-overlay" style="align-items:flex-start;justify-content:flex-end;">
      <div class="modal-box" style="max-width:260px;margin:66px 26px 0 0;">
        <div class="modal-body" style="padding:10px;">
          <a href="<?= BASE_URL ?>/profile.php" class="btn btn-outline btn-block" style="margin-bottom:8px;">👤 My Profile</a>
          <a href="<?= BASE_URL ?>/logout.php" class="btn btn-red btn-block">🚪 Logout</a>
        </div>
      </div>
    </div>

    <main class="content">
      <?php foreach (getFlashes() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>

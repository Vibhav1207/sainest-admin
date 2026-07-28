<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';

$today = date('Y-m-d');

/* KPI: Rooms */
$allRooms = db()->query("SELECT * FROM rooms")->fetchAll();
$activeReservedRoomIds = getActiveReservationsToday();
$totalRooms = count($allRooms);
$occupied   = 0;
$available  = 0;
$dirty      = 0;
foreach ($allRooms as $r) {
    $status = getRoomCurrentStatus($r, $activeReservedRoomIds);
    if ($status === 'occupied') {
        $occupied++;
    } elseif ($status === 'available') {
        $available++;
    } elseif ($status === 'dirty') {
        $dirty++;
    }
}
$occupancyPct = $totalRooms > 0 ? round(($occupied / $totalRooms) * 100) : 0;

/* KPI: Today's activity — actual check-ins (status must be checked_in, not just reserved) */
$stmt = db()->prepare("SELECT COUNT(*) c FROM bookings WHERE DATE(checkin_datetime) = :d AND status = 'checked_in'");
$stmt->execute(['d' => $today]);
$todayCheckins = (int) $stmt->fetch()['c'];

$stmt = db()->prepare("SELECT COUNT(*) c FROM bookings WHERE DATE(actual_checkout_datetime) = :d");
$stmt->execute(['d' => $today]);
$todayCheckouts = (int) $stmt->fetch()['c'];

$stmt = db()->prepare("SELECT COUNT(*) c FROM bookings WHERE status='checked_in' AND expected_checkout_date = :d");
$stmt->execute(['d' => $today]);
$dueOutToday = (int) $stmt->fetch()['c'];

/* KPI: Revenue this month */
$stmt = db()->prepare("SELECT COALESCE(SUM(total_amount),0) t FROM invoices WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");
$stmt->execute();
$monthRevenue = (float) $stmt->fetch()['t'];

/* KPI: Commission (internal only) */
$commissionThisMonth = 0.0;
$pendingCommission = 0.0;
$actualRoomRevenueThisMonth = 0.0;
if (canViewCommission()) {
    $stmt = db()->query("SELECT COALESCE(SUM(commission_amount),0) t FROM bookings WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");
    $commissionThisMonth = (float) $stmt->fetch()['t'];
    $stmt = db()->query("SELECT COALESCE(SUM(commission_amount),0) t FROM bookings WHERE commission_status='pending'");
    $pendingCommission = (float) $stmt->fetch()['t'];

    // Actual room revenue = what was billed to guests (room_charges) minus the
    // hidden commission markup folded into it. This is the true profit figure
    // for rooms, kept separate from the commission payable to agents/OTAs.
    $stmt = db()->query("SELECT COALESCE(SUM(room_charges - commission_amount),0) t FROM invoices WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");
    $actualRoomRevenueThisMonth = (float) $stmt->fetch()['t'];
}

/* Housekeeping pending */
$pendingTasks = (int) db()->query("SELECT COUNT(*) c FROM housekeeping_tasks WHERE status != 'completed'")->fetch()['c'];

/* Recent bookings */
$recentBookings = db()->query("
  SELECT b.*,
         COALESCE(
           NULLIF(GROUP_CONCAT(DISTINCT r_multi.room_number ORDER BY CAST(r_multi.room_number AS UNSIGNED) SEPARATOR ', '), ''),
           r_primary.room_number,
           'Unassigned'
         ) AS room_number,
         g.full_name AS guest_name
  FROM bookings b
  LEFT JOIN rooms r_primary ON r_primary.id = b.room_id
  LEFT JOIN booking_rooms br ON br.booking_id = b.id
  LEFT JOIN rooms r_multi ON r_multi.id = br.room_id
  JOIN guests g ON g.id = b.primary_guest_id
  GROUP BY b.id
  ORDER BY b.created_at DESC LIMIT 8
")->fetchAll();

/* Rooms due for checkout today */
$dueOutList = db()->prepare("
  SELECT b.*,
         COALESCE(
           NULLIF(GROUP_CONCAT(DISTINCT r_multi.room_number ORDER BY CAST(r_multi.room_number AS UNSIGNED) SEPARATOR ', '), ''),
           r_primary.room_number,
           'Unassigned'
         ) AS room_number,
         g.full_name AS guest_name
  FROM bookings b
  LEFT JOIN rooms r_primary ON r_primary.id = b.room_id
  LEFT JOIN booking_rooms br ON br.booking_id = b.id
  LEFT JOIN rooms r_multi ON r_multi.id = br.room_id
  JOIN guests g ON g.id = b.primary_guest_id
  WHERE b.status='checked_in' AND b.expected_checkout_date = :d
  GROUP BY b.id
  ORDER BY b.checkin_datetime ASC
");
$dueOutList->execute(['d' => $today]);
$dueOutList = $dueOutList->fetchAll();

/* Today's expected check-ins (reservations where checkin_datetime is today) */
$todayExpectedCheckins = db()->prepare("
  SELECT b.*,
         COALESCE(
           NULLIF(GROUP_CONCAT(DISTINCT r_multi.room_number ORDER BY CAST(r_multi.room_number AS UNSIGNED) SEPARATOR ', '), ''),
           r_primary.room_number,
           'Unassigned'
         ) AS room_number,
         g.full_name AS guest_name, g.phone AS guest_phone
  FROM bookings b
  LEFT JOIN rooms r_primary ON r_primary.id = b.room_id
  LEFT JOIN booking_rooms br ON br.booking_id = b.id
  LEFT JOIN rooms r_multi ON r_multi.id = br.room_id
  JOIN guests g ON g.id = b.primary_guest_id
  WHERE b.status = 'reserved' AND DATE(b.checkin_datetime) = :d
  GROUP BY b.id
  ORDER BY b.checkin_datetime ASC
");
$todayExpectedCheckins->execute(['d' => $today]);
$todayExpectedCheckins = $todayExpectedCheckins->fetchAll();

/* Advance bookings (reservations) arriving today or soon */
$upcomingReservations = db()->query("
  SELECT b.*,
         COALESCE(
           NULLIF(GROUP_CONCAT(DISTINCT r_multi.room_number ORDER BY CAST(r_multi.room_number AS UNSIGNED) SEPARATOR ', '), ''),
           r_primary.room_number,
           'Unassigned'
         ) AS room_number,
         g.full_name AS guest_name, g.phone AS guest_phone
  FROM bookings b
  LEFT JOIN rooms r_primary ON r_primary.id = b.room_id
  LEFT JOIN booking_rooms br ON br.booking_id = b.id
  LEFT JOIN rooms r_multi ON r_multi.id = br.room_id
  JOIN guests g ON g.id = b.primary_guest_id
  WHERE b.status = 'reserved'
  GROUP BY b.id
  ORDER BY b.checkin_datetime ASC
  LIMIT 8
")->fetchAll();
$totalReservations = (int) db()->query("SELECT COUNT(*) c FROM bookings WHERE status = 'reserved'")->fetch()['c'];

require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-header">
  <div>
    <h2>Welcome back, <?= e(explode(' ', $_SESSION['name'] ?? '')[0]) ?> 👋</h2>
    <div class="desc"><?= date('l, d F Y') ?> — here's what's happening at <?= e(getSetting('hotel_name')) ?> today.</div>
  </div>
  <div class="page-actions">
    <a href="<?= BASE_URL ?>/checkin.php" class="btn btn-gold">🛎️ New Check-In</a>
    <a href="<?= BASE_URL ?>/advance_booking.php" class="btn btn-outline">📅 Advance Booking</a>
    <a href="<?= BASE_URL ?>/checkout.php" class="btn btn-outline">🧾 Process Check-Out</a>
  </div>
</div>

<div class="grid-cards">
  <div class="kpi-card c-gold">
    <div class="kpi-icon">🚪</div>
    <div><div class="kpi-val"><?= $occupied ?>/<?= $totalRooms ?></div><div class="kpi-label">Rooms Occupied (<?= $occupancyPct ?>%)</div></div>
  </div>
  <div class="kpi-card c-green">
    <div class="kpi-icon">✅</div>
    <div><div class="kpi-val"><?= $available ?></div><div class="kpi-label">Rooms Available</div></div>
  </div>
  <div class="kpi-card c-red">
    <div class="kpi-icon">🧹</div>
    <div><div class="kpi-val"><?= $pendingTasks ?></div><div class="kpi-label">Housekeeping Pending</div></div>
  </div>
  <div class="kpi-card c-blue">
    <div class="kpi-icon">📤</div>
    <div><div class="kpi-val"><?= $dueOutToday ?></div><div class="kpi-label">Check-Outs Due Today</div></div>
  </div>
  <div class="kpi-card c-blue">
    <div class="kpi-icon">📅</div>
    <div><div class="kpi-val"><?= $totalReservations ?></div><div class="kpi-label">Advance Bookings (Reserved)</div></div>
  </div>
</div>

<div class="grid-cards">
  <div class="kpi-card c-gold">
    <div class="kpi-icon">🛎️</div>
    <div><div class="kpi-val"><?= $todayCheckins ?></div><div class="kpi-label">Check-Ins Today</div></div>
  </div>
  <div class="kpi-card c-green">
    <div class="kpi-icon">💰</div>
    <div><div class="kpi-val"><?= money($monthRevenue) ?></div><div class="kpi-label">Revenue This Month</div></div>
  </div>
  <?php if (canViewCommission()): ?>
  <div class="kpi-card c-green">
    <div class="kpi-icon">🏨</div>
    <div><div class="kpi-val"><?= money($actualRoomRevenueThisMonth) ?></div><div class="kpi-label">Actual Room Revenue This Month <span class="internal-only-tag">Internal</span></div></div>
  </div>
  <div class="kpi-card c-blue">
    <div class="kpi-icon">🤝</div>
    <div><div class="kpi-val"><?= money($commissionThisMonth) ?></div><div class="kpi-label">Commission This Month <span class="internal-only-tag">Internal</span></div></div>
  </div>
  <div class="kpi-card c-red">
    <div class="kpi-icon">⏳</div>
    <div><div class="kpi-val"><?= money($pendingCommission) ?></div><div class="kpi-label">Commission Pending <span class="internal-only-tag">Internal</span></div></div>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-head">
    <h3>📤 Check-Outs Due Today</h3>
    <a href="<?= BASE_URL ?>/checkout.php" class="btn btn-sm btn-outline">Go to Check-Out</a>
  </div>
  <?php if (!$dueOutList): ?>
    <div class="empty-state" style="padding:24px;"><div class="empty-icon">📭</div>No check-outs due today.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Room</th><th>Guest</th><th>Booking Code</th><th>Check-In</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($dueOutList as $b): ?>
        <tr>
          <td><strong><?= e($b['room_number']) ?></strong></td>
          <td><?= e($b['guest_name']) ?></td>
          <td class="nowrap"><?= e($b['booking_code']) ?></td>
          <td class="nowrap"><?= date('d M, h:i A', strtotime($b['checkin_datetime'])) ?></td>
          <td><?= bookingStatusBadge($b['status']) ?></td>
          <td><a href="<?= BASE_URL ?>/checkout.php?booking_id=<?= $b['id'] ?>" class="btn btn-sm btn-gold">Check Out</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-head">
    <h3>🛎️ Today's Expected Check-Ins</h3>

    <a href="<?= BASE_URL ?>/bookings.php?status=reserved" class="btn btn-sm btn-outline">View All Reservations</a>
  </div>
  <?php if (!$todayExpectedCheckins): ?>
    <div class="empty-state" style="padding:24px;"><div class="empty-icon">📭</div>No reservations arriving today.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Room(s)</th><th>Guest</th><th>Phone</th><th>Booking Code</th><th>Planned Arrival</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($todayExpectedCheckins as $b): ?>
        <tr>
          <td><strong><?= e($b['room_number']) ?></strong></td>
          <td><?= e($b['guest_name']) ?></td>
          <td class="nowrap"><?= e($b['guest_phone']) ?></td>
          <td class="nowrap"><?= e($b['booking_code']) ?></td>
          <td class="nowrap"><?= date('d M Y, h:i A', strtotime($b['checkin_datetime'])) ?></td>
          <td><?= bookingStatusBadge($b['status']) ?></td>
          <td><a href="<?= BASE_URL ?>/checkin_from_reservation.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-gold">Check In →</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-head">
    <h3>📅 Upcoming Advance Bookings</h3>
    <a href="<?= BASE_URL ?>/bookings.php?status=reserved" class="btn btn-sm btn-outline">View All Reservations</a>
  </div>
  <?php if (!$upcomingReservations): ?>
    <div class="empty-state" style="padding:24px;"><div class="empty-icon">📭</div>No advance bookings right now.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Room</th><th>Guest</th><th>Phone</th><th>Booking Code</th><th>Planned Arrival</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($upcomingReservations as $b): ?>
        <tr>
          <td><strong><?= e($b['room_number']) ?></strong></td>
          <td><?= e($b['guest_name']) ?></td>
          <td class="nowrap"><?= e($b['guest_phone']) ?></td>
          <td class="nowrap"><?= e($b['booking_code']) ?></td>
          <td class="nowrap"><?= date('d M Y, h:i A', strtotime($b['checkin_datetime'])) ?></td>
          <td><?= bookingStatusBadge($b['status']) ?></td>
          <td><a href="<?= BASE_URL ?>/checkin_from_reservation.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-gold">Check In</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-head">
    <h3>📋 Recent Bookings</h3>
    <a href="<?= BASE_URL ?>/bookings.php" class="btn btn-sm btn-outline">View All</a>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Code</th><th>Guest</th><th>Room</th><th>Check-In</th><th>Nights</th><th>Source</th>
          <?php if (canViewCommission()): ?><th>Commission</th><?php endif; ?>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($recentBookings as $b): ?>
        <tr>
          <td class="nowrap"><a href="<?= BASE_URL ?>/booking_view.php?id=<?= $b['id'] ?>"><?= e($b['booking_code']) ?></a></td>
          <td><?= e($b['guest_name']) ?></td>
          <td><?= e($b['room_number']) ?></td>
          <td class="nowrap"><?= date('d M Y', strtotime($b['checkin_datetime'])) ?></td>
          <td><?= nightsBetween($b['checkin_datetime'], $b['expected_checkout_date']) ?></td>
          <td><span class="badge badge-gray"><?= e(ucwords(str_replace('_',' ',$b['booking_source']))) ?></span></td>
          <?php if (canViewCommission()): ?>
            <td>
              <?php if ($b['commission_amount'] > 0): ?>
                <span class="badge badge-commission">💰 <?= money($b['commission_amount']) ?></span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
          <?php endif; ?>
          <td><?= bookingStatusBadge($b['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>

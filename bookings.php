<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk']);

$pageTitle = 'All Bookings';
$activeNav = 'bookings';

$statusFilter = $_GET['status'] ?? '';
$typeFilter   = $_GET['type'] ?? '';
$search       = trim($_GET['q'] ?? '');

$sql = "
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
  WHERE 1=1
";
$params = [];
if ($statusFilter) {
    $sql .= " AND b.status = :status";
    $params['status'] = $statusFilter;
}
if ($typeFilter) {
    $sql .= " AND b.booking_type = :btype";
    $params['btype'] = $typeFilter;
}
if ($search) {
    $sql .= " AND (b.booking_code LIKE :q1 OR g.full_name LIKE :q2 OR g.phone LIKE :q3 OR r_primary.room_number LIKE :q4 OR r_multi.room_number LIKE :q4_2 OR b.company_name LIKE :q5 OR b.company_gst_number LIKE :q6)";
    $params['q1'] = "%$search%";
    $params['q2'] = "%$search%";
    $params['q3'] = "%$search%";
    $params['q4'] = "%$search%";
    $params['q4_2'] = "%$search%";
    $params['q5'] = "%$search%";
    $params['q6'] = "%$search%";
}
$sql .= " GROUP BY b.id ORDER BY b.created_at DESC LIMIT 200";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-header">
  <div>
    <h2>All Bookings</h2>
    <div class="desc">Search and review every booking made at the hotel.</div>
  </div>
  <div class="page-actions">
    <a href="<?= BASE_URL ?>/advance_booking.php" class="btn btn-outline">📅 Advance Booking</a>
    <a href="<?= BASE_URL ?>/checkin.php" class="btn btn-gold">🛎️ New Check-In</a>
  </div>
</div>

<form method="get" class="search-bar">
  <input type="text" name="q" class="form-control" placeholder="Search by name, company, phone, GSTIN or code..." value="<?= e($search) ?>">
  <select name="type" class="form-control" style="max-width:180px;">
    <option value="">All Types</option>
    <option value="regular" <?= $typeFilter === 'regular' ? 'selected' : '' ?>>👤 Regular</option>
    <option value="corporate" <?= $typeFilter === 'corporate' ? 'selected' : '' ?>>🏢 Corporate</option>
  </select>
  <select name="status" class="form-control" style="max-width:200px;">
    <option value="">All Statuses</option>
    <option value="reserved" <?= $statusFilter === 'reserved' ? 'selected' : '' ?>>Reserved (Advance Bookings)</option>
    <option value="checked_in" <?= $statusFilter === 'checked_in' ? 'selected' : '' ?>>Checked In</option>
    <option value="checked_out" <?= $statusFilter === 'checked_out' ? 'selected' : '' ?>>Checked Out</option>
    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
  </select>
  <button class="btn btn-outline">🔍 Search</button>
  <?php if ($search || $statusFilter || $typeFilter): ?><a href="<?= BASE_URL ?>/bookings.php" class="btn btn-sm btn-red">✕ Clear</a><?php endif; ?>
</form>

<div class="card">
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Code</th><th>Guest / Company</th><th>Phone</th><th>Room(s)</th><th>Check-In</th><th>Check-Out</th>
          <th>Type / Source</th><?php if (canViewCommission()): ?><th>Commission</th><?php endif; ?><th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($bookings as $b): ?>
        <tr>
          <td class="nowrap"><?= e($b['booking_code']) ?></td>
          <td>
            <strong><?= e($b['guest_name']) ?></strong>
            <?php if (($b['booking_type'] ?? '') === 'corporate'): ?>
              <br><small style="color:var(--gold); font-weight:600;">🏢 <?= e($b['company_name']) ?></small>
            <?php endif; ?>
          </td>
          <td class="nowrap"><?= e($b['guest_phone']) ?></td>
          <td><strong><?= $b['room_number'] !== 'Unassigned' ? 'Room ' . e($b['room_number']) : '<span class="badge badge-gray">Unassigned</span>' ?></strong></td>
          <td class="nowrap"><?= date('d M Y', strtotime($b['checkin_datetime'])) ?></td>
          <td class="nowrap"><?= $b['actual_checkout_datetime'] ? date('d M Y', strtotime($b['actual_checkout_datetime'])) : date('d M Y', strtotime($b['expected_checkout_date'])) . ' (exp.)' ?></td>
          <td>
            <?php if (($b['booking_type'] ?? '') === 'corporate'): ?>
              <span class="badge badge-gold">🏢 Corporate</span><br>
            <?php endif; ?>
            <span class="badge badge-gray"><?= e(ucwords(str_replace('_',' ',$b['booking_source']))) ?></span>
          </td>
          <?php if (canViewCommission()): ?>
            <td><?= $b['commission_amount'] > 0 ? '<span class="badge badge-commission">' . money($b['commission_amount']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
          <?php endif; ?>
          <td><?= bookingStatusBadge($b['status']) ?></td>
          <td class="nowrap">
            <a href="<?= BASE_URL ?>/booking_view.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-outline">View</a>
            <?php if ($b['status'] !== 'checked_out'): ?>
              <a href="<?= BASE_URL ?>/booking_edit.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-gold">Edit</a>
            <?php endif; ?>
            <?php if ($b['status'] === 'reserved'): ?>
              <a href="<?= BASE_URL ?>/checkin_from_reservation.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-gold">Check-In</a>
            <?php endif; ?>
            <?php if (in_array($b['status'], ['reserved', 'cancelled'], true) && hasRole(['admin', 'manager'])): ?>
              <form method="post" action="<?= BASE_URL ?>/booking_delete.php" style="display:inline;" onsubmit="return confirm('Permanently delete booking <?= e($b['booking_code']) ?>?');">
                <?= csrfField() ?>
                <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                <button type="submit" class="btn btn-sm btn-red">Delete</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$bookings): ?>
        <tr><td colspan="10"><div class="empty-state"><div class="empty-icon">📭</div>No bookings found.</div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>

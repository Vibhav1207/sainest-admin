<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk']);

$bookingId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare("
  SELECT b.*, COALESCE(r.room_number, 'Unassigned') AS room_number, COALESCE(rt.name, 'Standard') AS room_type_name, u1.full_name AS created_by_name, u2.full_name AS checked_out_by_name
  FROM bookings b
  LEFT JOIN rooms r ON r.id = b.room_id
  LEFT JOIN room_types rt ON rt.id = r.room_type_id
  LEFT JOIN users u1 ON u1.id = b.created_by
  LEFT JOIN users u2 ON u2.id = b.checked_out_by
  WHERE b.id = :id
");
$stmt->execute(['id' => $bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    flash('error', 'Booking not found.');
    redirect('bookings.php');
}

$gStmt = db()->prepare("
  SELECT g.*, bg.is_primary FROM booking_guests bg
  JOIN guests g ON g.id = bg.guest_id
  WHERE bg.booking_id = :id ORDER BY bg.is_primary DESC, g.id ASC
");
$gStmt->execute(['id' => $bookingId]);
$guests = $gStmt->fetchAll();

$pStmt = db()->prepare("SELECT p.*, u.full_name AS received_by_name FROM payments p LEFT JOIN users u ON u.id = p.received_by WHERE p.booking_id = :id ORDER BY p.created_at ASC");
$pStmt->execute(['id' => $bookingId]);
$payments = $pStmt->fetchAll();

$totalPaid = 0.0;
foreach ($payments as $payment) {
  $totalPaid += (float) ($payment['amount'] ?? 0);
}

$invStmt = db()->prepare("SELECT * FROM invoices WHERE booking_id = :id ORDER BY id DESC LIMIT 1");
$invStmt->execute(['id' => $bookingId]);
$invoice = $invStmt->fetch();

$bookingRooms = getBookingRooms($bookingId);
$roomNumbersStr = implode(', ', array_map(fn($r) => ($r['room_number'] !== 'Unassigned' ? 'Room ' . $r['room_number'] : 'Unassigned') . ' (' . $r['room_type_name'] . ')', $bookingRooms));
$itemizedExtraCharges = getBookingExtraCharges($bookingId);

$pageTitle = 'Booking ' . $booking['booking_code'];
$activeNav = 'bookings';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-header">
  <div>
    <h2>Booking <?= e($booking['booking_code']) ?> <?= bookingStatusBadge($booking['status']) ?> <?= ($booking['booking_type'] ?? '') === 'corporate' ? '<span class="badge badge-gold" style="font-size:0.85rem; vertical-align:middle; margin-left:6px;">🏢 Corporate Booking</span>' : '' ?></h2>
    <div class="desc"><?= e($roomNumbersStr) ?></div>
  </div>
  <div class="page-actions">
    <?php if (!in_array($booking['status'], ['checked_out', 'cancelled'], true)): ?>
      <a href="<?= BASE_URL ?>/booking_edit.php?id=<?= $booking['id'] ?>" class="btn btn-gold">✏️ Edit Booking</a>
    <?php endif; ?>
    <?php if ($booking['status'] === 'reserved'): ?>
      <a href="<?= BASE_URL ?>/checkin_from_reservation.php?id=<?= $booking['id'] ?>" class="btn btn-gold">✅ Complete Check-In</a>
      <form method="post" action="<?= BASE_URL ?>/booking_cancel.php" style="display:inline;" onsubmit="return confirm('Cancel this reservation?');">
        <?= csrfField() ?>
        <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
        <button type="submit" class="btn btn-outline" style="border-color:var(--red); color:var(--red);">✕ Cancel Reservation</button>
      </form>
    <?php elseif ($booking['status'] === 'checked_in'): ?>
      <a href="<?= BASE_URL ?>/booking_edit_stay.php?id=<?= $booking['id'] ?>" class="btn btn-outline">✏️ Edit Stay / Add Charges</a>
      <a href="<?= BASE_URL ?>/checkout.php?booking_id=<?= $booking['id'] ?>" class="btn btn-gold">🧾 Check Out</a>
    <?php elseif ($invoice): ?>
      <a href="<?= BASE_URL ?>/invoice_print.php?id=<?= $invoice['id'] ?>" class="btn btn-outline">🖨️ View Invoice</a>
    <?php endif; ?>
    <?php if (in_array($booking['status'], ['reserved', 'cancelled'], true) && hasRole(['admin', 'manager'])): ?>
      <form method="post" action="<?= BASE_URL ?>/booking_delete.php" style="display:inline;" onsubmit="return confirm('Permanently delete booking <?= e($booking['booking_code']) ?>? This action cannot be undone.');">
        <?= csrfField() ?>
        <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
        <button type="submit" class="btn btn-red">🗑️ Delete Booking</button>
      </form>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/bookings.php" class="btn btn-outline">← Back</a>
  </div>
</div>

<?php if ($booking['status'] === 'reserved'): ?>
  <div class="alert alert-info">📅 This is an advance booking — the guest has not arrived yet. Planned arrival: <strong><?= date('d M Y, h:i A', strtotime($booking['checkin_datetime'])) ?></strong>. Full guest ID proof will be collected when you click "Complete Check-In".</div>
<?php endif; ?>

<div class="grid-cards">
  <div class="kpi-card c-gold"><div class="kpi-icon">📅</div><div><div class="kpi-val" style="font-size:1rem;"><?= date('d M Y, h:i A', strtotime($booking['checkin_datetime'])) ?></div><div class="kpi-label">Check-In</div></div></div>
  <div class="kpi-card c-blue"><div class="kpi-icon">📤</div><div><div class="kpi-val" style="font-size:1rem;"><?= $booking['actual_checkout_datetime'] ? date('d M Y, h:i A', strtotime($booking['actual_checkout_datetime'])) : date('d M Y', strtotime($booking['expected_checkout_date'])) . ' (expected)' ?></div><div class="kpi-label">Check-Out</div></div></div>
  <div class="kpi-card c-green"><div class="kpi-icon">💰</div><div><div class="kpi-val"><?= money($totalPaid) ?></div><div class="kpi-label">Total Paid</div></div></div>
  <div class="kpi-card c-gold"><div class="kpi-icon">➕</div><div><div class="kpi-val"><?= money((float)($booking['extra_amount'] ?? 0)) ?></div><div class="kpi-label">Extra Amount</div></div></div>
  <div class="kpi-card c-red"><div class="kpi-icon">👥</div><div><div class="kpi-val"><?= count($guests) ?></div><div class="kpi-label">Guests in Room</div></div></div>
</div>

<?php if (canViewCommission()): ?>
<div class="card" style="border:1.5px solid var(--gold-pale);">
  <div class="card-head"><h3>🔒 Internal — Commission &amp; Booking Source</h3></div>
  <div class="form-row">
    <div><span class="text-muted">Source</span><br><strong><?= e(ucwords(str_replace('_',' ',$booking['booking_source']))) ?></strong></div>
    <div><span class="text-muted">Agent / OTA Name</span><br><strong><?= e($booking['agent_or_ota_name'] ?: '—') ?></strong></div>
    <div><span class="text-muted">Commission %</span><br><strong><?= $booking['commission_percent'] ?>%</strong></div>
    <div><span class="text-muted">Commission Amount</span><br><strong><?= money((float)($booking['commission_amount'] ?? 0)) ?></strong></div>
    <div><span class="text-muted">Commission Status</span><br><strong><?= e(ucwords(str_replace('_',' ',$booking['commission_status']))) ?></strong></div>
  </div>
  <?php if ($invoice && $booking['commission_amount'] > 0): ?>
    <div class="form-row" style="margin-top:10px;">
      <div><span class="text-muted">Actual Room Revenue (billed)</span><br><strong><?= money((float)($invoice['room_charges'] ?? 0) - (float)($invoice['commission_amount'] ?? 0)) ?></strong></div>
      <div><span class="text-muted">Commission (billed)</span><br><strong><?= money((float)($invoice['commission_amount'] ?? 0)) ?></strong></div>
      <div><span class="text-muted">Guest Was Billed (Room Charges)</span><br><strong><?= money((float)($invoice['room_charges'] ?? 0)) ?></strong></div>
    </div>
  <?php endif; ?>
  <p class="tag-note" style="margin-top:10px;">The commission amount is silently folded into the guest's Room Charges at check-out — it is never itemised or printed on the guest invoice.</p>
</div>
<?php endif; ?>

<?php if (($booking['booking_type'] ?? '') === 'corporate'): ?>
<div class="card" style="border-left:4px solid var(--gold);">
  <div class="card-head"><h3>🏢 Corporate Billing Information</h3></div>
  <div class="form-row" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
    <div><span class="text-muted">Company Name</span><br><strong style="font-size:1.05rem;"><?= e($booking['company_name']) ?></strong></div>
    <div><span class="text-muted">Company GSTIN</span><br><strong style="font-family:monospace; font-size:1rem;"><?= e($booking['company_gst_number']) ?></strong></div>
    <?php if ($booking['company_contact_person']): ?>
      <div><span class="text-muted">Contact Person</span><br><strong><?= e($booking['company_contact_person']) ?></strong></div>
    <?php endif; ?>
    <?php if ($booking['company_phone']): ?>
      <div><span class="text-muted">Company Phone</span><br><strong><?= e($booking['company_phone']) ?></strong></div>
    <?php endif; ?>
  </div>
  <?php if ($booking['company_address']): ?>
    <div style="margin-top:10px;"><span class="text-muted">Company Address</span><br><strong><?= e($booking['company_address']) ?></strong></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h3>🏨 Rooms Allocated in this Stay (<?= count($bookingRooms) ?>)</h3></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Room Number</th>
          <th>Floor</th>
          <th>Room Type</th>
          <th>Rate Per Night (₹)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($bookingRooms as $br): ?>
          <tr>
            <td><strong><?= $br['room_number'] !== 'Unassigned' ? 'Room ' . e($br['room_number']) : '<span class="badge badge-gray">Unassigned</span>' ?></strong></td>
            <td><?= $br['floor'] !== '—' ? 'Floor ' . e($br['floor']) : '—' ?></td>
            <td><?= e($br['room_type_name']) ?></td>
            <td><?= money((float)($br['rate_per_night'] ?? 0)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($itemizedExtraCharges): ?>
<div class="card">
  <div class="card-head">
    <h3>☕ Extra Charges Recorded During Stay</h3>
    <?php if ($booking['status'] === 'checked_in'): ?>
      <a href="<?= BASE_URL ?>/booking_edit_stay.php?id=<?= $booking['id'] ?>" class="btn btn-sm btn-gold">➕ Add / Manage Charges</a>
    <?php endif; ?>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Date &amp; Time</th>
          <th>Charge Item</th>
          <th class="text-right">Qty</th>
          <th class="text-right">Unit Price (₹)</th>
          <th class="text-right">Total Amount (₹)</th>
          <th>Remarks</th>
          <th>Added By</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($itemizedExtraCharges as $ec): ?>
          <tr>
            <td class="nowrap"><?= date('d M Y, h:i A', strtotime($ec['created_at'])) ?></td>
            <td><strong><?= e($ec['charge_name']) ?></strong></td>
            <td class="text-right"><?= (float) $ec['qty'] ?></td>
            <td class="text-right"><?= money((float)($ec['unit_price'] ?? 0)) ?></td>
            <td class="text-right"><strong><?= money((float)($ec['total_amount'] ?? 0)) ?></strong></td>
            <td class="text-muted"><?= e($ec['remarks'] ?: '—') ?></td>
            <td><?= e($ec['created_by_name'] ?? 'System') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h3>👥 Guest Details &amp; ID Proof</h3></div>
  <div class="room-grid" style="grid-template-columns:repeat(auto-fill,minmax(260px,1fr));">
    <?php foreach ($guests as $g): ?>
      <div class="room-tile" style="cursor:default;">
        <div class="rn" style="font-size:1.1rem;"><?= e($g['full_name']) ?> <?= $g['is_primary'] ? '<span class="badge badge-gold">Primary</span>' : '' ?></div>
        <div class="rt">
          <?= $g['age'] ? e($g['age']) . ' yrs' : '' ?> <?= $g['gender'] ? '· ' . e(ucfirst($g['gender'])) : '' ?><br>
          <?= $g['phone'] ? '📞 ' . e($g['phone']) . '<br>' : '' ?>
          <?= $g['city'] || $g['state'] ? '📍 ' . e(trim($g['city'] . ' ' . $g['state'])) : '' ?>
        </div>
        <?php if ($g['id_proof_type']): ?>
          <div class="tag-note" style="margin-bottom:6px;"><?= e(ucwords(str_replace('_',' ',$g['id_proof_type']))) ?>: <strong><?= e($g['id_proof_number'] ?: '—') ?></strong></div>
        <?php endif; ?>
        <div style="display:flex;gap:8px;">
          <?php if ($g['id_proof_photo']): ?>
            <a href="<?= BASE_URL ?>/doc_view.php?f=<?= urlencode($g['id_proof_photo']) ?>" target="_blank" class="btn btn-sm btn-outline">📄 Front</a>
          <?php endif; ?>
          <?php if ($g['id_proof_photo_back']): ?>
            <a href="<?= BASE_URL ?>/doc_view.php?f=<?= urlencode($g['id_proof_photo_back']) ?>" target="_blank" class="btn btn-sm btn-outline">📄 Back</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>💳 Payment History</h3></div>
  <?php if (!$payments): ?>
    <div class="empty-state" style="padding:20px;">No payments recorded yet.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Date</th><th>Type</th><th>Mode</th><th>Amount</th><th>Received By</th><th>Note</th></tr></thead>
      <tbody>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td class="nowrap"><?= date('d M Y, h:i A', strtotime($p['created_at'])) ?></td>
          <td><?= e(ucfirst($p['payment_type'])) ?></td>
          <td><?= e(strtoupper($p['mode'])) ?></td>
          <td><?= money((float)($p['amount'] ?? 0)) ?></td>
          <td><?= e($p['received_by_name'] ?? '—') ?></td>
          <td class="text-muted"><?= e($p['note']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if ($booking['special_requests']): ?>
<div class="card"><div class="card-head"><h3>📝 Special Requests</h3></div><p><?= e($booking['special_requests']) ?></p></div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>

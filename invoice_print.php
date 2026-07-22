<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk']);

$invoiceId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare("
  SELECT inv.*, b.booking_code, b.checkin_datetime, b.expected_checkout_date, b.actual_checkout_datetime,
         b.rate_per_night, r.room_number, rt.name AS room_type_name,
         g.full_name AS guest_name, g.phone AS guest_phone, g.address AS guest_address, g.city AS guest_city, g.state AS guest_state
  FROM invoices inv
  JOIN bookings b ON b.id = inv.booking_id
  JOIN rooms r ON r.id = b.room_id
  JOIN room_types rt ON rt.id = r.room_type_id
  JOIN guests g ON g.id = b.primary_guest_id
  WHERE inv.id = :id
");
$stmt->execute(['id' => $invoiceId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    flash('error', 'Invoice not found.');
    redirect('bookings.php');
}

$itemsStmt = db()->prepare("SELECT * FROM invoice_items WHERE invoice_id = :id");
$itemsStmt->execute(['id' => $invoiceId]);
$items = $itemsStmt->fetchAll();

$nights = nightsBetween($invoice['checkin_datetime'], date('Y-m-d', strtotime($invoice['actual_checkout_datetime'] ?? 'now')));

// The guest-facing "Rate" shown here must always multiply out cleanly to the
// room_charges total (which already has any commission folded in). We never
// print the base rate or commission separately on this document — the guest
// only ever sees one clean per-night rate and one Room Charges amount.
$displayRatePerNight = $nights > 0 ? $invoice['room_charges'] / $nights : $invoice['room_charges'];

$invRooms = getBookingRooms($invoice['booking_id']);
$invRoomNumbersStr = implode(', ', array_map(fn($r) => 'Room ' . $r['room_number'], $invRooms));
$invBooking = db()->query("SELECT booking_type, company_name, company_gst_number, company_address, company_contact_person, company_phone FROM bookings WHERE id = " . (int)$invoice['booking_id'])->fetch();

$pageTitle = 'Invoice ' . $invoice['invoice_number'];
$activeNav = 'bookings';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-header no-print">
  <div><h2>Invoice <?= e($invoice['invoice_number']) ?></h2></div>
  <div class="page-actions">
    <button onclick="window.print()" class="btn btn-gold">🖨️ Print / Save PDF</button>
    <a href="<?= BASE_URL ?>/bookings.php" class="btn btn-outline">← Back to Bookings</a>
  </div>
</div>

<div class="card invoice-sheet" style="max-width:800px;margin:0 auto;">
  <div style="display:flex;align-items:center;gap:16px;border-bottom:3px solid var(--gold-light);padding-bottom:18px;margin-bottom:22px;">
    <img src="<?= BASE_URL ?>/assets/images/logo.png" style="width:64px;height:64px;object-fit:contain;">
    <div style="flex:1;">
      <h2 style="margin-bottom:2px;"><?= e(getSetting('hotel_name')) ?></h2>
      <div class="text-muted" style="font-size:0.85rem;"><?= e(getSetting('hotel_address')) ?></div>
      <div class="text-muted" style="font-size:0.85rem;">📞 <?= e(getSetting('hotel_phone')) ?> &nbsp;·&nbsp; ✉️ <?= e(getSetting('hotel_email')) ?><?= getSetting('hotel_gst_number') ? ' &nbsp;·&nbsp; GSTIN: ' . e(getSetting('hotel_gst_number')) : '' ?></div>
    </div>
    <div style="text-align:right;">
      <div style="font-family:'Playfair Display',serif;font-size:1.1rem;font-weight:700;color:var(--gold);">TAX INVOICE</div>
      <div class="text-muted" style="font-size:0.82rem;"><?= e($invoice['invoice_number']) ?></div>
      <div class="text-muted" style="font-size:0.82rem;"><?= date('d M Y', strtotime($invoice['created_at'])) ?></div>
    </div>
  </div>

  <div class="form-row" style="margin-bottom:20px;">
    <div>
      <?php if (($invBooking['booking_type'] ?? '') === 'corporate'): ?>
        <div class="text-muted" style="font-size:0.75rem;text-transform:uppercase;">Billed To (Corporate Account)</div>
        <strong style="font-size:1.05rem;"><?= e($invBooking['company_name']) ?></strong><br>
        <strong>GSTIN: <?= e($invBooking['company_gst_number']) ?></strong><br>
        <?php if ($invBooking['company_address']): ?>
          <?= e($invBooking['company_address']) ?><br>
        <?php endif; ?>
        <span class="text-muted">Guest / Contact:</span> <?= e($invBooking['company_contact_person'] ?: $invoice['guest_name']) ?>
        <?php if ($invBooking['company_phone'] || $invoice['guest_phone']): ?>
          (<?= e($invBooking['company_phone'] ?: $invoice['guest_phone']) ?>)
        <?php endif; ?>
      <?php else: ?>
        <div class="text-muted" style="font-size:0.75rem;text-transform:uppercase;">Billed To</div>
        <strong><?= e($invoice['guest_name']) ?></strong><br>
        <?php if ($invoice['guest_phone']): ?><?= e($invoice['guest_phone']) ?><br><?php endif; ?>
        <?= e(trim($invoice['guest_address'] . ' ' . $invoice['guest_city'] . ' ' . $invoice['guest_state'])) ?>
      <?php endif; ?>
    </div>
    <div>
      <div class="text-muted" style="font-size:0.75rem;text-transform:uppercase;">Booking Details</div>
      <strong>Allocated Room(s):</strong> <?= e($invRoomNumbersStr) ?><br>
      Booking Code: <?= e($invoice['booking_code']) ?><br>
      Check-In: <?= date('d M Y, h:i A', strtotime($invoice['checkin_datetime'])) ?><br>
      Check-Out: <?= $invoice['actual_checkout_datetime'] ? date('d M Y, h:i A', strtotime($invoice['actual_checkout_datetime'])) : '—' ?>
    </div>
  </div>

  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Description</th><th class="text-right">Qty</th><th class="text-right">Rate</th><th class="text-right">Amount</th></tr></thead>
      <tbody>
        <tr>
          <td>
            <strong>Room Charges — <?= count($invRooms) > 1 ? count($invRooms) . ' Rooms (' . e($invRoomNumbersStr) . ')' : e($invoice['room_type_name']) ?></strong> (<?= $nights ?> night<?= $nights > 1 ? 's' : '' ?>)
            <?php if (count($invRooms) > 1): ?>
              <div class="text-muted" style="font-size:0.82rem; margin-top:4px;">
                <?php foreach ($invRooms as $ir): ?>
                  • Room <?= e($ir['room_number']) ?> — <?= e($ir['room_type_name']) ?> @ <?= money($ir['rate_per_night']) ?>/night<br>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </td>
          <td class="text-right"><?= $nights ?></td>
          <td class="text-right"><?= money($displayRatePerNight) ?></td>
          <td class="text-right"><?= money($invoice['room_charges']) ?></td>
        </tr>
        <?php if ($items): ?>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?= e($it['description']) ?></td>
              <td class="text-right"><?= (float) $it['qty'] ?></td>
              <td class="text-right"><?= money($it['rate']) ?></td>
              <td class="text-right"><?= money($it['amount']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php else:
          $recExtra = getBookingExtraCharges($invoice['booking_id']);
          foreach ($recExtra as $ec): ?>
            <tr>
              <td><?= e($ec['charge_name']) ?><?= $ec['remarks'] ? ' (' . e($ec['remarks']) . ')' : '' ?></td>
              <td class="text-right"><?= (float) $ec['qty'] ?></td>
              <td class="text-right"><?= money($ec['unit_price']) ?></td>
              <td class="text-right"><?= money($ec['total_amount']) ?></td>
            </tr>
          <?php endforeach;
        endif; ?>
      </tbody>
    </table>
  </div>

  <div style="display:flex;justify-content:flex-end;margin-top:18px;">
    <div style="width:280px;">
      <div class="stat-row"><span class="lbl">Subtotal</span><span class="val"><?= money($invoice['room_charges'] + $invoice['extra_charges']) ?></span></div>
      <div class="stat-row"><span class="lbl">Discount</span><span class="val">- <?= money($invoice['discount_amount']) ?></span></div>
      <div class="stat-row"><span class="lbl">Tax (GST)</span><span class="val"><?= money($invoice['tax_amount']) ?></span></div>
      <div class="stat-row total"><span class="lbl">Grand Total</span><span class="val"><?= money($invoice['total_amount']) ?></span></div>
      <div class="stat-row"><span class="lbl">Amount Paid</span><span class="val"><?= money($invoice['paid_amount']) ?></span></div>
      <div class="stat-row total"><span class="lbl">Balance Due</span><span class="val"><?= money($invoice['balance_amount']) ?></span></div>
    </div>
  </div>

  <div class="divider"></div>
  <p style="text-align:center;color:var(--text-muted);font-size:0.85rem;">Thank you for staying with us. We hope to welcome you back to <?= e(getSetting('hotel_name')) ?> soon! 🙏</p>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>

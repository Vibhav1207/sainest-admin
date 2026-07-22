<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk']);

$pageTitle = 'Check-Out';
$activeNav = 'checkout';

$selectedBookingId = (int) ($_GET['booking_id'] ?? 0);
$selectedBooking = null;
$selectedGuests = [];
$selectedPayments = [];

if ($selectedBookingId) {
    $stmt = db()->prepare("
      SELECT b.*, r.room_number, r.id AS room_id, rt.name AS room_type_name, g.full_name AS guest_name, g.phone AS guest_phone
      FROM bookings b
      JOIN rooms r ON r.id = b.room_id
      JOIN room_types rt ON rt.id = r.room_type_id
      JOIN guests g ON g.id = b.primary_guest_id
      WHERE b.id = :id AND b.status = 'checked_in'
    ");
    $stmt->execute(['id' => $selectedBookingId]);
    $selectedBooking = $stmt->fetch();

    if ($selectedBooking) {
        $selectedBookingRooms = getBookingRooms($selectedBookingId);
        $gStmt = db()->prepare("
          SELECT g.* FROM booking_guests bg JOIN guests g ON g.id = bg.guest_id
          WHERE bg.booking_id = :id ORDER BY bg.is_primary DESC
        ");
        $gStmt->execute(['id' => $selectedBookingId]);
        $selectedGuests = $gStmt->fetchAll();

        $pStmt = db()->prepare("SELECT * FROM payments WHERE booking_id = :id ORDER BY created_at ASC");
        $pStmt->execute(['id' => $selectedBookingId]);
        $selectedPayments = $pStmt->fetchAll();
        $selectedExtraCharges = getBookingExtraCharges($selectedBookingId);
    }
}

$activeBookings = db()->query("
  SELECT b.*, r.room_number, g.full_name AS guest_name
  FROM bookings b
  JOIN rooms r ON r.id = b.room_id
  JOIN guests g ON g.id = b.primary_guest_id
  WHERE b.status = 'checked_in'
  ORDER BY b.expected_checkout_date ASC, b.checkin_datetime ASC
")->fetchAll();

require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-header">
  <div>
    <h2>Guest Check-Out</h2>
    <div class="desc">Select a room to generate the final bill and complete check-out.</div>
  </div>
</div>

<?php if (!$selectedBooking): ?>
  <div class="card">
    <div class="card-head"><h3>🛏️ Currently Checked-In Rooms</h3></div>
    <?php if (!$activeBookings): ?>
      <div class="empty-state"><div class="empty-icon">🛌</div>No rooms are currently occupied.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Room(s)</th><th>Guest</th><th>Booking Code</th><th>Check-In</th><th>Expected Check-Out</th><th>Nights</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($activeBookings as $b):
            $bRooms = getBookingRooms((int)$b['id']);
            $rStr = implode(', ', array_map(fn($r) => 'Room ' . $r['room_number'], $bRooms)) ?: ('Room ' . $b['room_number']);
            $nights = nightsBetween($b['checkin_datetime'], $b['expected_checkout_date']);
            $overdue = strtotime($b['expected_checkout_date']) < strtotime(date('Y-m-d'));
          ?>
            <tr>
              <td><strong><?= e($rStr) ?></strong></td>
              <td><?= e($b['guest_name']) ?></td>
              <td class="nowrap"><?= e($b['booking_code']) ?></td>
              <td class="nowrap"><?= date('d M Y, h:i A', strtotime($b['checkin_datetime'])) ?></td>
              <td class="nowrap"><?= date('d M Y', strtotime($b['expected_checkout_date'])) ?> <?= $overdue ? '<span class="badge badge-red">Overdue</span>' : '' ?></td>
              <td><?= $nights ?></td>
              <td><a href="?booking_id=<?= $b['id'] ?>" class="btn btn-sm btn-gold">Check Out →</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php else:
  $nights = nightsBetween($selectedBooking['checkin_datetime'], date('Y-m-d'));
  $nightsExpected = nightsBetween($selectedBooking['checkin_datetime'], $selectedBooking['expected_checkout_date']);
  $useNights = max($nights, 1);
  $commissionAmount = (float) $selectedBooking['commission_amount'];
  $actualRoomCharges = $selectedBooking['rate_per_night'] * $useNights;
  $roomCharges = $actualRoomCharges + $commissionAmount;
  $paidSoFar = array_sum(array_column($selectedPayments, 'amount'));
  $rStrHeader = implode(', ', array_map(fn($r) => 'Room ' . $r['room_number'] . ' (' . $r['room_type_name'] . ')', $selectedBookingRooms));
?>
  <div class="card">
    <div class="card-head">
      <h3>🧾 Billing — <?= e($rStrHeader) ?> (<?= e($selectedBooking['booking_code']) ?>)</h3>
      <a href="checkout.php" class="btn btn-sm btn-outline">← Back to list</a>
    </div>

    <div class="form-row" style="margin-bottom:18px;">
      <div><span class="text-muted">Primary Guest</span><br><strong><?= e($selectedBooking['guest_name']) ?></strong></div>
      <div><span class="text-muted">Rooms in Stay</span><br><strong><?= e($rStrHeader) ?></strong></div>
      <div><span class="text-muted">Check-In</span><br><strong><?= date('d M Y, h:i A', strtotime($selectedBooking['checkin_datetime'])) ?></strong></div>
      <div><span class="text-muted">Total Guests</span><br><strong><?= count($selectedGuests) ?: $selectedBooking['num_guests'] ?></strong></div>
    </div>

    <?php if (($selectedBooking['booking_type'] ?? '') === 'corporate'): ?>
      <div style="background:#faf9f5; border:1.5px solid var(--gold); border-radius:6px; padding:12px; margin-bottom:16px;">
        <strong style="color:var(--gold); font-size:0.95rem;">🏢 Billed to Corporate Account:</strong><br>
        <span style="font-size:1.05rem; font-weight:700; color:var(--text-color);"><?= e($selectedBooking['company_name']) ?></span> &nbsp;·&nbsp; GSTIN: <code style="font-size:0.95rem; background:#fff; padding:2px 6px; border:1px solid #ddd; border-radius:4px;"><?= e($selectedBooking['company_gst_number']) ?></code>
        <?php if ($selectedBooking['company_contact_person']): ?> &nbsp;·&nbsp; Contact: <strong><?= e($selectedBooking['company_contact_person']) ?></strong><?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (canViewCommission() && $commissionAmount > 0): ?>
      <div class="alert alert-info">
        🔒 Internal note: This booking carries a commission of <?= money($commissionAmount) ?> (<?= e(ucwords(str_replace('_',' ',$selectedBooking['booking_source']))) ?><?= $selectedBooking['agent_or_ota_name'] ? ' — ' . e($selectedBooking['agent_or_ota_name']) : '' ?>).
        It is folded into the Room Charges below so the guest is billed one combined figure and never sees a commission line item.<br>
        Actual room revenue: <strong><?= money($actualRoomCharges) ?></strong> &nbsp;+&nbsp; Commission: <strong><?= money($commissionAmount) ?></strong> &nbsp;=&nbsp; Guest sees: <strong><?= money($roomCharges) ?></strong>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= BASE_URL ?>/checkout_process.php" id="checkoutForm">
      <?= csrfField() ?>
      <input type="hidden" name="booking_id" value="<?= $selectedBooking['id'] ?>">

      <div class="form-row">
        <div class="form-group">
          <label>Nights Stayed</label>
          <input type="number" name="nights" id="nightsInput" class="form-control" value="<?= $useNights ?>" min="1" onchange="recalcTotal()">
        </div>
        <div class="form-group">
          <label>Base Rate Per Night <?= canViewCommission() ? '<span class="internal-only-tag">Internal</span>' : '' ?></label>
          <input type="number" name="rate_per_night" id="rateInput" class="form-control" value="<?= $selectedBooking['rate_per_night'] ?>" step="0.01" onchange="recalcTotal()">
        </div>
        <div class="form-group">
          <label>Discount (₹)</label>
          <input type="number" name="discount_amount" id="discountInput" class="form-control" value="0" step="0.01" onchange="recalcTotal()">
        </div>
        <div class="form-group">
          <label>Tax % (GST)</label>
          <input type="number" name="tax_percent" id="taxInput" class="form-control" value="<?= $selectedBooking['tax_percent'] ?>" step="0.01" onchange="recalcTotal()">
        </div>
      </div>

      <div class="divider"></div>
      <h4 style="margin-bottom:12px;">Extra Charges (Food, Laundry, Mini-bar, etc.)</h4>
      <div id="extraItemsContainer"></div>
      <button type="button" id="addItemBtn" class="btn btn-sm btn-outline" style="margin-bottom:18px;">➕ Add Charge</button>

      <div class="divider"></div>
      <div class="form-row">
        <div class="form-group">
          <label>Additional Payment Now</label>
          <input type="number" name="payment_now" id="paymentNowInput" class="form-control" value="0" step="0.01">
        </div>
        <div class="form-group">
          <label>Payment Mode</label>
          <select name="payment_mode" class="form-control">
            <option value="cash">Cash</option>
            <option value="card">Card</option>
            <option value="upi">UPI</option>
            <option value="bank_transfer">Bank Transfer</option>
            <option value="online">Online</option>
          </select>
        </div>
      </div>

      <div class="card" style="background:var(--cream);box-shadow:none;">
        <div class="stat-row"><span class="lbl">Room Charges <?= canViewCommission() && $commissionAmount > 0 ? '<span class="internal-only-tag" title="Includes '.money($commissionAmount).' commission — this is what the guest sees, no breakdown">Incl. commission</span>' : '' ?></span><span class="val" id="roomChargesDisplay"><?= money($roomCharges) ?></span></div>
        <div class="stat-row"><span class="lbl">Extra Charges</span><span class="val" id="extraChargesDisplay"><?= money(0) ?></span></div>
        <div class="stat-row"><span class="lbl">Discount</span><span class="val" id="discountDisplay">- <?= money(0) ?></span></div>
        <div class="stat-row"><span class="lbl">Tax</span><span class="val" id="taxDisplay"><?= money(0) ?></span></div>
        <div class="stat-row"><span class="lbl">Already Paid</span><span class="val"><?= money($paidSoFar) ?></span></div>
        <div class="stat-row total"><span class="lbl">Grand Total</span><span class="val" id="grandTotalDisplay">—</span></div>
        <div class="stat-row total"><span class="lbl">Balance Due</span><span class="val" id="balanceDisplay">—</span></div>
      </div>

      <div style="text-align:right;margin-top:18px;">
        <button type="submit" class="btn btn-gold" style="min-width:220px;">✅ Complete Check-Out &amp; Generate Bill</button>
      </div>
    </form>
  </div>

<template id="itemTemplate">
  <div class="form-row extra-item-row" style="align-items:end;">
    <div class="form-group">
      <label>Description</label>
      <input type="text" name="item_desc[]" class="form-control" placeholder="e.g. Breakfast, Laundry">
    </div>
    <div class="form-group">
      <label>Qty</label>
      <input type="number" name="item_qty[]" class="form-control item-qty" value="1" min="0" step="1">
    </div>
    <div class="form-group">
      <label>Rate</label>
      <input type="number" name="item_rate[]" class="form-control item-rate" value="0" min="0" step="0.01">
    </div>
    <div class="form-group">
      <button type="button" class="btn btn-sm btn-red remove-item-btn">✕ Remove</button>
    </div>
  </div>
</template>

<script>
const extraItemsContainer = document.getElementById('extraItemsContainer');
const itemTemplate = document.getElementById('itemTemplate');
const INITIAL_EXTRA_AMOUNT = <?= (float) ($selectedBooking['extra_amount'] ?? 0) ?>;
const RECORDED_EXTRA_CHARGES = <?= json_encode($selectedExtraCharges ?? []) ?>;

function addExtraItemRow(desc, qty, rate) {
  const clone = itemTemplate.content.cloneNode(true);
  if (desc) clone.querySelector('input[name="item_desc[]"]').value = desc;
  if (qty) clone.querySelector('input[name="item_qty[]"]').value = qty;
  if (rate !== undefined) clone.querySelector('input[name="item_rate[]"]').value = rate;

  clone.querySelector('.remove-item-btn').addEventListener('click', function (e) {
    e.target.closest('.extra-item-row').remove();
    recalcTotal();
  });
  extraItemsContainer.appendChild(clone);
  extraItemsContainer.querySelectorAll('.item-qty, .item-rate').forEach(inp => inp.addEventListener('input', recalcTotal));
}

document.getElementById('addItemBtn').addEventListener('click', function () {
  addExtraItemRow('', 1, 0);
});

if (RECORDED_EXTRA_CHARGES.length > 0) {
  RECORDED_EXTRA_CHARGES.forEach(ec => {
    let label = ec.charge_name;
    if (ec.remarks) label += ' (' + ec.remarks + ')';
    addExtraItemRow(label, parseFloat(ec.qty), parseFloat(ec.unit_price));
  });
} else if (INITIAL_EXTRA_AMOUNT > 0) {
  addExtraItemRow('Extra Amount (Check-In / Booking)', 1, INITIAL_EXTRA_AMOUNT);
}

const ROOM_RATE_BASE = <?= (float) $selectedBooking['rate_per_night'] ?>;
const COMMISSION_AMOUNT = <?= (float) $commissionAmount ?>; // internal — folded into room charges, never shown as its own line to the guest
const CURRENCY = '<?= e(getSetting('currency_symbol','₹')) ?>';

function recalcTotal() {
  const nights = parseFloat(document.getElementById('nightsInput').value) || 0;
  const rate = parseFloat(document.getElementById('rateInput').value) || 0;
  const discount = parseFloat(document.getElementById('discountInput').value) || 0;
  const taxPct = parseFloat(document.getElementById('taxInput').value) || 0;

  const roomCharges = (nights * rate) + COMMISSION_AMOUNT;
  let extraCharges = 0;
  document.querySelectorAll('.extra-item-row').forEach(row => {
    const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
    const r = parseFloat(row.querySelector('.item-rate').value) || 0;
    extraCharges += qty * r;
  });

  const taxable = roomCharges + extraCharges - discount;
  const tax = Math.max(0, (taxable * taxPct) / 100);
  const grandTotal = Math.max(0, taxable + tax);
  const alreadyPaid = <?= (float) $paidSoFar ?>;
  const paymentNow = parseFloat(document.getElementById('paymentNowInput').value) || 0;
  const balance = grandTotal - alreadyPaid - paymentNow;

  document.getElementById('roomChargesDisplay').textContent = CURRENCY + roomCharges.toFixed(2);
  document.getElementById('extraChargesDisplay').textContent = CURRENCY + extraCharges.toFixed(2);
  document.getElementById('discountDisplay').textContent = '- ' + CURRENCY + discount.toFixed(2);
  document.getElementById('taxDisplay').textContent = CURRENCY + tax.toFixed(2);
  document.getElementById('grandTotalDisplay').textContent = CURRENCY + grandTotal.toFixed(2);
  document.getElementById('balanceDisplay').textContent = CURRENCY + balance.toFixed(2);
}
document.getElementById('paymentNowInput').addEventListener('input', recalcTotal);
recalcTotal();
</script>

<?php endif; ?>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>

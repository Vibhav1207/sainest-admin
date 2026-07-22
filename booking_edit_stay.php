<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk']);

$bookingId = (int) ($_GET['id'] ?? 0);

if ($bookingId <= 0) {
    flash('error', 'Invalid booking ID.');
    redirect('rooms.php');
}

$pdo = db();
$stmt = $pdo->prepare("
  SELECT b.*, g.full_name AS guest_name, g.phone AS guest_phone
  FROM bookings b
  JOIN guests g ON g.id = b.primary_guest_id
  WHERE b.id = :id AND b.status = 'checked_in'
");
$stmt->execute(['id' => $bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    flash('error', 'Booking not found or is no longer active (checked out / cancelled).');
    redirect('rooms.php');
}

$bookingRooms = getBookingRooms($bookingId);
$roomsListStr = implode(', ', array_map(fn($r) => 'Room ' . $r['room_number'] . ' (' . $r['room_type_name'] . ')', $bookingRooms));

$extraCharges = getBookingExtraCharges($bookingId);
$totalExtraAmount = array_sum(array_column($extraCharges, 'total_amount'));

$pageTitle = 'Edit Stay — Booking ' . $booking['booking_code'];
$activeNav = 'rooms';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-header">
  <div>
    <h2>✏️ Edit Stay &amp; Add Extra Charges</h2>
    <div class="desc">Booking <strong><?= e($booking['booking_code']) ?></strong> — <?= e($roomsListStr) ?> (<?= e($booking['guest_name']) ?>)</div>
  </div>
  <div class="page-actions">
    <a href="<?= BASE_URL ?>/booking_view.php?id=<?= $booking['id'] ?>" class="btn btn-outline">👁️ View Booking</a>
    <a href="<?= BASE_URL ?>/rooms.php" class="btn btn-outline">← Back to Rooms</a>
  </div>
</div>

<div class="card" style="margin-bottom:24px;">
  <div class="card-head"><h3>➕ Add Extra Charges during Stay</h3></div>
  <form method="post" action="<?= BASE_URL ?>/booking_extra_charge_save.php" id="extraChargeForm">
    <?= csrfField() ?>
    <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">

    <div style="background:var(--cream-light, #faf9f5); border:1.5px solid var(--border-color, #e5e0d8); border-radius:8px; padding:16px; margin-bottom:18px;">
      <div class="form-row" style="align-items:end;">
        <div class="form-group" style="flex:2;">
          <label>Additional Charge *</label>
          <select id="presetSelect" class="form-control">
            <option value="">-- Select Additional Charge --</option>
            <option value="Tea" data-price="50">Tea</option>
            <option value="Coffee / Milk" data-price="40">Coffee / Milk</option>
            <option value="Extra Bed" data-price="500">Extra Bed</option>
          </select>
        </div>
        <div class="form-group" style="flex:2;" id="customNameGroup">
          <label>Item Name / Description *</label>
          <input type="text" id="chargeNameInput" class="form-control" placeholder="e.g. Laundry, Extra Tea, Food">
        </div>
        <div class="form-group" style="flex:1;">
          <label>Qty *</label>
          <input type="number" id="qtyInput" class="form-control" value="1" min="0.1" step="0.1" oninput="recalcChargeTotal()">
        </div>
        <div class="form-group" style="flex:1.5;">
          <label>Amount (₹) *</label>
          <input type="number" id="priceInput" class="form-control" step="0.01" min="0" placeholder="0.00" oninput="recalcChargeTotal()">
        </div>
        <div class="form-group" style="flex:1.5;">
          <label>Total (₹)</label>
          <input type="number" id="totalInput" class="form-control" readonly style="background:#f5f3ef; font-weight:bold;">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label>Remarks / Notes (optional)</label>
          <input type="text" id="remarksInput" class="form-control" placeholder="Optional notes (e.g. 2 shirts dry-cleaned, late departure by 2 hrs)">
        </div>
        <div class="form-group" style="align-self:flex-end; max-width:180px;">
          <button type="button" id="addChargeToListBtn" class="btn btn-gold" style="width:100%; white-space:nowrap;">➕ Add to List</button>
        </div>
      </div>
      <div id="chargeFormError" style="display:none; margin-top:8px; font-size:0.88rem; color:var(--red, #d9534f); font-weight:bold;"></div>
    </div>

    <!-- Charges Pending Save -->
    <div style="margin-bottom:20px;">
      <label style="font-weight:700; margin-bottom:8px; display:block;">New Charges to Save</label>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Item / Charge</th>
              <th class="text-right">Qty</th>
              <th class="text-right">Unit Price (₹)</th>
              <th class="text-right">Total (₹)</th>
              <th>Remarks</th>
              <th style="width:80px; text-align:right;">Action</th>
            </tr>
          </thead>
          <tbody id="newChargesTableBody">
            <tr id="emptyNewChargesRow">
              <td colspan="6" class="text-muted" style="text-align:center; padding:18px;">
                No new charges added to list. Use the form above to add charges, then click <strong>💾 Save Charges to Booking</strong> below.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div id="hiddenChargeInputs"></div>
    </div>

    <div style="text-align:right;">
      <button type="submit" class="btn btn-gold" id="saveChargesBtn" disabled>💾 Save Charges to Booking</button>
    </div>
  </form>
</div>

<!-- History of Previously Added Extra Charges -->
<div class="card">
  <div class="card-head">
    <h3>📋 Recorded Extra Charges History (Total: <?= money($totalExtraAmount) ?>)</h3>
  </div>
  <?php if (!$extraCharges): ?>
    <div class="empty-state" style="padding:24px;">No extra charges have been added to this stay yet.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Date &amp; Time</th>
            <th>Charge Name</th>
            <th class="text-right">Qty</th>
            <th class="text-right">Unit Price (₹)</th>
            <th class="text-right">Total Amount (₹)</th>
            <th>Remarks</th>
            <th>Added By</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($extraCharges as $ec): ?>
            <tr>
              <td class="nowrap"><?= date('d M Y, h:i A', strtotime($ec['created_at'])) ?></td>
              <td><strong><?= e($ec['charge_name']) ?></strong></td>
              <td class="text-right"><?= (float) $ec['qty'] ?></td>
              <td class="text-right"><?= money($ec['unit_price']) ?></td>
              <td class="text-right"><strong><?= money($ec['total_amount']) ?></strong></td>
              <td class="text-muted"><?= e($ec['remarks'] ?: '—') ?></td>
              <td><?= e($ec['created_by_name'] ?? 'System') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
const presetSelect = document.getElementById('presetSelect');
const chargeNameInput = document.getElementById('chargeNameInput');
const qtyInput = document.getElementById('qtyInput');
const priceInput = document.getElementById('priceInput');
const totalInput = document.getElementById('totalInput');
const remarksInput = document.getElementById('remarksInput');
const addBtn = document.getElementById('addChargeToListBtn');
const errBox = document.getElementById('chargeFormError');
const tableBody = document.getElementById('newChargesTableBody');
const hiddenInputs = document.getElementById('hiddenChargeInputs');
const saveBtn = document.getElementById('saveChargesBtn');

presetSelect.addEventListener('change', function () {
  if (this.value) {
    chargeNameInput.value = this.value;
    const opt = this.options[this.selectedIndex];
    if (opt.dataset.price) {
      priceInput.value = opt.dataset.price;
      recalcChargeTotal();
    }
  }
});

function recalcChargeTotal() {
  const qty = parseFloat(qtyInput.value) || 0;
  const price = parseFloat(priceInput.value) || 0;
  totalInput.value = (qty * price).toFixed(2);
}

const pendingCharges = [];

function renderPendingCharges() {
  tableBody.innerHTML = '';
  hiddenInputs.innerHTML = '';

  if (pendingCharges.length === 0) {
    tableBody.innerHTML = '<tr id="emptyNewChargesRow"><td colspan="6" class="text-muted" style="text-align:center; padding:18px;">No new charges added to list. Use the form above to add charges, then click <strong>💾 Save Charges to Booking</strong> below.</td></tr>';
    saveBtn.disabled = true;
    return;
  }

  saveBtn.disabled = false;

  pendingCharges.forEach((c, idx) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><strong>${escapeHtml(c.name)}</strong></td>
      <td class="text-right">${c.qty}</td>
      <td class="text-right">₹${c.price.toFixed(2)}</td>
      <td class="text-right"><strong>₹${c.total.toFixed(2)}</strong></td>
      <td>${escapeHtml(c.remarks || '—')}</td>
      <td style="text-align:right;">
        <button type="button" class="btn btn-sm btn-red" onclick="removePendingCharge(${idx})">✕ Remove</button>
      </td>
    `;
    tableBody.appendChild(tr);

    // Hidden inputs
    const hiddenName = document.createElement('input');
    hiddenName.type = 'hidden';
    hiddenName.name = 'charge_name[]';
    hiddenName.value = c.name;
    hiddenInputs.appendChild(hiddenName);

    const hiddenQty = document.createElement('input');
    hiddenQty.type = 'hidden';
    hiddenQty.name = 'charge_qty[]';
    hiddenQty.value = c.qty;
    hiddenInputs.appendChild(hiddenQty);

    const hiddenPrice = document.createElement('input');
    hiddenPrice.type = 'hidden';
    hiddenPrice.name = 'charge_price[]';
    hiddenPrice.value = c.price;
    hiddenInputs.appendChild(hiddenPrice);

    const hiddenRemarks = document.createElement('input');
    hiddenRemarks.type = 'hidden';
    hiddenRemarks.name = 'charge_remarks[]';
    hiddenRemarks.value = c.remarks;
    hiddenInputs.appendChild(hiddenRemarks);
  });
}

function removePendingCharge(index) {
  pendingCharges.splice(index, 1);
  renderPendingCharges();
}

addBtn.addEventListener('click', function () {
  errBox.style.display = 'none';

  const name = chargeNameInput.value.trim();
  const qty = parseFloat(qtyInput.value) || 0;
  const price = parseFloat(priceInput.value) || 0;
  const remarks = remarksInput.value.trim();

  if (!name) {
    errBox.textContent = 'Please choose a charge type or type an item name.';
    errBox.style.display = 'block';
    return;
  }
  if (qty <= 0) {
    errBox.textContent = 'Please enter a valid quantity.';
    errBox.style.display = 'block';
    return;
  }
  if (price < 0) {
    errBox.textContent = 'Unit price cannot be negative.';
    errBox.style.display = 'block';
    return;
  }

  pendingCharges.push({
    name: name,
    qty: qty,
    price: price,
    total: qty * price,
    remarks: remarks
  });

  renderPendingCharges();

  // Reset inputs
  presetSelect.value = '';
  chargeNameInput.value = '';
  qtyInput.value = '1';
  priceInput.value = '';
  totalInput.value = '';
  remarksInput.value = '';
});
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>

<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk']);

$pageTitle = 'Advance Booking';
$activeNav = 'advance_booking';

try { ensureRoomTypesMigrated(); } catch (Throwable $e) {}
$roomTypes = getRoomTypes();

// Load all non-maintenance rooms server-side (same as checkin.php approach)
$availableRooms = db()->query("
    SELECT r.id, r.room_number, r.room_type_id, r.status,
           COALESCE(rt.name, 'Standard') AS type_name,
           COALESCE(rt.base_rate, 0)     AS base_rate,
           COALESCE(rt.max_guests, 2)    AS max_guests
    FROM rooms r
    LEFT JOIN room_types rt ON rt.id = r.room_type_id
    WHERE r.status != 'maintenance'
    ORDER BY CAST(r.room_number AS UNSIGNED) ASC
")->fetchAll(PDO::FETCH_ASSOC);

$defaultCheckin  = date('Y-m-d');
$defaultCheckout = date('Y-m-d', strtotime('+1 day'));


require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-header">
  <div>
    <h2>Advance Booking / Reservation</h2>
    <div class="desc">Reserve one or multiple rooms ahead of time for a guest who has not arrived yet. No ID proof is required now — that is collected when the guest actually checks in.</div>
  </div>
  <div class="page-actions">
    <a href="<?= BASE_URL ?>/bookings.php?status=reserved" class="btn btn-outline">📅 View Reservations</a>
    <a href="<?= BASE_URL ?>/checkin.php" class="btn btn-outline">🛎️ Walk-In Check-In</a>
  </div>
</div>

<div class="alert alert-info">ℹ️ You can reserve multiple rooms in a single booking. The system checks exact stay dates against existing reservations and only lists available rooms for your chosen dates.</div>

<form method="post" action="<?= BASE_URL ?>/advance_booking_save.php" id="advanceForm">
  <?= csrfField() ?>

  <div class="card">
    <div class="card-head"><h3>1. Room &amp; Stay Details <span class="tag-note" style="margin-left:10px; font-weight:normal;">(Select 1 or multiple rooms for this reservation)</span></h3></div>

    <!-- Booking Type Selector -->
    <div style="background:var(--cream-light, #faf9f5); border:1.5px solid var(--border-color, #e5e0d8); border-radius:8px; padding:16px; margin-bottom:18px;">
      <label style="font-weight:700; margin-bottom:10px; display:block;">Booking Type *</label>
      <div style="display:flex; gap:24px; align-items:center;">
        <label style="cursor:pointer; display:flex; align-items:center; gap:6px; font-weight:600;">
          <input type="radio" name="booking_type" value="regular" checked onchange="toggleCorporateFields()">
          👤 Regular Guest
        </label>
        <label style="cursor:pointer; display:flex; align-items:center; gap:6px; font-weight:600;">
          <input type="radio" name="booking_type" value="corporate" onchange="toggleCorporateFields()">
          🏢 Corporate Booking
        </label>
      </div>

      <!-- Corporate Fields Container -->
      <div id="corporateFieldsSection" style="display:none; margin-top:16px; border-top:1px solid var(--border-color, #e5e0d8); padding-top:16px;">
        <h4 style="margin-bottom:12px; font-size:0.95rem; color:var(--gold);">🏢 Corporate Billing Details</h4>
        <div class="form-row">
          <div class="form-group">
            <label>Company Name <span class="text-danger">*</span></label>
            <input type="text" name="company_name" id="companyNameInput" class="form-control" placeholder="e.g. Tata Consultancy Services Ltd">
          </div>
          <div class="form-group">
            <label>Company GST Number <span class="text-danger">*</span></label>
            <input type="text" name="company_gst_number" id="companyGstInput" class="form-control" placeholder="e.g. 27AAACT1234A1Z5" style="text-transform:uppercase;">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Contact Person (optional)</label>
            <input type="text" name="company_contact_person" class="form-control" placeholder="e.g. Rajesh Kumar (HR Manager)">
          </div>
          <div class="form-group">
            <label>Company Phone (optional)</label>
            <input type="text" name="company_phone" class="form-control" placeholder="e.g. 022-66554433">
          </div>
        </div>
        <div class="form-group">
          <label>Company Address (optional)</label>
          <input type="text" name="company_address" class="form-control" placeholder="e.g. TCS House, Raveline Street, Fort, Mumbai 400001">
        </div>
      </div>
    </div>

    <!-- Stay Dates -->
    <div class="form-row">
      <div class="form-group">
        <label>Planned Check-In Date *</label>
        <input type="date" name="checkin_date" id="checkinDate" class="form-control" value="<?= $defaultCheckin ?>" min="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="form-group">
        <label>Planned Check-In Time</label>
        <input type="time" name="checkin_time" class="form-control" value="<?= e(getSetting('default_checkin_time', '12:00')) ?>">
      </div>
      <div class="form-group">
        <label>Expected Check-Out Date *</label>
        <input type="date" name="expected_checkout_date" id="checkoutDate" class="form-control" value="<?= $defaultCheckout ?>" min="<?= $defaultCheckin ?>" required>
      </div>
    </div>

    <!-- Multi-Room Selector -->
    <div style="background:var(--cream-light, #faf9f5); border:1.5px solid var(--border-color, #e5e0d8); border-radius:8px; padding:16px; margin-bottom:18px;">
      <h4 style="margin-bottom:12px; font-size:0.95rem; color:var(--text-color);">➕ Select Room &amp; Add to Reservation</h4>
      <div class="form-row" style="align-items:end;">
        <div class="form-group">
          <label>Room Type</label>
          <select id="roomTypeSelect" class="form-control">
            <option value="">-- Choose Room Type --</option>
            <?php foreach ($roomTypes as $t): ?>
              <option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Available Room Number</label>
          <select id="roomSelect" class="form-control" disabled>
            <option value="">-- Select Room Type first --</option>
          </select>
        </div>
        <div class="form-group">
          <label>Rate Per Night (₹)</label>
          <input type="number" id="rateInput" class="form-control" step="0.01" min="0" placeholder="0.00">
        </div>
        <div class="form-group">
          <button type="button" id="addRoomBtn" class="btn btn-gold" style="white-space:nowrap; width:100%;">➕ Add Room</button>
        </div>
      </div>
      <div id="roomSelectError" style="display:none; margin-top:8px; font-size:0.88rem; color:var(--red, #d9534f); font-weight:bold;"></div>
    </div>

    <!-- Selected Rooms Summary List -->
    <div style="margin-bottom:20px;">
      <label style="font-weight:700; margin-bottom:8px; display:block;">Selected Rooms for this Reservation *</label>
      <div class="table-wrap">
        <table class="data-table" id="selectedRoomsTable">
          <thead>
            <tr>
              <th>Room Number</th>
              <th>Room Type</th>
              <th>Rate Per Night (₹)</th>
              <th style="width:100px; text-align:right;">Action</th>
            </tr>
          </thead>
          <tbody id="selectedRoomsBody">
            <tr id="emptyRoomsRow">
              <td colspan="4" class="text-muted" style="text-align:center; padding:18px;">
                No rooms added yet. Select dates above, choose a Room Type and Room Number, then click <strong>➕ Add Room</strong>.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <!-- Hidden inputs for backend form processing: room_ids[], room_rates[] -->
      <div id="selectedRoomsHiddenInputs"></div>
    </div>

    <!-- Booking Summary Widget -->
    <div id="bookingSummaryCard" class="card" style="background:var(--cream-light, #faf9f5); border:1.5px solid var(--gold-pale, #d4af37); box-shadow:none; margin-top:16px;">
      <h4 style="margin-bottom:12px; font-size:1rem; color:var(--gold, #996515); font-family:'Playfair Display', serif;">📋 Booking Summary</h4>
      <div class="stat-row"><span class="lbl">Total Rooms Selected</span><span class="val" id="summaryTotalRooms">0</span></div>
      <div class="stat-row"><span class="lbl">Selected Room Numbers</span><span class="val" id="summaryRoomNumbers">None</span></div>
      <div class="stat-row"><span class="lbl">Room Types</span><span class="val" id="summaryRoomTypes">None</span></div>
      <div class="stat-row"><span class="lbl">Duration of Stay</span><span class="val" id="summaryNights">1 night</span></div>
      <div class="stat-row"><span class="lbl">Total Nightly Rate (All Rooms)</span><span class="val" id="summaryNightlyTotal">₹0.00</span></div>
      <div class="stat-row"><span class="lbl">Total Room Charges</span><span class="val" id="summaryRoomCharges">₹0.00</span></div>
      <div class="stat-row"><span class="lbl">Additional Charges</span><span class="val" id="summaryExtraCharges">₹0.00</span></div>
      <div class="stat-row"><span class="lbl">Taxes (<span id="summaryTaxPercentDisplay">12</span>% GST)</span><span class="val" id="summaryTaxAmount">₹0.00</span></div>
      <div class="stat-row total" style="border-top:2px solid var(--border-color, #e5e0d8); padding-top:8px; margin-top:8px;"><span class="lbl" style="font-weight:700; font-size:1.05rem;">Grand Total</span><span class="val" id="summaryGrandTotal" style="font-weight:700; font-size:1.15rem; color:var(--gold, #996515);">₹0.00</span></div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Number of Guests (approx.) *</label>
        <input type="number" name="num_guests" class="form-control" value="1" min="1" max="12" required>
      </div>
      <div class="form-group">
        <label>Advance / Token Amount Received</label>
        <input type="number" name="advance_amount" id="advanceAmountInput" class="form-control" step="0.01" min="0" value="0">
      </div>
      <div class="form-group">
        <label>Tax % (GST)</label>
        <input type="number" name="tax_percent" id="taxPercentInput" class="form-control" step="0.01" min="0" value="<?= e(getSetting('default_tax_percent','12')) ?>">
      </div>
    </div>
    <div class="form-group">
      <label>Special Requests / Notes</label>
      <textarea name="special_requests" class="form-control" placeholder="Special requests or guest preferences (near-temple view, high floor, etc.)"></textarea>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>2. Additional Charges (Optional)</h3></div>
    <div style="background:var(--cream-light,#faf9f5);border:1.5px solid var(--border-color,#e5e0d8);border-radius:8px;padding:16px;margin-bottom:18px;">
      <div class="form-row" style="align-items:end;">
        <div class="form-group" style="flex:2;">
          <label>Additional Charge</label>
          <select id="ab_presetSelect" class="form-control">
            <option value="">-- Select Additional Charge --</option>
            <option value="Tea" data-price="50">Tea</option>
            <option value="Coffee / Milk" data-price="40">Coffee / Milk</option>
            <option value="Extra Bed" data-price="500">Extra Bed</option>
          </select>
        </div>
        <div class="form-group" style="flex:2;">
          <label>Item Name</label>
          <input type="text" id="ab_chargeNameInput" class="form-control" placeholder="Select from dropdown or type...">
        </div>
        <div class="form-group" style="flex:1;">
          <label>Qty</label>
          <input type="number" id="ab_qtyInput" class="form-control" value="1" min="0.1" step="0.1" oninput="ab_recalcTotal()">
        </div>
        <div class="form-group" style="flex:1.5;">
          <label>Amount (₹)</label>
          <input type="number" id="ab_priceInput" class="form-control" step="0.01" min="0" placeholder="0.00" oninput="ab_recalcTotal()">
        </div>
        <div class="form-group" style="flex:1.5;">
          <label>Total (₹)</label>
          <input type="number" id="ab_totalInput" class="form-control" readonly style="background:#f5f3ef;font-weight:bold;">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label>Remarks (optional)</label>
          <input type="text" id="ab_remarksInput" class="form-control" placeholder="Optional notes">
        </div>
        <div class="form-group" style="align-self:flex-end;max-width:200px;">
          <button type="button" id="ab_addChargeBtn" class="btn btn-gold" style="width:100%;white-space:nowrap;">&#10133; Add Another Charge</button>
        </div>
      </div>
      <div id="ab_chargeError" style="display:none;margin-top:8px;font-size:0.88rem;color:var(--red,#d9534f);font-weight:bold;"></div>
    </div>
    <div>
      <label style="font-weight:700;margin-bottom:8px;display:block;">Selected Charges</label>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr>
            <th>Item / Charge</th><th class="text-right">Qty</th><th class="text-right">Amount (₹)</th><th class="text-right">Total (₹)</th><th>Remarks</th><th style="width:80px;text-align:right;">Action</th>
          </tr></thead>
          <tbody id="ab_chargesTableBody">
            <tr id="ab_emptyRow"><td colspan="6" class="text-muted" style="text-align:center;padding:14px;">No charges added yet.</td></tr>
          </tbody>
        </table>
      </div>
      <div id="ab_hiddenChargeInputs"></div>
    </div>
  </div>

  <div class="card" style="border:1.5px solid var(--gold-pale);">
    <div class="card-head">
      <h3>3. Booking Source &amp; Commission <span class="internal-only-tag">🔒 Internal Only — Never Shown to Guest</span></h3>
    </div>
    <div class="form-row-3">
      <div class="form-group">
        <label>Booking Source</label>
        <select name="booking_source" id="bookingSource" class="form-control">
          <option value="walk_in">Walk-In</option>
          <option value="phone" selected>Phone Booking</option>
          <option value="online">Online / Website</option>
          <option value="agent">Travel Agent</option>
          <option value="clear_trip">Clear Trip</option>
          <option value="ota_mmt">OTA — MakeMyTrip</option>
          <option value="ota_goibibo">OTA — Goibibo</option>
          <option value="ota_booking_com">OTA — Booking.com</option>
          <option value="ota_other">OTA — Other</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div class="form-group">
        <label>Agent / OTA Name</label>
        <input type="text" name="agent_or_ota_name" class="form-control" placeholder="e.g. Ramesh Travels">
      </div>
      <div class="form-group">
        <label>Commission %</label>
        <input type="number" name="commission_percent" id="commissionPercent" class="form-control" step="0.01" min="0" max="100" value="0">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Commission Amount (auto-calculated, editable)</label>
        <input type="number" name="commission_amount" id="commissionAmount" class="form-control" step="0.01" min="0" value="0">
      </div>
      <div class="form-group">
        <label>Commission Status</label>
        <select name="commission_status" class="form-control">
          <option value="not_applicable">Not Applicable</option>
          <option value="pending">Pending Payment</option>
          <option value="paid">Already Paid</option>
        </select>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>4. Contact / Primary Guest Details</h3></div>
    <p class="tag-note" style="margin-bottom:14px;">Just the contact person's details for now. Full guest list and ID proof photos are collected when the guest actually arrives and checks in.</p>
    <div class="form-row">
      <div class="form-group">
        <label>Full Name *</label>
        <input type="text" name="guest_name" class="form-control" required>
      </div>
      <div class="form-group">
        <label>Phone Number *</label>
        <input type="tel" name="guest_phone" class="form-control" placeholder="10-digit mobile" required>
      </div>
      <div class="form-group">
        <label>Email</label>
        <input type="email" name="guest_email" class="form-control">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>City</label>
        <input type="text" name="guest_city" class="form-control">
      </div>
      <div class="form-group">
        <label>State</label>
        <input type="text" name="guest_state" class="form-control">
      </div>
    </div>
  </div>

  <div class="card" style="text-align:right;">
    <button type="submit" class="btn btn-gold" style="min-width:240px;">📅 Confirm Advance Booking</button>
  </div>
</form>

<script>
const BASE_URL = "<?= BASE_URL ?>";
const AVAILABLE_ROOMS = <?= json_encode(array_values($availableRooms)) ?>;
const roomTypeSelect = document.getElementById('roomTypeSelect');
const roomSelect     = document.getElementById('roomSelect');
const rateInput      = document.getElementById('rateInput');
const addRoomBtn     = document.getElementById('addRoomBtn');
const roomSelectError = document.getElementById('roomSelectError');
const checkinDate    = document.getElementById('checkinDate');
const checkoutDate   = document.getElementById('checkoutDate');
const commissionPercent = document.getElementById('commissionPercent');
const commissionAmount  = document.getElementById('commissionAmount');
const taxPercentInput   = document.getElementById('taxPercentInput');

// Multi-room map: key -> { roomId, roomNumber, typeName, rate, roomTypeId }
const selectedRoomsMap = new Map();
let additionalChargesTotal = 0;


function escapeHtml(unsafe) {
  if (!unsafe) return '';
  return unsafe.toString()
     .replace(/&/g, "&amp;")
     .replace(/</g, "&lt;")
     .replace(/>/g, "&gt;")
     .replace(/"/g, "&quot;")
     .replace(/'/g, "&#039;");
}

function updateAvailableRoomDropdown() {
  const selectedTypeId = roomTypeSelect.value;
  roomSelect.innerHTML = '<option value="">-- Unassigned (Room Type Only) --</option>';

  if (!selectedTypeId) {
    roomSelect.disabled = true;
    rateInput.value = '';
    return;
  }

  const filtered = AVAILABLE_ROOMS.filter(r => String(r.room_type_id) === String(selectedTypeId));

  filtered.forEach(r => {
    const isAlreadySelected = Array.from(selectedRoomsMap.values()).some(sr => sr.roomId === parseInt(r.id));
    if (isAlreadySelected) return;

    const opt = document.createElement('option');
    opt.value      = r.id;
    opt.dataset.rate     = r.base_rate;
    opt.dataset.number   = r.room_number;
    opt.dataset.typename = r.type_name;
    const statusLabel = r.status === 'available' ? '✅ Available' : ('⚠️ ' + r.status);
    opt.textContent = 'Room ' + r.room_number + ' — ' + statusLabel;
    if (r.status !== 'available') opt.style.color = '#999';
    roomSelect.appendChild(opt);
  });

  if (filtered.length === 0) {
    const opt = document.createElement('option');
    opt.value = '';
    opt.textContent = '— No rooms found for this type —';
    opt.disabled = true;
    roomSelect.appendChild(opt);
  }

  roomSelect.disabled = false;

  // Auto-fill rate from first available room for this type
  const firstAvail = filtered.find(r => r.status === 'available');
  if (firstAvail && !rateInput.value) {
    rateInput.value = firstAvail.base_rate;
  }
}

function renderSelectedRooms() {
  const body = document.getElementById('selectedRoomsBody');
  const hiddenInputs = document.getElementById('selectedRoomsHiddenInputs');
  body.innerHTML = '';
  hiddenInputs.innerHTML = '';

  if (selectedRoomsMap.size === 0) {
    body.innerHTML = '<tr id="emptyRoomsRow"><td colspan="4" class="text-muted" style="text-align:center; padding:18px;">No rooms added yet. Select a Room Type above and click <strong>➕ Add Room</strong> (Room number is optional).</td></tr>';
    recalcSummary();
    return;
  }

  selectedRoomsMap.forEach((room, key) => {
    const tr = document.createElement('tr');
    const roomDisplay = room.roomId > 0 ? ('Room ' + escapeHtml(room.roomNumber)) : '<span class="badge badge-gray">Unassigned</span>';
    tr.innerHTML = `
      <td><strong>${roomDisplay}</strong></td>
      <td>${escapeHtml(room.typeName)}</td>
      <td>
        <input type="number" class="form-control form-control-sm" style="max-width:140px; display:inline-block;" step="0.01" min="0" value="${room.rate}" onchange="updateRoomRate('${key}', this.value)">
      </td>
      <td style="text-align:right;">
        <button type="button" class="btn btn-sm btn-red" onclick="removeSelectedRoom('${key}')">✕ Remove</button>
      </td>
    `;
    body.appendChild(tr);

    // Hidden inputs for backend form processing
    const inputRoom = document.createElement('input');
    inputRoom.type = 'hidden';
    inputRoom.name = 'room_ids[]';
    inputRoom.value = room.roomId || '';
    hiddenInputs.appendChild(inputRoom);

    const inputRate = document.createElement('input');
    inputRate.type = 'hidden';
    inputRate.name = 'room_rates[]';
    inputRate.value = room.rate;
    hiddenInputs.appendChild(inputRate);

    const inputType = document.createElement('input');
    inputType.type = 'hidden';
    inputType.name = 'room_type_ids[]';
    inputType.value = room.roomTypeId || '';
    hiddenInputs.appendChild(inputType);
  });

  recalcSummary();
}

function updateRoomRate(key, newRate) {
  if (selectedRoomsMap.has(key)) {
    selectedRoomsMap.get(key).rate = parseFloat(newRate) || 0;
    renderSelectedRooms();
  }
}

function removeSelectedRoom(key) {
  selectedRoomsMap.delete(key);
  renderSelectedRooms();
  updateAvailableRoomDropdown();
}

function getTotalNightlyRate() {
  let sum = 0;
  selectedRoomsMap.forEach(r => sum += r.rate);
  return sum;
}

function calcNights() {
  if (!checkinDate.value || !checkoutDate.value) return 1;
  const d1 = new Date(checkinDate.value);
  const d2 = new Date(checkoutDate.value);
  const diffTime = d2 - d1;
  const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
  return Math.max(1, diffDays);
}

function recalcSummary() {
  const totalRooms = selectedRoomsMap.size;
  const roomNumbers = Array.from(selectedRoomsMap.values()).map(r => r.roomId > 0 ? ('Room ' + r.roomNumber) : 'Unassigned').join(', ') || 'None';
  const roomTypesSet = new Set(Array.from(selectedRoomsMap.values()).map(r => r.typeName));
  const roomTypesStr = Array.from(roomTypesSet).join(', ') || 'None';
  
  const nights = calcNights();
  const totalNightlyRate = getTotalNightlyRate();
  const roomCharges = totalNightlyRate * nights;
  
  const taxPct = parseFloat(taxPercentInput.value) || 0;
  const taxable = roomCharges + additionalChargesTotal;
  const taxAmount = (taxable * taxPct) / 100;
  const grandTotal = taxable + taxAmount;

  document.getElementById('summaryTotalRooms').textContent = totalRooms;
  document.getElementById('summaryRoomNumbers').textContent = roomNumbers;
  document.getElementById('summaryRoomTypes').textContent = roomTypesStr;
  document.getElementById('summaryNights').textContent = nights + (nights === 1 ? ' night' : ' nights');
  document.getElementById('summaryNightlyTotal').textContent = '₹' + totalNightlyRate.toFixed(2);
  document.getElementById('summaryRoomCharges').textContent = '₹' + roomCharges.toFixed(2);
  document.getElementById('summaryExtraCharges').textContent = '₹' + additionalChargesTotal.toFixed(2);
  document.getElementById('summaryTaxPercentDisplay').textContent = taxPct;
  document.getElementById('summaryTaxAmount').textContent = '₹' + taxAmount.toFixed(2);
  document.getElementById('summaryGrandTotal').textContent = '₹' + grandTotal.toFixed(2);

  recalcCommission();
}

function recalcCommission() {
  const rate = getTotalNightlyRate();
  const pct = parseFloat(commissionPercent.value) || 0;
  commissionAmount.value = ((rate * pct) / 100).toFixed(2);
}

roomTypeSelect.addEventListener('change', updateAvailableRoomDropdown);

roomSelect.addEventListener('change', function () {
  const opt = this.options[this.selectedIndex];
  if (opt && opt.dataset && opt.dataset.rate) {
    rateInput.value = opt.dataset.rate;
  }
});

addRoomBtn.addEventListener('click', function () {
  roomSelectError.style.display = 'none';

  const selectedTypeId = roomTypeSelect.value;
  if (!selectedTypeId) {
    roomSelectError.textContent = 'Please select a Room Type first.';
    roomSelectError.style.display = 'block';
    return;
  }

  const selectedTypeOpt = roomTypeSelect.options[roomTypeSelect.selectedIndex];
  const typeName = selectedTypeOpt ? selectedTypeOpt.text : 'Room';
  
  const rawRoomVal = roomSelect.value;
  const roomId = parseInt(rawRoomVal) || 0;
  
  if (roomId > 0 && Array.from(selectedRoomsMap.values()).some(sr => sr.roomId === roomId)) {
    roomSelectError.textContent = 'This room number has already been added to the reservation.';
    roomSelectError.style.display = 'block';
    return;
  }

  const opt = roomSelect.selectedIndex >= 0 ? roomSelect.options[roomSelect.selectedIndex] : null;
  let roomNumber = 'Unassigned';
  let rate = parseFloat(rateInput.value) || 0;

  if (opt && opt.dataset && opt.dataset.number) {
    roomNumber = opt.dataset.number;
    if (!rate && opt.dataset.rate) {
      rate = parseFloat(opt.dataset.rate) || 0;
    }
  }

  const key = roomId > 0 ? String(roomId) : ('unassigned_' + Date.now() + '_' + Math.random().toString(36).substr(2, 4));

  selectedRoomsMap.set(key, {
    key: key,
    roomId: roomId,
    roomNumber: roomNumber,
    typeName: typeName,
    rate: rate,
    roomTypeId: selectedTypeId
  });

  renderSelectedRooms();
  updateAvailableRoomDropdown();
  roomSelect.value = '';
  rateInput.value = '';
});

checkinDate.addEventListener('change', function () {
  checkoutDate.min = this.value;
  if (checkoutDate.value < this.value) {
    checkoutDate.value = this.value;
  }
  // Re-filter dropdown with current static list (no AJAX needed)
  updateAvailableRoomDropdown();
});

checkoutDate.addEventListener('change', function () {
  updateAvailableRoomDropdown();
});

taxPercentInput.addEventListener('input', recalcSummary);
commissionPercent.addEventListener('input', recalcCommission);

document.getElementById('advanceForm').addEventListener('submit', function (e) {
  if (selectedRoomsMap.size === 0) {
    const selectedTypeId = roomTypeSelect.value;
    if (selectedTypeId) {
      // Auto-add room type reservation if user didn't explicitly click "Add Room"
      addRoomBtn.click();
    } else {
      e.preventDefault();
      alert('Please select a Room Type for the reservation before saving.');
      return false;
    }
  }
});

function toggleCorporateFields() {
  const isCorporate = document.querySelector('input[name="booking_type"]:checked').value === 'corporate';
  const sec = document.getElementById('corporateFieldsSection');
  const compInput = document.getElementById('companyNameInput');
  const gstInput = document.getElementById('companyGstInput');

  if (isCorporate) {
    sec.style.display = 'block';
    compInput.required = true;
    gstInput.required = true;
  } else {
    sec.style.display = 'none';
    compInput.required = false;
    gstInput.required = false;
  }
}

// ---- Additional Charges Widget ----
(function(){
  const pSel   = document.getElementById('ab_presetSelect');
  const nInp   = document.getElementById('ab_chargeNameInput');
  const qInp   = document.getElementById('ab_qtyInput');
  const pInp   = document.getElementById('ab_priceInput');
  const tInp   = document.getElementById('ab_totalInput');
  const rInp   = document.getElementById('ab_remarksInput');
  const aBtn   = document.getElementById('ab_addChargeBtn');
  const eBox   = document.getElementById('ab_chargeError');
  const tbody  = document.getElementById('ab_chargesTableBody');
  const hidden = document.getElementById('ab_hiddenChargeInputs');
  const items  = [];

  pSel.addEventListener('change', function(){
    if(this.value){ nInp.value=this.value; const o=this.options[this.selectedIndex]; if(o.dataset.price){pInp.value=o.dataset.price;ab_recalc();} }
  });
  window.ab_recalcTotal = function(){ ab_recalc(); };
  function ab_recalc(){ tInp.value=((parseFloat(qInp.value)||0)*(parseFloat(pInp.value)||0)).toFixed(2); }
  function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
  function render(){
    tbody.innerHTML=''; hidden.innerHTML='';
    additionalChargesTotal = 0;
    if(!items.length){
      tbody.innerHTML='<tr id="ab_emptyRow"><td colspan="6" class="text-muted" style="text-align:center;padding:14px;">No charges added yet.</td></tr>';
      recalcSummary();
      return;
    }
    items.forEach((c,i)=>{
      additionalChargesTotal += c.total;
      const tr=document.createElement('tr');
      tr.innerHTML=`<td><strong>${esc(c.name)}</strong></td><td class="text-right">${c.qty}</td><td class="text-right">&#8377;${c.price.toFixed(2)}</td><td class="text-right"><strong>&#8377;${c.total.toFixed(2)}</strong></td><td>${esc(c.rem||'—')}</td><td style="text-align:right;"><button type="button" class="btn btn-sm btn-red" onclick="ab_remove(${i})">✕</button></td>`;
      tbody.appendChild(tr);
      const mk=(n,v)=>{const el=document.createElement('input');el.type='hidden';el.name=n;el.value=v;hidden.appendChild(el);};
      mk('charge_name[]',c.name); mk('charge_qty[]',c.qty); mk('charge_price[]',c.price); mk('charge_remarks[]',c.rem);
    });
    recalcSummary();
  }
  window.ab_remove=function(i){ items.splice(i,1); render(); };
  aBtn.addEventListener('click',function(){
    eBox.style.display='none';
    const n=nInp.value.trim(), q=parseFloat(qInp.value)||0, p=parseFloat(pInp.value)||0;
    if(!n){eBox.textContent='Please select or type a charge.';eBox.style.display='block';return;}
    if(q<=0){eBox.textContent='Please enter a valid quantity.';eBox.style.display='block';return;}
    if(p<0){eBox.textContent='Amount cannot be negative.';eBox.style.display='block';return;}
    items.push({name:n,qty:q,price:p,total:q*p,rem:rInp.value.trim()});
    render();
    pSel.value=''; nInp.value=''; qInp.value='1'; pInp.value=''; tInp.value=''; rInp.value='';
  });
})();

// Rooms already embedded in AVAILABLE_ROOMS — no fetch needed on load

</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>

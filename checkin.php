<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk']);

$pageTitle = 'Check-In';
$activeNav = 'checkin';

$roomTypes = getRoomTypes();
$availableRooms = db()->query("
  SELECT r.*, rt.name AS type_name, rt.base_rate, rt.max_guests
  FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
  WHERE r.status = 'available'
  ORDER BY r.room_number ASC
")->fetchAll();

$activeReservedRoomIds = getActiveReservationsToday();
$availableRooms = array_filter($availableRooms, function($r) use ($activeReservedRoomIds) {
    return !in_array((int)$r['id'], $activeReservedRoomIds, true);
});
$availableRooms = array_values($availableRooms);

$defaultCheckoutDate = date('Y-m-d', strtotime('+1 day'));

require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-header">
  <div>
    <h2>New Guest Check-In</h2>
    <div class="desc">Register guest details, assign a room and complete check-in.</div>
  </div>
  <div class="page-actions">
    <a href="<?= BASE_URL ?>/bookings.php" class="btn btn-outline">📋 View All Bookings</a>
  </div>
</div>

<?php if (empty($availableRooms)): ?>
  <div class="alert alert-warning">⚠️ No rooms are currently available. Please free up a room or add new rooms from <a href="<?= BASE_URL ?>/rooms.php" style="text-decoration:underline;">Room Management</a>.</div>
<?php endif; ?>

<form method="post" action="<?= BASE_URL ?>/checkin_save.php" enctype="multipart/form-data" id="checkinForm">
  <?= csrfField() ?>

  <div class="card">
    <div class="card-head">
      <h3>1. Room &amp; Stay Details <span class="tag-note" style="margin-left:10px; font-weight:normal;">(Select 1 or multiple rooms for this guest)</span></h3>
    </div>

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
    
    <!-- Multi-Room Selector -->
    <div style="background:var(--cream-light, #faf9f5); border:1.5px solid var(--border-color, #e5e0d8); border-radius:8px; padding:16px; margin-bottom:18px;">
      <h4 style="margin-bottom:12px; font-size:0.95rem; color:var(--text-color);">➕ Select Room &amp; Add to Stay</h4>
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
      <label style="font-weight:700; margin-bottom:8px; display:block;">Selected Rooms for this Stay *</label>
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
                No rooms added yet. Select a Room Type and Room Number above, then click <strong>➕ Add Room</strong>.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <!-- Hidden inputs for backend form processing: room_ids[], room_rates[] -->
      <div id="selectedRoomsHiddenInputs"></div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Expected Check-Out Date *</label>
        <input type="date" name="expected_checkout_date" class="form-control" value="<?= $defaultCheckoutDate ?>" min="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="form-group">
        <label>Number of Guests *</label>
        <input type="number" name="num_guests" id="numGuestsInput" class="form-control" value="1" min="1" max="12" required>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Advance / Token Amount Received</label>
        <input type="number" name="advance_amount" class="form-control" step="0.01" min="0" value="0">
      </div>
      <div class="form-group">
        <label>Tax % (GST)</label>
        <input type="number" name="tax_percent" class="form-control" step="0.01" min="0" value="<?= e(getSetting('default_tax_percent','12')) ?>">
      </div>
    </div>
    <div class="form-group">
      <label>Special Requests / Notes</label>
      <textarea name="special_requests" class="form-control" placeholder="Special requests or guest preferences (near-temple view, high floor, etc.)"></textarea>
    </div>
  </div>

  <div class="card" style="border:1.5px solid var(--gold-pale);">
    <div class="card-head">
      <h3>2. Booking Source &amp; Commission <span class="internal-only-tag">🔒 Internal Only — Never Shown to Guest</span></h3>
    </div>
    <p class="tag-note" style="margin-bottom:14px;">This section is for internal office use. At check-out, the commission amount is silently added into the guest's Room Charges — the guest only ever sees one combined room amount, never a separate commission line. The true split (actual room revenue vs. commission) is only visible here, on the dashboard and in reports.</p>
    <div class="form-row-3">
      <div class="form-group">
        <label>Booking Source</label>
        <select name="booking_source" id="bookingSource" class="form-control">
          <option value="walk_in">Walk-In</option>
          <option value="phone">Phone Booking</option>
          <option value="online">Online / Website</option>
          <option value="clear_trip">Clear Trip</option>
          <option value="agent">Travel Agent</option>
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
    <div class="tag-note" id="commissionPreview" style="margin-top:6px;"></div>
  </div>

  <div class="card">
    <div class="card-head"><h3>3. Additional Charges</h3></div>
    <div style="background:var(--cream-light, #faf9f5); border:1.5px solid var(--border-color, #e5e0d8); border-radius:8px; padding:16px; margin-bottom:18px;">
      <div class="form-row" style="align-items:end;">
        <div class="form-group" style="flex:2;">
          <label>Additional Charge</label>
          <select id="ci_presetSelect" class="form-control">
            <option value="">-- Select Additional Charge --</option>
            <option value="Tea" data-price="50">Tea</option>
            <option value="Coffee / Milk" data-price="40">Coffee / Milk</option>
            <option value="Extra Bed" data-price="500">Extra Bed</option>
          </select>
        </div>
        <div class="form-group" style="flex:2;">
          <label>Item Name *</label>
          <input type="text" id="ci_chargeNameInput" class="form-control" placeholder="Select from dropdown or type...">
        </div>
        <div class="form-group" style="flex:1;">
          <label>Qty *</label>
          <input type="number" id="ci_qtyInput" class="form-control" value="1" min="0.1" step="0.1" oninput="ci_recalcTotal()">
        </div>
        <div class="form-group" style="flex:1.5;">
          <label>Amount (₹) *</label>
          <input type="number" id="ci_priceInput" class="form-control" step="0.01" min="0" placeholder="0.00" oninput="ci_recalcTotal()">
        </div>
        <div class="form-group" style="flex:1.5;">
          <label>Total (₹)</label>
          <input type="number" id="ci_totalInput" class="form-control" readonly style="background:#f5f3ef; font-weight:bold;">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label>Remarks (optional)</label>
          <input type="text" id="ci_remarksInput" class="form-control" placeholder="Optional notes">
        </div>
        <div class="form-group" style="align-self:flex-end; max-width:200px;">
          <button type="button" id="ci_addChargeBtn" class="btn btn-gold" style="width:100%; white-space:nowrap;">&#10133; Add Another Charge</button>
        </div>
      </div>
      <div id="ci_chargeError" style="display:none; margin-top:8px; font-size:0.88rem; color:var(--red, #d9534f); font-weight:bold;"></div>
    </div>
    <div>
      <label style="font-weight:700; margin-bottom:8px; display:block;">Selected Charges</label>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr>
            <th>Item / Charge</th><th class="text-right">Qty</th><th class="text-right">Amount (₹)</th><th class="text-right">Total (₹)</th><th>Remarks</th><th style="width:80px; text-align:right;">Action</th>
          </tr></thead>
          <tbody id="ci_chargesTableBody">
            <tr id="ci_emptyRow"><td colspan="6" class="text-muted" style="text-align:center; padding:14px;">No charges added yet.</td></tr>
          </tbody>
        </table>
      </div>
      <div id="ci_hiddenChargeInputs"></div>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <h3>3. Guest Details &amp; ID Proof</h3>
    </div>
    <p class="tag-note" style="margin-bottom:14px;">The primary guest's photo ID is mandatory. Use "Add Guest" below if more than one person will be staying in this room.</p>
    <div id="guestsContainer"></div>
    <button type="button" id="addGuestBtn" class="btn btn-outline btn-sm">➕ Add Another Guest</button>
  </div>

  <div class="card" style="text-align:right;">
    <button type="submit" class="btn btn-gold" style="min-width:220px;">✅ Complete Check-In</button>
  </div>
</form>

<!-- Template for a guest block (cloned by JS) -->
<template id="guestTemplate">
  <div class="guest-block">
    <div class="gb-title">
      <span class="gb-heading-text">Guest</span>
      <button type="button" class="remove-guest-btn" title="Remove guest">✕</button>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Full Name *</label>
        <input type="text" name="guest_name[]" class="form-control" required>
      </div>
      <div class="form-group">
        <label>Phone Number</label>
        <input type="tel" name="guest_phone[]" class="form-control" placeholder="10-digit mobile">
      </div>
      <div class="form-group">
        <label>Age</label>
        <input type="number" name="guest_age[]" class="form-control" min="0" max="120">
      </div>
    </div>
    <div class="form-row-3">
      <div class="form-group">
        <label>Gender</label>
        <select name="guest_gender[]" class="form-control">
          <option value="">--</option>
          <option value="male">Male</option>
          <option value="female">Female</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div class="form-group">
        <label>City</label>
        <input type="text" name="guest_city[]" class="form-control">
      </div>
      <div class="form-group">
        <label>State</label>
        <input type="text" name="guest_state[]" class="form-control">
      </div>
    </div>
    <div class="form-group">
      <label>Address</label>
      <input type="text" name="guest_address[]" class="form-control">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Email</label>
        <input type="email" name="guest_email[]" class="form-control">
      </div>
      <div class="form-group">
        <label class="id-req-label">ID Proof Type</label>
        <select name="guest_id_type[]" class="form-control">
          <option value="">-- Select --</option>
          <option value="aadhar">Aadhar Card</option>
          <option value="pan">PAN Card</option>
          <option value="passport">Passport</option>
          <option value="driving_license">Driving License</option>
          <option value="voter_id">Voter ID</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div class="form-group">
        <label class="id-req-label">ID Proof Number</label>
        <input type="text" name="guest_id_number[]" class="form-control" placeholder="Enter ID number">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>ID Proof Photo (Front) — JPG/PNG/PDF, max 5MB</label>
        <input type="file" name="guest_id_photo[]" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
      </div>
      <div class="form-group">
        <label>ID Proof Photo (Back) — optional</label>
        <input type="file" name="guest_id_photo_back[]" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
      </div>
    </div>
  </div>
</template>

<script>
const AVAILABLE_ROOMS = <?= json_encode($availableRooms) ?>;
const roomTypeSelect = document.getElementById('roomTypeSelect');
const roomSelect = document.getElementById('roomSelect');
const rateInput = document.getElementById('rateInput');
const addRoomBtn = document.getElementById('addRoomBtn');
const roomSelectError = document.getElementById('roomSelectError');
const numGuestsInput = document.getElementById('numGuestsInput');
const guestsContainer = document.getElementById('guestsContainer');
const guestTemplate = document.getElementById('guestTemplate');
const addGuestBtn = document.getElementById('addGuestBtn');
const commissionPercent = document.getElementById('commissionPercent');
const commissionAmount = document.getElementById('commissionAmount');
const bookingSource = document.getElementById('bookingSource');

// Multi-room map: roomId -> { roomId, roomNumber, typeName, rate }
const selectedRoomsMap = new Map();

function escapeHtml(unsafe) {
  if (!unsafe) return '';
  return unsafe.toString()
     .replace(/&/g, "&amp;")
     .replace(/</g, "&lt;")
     .replace(/>/g, "&gt;")
     .replace(/"/g, "&quot;")
     .replace(/'/g, "&#039;");
}

function renderSelectedRooms() {
  const body = document.getElementById('selectedRoomsBody');
  const hiddenInputs = document.getElementById('selectedRoomsHiddenInputs');
  body.innerHTML = '';
  hiddenInputs.innerHTML = '';

  if (selectedRoomsMap.size === 0) {
    body.innerHTML = '<tr id="emptyRoomsRow"><td colspan="4" class="text-muted" style="text-align:center; padding:18px;">No rooms added yet. Select a Room Type and Room Number above, then click <strong>➕ Add Room</strong>.</td></tr>';
    recalcCommission();
    return;
  }

  selectedRoomsMap.forEach((room, id) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><strong>Room ${escapeHtml(room.roomNumber)}</strong></td>
      <td>${escapeHtml(room.typeName)}</td>
      <td>
        <input type="number" class="form-control form-control-sm" style="max-width:140px; display:inline-block;" step="0.01" min="0" value="${room.rate}" onchange="updateRoomRate(${id}, this.value)">
      </td>
      <td style="text-align:right;">
        <button type="button" class="btn btn-sm btn-red" onclick="removeSelectedRoom(${id})">✕ Remove</button>
      </td>
    `;
    body.appendChild(tr);

    // Hidden inputs for backend processing
    const inputRoom = document.createElement('input');
    inputRoom.type = 'hidden';
    inputRoom.name = 'room_ids[]';
    inputRoom.value = id;
    hiddenInputs.appendChild(inputRoom);

    const inputRate = document.createElement('input');
    inputRate.type = 'hidden';
    inputRate.name = 'room_rates[]';
    inputRate.value = room.rate;
    hiddenInputs.appendChild(inputRate);
  });

  recalcCommission();
}

function updateRoomRate(roomId, newRate) {
  if (selectedRoomsMap.has(roomId)) {
    selectedRoomsMap.get(roomId).rate = parseFloat(newRate) || 0;
    renderSelectedRooms();
  }
}

function removeSelectedRoom(roomId) {
  selectedRoomsMap.delete(roomId);
  renderSelectedRooms();
  updateAvailableRoomDropdown();
}

function updateAvailableRoomDropdown() {
  const selectedTypeId = roomTypeSelect.value;
  roomSelect.innerHTML = '<option value="">-- Choose Room Number --</option>';
  
  if (!selectedTypeId) {
    roomSelect.disabled = true;
    rateInput.value = '';
    return;
  }
  
  const filtered = AVAILABLE_ROOMS.filter(r => r.room_type_id == selectedTypeId && r.status === 'available' && !selectedRoomsMap.has(parseInt(r.id)));
  
  if (filtered.length === 0) {
    roomSelect.innerHTML = '<option value="">No available rooms for selected type.</option>';
    roomSelect.disabled = true;
    rateInput.value = '';
    return;
  }
  
  filtered.forEach(r => {
    const opt = document.createElement('option');
    opt.value = r.id;
    opt.dataset.rate = r.base_rate;
    opt.dataset.number = r.room_number;
    opt.dataset.typename = r.type_name;
    opt.textContent = 'Room ' + r.room_number;
    roomSelect.appendChild(opt);
  });
  
  roomSelect.disabled = false;

  // Auto-populate rate from room type base_rate
  const firstRoom = filtered[0];
  if (firstRoom) {
    rateInput.value = firstRoom.base_rate;
  }
}

roomTypeSelect.addEventListener('change', updateAvailableRoomDropdown);

roomSelect.addEventListener('change', function () {
  const opt = this.options[this.selectedIndex];
  if (opt && opt.dataset.rate) {
    rateInput.value = opt.dataset.rate;
  }
});

addRoomBtn.addEventListener('click', function () {
  roomSelectError.style.display = 'none';

  const roomId = parseInt(roomSelect.value);
  if (!roomId) {
    roomSelectError.textContent = 'Please select an available room number first.';
    roomSelectError.style.display = 'block';
    return;
  }

  if (selectedRoomsMap.has(roomId)) {
    roomSelectError.textContent = 'This room has already been added to the stay.';
    roomSelectError.style.display = 'block';
    return;
  }

  const opt = roomSelect.options[roomSelect.selectedIndex];
  const rate = parseFloat(rateInput.value) || parseFloat(opt.dataset.rate) || 0;
  
  selectedRoomsMap.set(roomId, {
    roomId: roomId,
    roomNumber: opt.dataset.number,
    typeName: opt.dataset.typename,
    rate: rate
  });

  renderSelectedRooms();
  updateAvailableRoomDropdown();
  roomSelect.value = '';
  rateInput.value = '';
});

function getTotalNightlyRate() {
  let sum = 0;
  selectedRoomsMap.forEach(r => sum += r.rate);
  return sum;
}

function addGuestBlock(isPrimary) {
  const clone = guestTemplate.content.cloneNode(true);
  const block = clone.querySelector('.guest-block');
  const heading = clone.querySelector('.gb-heading-text');
  const removeBtn = clone.querySelector('.remove-guest-btn');
  const idTypeLabel = clone.querySelector('.id-req-label');

  const index = guestsContainer.children.length + 1;
  heading.textContent = isPrimary ? '👤 Primary Guest (mandatory ID proof)' : '👤 Guest ' + index;

  if (isPrimary) {
    block.classList.add('primary');
    block.querySelector('select[name="guest_id_type[]"]').required = true;
    block.querySelector('input[name="guest_id_number[]"]').required = true;
    removeBtn.style.display = 'none';
  } else {
    removeBtn.addEventListener('click', function () {
      block.remove();
      renumberGuests();
    });
  }
  guestsContainer.appendChild(clone);
}

function renumberGuests() {
  const blocks = guestsContainer.querySelectorAll('.guest-block:not(.primary)');
  blocks.forEach((b, i) => {
    b.querySelector('.gb-heading-text').textContent = '👤 Guest ' + (i + 2);
  });
  numGuestsInput.value = guestsContainer.querySelectorAll('.guest-block').length;
}

addGuestBtn.addEventListener('click', function () {
  addGuestBlock(false);
  numGuestsInput.value = guestsContainer.querySelectorAll('.guest-block').length;
});

const commissionPreview = document.getElementById('commissionPreview');

function recalcCommission() {
  const rate = getTotalNightlyRate();
  const pct = parseFloat(commissionPercent.value) || 0;
  commissionAmount.value = ((rate * pct) / 100).toFixed(2);
  updateCommissionPreview();
}

function updateCommissionPreview() {
  const comm = parseFloat(commissionAmount.value) || 0;
  if (comm > 0) {
    commissionPreview.textContent =
      '🔒 Internal preview — this commission of ₹' + comm.toFixed(2) +
      ' will be added once to the guest\'s total Room Charges at check-out (spread invisibly across the blended per-night rate on the invoice). The guest will never see this amount broken out.';
  } else {
    commissionPreview.textContent = '';
  }
}

commissionPercent.addEventListener('input', recalcCommission);
commissionAmount.addEventListener('input', updateCommissionPreview);

document.querySelector('form').addEventListener('submit', function (e) {
  if (selectedRoomsMap.size === 0) {
    e.preventDefault();
    alert('Please select and add at least one room to the stay before saving check-in.');
    return false;
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

// Start with one mandatory primary guest block
addGuestBlock(true);

// ---- Additional Charges Widget (Check-In) ----
(function() {
  const presetSel   = document.getElementById('ci_presetSelect');
  const nameInp     = document.getElementById('ci_chargeNameInput');
  const qtyInp      = document.getElementById('ci_qtyInput');
  const priceInp    = document.getElementById('ci_priceInput');
  const totalInp    = document.getElementById('ci_totalInput');
  const remInp      = document.getElementById('ci_remarksInput');
  const addBtn2     = document.getElementById('ci_addChargeBtn');
  const errBox2     = document.getElementById('ci_chargeError');
  const tbody2      = document.getElementById('ci_chargesTableBody');
  const hiddenDiv2  = document.getElementById('ci_hiddenChargeInputs');
  const pending2    = [];

  presetSel.addEventListener('change', function() {
    if (this.value) {
      nameInp.value = this.value;
      const opt = this.options[this.selectedIndex];
      if (opt.dataset.price) { priceInp.value = opt.dataset.price; ci_recalc(); }
    }
  });

  window.ci_recalcTotal = function() { ci_recalc(); };
  function ci_recalc() {
    totalInp.value = ((parseFloat(qtyInp.value)||0) * (parseFloat(priceInp.value)||0)).toFixed(2);
  }

  function ci_render() {
    tbody2.innerHTML = '';
    hiddenDiv2.innerHTML = '';
    if (pending2.length === 0) {
      tbody2.innerHTML = '<tr id="ci_emptyRow"><td colspan="6" class="text-muted" style="text-align:center;padding:14px;">No charges added yet.</td></tr>';
      return;
    }
    pending2.forEach((c, i) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td><strong>${escHtml(c.name)}</strong></td><td class="text-right">${c.qty}</td><td class="text-right">₹${c.price.toFixed(2)}</td><td class="text-right"><strong>₹${c.total.toFixed(2)}</strong></td><td>${escHtml(c.remarks||'—')}</td><td style="text-align:right;"><button type="button" class="btn btn-sm btn-red" onclick="ci_remove(${i})">✕</button></td>`;
      tbody2.appendChild(tr);
      const mk = (n,v) => { const el=document.createElement('input'); el.type='hidden'; el.name=n; el.value=v; hiddenDiv2.appendChild(el); };
      mk('charge_name[]', c.name); mk('charge_qty[]', c.qty); mk('charge_price[]', c.price); mk('charge_remarks[]', c.remarks);
    });
  }

  window.ci_remove = function(i) { pending2.splice(i,1); ci_render(); };

  addBtn2.addEventListener('click', function() {
    errBox2.style.display = 'none';
    const name = nameInp.value.trim();
    const qty  = parseFloat(qtyInp.value) || 0;
    const price= parseFloat(priceInp.value) || 0;
    if (!name) { errBox2.textContent='Please select or type a charge.'; errBox2.style.display='block'; return; }
    if (qty<=0) { errBox2.textContent='Please enter a valid quantity.'; errBox2.style.display='block'; return; }
    if (price<0) { errBox2.textContent='Amount cannot be negative.'; errBox2.style.display='block'; return; }
    pending2.push({name, qty, price, total:qty*price, remarks:remInp.value.trim()});
    ci_render();
    presetSel.value=''; nameInp.value=''; qtyInp.value='1'; priceInp.value=''; totalInp.value=''; remInp.value='';
  });

  function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
  }
})();
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>

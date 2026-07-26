<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk']);

$bookingId = (int) ($_GET['id'] ?? 0);
if ($bookingId <= 0) {
    flash('error', 'Invalid booking ID.');
    redirect('bookings.php');
}

$bookingEditOldInput = $_SESSION['booking_edit_old_input'] ?? [];
unset($_SESSION['booking_edit_old_input']);

$pdo = db();

// Load booking with guest info
$stmt = $pdo->prepare("
  SELECT b.*,
         g.full_name AS guest_name, g.phone AS guest_phone, g.email AS guest_email,
         g.address AS guest_address, g.city AS guest_city, g.state AS guest_state,
         g.id_proof_type, g.id_proof_number, g.id_proof_photo, g.id_proof_photo_back,
         g.age AS guest_age, g.gender AS guest_gender, g.id AS guest_id
  FROM bookings b
  JOIN guests g ON g.id = b.primary_guest_id
  WHERE b.id = :id
");
$stmt->execute(['id' => $bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    flash('error', 'Booking not found.');
    redirect('bookings.php');
}

if ($bookingEditOldInput) {
  $booking = array_merge($booking, $bookingEditOldInput);
}

// Block editing of already-checked-out or cancelled bookings
if (in_array($booking['status'], ['checked_out', 'cancelled'])) {
    flash('error', 'Checked-out and cancelled bookings cannot be edited. Create a new booking instead.');
    redirect('booking_view.php?id=' . $bookingId);
}

// Load room types for dropdown
$roomTypes = getRoomTypes();
$bookingRooms = getBookingRooms($bookingId);

// Load existing extra charges
$extraCharges = getBookingExtraCharges($bookingId);
$totalExtraAmount = array_sum(array_column($extraCharges, 'total_amount'));

// Handle edit_charge parameter from URL
$editChargeId = (int)($_GET['edit_charge'] ?? 0);
$editChargeData = null;
if ($editChargeId > 0) {
    $editStmt = $pdo->prepare("SELECT * FROM booking_extra_charges WHERE id = :id AND booking_id = :bid");
    $editStmt->execute(['id' => $editChargeId, 'bid' => $bookingId]);
    $editChargeData = $editStmt->fetch();
}

// Preset charge options
$presetCharges = [
    ['name' => 'Tea', 'price' => 50],
    ['name' => 'Coffee / Milk', 'price' => 40],
    ['name' => 'Breakfast', 'price' => 150],
    ['name' => 'Lunch', 'price' => 250],
    ['name' => 'Dinner', 'price' => 350],
    ['name' => 'Laundry', 'price' => 40],
    ['name' => 'Room Service', 'price' => 100],
    ['name' => 'Extra Mattress', 'price' => 500],
    ['name' => 'Mineral Water', 'price' => 30],
    ['name' => 'Cold Drink', 'price' => 60],
    ['name' => 'Snacks', 'price' => 80],
    ['name' => 'Taxi', 'price' => 500],
    ['name' => 'Parking', 'price' => 100],
    ['name' => 'Other', 'price' => 0],
];

$pageTitle = 'Edit Booking — ' . $booking['booking_code'];
$activeNav = 'bookings';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-header">
  <div>
    <h2>✏️ Edit Booking — <?= e($booking['booking_code']) ?></h2>
    <div class="desc">Modify room allocations, stay dates, guest details and billing rates.</div>
  </div>
  <div class="page-actions">
    <a href="<?= BASE_URL ?>/booking_view.php?id=<?= $bookingId ?>" class="btn btn-outline">← Back to Booking</a>
  </div>
</div>

<form method="post" action="<?= BASE_URL ?>/booking_edit_save.php" enctype="multipart/form-data" id="editBookingForm">
  <?= csrfField() ?>
  <input type="hidden" name="booking_id" value="<?= $bookingId ?>">

  <!-- ==================== BOOKING TYPE ==================== -->
  <div class="card">
    <div class="card-head"><h3>1. Booking Type</h3></div>
    <div style="background:var(--cream-light,#faf9f5);border:1.5px solid var(--border-color,#e5e0d8);border-radius:8px;padding:16px;margin-bottom:18px;">
      <label style="font-weight:700;margin-bottom:10px;display:block;">Booking Type *</label>
      <div style="display:flex;gap:24px;align-items:center;">
        <label style="cursor:pointer;display:flex;align-items:center;gap:6px;font-weight:600;">
          <input type="radio" name="booking_type" value="regular" <?= ($booking['booking_type'] ?? 'regular') === 'regular' ? 'checked' : '' ?> onchange="toggleCorporateFields()">
          👤 Regular Guest
        </label>
        <label style="cursor:pointer;display:flex;align-items:center;gap:6px;font-weight:600;">
          <input type="radio" name="booking_type" value="corporate" <?= ($booking['booking_type'] ?? '') === 'corporate' ? 'checked' : '' ?> onchange="toggleCorporateFields()">
          🏢 Corporate Booking
        </label>
      </div>
    </div>

    <div id="corporateFieldsSection" style="display:none;">
      <div class="form-row">
        <div class="form-group">
          <label>Company Name *</label>
          <input type="text" name="company_name" id="companyNameInput" class="form-control" value="<?= e($booking['company_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>GST Number *</label>
          <input type="text" name="company_gst_number" id="companyGstInput" class="form-control" value="<?= e($booking['company_gst_number'] ?? '') ?>" maxlength="15" style="text-transform:uppercase;">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Company Address</label>
          <input type="text" name="company_address" class="form-control" value="<?= e($booking['company_address'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Contact Person</label>
          <input type="text" name="company_contact_person" class="form-control" value="<?= e($booking['company_contact_person'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Company Phone</label>
          <input type="text" name="company_phone" class="form-control" value="<?= e($booking['company_phone'] ?? '') ?>">
        </div>
      </div>
    </div>
  </div>

  <!-- ==================== STAY & ROOM ALLOCATION DETAILS ==================== -->
  <div class="card">
    <div class="card-head"><h3>2. Stay &amp; Multi-Room Allocation</h3></div>

    <div class="form-row">
      <div class="form-group">
        <label>Check-In Date &amp; Time *</label>
        <input type="datetime-local" name="checkin_datetime" id=give me a sql qery to run for the follwing

update room type of particular room number multiple at a time"checkinDatetime" class="form-control"
          value="<?= date('Y-m-d\TH:i', strtotime($booking['checkin_datetime'])) ?>" required>
      </div>
      <div class="form-group">
        <label>Expected Check-Out Date *</label>
        <input type="date" name="expected_checkout_date" id="checkoutDate" class="form-control"
          value="<?= e($booking['expected_checkout_date']) ?>" required>
      </div>
    </div>

    <!-- Multi-Room Selector -->
    <div style="background:var(--cream-light, #faf9f5); border:1.5px solid var(--border-color, #e5e0d8); border-radius:8px; padding:16px; margin-bottom:18px;">
      <h4 style="margin-bottom:12px; font-size:0.95rem; color:var(--text-color);">➕ Add / Modify Allocated Rooms</h4>
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
      <label style="font-weight:700; margin-bottom:8px; display:block;">Selected Rooms for this Booking *</label>
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
        <label>Total Rate per Night (₹)</label>
        <input type="number" name="rate_per_night" id="totalRateInput" class="form-control" step="0.01" min="0" value="<?= e($booking['rate_per_night']) ?>">
      </div>
      <div class="form-group">
        <label>Number of Guests *</label>
        <input type="number" name="num_guests" class="form-control" value="<?= e($booking['num_guests']) ?>" min="1" max="12" required>
      </div>
      <div class="form-group">
        <label>Advance Amount (₹)</label>
        <input type="number" name="advance_amount" class="form-control" step="0.01" min="0"
          value="<?= e($booking['advance_amount']) ?>">
      </div>
      <div class="form-group">
        <label>Additional Charges (₹)</label>
        <input type="number" name="extra_amount" class="form-control" step="0.01" min="0"
          value="<?= e($booking['extra_amount'] ?? 0) ?>">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Tax % (GST)</label>
        <input type="number" name="tax_percent" class="form-control" step="0.01" min="0"
          value="<?= e($booking['tax_percent']) ?>">
      </div>
      <div class="form-group">
        <label>Discount (₹)</label>
        <input type="number" name="discount_amount" class="form-control" step="0.01" min="0"
          value="<?= e($booking['discount_amount'] ?? 0) ?>">
      </div>
    </div>

    <div class="form-group">
      <label>Special Requests / Notes</label>
      <textarea name="special_requests" class="form-control" rows="2" placeholder="Guest preferences, near-temple view, late arrival, etc."><?= e($booking['special_requests'] ?? '') ?></textarea>
    </div>
  </div>

<!-- ==================== BOOKING SOURCE ==================== -->
  <div class="card" style="border:1.5px solid var(--gold-pale);">
    <div class="card-head">
      <h3>5. Booking Source & Commission <span class="internal-only-tag">🔒 Internal Only — Never Shown to Guest</span></h3>
    </div>
    <div class="form-row-3">
      <div class="form-group">
        <label>Booking Source</label>
        <select name="booking_source" id="bookingSource" class="form-control" onchange="toggleAgentField()">
          <option value="walk_in" <?= $booking['booking_source'] === 'walk_in' ? 'selected' : '' ?>>Walk-In</option>
          <option value="phone" <?= $booking['booking_source'] === 'phone' ? 'selected' : '' ?>>Phone Booking</option>
          <option value="online" <?= $booking['booking_source'] === 'online' ? 'selected' : '' ?>>Online / Website</option>
          <option value="clear_trip" <?= $booking['booking_source'] === 'clear_trip' ? 'selected' : '' ?>>Clear Trip</option>
          <option value="agent" <?= $booking['booking_source'] === 'agent' ? 'selected' : '' ?>>Travel Agent</option>
          <option value="ota_mmt" <?= $booking['booking_source'] === 'ota_mmt' ? 'selected' : '' ?>>OTA — MakeMyTrip</option>
          <option value="ota_goibibo" <?= $booking['booking_source'] === 'ota_goibibo' ? 'selected' : '' ?>>OTA — Goibibo</option>
          <option value="ota_booking_com" <?= $booking['booking_source'] === 'ota_booking_com' ? 'selected' : '' ?>>OTA — Booking.com</option>
          <option value="ota_other" <?= $booking['booking_source'] === 'ota_other' ? 'selected' : '' ?>>OTA — Other</option>
          <option value="other" <?= $booking['booking_source'] === 'other' ? 'selected' : '' ?>>Other</option>
        </select>
      </div>
      <div class="form-group" id="agentNameGroup">
        <label>Agent / OTA Name</label>
        <input type="text" name="agent_or_ota_name" class="form-control" value="<?= e($booking['agent_or_ota_name'] ?? '') ?>" placeholder="e.g. Ramesh Travels">
      </div>
      <div class="form-group">
        <label>Commission % <span class="internal-only-tag">Internal</span></label>
        <input type="number" name="commission_percent" id="commissionPercent" class="form-control" step="0.01" min="0" max="100"
          value="<?= e($booking['commission_percent'] ?? 0) ?>">
      </div>
      <div class="form-group">
        <label>Commission Amount (₹) <span class="internal-only-tag">Internal</span></label>
        <input type="number" name="commission_amount" id="commissionAmount" class="form-control" step="0.01" min="0"
          value="<?= e($booking['commission_amount'] ?? 0) ?>">
      </div>
      <div class="form-group">
        <label>Commission Status</label>
        <select name="commission_status" class="form-control">
          <option value="not_applicable" <?= ($booking['commission_status'] ?? '') === 'not_applicable' ? 'selected' : '' ?>>Not Applicable</option>
          <option value="pending" <?= ($booking['commission_status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending Payment</option>
          <option value="paid" <?= ($booking['commission_status'] ?? '') === 'paid' ? 'selected' : '' ?>>Already Paid</option>
        </select>
      </div>
    </div>
  </div>

  <!-- ==================== EXTRA CHARGES ==================== -->
  <div class="card">
    <div class="card-head"><h3>5. Extra Charges <span style="font-weight:normal; font-size:0.85rem; color:var(--text-muted);">(Dinner, Tea, Laundry, Room Service, etc.)</span></h3></div>
    <p class="tag-note" style="margin-bottom:14px;">Add, edit, or remove itemized extra charges. Totals update automatically and sync to the booking.</p>

    <div style="background:var(--cream-light, #faf9f5); border:1.5px solid var(--border-color, #e5e0d8); border-radius:8px; padding:16px; margin-bottom:18px;">
      <div class="form-row" style="align-items:end;">
        <div class="form-group" style="flex:2;">
          <label>Charge Name *</label>
          <select id="extraChargePresetSelect" class="form-control">
            <option value="">-- Quick Select (Auto-fills Price) --</option>
            <?php foreach ($presetCharges as $pc): ?>
              <option value="<?= e($pc['name']) ?>" data-price="<?= $pc['price'] ?>"><?= e($pc['name']) ?> (<?= money($pc['price']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="flex:2;">
          <label>Custom Name / Description *</label>
          <input type="text" id="extraChargeNameInput" class="form-control" placeholder="Select preset or type custom charge...">
        </div>
        <div class="form-group" style="flex:1;">
          <label>Qty *</label>
          <input type="number" id="extraChargeQtyInput" class="form-control" value="1" min="0.1" step="0.1" oninput="recalcExtraChargeTotal()">
        </div>
        <div class="form-group" style="flex:1.5;">
          <label>Unit Price (₹) *</label>
          <input type="number" id="extraChargePriceInput" class="form-control" step="0.01" min="0" placeholder="0.00" oninput="recalcExtraChargeTotal()">
        </div>
        <div class="form-group" style="flex:1.5;">
          <label>Total (₹)</label>
          <input type="number" id="extraChargeTotalInput" class="form-control" readonly style="background:#f5f3ef; font-weight:bold;">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label>Remarks / Notes (optional)</label>
          <input type="text" id="extraChargeRemarksInput" class="form-control" placeholder="e.g. 2 shirts dry-cleaned, late night order">
        </div>
        <div class="form-group" style="align-self:flex-end; max-width:200px;">
          <button type="button" id="addExtraChargeBtn" class="btn btn-gold" style="width:100%; white-space:nowrap;">➕ Add Charge</button>
        </div>
      </div>
      <div id="extraChargeFormError" style="display:none; margin-top:8px; font-size:0.88rem; color:var(--red, #d9534f); font-weight:bold;"></div>
    </div>

    <!-- Selected Extra Charges Table -->
    <div style="margin-bottom:20px;">
      <label style="font-weight:700; margin-bottom:8px; display:block;">Extra Charges for This Booking (Total: <span id="extraChargesTotalDisplay"><?= money($totalExtraAmount) ?></span>)</label>
      <div class="table-wrap">
        <table class="data-table" id="extraChargesTable">
          <thead>
            <tr>
              <th>Charge Name</th>
              <th class="text-right">Qty</th>
              <th class="text-right">Unit Price (₹)</th>
              <th class="text-right">Total (₹)</th>
              <th>Remarks</th>
              <th style="width:80px; text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody id="extraChargesTableBody">
            <?php if (!$extraCharges): ?>
              <tr id="emptyExtraChargesRow">
                <td colspan="6" class="text-muted" style="text-align:center; padding:18px;">
                  No extra charges added yet. Use the form above to add charges.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($extraCharges as $idx => $ec): ?>
                <tr data-charge-id="<?= (int)$ec['id'] ?>" data-idx="<?= $idx ?>">
                  <td><strong><?= e($ec['charge_name']) ?></strong></td>
                  <td class="text-right"><input type="number" class="form-control form-control-sm extra-charge-qty" value="<?= (float)$ec['qty'] ?>" min="0.1" step="0.1" style="max-width:80px; display:inline-block;" onchange="updateExtraChargeTotal(this)"></td>
                  <td class="text-right"><input type="number" class="form-control form-control-sm extra-charge-price" value="<?= (float)$ec['unit_price'] ?>" step="0.01" min="0" style="max-width:100px; display:inline-block;" onchange="updateExtraChargeTotal(this)"></td>
                  <td class="text-right"><strong><span class="extra-charge-row-total"><?= money((float)$ec['total_amount']) ?></span></strong></td>
                  <td><input type="text" class="form-control form-control-sm extra-charge-remarks" value="<?= e($ec['remarks'] ?? '') ?>" style="max-width:200px; display:inline-block;"></td>
                  <td style="text-align:right;">
                    <button type="button" class="btn btn-sm btn-outline" onclick="editExtraCharge(this)" title="Edit">✏️</button>
                    <button type="button" class="btn btn-sm btn-red" onclick="deleteExtraCharge(this, <?= (int)$ec['id'] ?>)" title="Delete">✕</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <!-- Hidden inputs for form submission -->
      <div id="extraChargesHiddenInputs"></div>
    </div>
  </div>

  <!-- ==================== GUEST DETAILS ==================== -->
  <div class="card">
    <div class="card-head"><h3>6. Primary Guest Details</h3></div>
    <p class="tag-note" style="margin-bottom:14px;">Editing the primary guest updates the guest record directly.</p>

    <div class="form-row">
      <div class="form-group">
        <label>Full Name *</label>
        <input type="text" name="guest_name" class="form-control" value="<?= e($booking['guest_name']) ?>" required>
      </div>
      <div class="form-group">
        <label>Phone Number *</label>
        <input type="tel" name="guest_phone" class="form-control" value="<?= e($booking['guest_phone']) ?>" required>
      </div>
      <div class="form-group">
        <label>Email</label>
        <input type="email" name="guest_email" class="form-control" value="<?= e($booking['guest_email'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Age</label>
        <input type="number" name="guest_age" class="form-control" value="<?= e($booking['guest_age'] ?? '') ?>" min="1" max="120">
      </div>
      <div class="form-group">
        <label>Gender</label>
        <select name="guest_gender" class="form-control">
          <option value="">— Select —</option>
          <option value="male" <?= ($booking['guest_gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
          <option value="female" <?= ($booking['guest_gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
          <option value="other" <?= ($booking['guest_gender'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
        </select>
      </div>
      <div class="form-group">
        <label>City</label>
        <input type="text" name="guest_city" class="form-control" value="<?= e($booking['guest_city'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>State</label>
        <input type="text" name="guest_state" class="form-control" value="<?= e($booking['guest_state'] ?? '') ?>">
      </div>
    </div>
    <div class="form-group">
      <label>Address</label>
      <input type="text" name="guest_address" class="form-control" value="<?= e($booking['guest_address'] ?? '') ?>">
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>ID Proof Type</label>
        <select name="guest_id_type" class="form-control">
          <option value="">— None —</option>
          <?php foreach (['Aadhaar Card','PAN Card','Driving Licence','Passport','Voter ID','Ration Card','Other'] as $idOpt): ?>
            <option value="<?= $idOpt ?>" <?= ($booking['id_proof_type'] ?? '') === $idOpt ? 'selected' : '' ?>><?= $idOpt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>ID Proof Number</label>
        <input type="text" name="guest_id_number" class="form-control" value="<?= e($booking['id_proof_number'] ?? '') ?>">
      </div>
    </div>

    <?php if ($booking['id_proof_photo']): ?>
      <div class="form-group">
        <label>Current ID Proof (Front)</label>
        <div><a href="<?= BASE_URL ?>/uploads/docs/<?= e($booking['id_proof_photo']) ?>" target="_blank" class="btn btn-sm btn-outline">📎 View Current</a></div>
      </div>
    <?php endif; ?>
    <div class="form-group">
      <label>Replace ID Proof Front (optional)</label>
      <input type="file" name="guest_id_photo" class="form-control" accept="image/*,application/pdf">
    </div>

    <?php if ($booking['id_proof_photo_back']): ?>
      <div class="form-group">
        <label>Current ID Proof (Back)</label>
        <div><a href="<?= BASE_URL ?>/uploads/docs/<?= e($booking['id_proof_photo_back']) ?>" target="_blank" class="btn btn-sm btn-outline">📎 View Current</a></div>
      </div>
    <?php endif; ?>
    <div class="form-group">
      <label>Replace ID Proof Back (optional)</label>
      <input type="file" name="guest_id_photo_back" class="form-control" accept="image/*,application/pdf">
    </div>
  </div>

  <!-- ==================== SAVE ==================== -->
  <div class="card" style="text-align:right;">
    <a href="<?= BASE_URL ?>/booking_view.php?id=<?= $bookingId ?>" class="btn btn-outline" style="margin-right:12px;">✕ Cancel</a>
    <button type="submit" class="btn btn-gold" style="min-width:200px;">💾 Save Changes</button>
  </div>
</form>

<script>
const BASE_URL = "<?= BASE_URL ?>";
const BOOKING_ID = <?= $bookingId ?>;
const INITIAL_ROOMS = <?= json_encode(array_map(function($br) {
    return [
        'roomId'     => (int)($br['room_id'] ?? 0),
        'roomNumber' => $br['room_number'] ?? 'Unassigned',
        'typeName'   => $br['room_type_name'] ?? 'Room',
        'rate'       => (float)($br['rate_per_night'] ?? 0),
        'roomTypeId' => !empty($br['room_type_id']) ? (int)$br['room_type_id'] : null
    ];
}, $bookingRooms)) ?>;

const roomTypeSelect = document.getElementById('roomTypeSelect');
const roomSelect = document.getElementById('roomSelect');
const rateInput = document.getElementById('rateInput');
const totalRateInput = document.getElementById('totalRateInput');
const addRoomBtn = document.getElementById('addRoomBtn');
const roomSelectError = document.getElementById('roomSelectError');
const checkinDatetime = document.getElementById('checkinDatetime');
const checkoutDate = document.getElementById('checkoutDate');
const commissionPercent = document.getElementById('commissionPercent');
const commissionAmount = document.getElementById('commissionAmount');

const selectedRoomsMap = new Map();
let availableRoomsList = [];

// Populate initial rooms
INITIAL_ROOMS.forEach((r, idx) => {
  const key = r.roomId > 0 ? String(r.roomId) : ('unassigned_' + idx);
  selectedRoomsMap.set(key, {
    key: key,
    roomId: r.roomId || 0,
    roomNumber: r.roomNumber || 'Unassigned',
    typeName: r.typeName || 'Room',
    rate: r.rate || 0,
    roomTypeId: r.roomTypeId || null
  });
});

function escapeHtml(unsafe) {
  if (!unsafe) return '';
  return unsafe.toString()
     .replace(/&/g, "&amp;")
     .replace(/</g, "&lt;")
     .replace(/>/g, "&gt;")
     .replace(/"/g, "&quot;")
     .replace(/'/g, "&#039;");
}

function fetchAvailableRooms(callback) {
  const cInFull = checkinDatetime.value;
  const cOut = checkoutDate.value;
  if (!cInFull || !cOut) return;
  const cInDate = cInFull.split('T')[0];

  // Show loading indicator
  roomSelect.innerHTML = '<option value="">⏳ Loading rooms...</option>';
  roomSelect.disabled = true;

  fetch(BASE_URL + '/api/get_available_rooms.php?checkin=' + encodeURIComponent(cInDate) + '&checkout=' + encodeURIComponent(cOut) + '&exclude_booking_id=' + BOOKING_ID)
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        availableRoomsList = data.rooms;
        updateAvailableRoomDropdown();
        if (typeof callback === 'function') callback();
      }
    })
    .catch(err => {
      console.error('Failed to load available rooms:', err);
      roomSelect.innerHTML = '<option value="">⚠️ Failed to load rooms</option>';
    });
}

function updateAvailableRoomDropdown() {
  const selectedTypeId = roomTypeSelect.value;
  roomSelect.innerHTML = '<option value="">-- Unassigned (Room Type Only) --</option>';

  if (!selectedTypeId) {
    roomSelect.disabled = true;
    rateInput.value = '';
    return;
  }

  // If list not yet loaded, trigger fetch first then re-run
  if (availableRoomsList.length === 0) {
    fetchAvailableRooms(() => updateAvailableRoomDropdown());
    return;
  }

  const filtered = availableRoomsList.filter(r => String(r.room_type_id) === String(selectedTypeId));

  filtered.forEach(r => {
    const isAlreadySelected = Array.from(selectedRoomsMap.values()).some(sr => sr.roomId === parseInt(r.id));

    const opt = document.createElement('option');
    opt.value = r.id;
    opt.dataset.rate = r.base_rate;
    opt.dataset.number = r.room_number;
    opt.dataset.typename = r.type_name;

    let statusText = isAlreadySelected ? '📌 Current' : (r.is_available ? '✅ Available' : ('⛔ ' + (r.conflict ? r.conflict : r.status)));
    opt.textContent = 'Room ' + r.room_number + ' — ' + statusText;
    if (!r.is_available && !isAlreadySelected) opt.style.color = '#999';
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

  // Auto-fill rate input with base rate of first available room
  const firstAvail = filtered.find(r => r.is_available);
  if (firstAvail && !rateInput.value) {
    rateInput.value = firstAvail.base_rate;
  }
}

function getRoomOptionsHtml(roomTypeId, currentRoomId) {
  if (!availableRoomsList || availableRoomsList.length === 0) {
    return '<option value="">⏳ Loading rooms...</option>';
  }
  const filtered = availableRoomsList.filter(r => String(r.room_type_id) === String(roomTypeId));
  let html = '<option value="">— UNASSIGNED —</option>';
  const assignedRoomIds = Array.from(selectedRoomsMap.values()).map(r => r.roomId).filter(id => id > 0);

  filtered.forEach(r => {
    const isCurrent = parseInt(r.id) === parseInt(currentRoomId);
    const isAssignedElsewhere = assignedRoomIds.includes(parseInt(r.id)) && !isCurrent;
    let label = 'Room ' + r.room_number;
    if (!r.is_available && !isCurrent) {
      label += ' — ' + (r.conflict || r.status || 'Occupied');
    }
    if (isCurrent) label += ' — Current';
    else if (isAssignedElsewhere) label += ' — Assigned to another row';
    const disabled = (!r.is_available && !isCurrent) || isAssignedElsewhere;
    const selected = isCurrent ? ' selected' : '';
    html += '<option value="' + r.id + '"' + selected + (disabled ? ' disabled' : '') + '>' + escapeHtml(label) + '</option>';
  });

  if (filtered.length === 0) {
    html += '<option value="" disabled>— No rooms found for this type —</option>';
  }
  return html;
}

function renderSelectedRooms() {
  const body = document.getElementById('selectedRoomsBody');
  const hiddenInputs = document.getElementById('selectedRoomsHiddenInputs');
  body.innerHTML = '';
  hiddenInputs.innerHTML = '';

  if (selectedRoomsMap.size === 0) {
    body.innerHTML = '<tr id="emptyRoomsRow"><td colspan="4" class="text-muted" style="text-align:center; padding:18px;">No rooms added yet. Select a Room Type above and click <strong>➕ Add Room</strong>.</td></tr>';
    totalRateInput.value = '0.00';
    recalcCommission();
    return;
  }

  let totalRate = 0;

  selectedRoomsMap.forEach((room, key) => {
    totalRate += room.rate;
    const tr = document.createElement('tr');
    const optionsHtml = getRoomOptionsHtml(room.roomTypeId, room.roomId);
    tr.innerHTML = `
      <td>
        <select class="form-control form-control-sm row-room-select" data-key="${key}" style="min-width:180px;" onchange="onRowRoomChange('${key}', this.value)">
          ${optionsHtml}
        </select>
      </td>
      <td>${escapeHtml(room.typeName)}</td>
      <td>
        <input type="number" class="form-control form-control-sm" style="max-width:140px; display:inline-block;" step="0.01" min="0" value="${room.rate}" onchange="updateRoomRate('${key}', this.value)">
      </td>
      <td style="text-align:right;">
        <button type="button" class="btn btn-sm btn-red" onclick="removeSelectedRoom('${key}')">✕ Remove</button>
      </td>
    `;
    body.appendChild(tr);

    const inputRoom = document.createElement('input');
    inputRoom.type = 'hidden';
    inputRoom.name = 'room_ids[]';
    inputRoom.value = room.roomId || '';
    inputRoom.id = 'hidden_room_' + key;
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

  totalRateInput.value = totalRate.toFixed(2);
  recalcCommission();
}

function onRowRoomChange(key, newRoomId) {
  if (!selectedRoomsMap.has(key)) return;
  const room = selectedRoomsMap.get(key);
  const roomId = parseInt(newRoomId) || 0;

  if (roomId > 0) {
    const roomData = availableRoomsList.find(r => parseInt(r.id) === roomId);
    if (roomData) {
      room.roomId = roomId;
      room.roomNumber = roomData.room_number;
      room.rate = roomData.base_rate || room.rate;
    }
  } else {
    room.roomId = 0;
    room.roomNumber = 'Unassigned';
  }

  const hiddenInput = document.getElementById('hidden_room_' + key);
  if (hiddenInput) hiddenInput.value = room.roomId || '';

  renderSelectedRooms();
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

function recalcCommission() {
  const rate = parseFloat(totalRateInput.value) || 0;
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
    roomSelectError.textContent = 'Please choose a Room Type first.';
    roomSelectError.style.display = 'block';
    return;
  }

  const selectedTypeOpt = roomTypeSelect.options[roomTypeSelect.selectedIndex];
  const typeName = selectedTypeOpt ? selectedTypeOpt.text : 'Room';

  const rawRoomVal = roomSelect.value;
  const roomId = parseInt(rawRoomVal) || 0;

  if (roomId > 0 && Array.from(selectedRoomsMap.values()).some(sr => sr.roomId === roomId)) {
    roomSelectError.textContent = 'This room number has already been added to the booking.';
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
    roomTypeId: selectedTypeId || null
  });

  renderSelectedRooms();
  updateAvailableRoomDropdown();
  roomSelect.value = '';
  rateInput.value = '';
});

checkinDatetime.addEventListener('change', fetchAvailableRooms);
checkoutDate.addEventListener('change', fetchAvailableRooms);
commissionPercent.addEventListener('input', recalcCommission);
totalRateInput.addEventListener('input', recalcCommission);

document.getElementById('editBookingForm').addEventListener('submit', function (e) {
  if (selectedRoomsMap.size === 0) {
    const selectedTypeId = roomTypeSelect.value;
    if (selectedTypeId) {
      addRoomBtn.click();
    } else {
      e.preventDefault();
      alert('Please select at least one room or room type before saving changes.');
      return false;
    }
  }
});

function toggleCorporateFields() {
  const isCorporate = document.querySelector('input[name="booking_type"]:checked').value === 'corporate';
  const sec = document.getElementById('corporateFieldsSection');
  const compInput = document.getElementById('companyNameInput');
  const gstInput  = document.getElementById('companyGstInput');
  sec.style.display = isCorporate ? 'block' : 'none';
  compInput.required = isCorporate;
  gstInput.required  = isCorporate;
}

function toggleAgentField() {
  const src = document.getElementById('bookingSource').value;
  const show = ['agent','ota_mmt','ota_goibibo','ota_booking_com','ota_other'].includes(src);
  document.getElementById('agentNameGroup').style.display = show ? 'block' : 'none';
}

toggleCorporateFields();
  toggleAgentField();
  renderSelectedRooms();

  // Pre-select room type from existing booking rooms
  if (INITIAL_ROOMS.length > 0 && INITIAL_ROOMS[0].roomTypeId) {
    roomTypeSelect.value = String(INITIAL_ROOMS[0].roomTypeId);
  }

  fetchAvailableRooms(() => renderSelectedRooms());

// ==================== EXTRA CHARGES JAVASCRIPT ====================
const extraChargePresetSelect = document.getElementById('extraChargePresetSelect');
const extraChargeNameInput = document.getElementById('extraChargeNameInput');
const extraChargeQtyInput = document.getElementById('extraChargeQtyInput');
const extraChargePriceInput = document.getElementById('extraChargePriceInput');
const extraChargeTotalInput = document.getElementById('extraChargeTotalInput');
const extraChargeRemarksInput = document.getElementById('extraChargeRemarksInput');
const addExtraChargeBtn = document.getElementById('addExtraChargeBtn');
const extraChargeFormError = document.getElementById('extraChargeFormError');
const extraChargesTableBody = document.getElementById('extraChargesTableBody');
const extraChargesHiddenInputs = document.getElementById('extraChargesHiddenInputs');
const extraChargesTotalDisplay = document.getElementById('extraChargesTotalDisplay');

function recalcExtraChargeTotal() {
  const qty = parseFloat(extraChargeQtyInput.value) || 0;
  const price = parseFloat(extraChargePriceInput.value) || 0;
  extraChargeTotalInput.value = (qty * price).toFixed(2);
}

if (extraChargePresetSelect) {
  extraChargePresetSelect.addEventListener('change', function () {
    if (this.value) {
      extraChargeNameInput.value = this.value;
      const opt = this.options[this.selectedIndex];
      if (opt.dataset.price) {
        extraChargePriceInput.value = opt.dataset.price;
        recalcExtraChargeTotal();
      }
    }
  });
}

function updateExtraChargeTotal(input) {
  const row = input.closest('tr');
  const chargeId = row.dataset.chargeId;
  const qty = parseFloat(row.querySelector('.extra-charge-qty').value) || 0;
  const price = parseFloat(row.querySelector('.extra-charge-price').value) || 0;
  const total = qty * price;
  row.querySelector('.extra-charge-row-total').textContent = '₹' + total.toFixed(2);
  updateExtraChargesTotal();
}

function updateExtraChargesTotal() {
  let total = 0;
  document.querySelectorAll('#extraChargesTableBody tr[data-charge-id]').forEach(row => {
    const qty = parseFloat(row.querySelector('.extra-charge-qty').value) || 0;
    const price = parseFloat(row.querySelector('.extra-charge-price').value) || 0;
    total += qty * price;
  });
  if (extraChargesTotalDisplay) {
    extraChargesTotalDisplay.textContent = '₹' + total.toFixed(2);
  }
  // Also update the hidden extra_amount input in the form
  const extraAmountInput = document.querySelector('input[name="extra_amount"]');
  if (extraAmountInput) {
    extraAmountInput.value = total.toFixed(2);
  }
}

function renderExtraChargesHiddenInputs() {
  extraChargesHiddenInputs.innerHTML = '';
  document.querySelectorAll('#extraChargesTableBody tr[data-charge-id]').forEach(row => {
    const chargeId = row.dataset.chargeId;
    const name = row.cells[0].querySelector('strong').textContent;
    const qty = row.querySelector('.extra-charge-qty').value;
    const price = row.querySelector('.extra-charge-price').value;
    const remarks = row.querySelector('.extra-charge-remarks').value;

    ['charge_id[]', 'charge_name[]', 'charge_qty[]', 'charge_price[]', 'charge_remarks[]'].forEach((fieldName, i) => {
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = fieldName;
      hidden.value = i === 0 ? chargeId : (i === 1 ? name : (i === 2 ? qty : (i === 3 ? price : remarks)));
      extraChargesHiddenInputs.appendChild(hidden);
    });
  });
}

function editExtraCharge(btn) {
  const row = btn.closest('tr');
  const name = row.cells[0].querySelector('strong').textContent;
  const qty = row.querySelector('.extra-charge-qty').value;
  const price = row.querySelector('.extra-charge-price').value;
  const remarks = row.querySelector('.extra-charge-remarks').value;
  const chargeId = row.dataset.chargeId;

  // Populate the form with existing values
  extraChargeNameInput.value = name;
  extraChargeQtyInput.value = qty;
  extraChargePriceInput.value = price;
  extraChargeRemarksInput.value = remarks;
  recalcExtraChargeTotal();

  // Store the charge ID being edited
  addExtraChargeBtn.dataset.editingId = chargeId;
  addExtraChargeBtn.textContent = '💾 Update Charge';

  // Scroll to form
  extraChargeNameInput.focus();
}

function deleteExtraCharge(btn, chargeId) {
  if (!confirm('Delete this extra charge?')) return;

  // Send AJAX request to delete
  fetch(BASE_URL + '/booking_extra_charge_delete.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: 'charge_id=' + chargeId + '&csrf_token=' + '<?= e(csrfToken()) ?>'
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      btn.closest('tr').remove();
      updateExtraChargesTotal();
      renderExtraChargesHiddenInputs();
      if (extraChargesTableBody.querySelectorAll('tr[data-charge-id]').length === 0) {
        extraChargesTableBody.innerHTML = '<tr id="emptyExtraChargesRow"><td colspan="6" class="text-muted" style="text-align:center; padding:18px;">No extra charges added yet. Use the form above to add charges.</td></tr>';
      }
    } else {
      alert('Failed to delete: ' + (data.error || 'Unknown error'));
    }
  })
  .catch(err => {
    console.error(err);
    alert('Error deleting charge');
  });
}

if (addExtraChargeBtn) {
  addExtraChargeBtn.addEventListener('click', function () {
    if (extraChargeFormError) extraChargeFormError.style.display = 'none';

    const name = extraChargeNameInput.value.trim();
    const qty = parseFloat(extraChargeQtyInput.value) || 0;
    const price = parseFloat(extraChargePriceInput.value) || 0;
    const remarks = extraChargeRemarksInput.value.trim();
    const editingId = this.dataset.editingId;

    if (!name) {
      if (extraChargeFormError) { extraChargeFormError.textContent = 'Please enter a charge name.'; extraChargeFormError.style.display = 'block'; }
      return;
    }
    if (qty <= 0) {
      if (extraChargeFormError) { extraChargeFormError.textContent = 'Please enter a valid quantity.'; extraChargeFormError.style.display = 'block'; }
      return;
    }
    if (price < 0) {
      if (extraChargeFormError) { extraChargeFormError.textContent = 'Unit price cannot be negative.'; extraChargeFormError.style.display = 'block'; }
      return;
    }

    const total = qty * price;

    if (editingId) {
      // Update existing row
      const row = extraChargesTableBody.querySelector('tr[data-charge-id="' + editingId + '"]');
      if (row) {
        row.cells[0].querySelector('strong').textContent = name;
        row.querySelector('.extra-charge-qty').value = qty;
        row.querySelector('.extra-charge-price').value = price;
        row.querySelector('.extra-charge-row-total').textContent = '₹' + total.toFixed(2);
        row.querySelector('.extra-charge-remarks').value = remarks;
      }
      this.dataset.editingId = '';
      this.textContent = '➕ Add Charge';
    } else {
      // Add new row
      const emptyRow = document.getElementById('emptyExtraChargesRow');
      if (emptyRow) emptyRow.remove();

      const newChargeId = 'new_' + Date.now() + '_' + Math.random().toString(36).substr(2, 4);
      const tr = document.createElement('tr');
      tr.dataset.chargeId = newChargeId;
      tr.innerHTML = `
        <td><strong>${escapeHtml(name)}</strong></td>
        <td class="text-right"><input type="number" class="form-control form-control-sm extra-charge-qty" value="${qty}" min="0.1" step="0.1" style="max-width:80px; display:inline-block;" onchange="updateExtraChargeTotal(this)"></td>
        <td class="text-right"><input type="number" class="form-control form-control-sm extra-charge-price" value="${price.toFixed(2)}" step="0.01" min="0" style="max-width:100px; display:inline-block;" onchange="updateExtraChargeTotal(this)"></td>
        <td class="text-right"><strong><span class="extra-charge-row-total">₹${total.toFixed(2)}</span></strong></td>
        <td><input type="text" class="form-control form-control-sm extra-charge-remarks" value="${escapeHtml(remarks)}" style="max-width:200px; display:inline-block;"></td>
        <td style="text-align:right;">
          <button type="button" class="btn btn-sm btn-outline" onclick="editExtraCharge(this)" title="Edit">✏️</button>
          <button type="button" class="btn btn-sm btn-red" onclick="deleteExtraCharge(this, '${newChargeId}')" title="Delete">✕</button>
        </td>
      `;
      extraChargesTableBody.appendChild(tr);
    }

    updateExtraChargesTotal();
    renderExtraChargesHiddenInputs();

    // Reset form
    extraChargePresetSelect.value = '';
    extraChargeNameInput.value = '';
    extraChargeQtyInput.value = '1';
    extraChargePriceInput.value = '';
    extraChargeTotalInput.value = '';
    extraChargeRemarksInput.value = '';
  });
}

// Initialize: render hidden inputs for existing charges
  renderExtraChargesHiddenInputs();

  // Handle edit_charge from URL parameter
  <?php if ($editChargeData): ?>
    extraChargeNameInput.value = <?= json_encode($editChargeData['charge_name']) ?>;
    extraChargeQtyInput.value = <?= json_encode((float)$editChargeData['qty']) ?>;
    extraChargePriceInput.value = <?= json_encode((float)$editChargeData['unit_price']) ?>;
    extraChargeRemarksInput.value = <?= json_encode($editChargeData['remarks'] ?? '') ?>;
    recalcExtraChargeTotal();
    addExtraChargeBtn.dataset.editingId = <?= json_encode((string)$editChargeData['id']) ?>;
    addExtraChargeBtn.textContent = '💾 Update Charge';
    extraChargeNameInput.focus();
  <?php endif; ?>
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>

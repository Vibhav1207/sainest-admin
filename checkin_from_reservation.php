<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk']);

$bookingId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare("
  SELECT b.*, COALESCE(r.room_number, 'Unassigned') AS room_number, COALESCE(r.status, 'available') AS room_status, COALESCE(rt.name, 'Standard') AS room_type_name
  FROM bookings b
  LEFT JOIN rooms r ON r.id = b.room_id
  LEFT JOIN room_types rt ON rt.id = r.room_type_id
  WHERE b.id = :id AND b.status = 'reserved'
");
$stmt->execute(['id' => $bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    flash('error', 'Reservation not found, already checked in, or cancelled.');
    redirect('bookings.php');
}

$bookingRooms = getBookingRooms($bookingId);
$roomNumbersStr = implode(', ', array_map(fn($r) => ($r['room_number'] !== 'Unassigned' ? 'Room ' . $r['room_number'] : 'Unassigned') . ' (' . $r['room_type_name'] . ')', $bookingRooms));

$gStmt = db()->prepare("
  SELECT g.* FROM booking_guests bg JOIN guests g ON g.id = bg.guest_id
  WHERE bg.booking_id = :id AND bg.is_primary = 1 LIMIT 1
");
$gStmt->execute(['id' => $bookingId]);
$primaryGuest = $gStmt->fetch() ?: [];

// Check if any room is presently occupied by someone else
$busyRoomsArr = [];
foreach ($bookingRooms as $br) {
    if ($br['room_status'] === 'occupied') {
        $activeStmt = db()->prepare("SELECT b.id FROM bookings b LEFT JOIN booking_rooms br2 ON br2.booking_id = b.id WHERE (b.room_id = :r1 OR br2.room_id = :r2) AND b.status = 'checked_in' AND b.id != :bid LIMIT 1");
        $activeStmt->execute(['r1' => $br['room_id'], 'r2' => $br['room_id'], 'bid' => $bookingId]);
        if ($activeStmt->fetch()) {
            $busyRoomsArr[] = 'Room ' . $br['room_number'];
        }
    }
}
$roomBusyNow = !empty($busyRoomsArr);

$pageTitle = 'Complete Check-In — ' . $booking['booking_code'];
$activeNav = 'bookings';

require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-header">
  <div>
    <h2>Complete Check-In for Reservation <?= e($booking['booking_code']) ?></h2>
    <div class="desc"><?= e($roomNumbersStr) ?>. Confirm the guest's ID proof and finish checking them in.</div>
  </div>
  <div class="page-actions">
    <a href="<?= BASE_URL ?>/booking_view.php?id=<?= $booking['id'] ?>" class="btn btn-outline">← Back to Booking</a>
  </div>
</div>

<?php if ($roomBusyNow): ?>
  <div class="alert alert-warning">⚠️ <?= e(implode(', ', $busyRoomsArr)) ?> currently show another guest checked in. Please check out that guest first, or this check-in will be blocked.</div>
<?php endif; ?>

<div class="alert alert-info">📅 Reserved on <?= date('d M Y', strtotime($booking['created_at'])) ?> for arrival <?= date('d M Y, h:i A', strtotime($booking['checkin_datetime'])) ?><?php if ($booking['advance_amount'] > 0): ?> · Advance already received: <strong><?= money($booking['advance_amount']) ?></strong><?php endif; ?></div>

<form method="post" action="<?= BASE_URL ?>/checkin_from_reservation_save.php" enctype="multipart/form-data" id="convertForm">
  <?= csrfField() ?>
  <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">

  <div class="card">
    <div class="card-head"><h3>1. Confirm Stay Details</h3></div>

    <!-- Booking Type Selector -->
    <div style="background:var(--cream-light, #faf9f5); border:1.5px solid var(--border-color, #e5e0d8); border-radius:8px; padding:16px; margin-bottom:18px;">
      <label style="font-weight:700; margin-bottom:10px; display:block;">Booking Type *</label>
      <div style="display:flex; gap:24px; align-items:center;">
        <label style="cursor:pointer; display:flex; align-items:center; gap:6px; font-weight:600;">
          <input type="radio" name="booking_type" value="regular" <?= ($booking['booking_type'] ?? 'regular') === 'regular' ? 'checked' : '' ?> onchange="toggleCorporateFields()">
          👤 Regular Guest
        </label>
        <label style="cursor:pointer; display:flex; align-items:center; gap:6px; font-weight:600;">
          <input type="radio" name="booking_type" value="corporate" <?= ($booking['booking_type'] ?? '') === 'corporate' ? 'checked' : '' ?> onchange="toggleCorporateFields()">
          🏢 Corporate Booking
        </label>
      </div>

      <!-- Corporate Fields Container -->
      <div id="corporateFieldsSection" style="display:<?= ($booking['booking_type'] ?? '') === 'corporate' ? 'block' : 'none' ?>; margin-top:16px; border-top:1px solid var(--border-color, #e5e0d8); padding-top:16px;">
        <h4 style="margin-bottom:12px; font-size:0.95rem; color:var(--gold);">🏢 Corporate Billing Details</h4>
        <div class="form-row">
          <div class="form-group">
            <label>Company Name <span class="text-danger">*</span></label>
            <input type="text" name="company_name" id="companyNameInput" class="form-control" value="<?= e($booking['company_name'] ?? '') ?>" placeholder="e.g. Tata Consultancy Services Ltd">
          </div>
          <div class="form-group">
            <label>Company GST Number <span class="text-danger">*</span></label>
            <input type="text" name="company_gst_number" id="companyGstInput" class="form-control" value="<?= e($booking['company_gst_number'] ?? '') ?>" placeholder="e.g. 27AAACT1234A1Z5" style="text-transform:uppercase;">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Contact Person (optional)</label>
            <input type="text" name="company_contact_person" class="form-control" value="<?= e($booking['company_contact_person'] ?? '') ?>" placeholder="e.g. Rajesh Kumar (HR Manager)">
          </div>
          <div class="form-group">
            <label>Company Phone (optional)</label>
            <input type="text" name="company_phone" class="form-control" value="<?= e($booking['company_phone'] ?? '') ?>" placeholder="e.g. 022-66554433">
          </div>
        </div>
        <div class="form-group">
          <label>Company Address (optional)</label>
          <input type="text" name="company_address" class="form-control" value="<?= e($booking['company_address'] ?? '') ?>" placeholder="e.g. TCS House, Raveline Street, Fort, Mumbai 400001">
        </div>
      </div>
    </div>

    <!-- Rooms Allocated Table -->
    <div style="margin-bottom:18px;">
      <label style="font-weight:700; margin-bottom:8px; display:block;">🏨 Reserved Rooms (<?= count($bookingRooms) ?>)</label>
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
                <td><strong>Room <?= e($br['room_number']) ?></strong></td>
                <td>Floor <?= e($br['floor']) ?></td>
                <td><?= e($br['room_type_name']) ?></td>
                <td><?= money((float)$br['rate_per_night']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Total Rate Per Night (₹) *</label>
        <input type="number" name="rate_per_night" class="form-control" step="0.01" min="0" value="<?= e($booking['rate_per_night']) ?>" required>
      </div>
      <div class="form-group">
        <label>Expected Check-Out Date *</label>
        <input type="date" name="expected_checkout_date" class="form-control" value="<?= e($booking['expected_checkout_date']) ?>" min="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="form-group">
        <label>Tax % (GST)</label>
        <input type="number" name="tax_percent" class="form-control" step="0.01" min="0" value="<?= e($booking['tax_percent']) ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Number of Guests *</label>
        <input type="number" name="num_guests" id="numGuestsInput" class="form-control" value="<?= (int) $booking['num_guests'] ?>" min="1" max="12" required>
      </div>
      <div class="form-group">
        <label>Extra Amount (₹)</label>
        <input type="number" name="extra_amount" class="form-control" step="0.01" min="0" value="<?= e($booking['extra_amount'] ?? 0) ?>">
        <div class="tag-note" style="margin-top:4px;">Extra bed, early check-in, late check-out, extra mattress, laundry, etc.</div>
      </div>
      <div class="form-group">
        <label>Additional Advance / Payment Now</label>
        <input type="number" name="advance_amount" class="form-control" step="0.01" min="0" value="0">
      </div>
    </div>
    <div class="form-group">
      <label>Special Requests / Notes</label>
      <textarea name="special_requests" class="form-control" placeholder="Special requests or guest preferences (near-temple view, high floor, etc.)"><?= e($booking['special_requests']) ?></textarea>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>2. Guest Details &amp; ID Proof</h3></div>
    <p class="tag-note" style="margin-bottom:14px;">The primary guest's photo ID is mandatory to complete check-in. Use "Add Guest" if more than one person will be staying.</p>
    <div id="guestsContainer"></div>
    <button type="button" id="addGuestBtn" class="btn btn-outline btn-sm">➕ Add Another Guest</button>
  </div>

  <div class="card" style="text-align:right;">
    <button type="submit" class="btn btn-gold" style="min-width:240px;">✅ Complete Check-In</button>
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
const guestsContainer = document.getElementById('guestsContainer');
const guestTemplate = document.getElementById('guestTemplate');
const addGuestBtn = document.getElementById('addGuestBtn');
const numGuestsInput = document.getElementById('numGuestsInput');

function addGuestBlock(isPrimary, prefill) {
  const clone = guestTemplate.content.cloneNode(true);
  const block = clone.querySelector('.guest-block');
  const heading = clone.querySelector('.gb-heading-text');
  const removeBtn = clone.querySelector('.remove-guest-btn');

  const index = guestsContainer.children.length + 1;
  heading.textContent = isPrimary ? '👤 Primary Guest (mandatory ID proof)' : '👤 Guest ' + index;

  if (prefill) {
    if (prefill.name) block.querySelector('input[name="guest_name[]"]').value = prefill.name;
    if (prefill.phone) block.querySelector('input[name="guest_phone[]"]').value = prefill.phone;
    if (prefill.email) block.querySelector('input[name="guest_email[]"]').value = prefill.email;
    if (prefill.city) block.querySelector('input[name="guest_city[]"]').value = prefill.city;
    if (prefill.state) block.querySelector('input[name="guest_state[]"]').value = prefill.state;
  }

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
}

addGuestBtn.addEventListener('click', function () {
  addGuestBlock(false);
});

// Pre-fill the mandatory primary guest block with what we already know from the reservation
addGuestBlock(true, {
  name:  <?= json_encode($primaryGuest['full_name'] ?? '') ?>,
  phone: <?= json_encode($primaryGuest['phone'] ?? '') ?>,
  email: <?= json_encode($primaryGuest['email'] ?? '') ?>,
  city:  <?= json_encode($primaryGuest['city'] ?? '') ?>,
  state: <?= json_encode($primaryGuest['state'] ?? '') ?>
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
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>

<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk', 'housekeeping']);

$pageTitle = 'Room Management';
$activeNav = 'rooms';

$canEdit = in_array($_SESSION['role'], ['admin', 'manager'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck() && $canEdit) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_room') {
            $roomNumber = trim($_POST['room_number']);
            $typeId = (int) $_POST['room_type_id'];
            
            $expectedType = getExpectedRoomTypeName($roomNumber);
            if ($expectedType) {
                $expectedTypeId = db()->query("SELECT id FROM room_types WHERE name = '" . $expectedType . "'")->fetchColumn();
                if ($expectedTypeId && $expectedTypeId != $typeId) {
                    throw new RuntimeException("Room $roomNumber must be assigned to '$expectedType'.");
                }
            }

            $stmt = db()->prepare("INSERT INTO rooms (room_number, room_type_id, floor, status, notes) VALUES (:n, :t, :f, 'available', :notes)");
            $stmt->execute([
                'n' => $roomNumber,
                't' => $typeId,
                'f' => trim($_POST['floor']),
                'notes' => trim($_POST['notes']),
            ]);
            flash('success', 'Room added successfully.');
        } elseif ($action === 'add_room_type') {
            $stmt = db()->prepare("INSERT INTO room_types (name, base_rate, max_guests, description) VALUES (:n, :r, :m, :d)");
            $stmt->execute([
                'n' => trim($_POST['type_name']),
                'r' => (float) $_POST['base_rate'],
                'm' => (int) $_POST['max_guests'],
                'd' => trim($_POST['description']),
            ]);
            flash('success', 'Room type added successfully.');
        } elseif ($action === 'update_status') {
            $roomIdForStatus = (int) $_POST['room_id'];
            $newStatus = $_POST['status'];

            // Keep this in sync with the guest lifecycle: a room with a guest
            // currently checked in must stay "occupied" (free it via Check-Out),
            // and a room can't be forced to "occupied" here without a real
            // booking (use Check-In for that) — mirrors api/update_room_status.php.
            $activeStmt = db()->prepare("SELECT id FROM bookings WHERE room_id = :id AND status = 'checked_in' LIMIT 1");
            $activeStmt->execute(['id' => $roomIdForStatus]);
            $hasActiveBooking = (bool) $activeStmt->fetch();

            if ($hasActiveBooking && $newStatus !== 'occupied') {
                throw new RuntimeException('This room has a guest currently checked in. Use Check-Out to free it first.');
            }
            if (!$hasActiveBooking && $newStatus === 'occupied') {
                throw new RuntimeException('This room has no active booking. Use Check-In to occupy it.');
            }

            $stmt = db()->prepare("UPDATE rooms SET status = :s WHERE id = :id");
            $stmt->execute(['s' => $newStatus, 'id' => $roomIdForStatus]);

            // Marking a room "available" (i.e. cleaned) closes out any open housekeeping tasks for it.
            if ($newStatus === 'available') {
                db()->prepare("UPDATE housekeeping_tasks SET status = 'completed', completed_at = NOW() WHERE room_id = :id AND status != 'completed'")
                    ->execute(['id' => $roomIdForStatus]);
            }

            flash('success', 'Room status updated.');
        } elseif ($action === 'edit_room_type') {
            $roomId = (int)$_POST['room_id'];
            $newTypeId = (int)$_POST['room_type_id'];

            // Validate room exists
            $roomStmt = db()->prepare("SELECT * FROM rooms WHERE id = :id");
            $roomStmt->execute(['id' => $roomId]);
            $room = $roomStmt->fetch();
            if (!$room) {
                throw new RuntimeException('Room not found.');
            }

            // Validate room type exists
            $typeStmt = db()->prepare("SELECT * FROM room_types WHERE id = :id");
            $typeStmt->execute(['id' => $newTypeId]);
            $type = $typeStmt->fetch();
            if (!$type) {
                throw new RuntimeException('Selected room type does not exist.');
            }

            $expectedType = getExpectedRoomTypeName($room['room_number']);
            if ($expectedType) {
                $expectedTypeId = db()->query("SELECT id FROM room_types WHERE name = '" . $expectedType . "'")->fetchColumn();
                if ($expectedTypeId && $expectedTypeId != $newTypeId) {
                    throw new RuntimeException("Room " . $room['room_number'] . " must be assigned to '$expectedType'.");
                }
            }

            // Update room type
            $updateStmt = db()->prepare("UPDATE rooms SET room_type_id = :type_id WHERE id = :id");
            $updateStmt->execute(['type_id' => $newTypeId, 'id' => $roomId]);

            flash('success', 'Room type updated successfully.');
        }
    } catch (Throwable $e) {
        flash('error', 'Action failed: ' . $e->getMessage());
    }
    redirect('rooms.php');
}

ensureRoomTypesMigrated();
$rooms = db()->query("
  SELECT r.*, rt.name AS type_name, rt.base_rate
  FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
  ORDER BY r.floor, r.room_number
")->fetchAll();

$activeReservedRoomIds = getActiveReservationsToday();
foreach ($rooms as &$r) {
    $r['status'] = getRoomCurrentStatus($r, $activeReservedRoomIds);
}
unset($r);
$roomTypes = getRoomTypes();

require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-header">
  <div>
    <h2>Room Management</h2>
    <div class="desc">View and manage all rooms, room types and their current status.</div>
  </div>
  <?php if ($canEdit): ?>
  <div class="page-actions">
    <button class="btn btn-outline" onclick="document.getElementById('typeModal').classList.add('open')">➕ Room Type</button>
    <button class="btn btn-gold" onclick="document.getElementById('roomModal').classList.add('open')">➕ Add Room</button>
  </div>
  <?php endif; ?>
</div>

<div class="room-legend">
  <span class="room-legend-item"><i class="room-legend-dot st-available"></i> Available</span>
  <span class="room-legend-item"><i class="room-legend-dot st-occupied"></i> Occupied</span>
  <span class="room-legend-item"><i class="room-legend-dot st-dirty"></i> Needs Cleaning</span>
  <span class="room-legend-item"><i class="room-legend-dot st-maintenance"></i> Maintenance</span>
  <span class="room-legend-item"><i class="room-legend-dot st-reserved"></i> Reserved</span>
</div>

<?php if (!$rooms): ?>
  <div class="room-grid" id="roomGrid">
    <div class="empty-state"><div class="empty-icon">🚪</div>No rooms set up yet.</div>
  </div>
<?php else: ?>
  <?php
  $groupedRooms = groupAndSortRooms($rooms);
  foreach ($groupedRooms as $floor => $floorRooms):
  ?>
    <div class="floor-section">
      <div class="floor-header">
        <h3 class="floor-title">Floor <?= $floor ?></h3>
      </div>
      <div class="room-grid floor-room-grid">
        <?php foreach ($floorRooms as $r): ?>
          <?php renderRoomCard($r); ?>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="card" style="margin-top:24px;">
  <div class="card-head"><h3>Room Types</h3></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Base Rate</th><th>Max Guests</th><th>Description</th></tr></thead>
      <tbody>
      <?php foreach ($roomTypes as $t): ?>
        <tr>
          <td><strong><?= e($t['name']) ?></strong></td>
          <td><?= money($t['base_rate']) ?></td>
          <td><?= $t['max_guests'] ?></td>
          <td class="text-muted"><?= e($t['description']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Room Modal -->
<div class="modal-overlay" id="roomModal">
  <div class="modal-box">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_room">
      <div class="modal-head"><h3>Add New Room</h3><button type="button" class="modal-close" onclick="document.getElementById('roomModal').classList.remove('open')">✕</button></div>
      <div class="modal-body">
        <div class="form-group"><label>Room Number *</label><input type="text" id="addRoomNumberInput" name="room_number" class="form-control" required></div>
        <div class="form-group"><label>Room Type *</label>
          <select name="room_type_id" id="addRoomTypeSelect" class="form-control" required>
            <?php foreach ($roomTypes as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Floor</label><input type="text" name="floor" class="form-control"></div>
        <div class="form-group"><label>Notes</label><input type="text" name="notes" class="form-control"></div>
      </div>
      <div class="modal-foot"><button type="submit" class="btn btn-gold">Add Room</button></div>
    </form>
  </div>
</div>

<!-- Add Room Type Modal -->
<div class="modal-overlay" id="typeModal">
  <div class="modal-box">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_room_type">
      <div class="modal-head"><h3>Add Room Type</h3><button type="button" class="modal-close" onclick="document.getElementById('typeModal').classList.remove('open')">✕</button></div>
      <div class="modal-body">
        <div class="form-group"><label>Type Name *</label><input type="text" name="type_name" class="form-control" required></div>
        <div class="form-group"><label>Base Rate (₹) *</label><input type="number" name="base_rate" class="form-control" step="0.01" required></div>
        <div class="form-group"><label>Max Guests *</label><input type="number" name="max_guests" class="form-control" value="2" required></div>
        <div class="form-group"><label>Description</label><input type="text" name="description" class="form-control"></div>
      </div>
      <div class="modal-foot"><button type="submit" class="btn btn-gold">Add Room Type</button></div>
    </form>
  </div>
</div>

<!-- Update Status Modal — instant AJAX update, no page reload. Tile colour
     changes the moment the server confirms the change, so status is always
     reflected immediately and unambiguously by colour. -->
<div class="modal-overlay" id="statusModal">
  <div class="modal-box">
    <div class="modal-head"><h3 id="statusModalTitle">Room</h3><button type="button" class="modal-close" onclick="closeStatusModal()">✕</button></div>
    <div class="modal-body">
      <div id="statusModalError" class="room-modal-error" style="display:none;"></div>
      <?php if ($canEdit): ?>
        <div class="hk-status-label" style="font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); margin-bottom:10px;">Set status</div>
        <div class="room-status-grid" id="statusGrid">
          <button type="button" class="room-status-btn st-available" data-status="available" onclick="setRoomStatus('available')">✅ Available</button>
          <button type="button" class="room-status-btn st-dirty" data-status="dirty" onclick="setRoomStatus('dirty')">🧹 Needs Cleaning</button>
          <button type="button" class="room-status-btn st-maintenance" data-status="maintenance" onclick="setRoomStatus('maintenance')">🔧 Maintenance</button>
          <button type="button" class="room-status-btn st-reserved" data-status="reserved" onclick="setRoomStatus('reserved')">📌 Reserved</button>
          <button type="button" class="room-status-btn st-occupied" data-status="occupied" onclick="setRoomStatus('occupied')" disabled title="Use Check-In to occupy a room">🛏️ Occupied</button>
        </div>
        <div class="room-modal-hint">Occupied ⇄ free transitions happen automatically via Check-In / Check-Out. Use the buttons above for cleaning, maintenance and reservation states.</div>
      <?php else: ?>
        <p class="text-muted">You don't have permission to change room status. Contact an admin or manager.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="modal-overlay" id="guestModal">
  <div class="modal-box" style="max-width: 600px;">
    <div class="modal-head"><h3 id="guestModalTitle">Occupant Information</h3><button type="button" class="modal-close" onclick="closeGuestModal()">✕</button></div>
    <div class="modal-body" id="guestModalBody">
      <!-- Loaded dynamically -->
    </div>
  </div>
</div>

<!-- Edit Room Type Modal -->
<div class="modal-overlay" id="editRoomModal">
  <div class="modal-box">
    <form method="post" id="editRoomForm" onsubmit="return handleEditRoomSubmit(event)">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit_room_type">
      <input type="hidden" name="room_id" id="editRoomId">
      <input type="hidden" name="room_status" id="editRoomStatus">
      <div class="modal-head">
        <h3>Edit Room Type — Room <span id="editRoomNumberHeader"></span></h3>
        <button type="button" class="modal-close" onclick="document.getElementById('editRoomModal').classList.remove('open')">✕</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label>Room Number</label>
          <input type="text" id="editRoomNumberDisplay" class="form-control" disabled>
        </div>
        <div class="form-group">
          <label>Room Type *</label>
          <select name="room_type_id" id="editRoomTypeSelect" class="form-control" required>
            <?php foreach ($roomTypes as $t): ?>
              <option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-gold">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<div id="roomToast" class="room-toast"></div>

<script>
const ROOM_CSRF = "<?= csrfToken() ?>";
const ROOM_BASE_URL = "<?= BASE_URL ?>";
const ROOM_CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
const ROOM_STATUS_META = {
  available:   { icon: '✅', label: 'Available',      badge: 'badge-green' },
  occupied:    { icon: '🛏️', label: 'Occupied',        badge: 'badge-gold' },
  dirty:       { icon: '🧹', label: 'Needs Cleaning',  badge: 'badge-red' },
  maintenance: { icon: '🔧', label: 'Maintenance',     badge: 'badge-gray' },
  reserved:    { icon: '📌', label: 'Reserved',        badge: 'badge-blue' },
};

const ROOM_TYPE_MAPPING = {
  'Standard 2 Bed': ['101', '102', '103', '105'],
  'Deluxe Suite 2 Bed': ['104', '205', '305', '401', '402', '403', '404', '405', '406'],
  'Standard 3 Bed': ['201', '202', '203', '206', '208', '301', '303', '306', '308'],
};

function enforceRoomTypeSelection(roomNumberInput, roomTypeSelect) {
  const num = roomNumberInput.value.trim();
  let expectedTypeName = null;
  for (const [typeName, numbers] of Object.entries(ROOM_TYPE_MAPPING)) {
    if (numbers.includes(num)) {
      expectedTypeName = typeName;
      break;
    }
  }

  if (expectedTypeName) {
    // Find the option with this text
    for (let i = 0; i < roomTypeSelect.options.length; i++) {
      if (roomTypeSelect.options[i].text === expectedTypeName) {
        roomTypeSelect.selectedIndex = i;
        roomTypeSelect.setAttribute('readonly', 'readonly');
        roomTypeSelect.style.pointerEvents = 'none';
        roomTypeSelect.style.backgroundColor = '#f1f0ec';
        break;
      }
    }
  } else {
    // Unlock
    roomTypeSelect.removeAttribute('readonly');
    roomTypeSelect.style.pointerEvents = 'auto';
    roomTypeSelect.style.backgroundColor = '';
  }
}

// Add Room listeners
const addRoomNumInput = document.getElementById('addRoomNumberInput');
if (addRoomNumInput) {
  addRoomNumInput.addEventListener('input', function() {
    enforceRoomTypeSelection(this, document.getElementById('addRoomTypeSelect'));
  });
}

let roomActiveTile = null;

function openStatusModal(tile) {
  roomActiveTile = tile;
  const status = tile.dataset.status;
  const number = tile.dataset.roomNumber;

  document.getElementById('statusModalTitle').textContent = 'Room ' + number + ' — Update Status';
  document.getElementById('statusModalError').style.display = 'none';

  if (ROOM_CAN_EDIT) {
    document.querySelectorAll('.room-status-btn').forEach(btn => {
      const s = btn.dataset.status;
      btn.classList.toggle('current', s === status);
      btn.disabled = (s === 'occupied') || (s === status); // occupied only via Check-In; current status can't be re-picked
    });
  }

  document.getElementById('statusModal').classList.add('open');
}

function closeStatusModal() {
  document.getElementById('statusModal').classList.remove('open');
  roomActiveTile = null;
}

function setRoomStatus(newStatus) {
  if (!roomActiveTile) return;
  const roomId = roomActiveTile.dataset.roomId;
  const errorBox = document.getElementById('statusModalError');
  errorBox.style.display = 'none';

  document.querySelectorAll('.room-status-btn').forEach(b => b.disabled = true);

  const body = new URLSearchParams();
  body.set('csrf_token', ROOM_CSRF);
  body.set('room_id', roomId);
  body.set('status', newStatus);

  fetch(ROOM_BASE_URL + '/api/update_room_status.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body.toString(),
  })
  .then(r => r.json())
  .then(data => {
    if (!data.success) {
      errorBox.textContent = data.message || 'Could not update room status.';
      errorBox.style.display = 'block';
      document.querySelectorAll('.room-status-btn').forEach(btn => {
        btn.disabled = (btn.dataset.status === 'occupied') || (btn.dataset.status === roomActiveTile.dataset.status);
      });
      return;
    }
    applyStatusToTile(roomActiveTile, data.status);
    roomToast('Room ' + data.room_number + ' set to ' + data.label);
    closeStatusModal();
  })
  .catch(() => {
    errorBox.textContent = 'Network error — please check your connection and try again.';
    errorBox.style.display = 'block';
    document.querySelectorAll('.room-status-btn').forEach(btn => {
      btn.disabled = (btn.dataset.status === 'occupied') || (btn.dataset.status === roomActiveTile.dataset.status);
    });
  });
}

function applyStatusToTile(tile, status) {
  const meta = ROOM_STATUS_META[status];
  tile.classList.remove('st-available', 'st-occupied', 'st-dirty', 'st-maintenance', 'st-reserved');
  tile.classList.add('st-' + status);
  tile.dataset.status = status;
  const badgeSlot = tile.querySelector('.badge-slot');
  if (badgeSlot) {
    badgeSlot.innerHTML = '<span class="badge ' + meta.badge + '">' + meta.label + '</span>';
  }
  tile.classList.remove('room-flash');
  void tile.offsetWidth; // restart animation
  tile.classList.add('room-flash');
  setTimeout(() => {
    tile.classList.remove('room-flash');
  }, 500);
}

let roomToastTimer = null;
function roomToast(msg) {
  const el = document.getElementById('roomToast');
  el.textContent = msg;
  el.classList.add('show');
  clearTimeout(roomToastTimer);
  roomToastTimer = setTimeout(() => el.classList.remove('show'), 2800);
}

function handleRoomTileClick(tile) {
  const status = tile.dataset.status;
  if (status === 'occupied') {
    openGuestModal(tile);
  } else {
    openStatusModal(tile);
  }
}

function openGuestModal(tile) {
  const roomId = tile.dataset.roomId;
  const number = tile.dataset.roomNumber;
  
  document.getElementById('guestModalTitle').textContent = 'Room ' + number + ' — Active Check-In';
  const body = document.getElementById('guestModalBody');
  body.innerHTML = '<div style="text-align:center; padding: 20px;"><span class="text-muted">Loading guest details...</span></div>';
  
  document.getElementById('guestModal').classList.add('open');
  
  fetch(ROOM_BASE_URL + '/api/get_occupied_room_guest.php?room_id=' + roomId)
    .then(r => r.json())
    .then(data => {
      if (!data.success) {
        body.innerHTML = '<div class="alert alert-error">' + escapeHtml(data.message || 'Could not fetch guest details.') + '</div>';
        return;
      }
      
      const b = data.booking;
      const guests = data.guests;
      
      let html = '';
      
      const roomsList = (res.booking_rooms || []).map(r => 'Room ' + r.room_number + ' (' + r.room_type_name + ')').join(', ') || ('Room ' + roomNum);

      html += '<div style="margin-bottom:18px;">';
      html += '  <div class="stat-row"><span class="lbl">Booked Room(s)</span><span class="val">' + escapeHtml(roomsList) + '</span></div>';
      html += '  <div class="stat-row"><span class="lbl">Check-In Time</span><span class="val">' + formatDate(b.checkin_datetime) + '</span></div>';
      html += '  <div class="stat-row"><span class="lbl">Expected Check-Out</span><span class="val">' + formatDate(b.expected_checkout_date, true) + '</span></div>';
      html += '  <div class="stat-row"><span class="lbl">Total Guests</span><span class="val">' + guests.length + '</span></div>';
      if (b.booking_type === 'corporate') {
        html += '  <div class="stat-row"><span class="lbl">Booking Type</span><span class="val"><span class="badge badge-gold">🏢 Corporate Booking</span></span></div>';
        html += '  <div class="stat-row"><span class="lbl">Company Name</span><span class="val"><strong>' + escapeHtml(b.company_name) + '</strong></span></div>';
        if (b.company_gst_number) {
          html += '  <div class="stat-row"><span class="lbl">Company GSTIN</span><span class="val"><code>' + escapeHtml(b.company_gst_number) + '</code></span></div>';
        }
      }
      if (b.extra_amount && parseFloat(b.extra_amount) > 0) {
        html += '  <div class="stat-row"><span class="lbl">Total Extra Charges</span><span class="val">₹' + parseFloat(b.extra_amount).toFixed(2) + '</span></div>';
      }
      html += '  <div class="stat-row"><span class="lbl">Booking Status</span><span class="val"><span class="badge badge-gold">Occupied</span></span></div>';
      html += '  <div class="stat-row"><span class="lbl">Actions</span><span class="val"><a href="' + ROOM_BASE_URL + '/booking_view.php?id=' + b.booking_id + '" style="color:var(--gold); font-weight:bold; text-decoration:underline;">View Details</a> &nbsp;·&nbsp; <a href="' + ROOM_BASE_URL + '/booking_edit_stay.php?id=' + b.booking_id + '" class="btn btn-sm btn-gold" style="display:inline-block; margin-left:6px;">✏️ Edit Stay / Add Charges</a></span></div>';
      html += '</div>';

      if (res.extra_charges && res.extra_charges.length > 0) {
        html += '<div style="background:#faf9f5; border:1px solid #e5e0d8; border-radius:6px; padding:12px; margin-bottom:18px;">';
        html += '  <h5 style="margin-bottom:8px; color:var(--text-color);">☕ Extra Charges Added During Stay:</h5>';
        html += '  <ul style="margin:0; padding-left:18px; font-size:0.88rem;">';
        res.extra_charges.forEach(ec => {
          html += '    <li><strong>' + escapeHtml(ec.charge_name) + '</strong> (' + parseFloat(ec.qty) + ' × ₹' + parseFloat(ec.unit_price).toFixed(2) + ' = <strong>₹' + parseFloat(ec.total_amount).toFixed(2) + '</strong>)' + (ec.remarks ? ' <em>(' + escapeHtml(ec.remarks) + ')</em>' : '') + '</li>';
        });
        html += '  </ul>';
        html += '</div>';
      }
      
      html += '<div class="divider"></div>';
      html += '<h4 style="margin-bottom:12px; font-family:\'Playfair Display\', serif;">Guests in Room</h4>';
      
      guests.forEach((g) => {
        html += '<div class="guest-block ' + (g.is_primary == 1 ? 'primary' : '') + '" style="cursor:default; margin-bottom:14px; padding:16px;">';
        html += '  <div class="gb-title" style="margin-bottom:10px;">';
        html += '    <span class="gb-heading-text" style="font-weight:700;">👤 ' + escapeHtml(g.full_name) + '</span>';
        if (g.is_primary == 1) {
          html += '    <span class="badge badge-gold" style="float:right;">Primary</span>';
        }
        html += '  </div>';
        
        html += '  <div class="form-row" style="grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap: 10px; font-size:0.88rem; line-height:1.6;">';
        if (g.phone) html += '    <div><span class="text-muted">Phone:</span> <strong>' + escapeHtml(g.phone) + '</strong></div>';
        if (g.email) html += '    <div><span class="text-muted">Email:</span> <strong>' + escapeHtml(g.email) + '</strong></div>';
        if (g.age) html += '      <div><span class="text-muted">Age:</span> <strong>' + g.age + ' yrs</strong></div>';
        if (g.gender) html += '   <div><span class="text-muted">Gender:</span> <strong>' + escapeHtml(g.gender.charAt(0).toUpperCase() + g.gender.slice(1)) + '</strong></div>';
        if (g.city || g.state) html += ' <div><span class="text-muted">From:</span> <strong>' + escapeHtml([g.city, g.state].filter(Boolean).join(', ')) + '</strong></div>';
        if (g.address) html += '  <div style="grid-column: 1 / -1;"><span class="text-muted">Address:</span> <strong>' + escapeHtml(g.address) + '</strong></div>';
        
        if (g.id_proof_type) {
          html += '  <div style="grid-column: 1 / -1; border-top:1px dashed var(--cream-dark); padding-top:8px; margin-top:4px;">';
          html += '    <span class="text-muted">ID Proof:</span> <strong>' + escapeHtml(g.id_proof_type.replace(/_/g, ' ').toUpperCase()) + '</strong> — <strong>' + escapeHtml(g.id_proof_number || '—') + '</strong>';
          html += '  </div>';
        }
        html += '  </div>';
        
        if (g.id_proof_photo || g.id_proof_photo_back) {
          html += '  <div style="display:flex; gap:8px; margin-top:12px;">';
          if (g.id_proof_photo) {
            html += '    <a href="' + ROOM_BASE_URL + '/doc_view.php?f=' + encodeURIComponent(g.id_proof_photo) + '" target="_blank" class="btn btn-sm btn-outline" style="padding: 6px 12px; font-size:0.75rem;">📄 View ID Front</a>';
          }
          if (g.id_proof_photo_back) {
            html += '    <a href="' + ROOM_BASE_URL + '/doc_view.php?f=' + encodeURIComponent(g.id_proof_photo_back) + '" target="_blank" class="btn btn-sm btn-outline" style="padding: 6px 12px; font-size:0.75rem;">📄 View ID Back</a>';
          }
          html += '  </div>';
        }
        html += '</div>';
      });
      
      body.innerHTML = html;
    })
    .catch(() => {
      body.innerHTML = '<div class="alert alert-error">Network error — please check your connection and try again.</div>';
    });
}

function closeGuestModal() {
  document.getElementById('guestModal').classList.remove('open');
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function formatDate(dateStr, dateOnly = false) {
  if (!dateStr) return '';
  const date = new Date(dateStr.replace(/-/g, "/"));
  const options = dateOnly 
    ? { day: '2-digit', month: 'short', year: 'numeric' }
    : { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true };
  return date.toLocaleDateString('en-IN', options);
}

document.getElementById('statusModal').addEventListener('click', function (e) {
  if (e.target === this) closeStatusModal();
});

document.getElementById('guestModal').addEventListener('click', function (e) {
  if (e.target === this) closeGuestModal();
});

function openEditRoomModal(e, id, number, typeId, status) {
  e.stopPropagation();
  document.getElementById('editRoomId').value = id;
  document.getElementById('editRoomStatus').value = status;
  document.getElementById('editRoomNumberHeader').textContent = number;
  document.getElementById('editRoomNumberDisplay').value = number;
  const select = document.getElementById('editRoomTypeSelect');
  select.value = typeId;
  enforceRoomTypeSelection(document.getElementById('editRoomNumberDisplay'), select);
  document.getElementById('editRoomModal').classList.add('open');
}

function handleEditRoomSubmit(e) {
  const status = document.getElementById('editRoomStatus').value;
  if (status === 'occupied') {
    const confirmChange = confirm("Warning: This room is currently occupied. Changing its room type will update its rate and classification for future bookings, but the active check-in stay rate remains unchanged. Do you want to proceed?");
    if (!confirmChange) {
      e.preventDefault();
      return false;
    }
  }
  return true;
}

document.getElementById('editRoomModal').addEventListener('click', function (e) {
  if (e.target === this) {
    this.classList.remove('open');
  }
});</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>

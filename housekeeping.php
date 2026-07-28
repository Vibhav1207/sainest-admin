<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk', 'housekeeping']);

$pageTitle = 'Housekeeping';
$activeNav = 'housekeeping';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_task') {
            $allowedTaskTypes = ['cleaning', 'maintenance', 'inspection', 'laundry', 'other'];
            $allowedPriorities = ['low', 'normal', 'high', 'urgent'];
            $taskType = $_POST['task_type'];
            $priority = $_POST['priority'];
            if (!in_array($taskType, $allowedTaskTypes, true)) {
                throw new RuntimeException('Invalid task type.');
            }
            if (!in_array($priority, $allowedPriorities, true)) {
                throw new RuntimeException('Invalid priority level.');
            }
            $stmt = db()->prepare("INSERT INTO housekeeping_tasks (room_id, task_type, status, priority, assigned_to, notes, created_by) VALUES (:r,:t,'pending',:p,:a,:n,:u)");
            $stmt->execute([
                'r' => (int) $_POST['room_id'],
                't' => $taskType,
                'p' => $priority,
                'a' => $_POST['assigned_to'] ?: null,
                'n' => trim($_POST['notes']),
                'u' => $_SESSION['user_id'],
            ]);
            flash('success', 'Housekeeping task created.');
        } elseif ($action === 'update_task') {
            $allowedStatuses = ['pending', 'in_progress', 'completed'];
            $taskId = (int) $_POST['task_id'];
            $status = $_POST['status'];
            if (!in_array($status, $allowedStatuses, true)) {
                throw new RuntimeException('Invalid task status.');
            }
            $completedAt = ($status === 'completed') ? new DateTime() : null;
            $stmt = db()->prepare("UPDATE housekeeping_tasks SET status = :s, completed_at = :c WHERE id = :id");
            $stmt->execute(['s' => $status, 'c' => $completedAt, 'id' => $taskId]);

            if ($status === 'completed') {
                $taskStmt = db()->prepare("SELECT room_id FROM housekeeping_tasks WHERE id = :id");
                $taskStmt->execute(['id' => $taskId]);
                $roomId = $taskStmt->fetch()['room_id'] ?? null;
                if ($roomId) {
                    $pending = db()->prepare("SELECT COUNT(*) c FROM housekeeping_tasks WHERE room_id = :r AND status != 'completed'");
                    $pending->execute(['r' => $roomId]);
                    if ((int) $pending->fetch()['c'] === 0) {
                        db()->prepare("UPDATE rooms SET status = 'available' WHERE id = :id AND status = 'dirty'")->execute(['id' => $roomId]);
                    }
                }
            }
            flash('success', 'Task updated.');
        }
    } catch (Throwable $e) {
        flash('error', 'Action failed: ' . $e->getMessage());
    }
    redirect('housekeeping.php');
}

$tasks = db()->query("
  SELECT h.*, r.room_number, u.full_name AS assigned_name
  FROM housekeeping_tasks h
  JOIN rooms r ON r.id = h.room_id
  LEFT JOIN users u ON u.id = h.assigned_to
  ORDER BY FIELD(h.status,'pending','in_progress','completed'), h.priority='urgent' DESC, h.created_at DESC
")->fetchAll();

$rooms = db()->query("SELECT id, room_number FROM rooms ORDER BY room_number")->fetchAll();
$staff = db()->query("SELECT id, full_name FROM users WHERE role IN ('housekeeping','frontdesk','manager') AND status='active' ORDER BY full_name")->fetchAll();

// Full room board for the visual dashboard: current status + who's in the room right now (if any)
ensureRoomTypesMigrated();
$roomBoard = db()->query("
  SELECT r.id, r.room_number, r.floor, r.status, rt.name AS type_name,
         b.id AS booking_id, b.expected_checkout_date, b.checkin_datetime,
         g.full_name AS guest_name,
         (SELECT COUNT(*) FROM housekeeping_tasks ht WHERE ht.room_id = r.id AND ht.status != 'completed') AS open_tasks
  FROM rooms r
  JOIN room_types rt ON rt.id = r.room_type_id
  LEFT JOIN bookings b ON b.room_id = r.id AND b.status = 'checked_in'
  LEFT JOIN guests g ON g.id = b.primary_guest_id
  ORDER BY r.floor, r.room_number
")->fetchAll();

$activeReservedRoomIds = getActiveReservationsToday();
foreach ($roomBoard as &$rb) {
    $rb['status'] = getRoomCurrentStatus($rb, $activeReservedRoomIds);
}
unset($rb);

$roomStatusMeta = [
    'available'   => ['label' => 'Available',      'icon' => '✅', 'class' => 'st-available'],
    'occupied'    => ['label' => 'Occupied',        'icon' => '🛏️', 'class' => 'st-occupied'],
    'dirty'       => ['label' => 'Needs Cleaning',  'icon' => '🧹', 'class' => 'st-dirty'],
    'maintenance' => ['label' => 'Maintenance',     'icon' => '🔧', 'class' => 'st-maintenance'],
    'reserved'    => ['label' => 'Reserved',        'icon' => '📌', 'class' => 'st-reserved'],
];

require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-header">
  <div>
    <h2>Housekeeping</h2>
    <div class="desc">Track cleaning, maintenance and inspection tasks across all rooms.</div>
  </div>
  <div class="page-actions">
    <button class="btn btn-gold" onclick="document.getElementById('taskModal').classList.add('open')">➕ New Task</button>
  </div>
</div>

<!-- ===================== ROOM STATUS DASHBOARD ===================== -->
<div class="card hk-board-card">
  <div class="card-head hk-board-head">
    <h3>🏨 Room Status Dashboard</h3>
    <div class="hk-legend">
      <span class="hk-legend-item"><i class="hk-dot st-available"></i> Available</span>
      <span class="hk-legend-item"><i class="hk-dot st-occupied"></i> Occupied</span>
      <span class="hk-legend-item"><i class="hk-dot st-dirty"></i> Needs Cleaning</span>
      <span class="hk-legend-item"><i class="hk-dot st-maintenance"></i> Maintenance</span>
      <span class="hk-legend-item"><i class="hk-dot st-reserved"></i> Reserved</span>
    </div>
  </div>
  <div class="hk-board-sub">Tap any room to view details and update its status.</div>

  <div class="hk-room-dashboard" id="hkRoomDashboard">
    <?php if (!$roomBoard): ?>
      <div class="empty-state"><div class="empty-icon">🚪</div>No rooms set up yet.</div>
    <?php else: ?>
      <?php
      $groupedRoomBoard = groupAndSortRooms($roomBoard);
      $boxIndex = 0;
      foreach ($groupedRoomBoard as $floor => $floorRooms):
      ?>
        <div class="floor-section">
          <div class="floor-header">
            <h3 class="floor-title">Floor <?= $floor ?></h3>
          </div>
          <div class="hk-room-grid floor-room-grid">
            <?php foreach ($floorRooms as $rb):
              $meta = $roomStatusMeta[$rb['status']] ?? $roomStatusMeta['available'];
            ?>
              <div class="hk-room-box <?= $meta['class'] ?>"
                   id="hkRoom-<?= $rb['id'] ?>"
                   style="--i:<?= $boxIndex++ ?>"
                   data-room-id="<?= $rb['id'] ?>"
                   data-room-number="<?= e($rb['room_number']) ?>"
                   data-status="<?= e($rb['status']) ?>"
                   data-type="<?= e($rb['type_name']) ?>"
                   data-floor="<?= e($rb['floor'] ?? '') ?>"
                   data-guest="<?= e($rb['guest_name'] ?? '') ?>"
                   data-checkout="<?= e($rb['expected_checkout_date'] ?? '') ?>"
                   data-open-tasks="<?= (int) $rb['open_tasks'] ?>"
                   onclick="hkOpenRoomModal(this)">
                <?php if ((int) $rb['open_tasks'] > 0): ?><span class="hk-task-pip" title="Open housekeeping task"><?= (int) $rb['open_tasks'] ?></span><?php endif; ?>
                <div class="hk-room-icon"><?= $meta['icon'] ?></div>
                <div class="hk-room-number"><?= e($rb['room_number']) ?></div>
                <div class="hk-room-floor"><?= e($rb['floor'] ? 'Floor ' . $rb['floor'] : $rb['type_name']) ?></div>
                <div class="hk-room-status-label"><?= e($meta['label']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<div class="tabs no-print">
  <button class="tab-btn active" data-filter="all">All (<?= count($tasks) ?>)</button>
  <button class="tab-btn" data-filter="pending">Pending</button>
  <button class="tab-btn" data-filter="in_progress">In Progress</button>
  <button class="tab-btn" data-filter="completed">Completed</button>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data-table" id="taskTable">
      <thead><tr><th>Room</th><th>Task</th><th>Priority</th><th>Assigned To</th><th>Notes</th><th>Status</th><th>Created</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($tasks as $t): ?>
        <tr data-status="<?= e($t['status']) ?>">
          <td><strong><?= e($t['room_number']) ?></strong></td>
          <td><?= e(ucfirst($t['task_type'])) ?></td>
          <td><?= $t['priority'] === 'urgent' ? '<span class="badge badge-red">Urgent</span>' : '<span class="badge badge-gray">Normal</span>' ?></td>
          <td><?= e($t['assigned_name'] ?? '—') ?></td>
          <td class="text-muted"><?= e($t['notes']) ?></td>
          <td>
            <?php
              $statusBadges = ['pending' => 'badge-red', 'in_progress' => 'badge-gold', 'completed' => 'badge-green'];
              $statusLabels = ['pending' => 'Pending', 'in_progress' => 'In Progress', 'completed' => 'Completed'];
            ?>
            <span class="badge <?= $statusBadges[$t['status']] ?>"><?= $statusLabels[$t['status']] ?></span>
          </td>
          <td class="nowrap text-muted"><?= date('d M, h:i A', strtotime($t['created_at'])) ?></td>
          <td class="nowrap">
            <?php if ($t['status'] !== 'completed'): ?>
              <form method="post" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_task">
                <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                <?php if ($t['status'] === 'pending'): ?>
                  <input type="hidden" name="status" value="in_progress">
                  <button class="btn btn-sm btn-outline">Start</button>
                <?php else: ?>
                  <input type="hidden" name="status" value="completed">
                  <button class="btn btn-sm btn-green">Mark Done</button>
                <?php endif; ?>
              </form>
            <?php else: ?>
              <span class="text-muted">✓ <?= $t['completed_at'] ? date('d M, h:i A', strtotime($t['completed_at'])) : '' ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$tasks): ?>
        <tr><td colspan="8"><div class="empty-state"><div class="empty-icon">🧹</div>No housekeeping tasks yet.</div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-overlay" id="taskModal">
  <div class="modal-box">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create_task">
      <div class="modal-head"><h3>New Housekeeping Task</h3><button type="button" class="modal-close" onclick="document.getElementById('taskModal').classList.remove('open')">✕</button></div>
      <div class="modal-body">
        <div class="form-group"><label>Room *</label>
          <select name="room_id" class="form-control" required>
            <?php foreach ($rooms as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['room_number']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Task Type</label>
          <select name="task_type" class="form-control">
            <option value="cleaning">Cleaning</option>
            <option value="maintenance">Maintenance</option>
            <option value="inspection">Inspection</option>
            <option value="turndown">Turndown</option>
          </select>
        </div>
        <div class="form-group"><label>Priority</label>
          <select name="priority" class="form-control">
            <option value="normal">Normal</option>
            <option value="urgent">Urgent</option>
          </select>
        </div>
        <div class="form-group"><label>Assign To</label>
          <select name="assigned_to" class="form-control">
            <option value="">-- Unassigned --</option>
            <?php foreach ($staff as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['full_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Notes</label><textarea name="notes" class="form-control"></textarea></div>
      </div>
      <div class="modal-foot"><button type="submit" class="btn btn-gold">Create Task</button></div>
    </form>
  </div>
</div>

<!-- Room detail + quick status change modal (used by the Room Status Dashboard above) -->
<div class="modal-overlay" id="hkRoomModal">
  <div class="modal-box hk-modal-box">
    <div class="modal-head">
      <h3 id="hkModalTitle">Room</h3>
      <button type="button" class="modal-close" onclick="hkCloseRoomModal()">✕</button>
    </div>
    <div class="modal-body">
      <div id="hkModalInfo" class="hk-modal-info"></div>
      <div id="hkModalError" class="hk-modal-error" style="display:none;"></div>

      <div class="hk-status-label">Set status</div>
      <div class="hk-status-grid" id="hkStatusGrid">
        <button type="button" class="hk-status-btn st-available" data-status="available" onclick="hkSetStatus('available')">✅ Available</button>
        <button type="button" class="hk-status-btn st-dirty" data-status="dirty" onclick="hkSetStatus('dirty')">🧹 Needs Cleaning</button>
        <button type="button" class="hk-status-btn st-maintenance" data-status="maintenance" onclick="hkSetStatus('maintenance')">🔧 Maintenance</button>
        <button type="button" class="hk-status-btn st-reserved" data-status="reserved" onclick="hkSetStatus('reserved')">📌 Reserved</button>
        <button type="button" class="hk-status-btn st-occupied" data-status="occupied" onclick="hkSetStatus('occupied')" disabled title="Use Check-In to occupy a room">🛏️ Occupied</button>
      </div>
      <div class="hk-modal-hint">Occupied ⇄ free transitions happen automatically via Check-In / Check-Out. Use the buttons above for cleaning, maintenance and reservation states.</div>
    </div>
  </div>
</div>

<div id="hkToast" class="hk-toast"></div>

<script>
const HK_CSRF = "<?= csrfToken() ?>";
const HK_BASE_URL = "<?= BASE_URL ?>";
const HK_STATUS_META = {
  available:   { icon: '✅', label: 'Available' },
  occupied:    { icon: '🛏️', label: 'Occupied' },
  dirty:       { icon: '🧹', label: 'Needs Cleaning' },
  maintenance: { icon: '🔧', label: 'Maintenance' },
  reserved:    { icon: '📌', label: 'Reserved' },
};

let hkActiveBox = null;

function hkOpenRoomModal(box) {
  hkActiveBox = box;
  const status   = box.dataset.status;
  const number   = box.dataset.roomNumber;
  const type     = box.dataset.type;
  const floor    = box.dataset.floor;
  const guest    = box.dataset.guest;
  const checkout = box.dataset.checkout;
  const openTasks = parseInt(box.dataset.openTasks || '0', 10);

  document.getElementById('hkModalTitle').textContent = 'Room ' + number;
  document.getElementById('hkModalError').style.display = 'none';

  let infoHtml = '<div class="hk-info-row"><span>Type</span><strong>' + hkEsc(type) + (floor ? ' · Floor ' + hkEsc(floor) : '') + '</strong></div>';
  infoHtml += '<div class="hk-info-row"><span>Current Status</span><strong class="hk-current-badge ' + 'st-' + status + '">' + HK_STATUS_META[status].icon + ' ' + HK_STATUS_META[status].label + '</strong></div>';
  if (guest) {
    infoHtml += '<div class="hk-info-row"><span>Guest</span><strong>' + hkEsc(guest) + '</strong></div>';
    if (checkout) infoHtml += '<div class="hk-info-row"><span>Expected Check-out</span><strong>' + hkEsc(checkout) + '</strong></div>';
  }
  if (openTasks > 0) {
    infoHtml += '<div class="hk-info-row"><span>Open Tasks</span><strong>' + openTasks + ' pending</strong></div>';
  }
  document.getElementById('hkModalInfo').innerHTML = infoHtml;

  document.querySelectorAll('.hk-status-btn').forEach(btn => {
    const s = btn.dataset.status;
    btn.classList.toggle('current', s === status);
    if (s === 'occupied') {
      btn.disabled = true; // occupied is always driven by Check-In, never set manually
    } else {
      btn.disabled = (s === status);
    }
  });

  document.getElementById('hkRoomModal').classList.add('open');
}

function hkCloseRoomModal() {
  document.getElementById('hkRoomModal').classList.remove('open');
  hkActiveBox = null;
}

function hkSetStatus(newStatus) {
  if (!hkActiveBox) return;
  const roomId = hkActiveBox.dataset.roomId;
  const errorBox = document.getElementById('hkModalError');
  errorBox.style.display = 'none';

  document.querySelectorAll('.hk-status-btn').forEach(b => b.disabled = true);

  const body = new URLSearchParams();
  body.set('csrf_token', HK_CSRF);
  body.set('room_id', roomId);
  body.set('status', newStatus);

  fetch(HK_BASE_URL + '/api/update_room_status.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body.toString(),
  })
  .then(r => r.json())
  .then(data => {
    if (!data.success) {
      errorBox.textContent = data.message || 'Could not update room status.';
      errorBox.style.display = 'block';
      document.querySelectorAll('.hk-status-btn').forEach(btn => {
        btn.disabled = (btn.dataset.status === 'occupied') || (btn.dataset.status === hkActiveBox.dataset.status);
      });
      return;
    }
    hkApplyStatusToBox(hkActiveBox, data.status);
    hkToast('Room ' + data.room_number + ' set to ' + data.label);
    hkCloseRoomModal();
  })
  .catch(() => {
    errorBox.textContent = 'Network error — please check your connection and try again.';
    errorBox.style.display = 'block';
    document.querySelectorAll('.hk-status-btn').forEach(btn => {
      btn.disabled = (btn.dataset.status === 'occupied') || (btn.dataset.status === hkActiveBox.dataset.status);
    });
  });
}

function hkApplyStatusToBox(box, status) {
  const meta = HK_STATUS_META[status];
  box.classList.remove('st-available', 'st-occupied', 'st-dirty', 'st-maintenance', 'st-reserved');
  box.classList.add('st-' + status);
  box.dataset.status = status;
  box.querySelector('.hk-room-icon').textContent = meta.icon;
  box.querySelector('.hk-room-status-label').textContent = meta.label;
  if (status === 'available') {
    box.dataset.openTasks = '0';
    const pip = box.querySelector('.hk-task-pip');
    if (pip) pip.remove();
  }
  box.classList.remove('hk-flash');
  void box.offsetWidth; // restart animation
  box.classList.add('hk-flash');
  setTimeout(() => {
    box.classList.remove('hk-flash');
  }, 500);
}

function hkEsc(str) {
  const d = document.createElement('div');
  d.textContent = str || '';
  return d.innerHTML;
}

let hkToastTimer = null;
function hkToast(msg) {
  const el = document.getElementById('hkToast');
  el.textContent = msg;
  el.classList.add('show');
  clearTimeout(hkToastTimer);
  hkToastTimer = setTimeout(() => el.classList.remove('show'), 2800);
}

document.getElementById('hkRoomModal').addEventListener('click', function (e) {
  if (e.target === this) hkCloseRoomModal();
});

document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', function () {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    const filter = this.dataset.filter;
    document.querySelectorAll('#taskTable tbody tr[data-status]').forEach(row => {
      row.style.display = (filter === 'all' || row.dataset.status === filter) ? '' : 'none';
    });
  });
});
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>

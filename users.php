<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin']);

$pageTitle = 'Staff Users';
$activeNav = 'users';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_user') {
            $stmt = db()->prepare("INSERT INTO users (full_name, username, password_hash, role, phone, status) VALUES (:n, :u, :p, :r, :ph, 'active')");
            $stmt->execute([
                'n' => trim($_POST['full_name']),
                'u' => trim($_POST['username']),
                'p' => password_hash($_POST['password'], PASSWORD_DEFAULT),
                'r' => $_POST['role'],
                'ph' => trim($_POST['phone']),
            ]);
            flash('success', 'Staff account created.');
        } elseif ($action === 'toggle_status') {
            $id = (int) $_POST['user_id'];
            if ($id !== (int) $_SESSION['user_id']) {
                $stmt = db()->prepare("UPDATE users SET status = IF(status='active','disabled','active') WHERE id = :id");
                $stmt->execute(['id' => $id]);
                flash('success', 'User status updated.');
            } else {
                flash('error', 'You cannot disable your own account.');
            }
        } elseif ($action === 'reset_password') {
            $stmt = db()->prepare("UPDATE users SET password_hash = :p WHERE id = :id");
            $stmt->execute(['p' => password_hash($_POST['new_password'], PASSWORD_DEFAULT), 'id' => (int) $_POST['user_id']]);
            flash('success', 'Password reset successfully.');
        }
    } catch (Throwable $e) {
        flash('error', 'Action failed: ' . $e->getMessage());
    }
    redirect('users.php');
}

$users = db()->query("SELECT * FROM users ORDER BY FIELD(role,'admin','manager','frontdesk','housekeeping'), full_name")->fetchAll();

require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-header">
  <div><h2>Staff Users</h2><div class="desc">Manage login accounts and roles for hotel staff.</div></div>
  <div class="page-actions"><button class="btn btn-gold" onclick="document.getElementById('userModal').classList.add('open')">➕ Add Staff</button></div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Phone</th><th>Last Login</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><strong><?= e($u['full_name']) ?></strong></td>
          <td><?= e($u['username']) ?></td>
          <td><span class="badge badge-gray"><?= e(roleLabel($u['role'])) ?></span></td>
          <td><?= e($u['phone']) ?></td>
          <td class="nowrap text-muted"><?= $u['last_login'] ? date('d M Y, h:i A', strtotime($u['last_login'])) : 'Never' ?></td>
          <td><?= $u['status'] === 'active' ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-red">Disabled</span>' ?></td>
          <td class="nowrap">
            <button class="btn btn-sm btn-outline" onclick="openReset(<?= $u['id'] ?>, '<?= e($u['full_name']) ?>')">🔑 Reset PW</button>
            <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure?');">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="toggle_status">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <button class="btn btn-sm <?= $u['status'] === 'active' ? 'btn-red' : 'btn-green' ?>"><?= $u['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-overlay" id="userModal">
  <div class="modal-box">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_user">
      <div class="modal-head"><h3>Add Staff Account</h3><button type="button" class="modal-close" onclick="document.getElementById('userModal').classList.remove('open')">✕</button></div>
      <div class="modal-body">
        <div class="form-group"><label>Full Name *</label><input type="text" name="full_name" class="form-control" required></div>
        <div class="form-group"><label>Username *</label><input type="text" name="username" class="form-control" required></div>
        <div class="form-group"><label>Password *</label><input type="password" name="password" class="form-control" required minlength="6"></div>
        <div class="form-group"><label>Role *</label>
          <select name="role" class="form-control" required>
            <option value="frontdesk">Front Desk</option>
            <option value="manager">Manager</option>
            <option value="housekeeping">Housekeeping</option>
            <option value="admin">Administrator</option>
          </select>
        </div>
        <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control"></div>
      </div>
      <div class="modal-foot"><button type="submit" class="btn btn-gold">Create Account</button></div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="resetModal">
  <div class="modal-box">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" id="resetUserId">
      <div class="modal-head"><h3 id="resetModalTitle">Reset Password</h3><button type="button" class="modal-close" onclick="document.getElementById('resetModal').classList.remove('open')">✕</button></div>
      <div class="modal-body">
        <div class="form-group"><label>New Password *</label><input type="password" name="new_password" class="form-control" required minlength="6"></div>
      </div>
      <div class="modal-foot"><button type="submit" class="btn btn-gold">Reset Password</button></div>
    </form>
  </div>
</div>

<script>
function openReset(id, name) {
  document.getElementById('resetUserId').value = id;
  document.getElementById('resetModalTitle').textContent = 'Reset Password — ' + name;
  document.getElementById('resetModal').classList.add('open');
}
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>

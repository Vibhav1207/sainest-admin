<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pageTitle = 'My Profile';
$activeNav = '';

$user = currentUser();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!password_verify($currentPassword, $user['password_hash'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New password and confirmation do not match.';
    } else {
        $stmt = db()->prepare("UPDATE users SET password_hash = :p WHERE id = :id");
        $stmt->execute(['p' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $user['id']]);
        logActivity('password_change', 'User changed their own password');
        flash('success', 'Password updated successfully.');
        redirect('profile.php');
    }
}

require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-header"><div><h2>My Profile</h2><div class="desc">Manage your account details.</div></div></div>

<div class="card" style="max-width:480px;">
  <div class="stat-row"><span class="lbl">Full Name</span><span class="val"><?= e($user['full_name']) ?></span></div>
  <div class="stat-row"><span class="lbl">Username</span><span class="val"><?= e($user['username']) ?></span></div>
  <div class="stat-row"><span class="lbl">Role</span><span class="val"><?= e(roleLabel($user['role'])) ?></span></div>
  <div class="stat-row"><span class="lbl">Phone</span><span class="val"><?= e($user['phone'] ?: '—') ?></span></div>
</div>

<div class="card" style="max-width:480px;">
  <div class="card-head"><h3>🔑 Change Password</h3></div>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrfField() ?>
    <div class="form-group"><label>Current Password</label><input type="password" name="current_password" class="form-control" required></div>
    <div class="form-group"><label>New Password</label><input type="password" name="new_password" class="form-control" required minlength="6"></div>
    <div class="form-group"><label>Confirm New Password</label><input type="password" name="confirm_password" class="form-control" required minlength="6"></div>
    <button type="submit" class="btn btn-gold">Update Password</button>
  </form>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>

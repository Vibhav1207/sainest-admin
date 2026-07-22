<?php
require_once __DIR__ . '/includes/functions.php';

if (!empty($_SESSION['user_id'])) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) {
        $error = 'Session expired, please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = db()->prepare("SELECT * FROM users WHERE username = :u LIMIT 1");
        $stmt->execute(['u' => $username]);
        $user = $stmt->fetch();

        if ($user && $user['status'] === 'active' && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role']    = $user['role'];
            $_SESSION['name']    = $user['full_name'];

            $upd = db()->prepare("UPDATE users SET last_login = NOW() WHERE id = :id");
            $upd->execute(['id' => $user['id']]);

            logActivity('login', 'User logged in');
            redirect('dashboard.php');
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$hotelName = getSetting('hotel_name', 'Hotel Sai Nest');
$hotelTagline = getSetting('hotel_tagline', '');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Login · <?= e($hotelName) ?> HMS</title>
<link rel="icon" href="<?= BASE_URL ?>/assets/images/logo.png">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-logo">
      <img src="<?= BASE_URL ?>/assets/images/logo.png" alt="<?= e($hotelName) ?>">
      <div class="hname"><?= e($hotelName) ?></div>
      <div class="htag"><?= e($hotelTagline) ?></div>
    </div>
    <h1>Staff Login</h1>
    <div class="login-sub">Hotel Management System</div>

    <?php if ($error): ?>
      <div class="alert alert-error">⚠️ <?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <?= csrfField() ?>
      <div class="form-group">
        <label>Username</label>
        <div class="input-icon-wrap">
          <span class="icon">👤</span>
          <input type="text" name="username" class="form-control" required autofocus>
        </div>
      </div>
      <div class="form-group">
        <label for="passwordInput">Password</label>
        <div class="input-icon-wrap pw-wrap">
          <span class="icon">🔒</span>
          <input type="password" id="passwordInput" name="password" class="form-control" required autocomplete="current-password">
          <button type="button" id="pwToggleBtn" class="pw-toggle-btn" aria-label="Show Password" title="Show Password">
            <!-- Eye open (default - password hidden) -->
            <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
            <!-- Eye off (shown when password visible) -->
            <svg id="eyeOffIcon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none;">
              <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
              <line x1="1" y1="1" x2="23" y2="23"/>
            </svg>
          </button>
        </div>
      </div>
      <button type="submit" class="btn btn-gold btn-block">Sign In</button>
    </form>

    <div class="divider"></div>
    <div style="text-align:center;font-size:0.78rem;color:var(--text-muted);">
      <?= e(getSetting('hotel_address', '')) ?><br>
      📞 <?= e(getSetting('hotel_phone', '')) ?> &nbsp;·&nbsp; ✉️ <?= e(getSetting('hotel_email', '')) ?>
    </div>
  </div>
</div>

<style>
/* ---- Show/Hide Password Toggle ---- */
.pw-wrap {
  position: relative;
}
.pw-wrap .form-control {
  padding-right: 44px; /* room for eye icon */
}
.pw-toggle-btn {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  cursor: pointer;
  padding: 4px 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--text-muted, #888);
  border-radius: 4px;
  transition: color 0.2s, background 0.15s;
  line-height: 1;
  -webkit-tap-highlight-color: transparent;
}
.pw-toggle-btn:hover,
.pw-toggle-btn:focus-visible {
  color: var(--gold, #c9a84c);
  background: rgba(201,168,76,0.08);
  outline: none;
}
.pw-toggle-btn svg {
  width: 18px;
  height: 18px;
  display: block;
  pointer-events: none;
}
@media (max-width: 480px) {
  .pw-toggle-btn svg { width: 20px; height: 20px; }
}
</style>

<script>
(function () {
  const btn     = document.getElementById('pwToggleBtn');
  const input   = document.getElementById('passwordInput');
  const eyeOn   = document.getElementById('eyeIcon');
  const eyeOff  = document.getElementById('eyeOffIcon');
  if (!btn || !input) return;

  btn.addEventListener('click', toggle);

  // Keyboard accessibility: also toggle on Space/Enter if button is focused
  btn.addEventListener('keydown', function (e) {
    if (e.key === ' ' || e.key === 'Enter') {
      e.preventDefault();
      toggle();
    }
  });

  function toggle() {
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    eyeOn.style.display  = isHidden ? 'none'  : 'block';
    eyeOff.style.display = isHidden ? 'block' : 'none';
    btn.setAttribute('aria-label', isHidden ? 'Hide Password' : 'Show Password');
    btn.setAttribute('title',      isHidden ? 'Hide Password' : 'Show Password');
    // Return focus to input after toggle so user can keep typing
    input.focus();
  }
})();
</script>

</body>
</html>

<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin']);

$pageTitle = 'Settings';
$activeNav = 'settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $fields = [
        'hotel_name', 'hotel_tagline', 'hotel_address', 'hotel_phone', 'hotel_whatsapp',
        'hotel_email', 'hotel_gst_number', 'currency_symbol', 'default_tax_percent',
        'default_checkin_time', 'default_checkout_time', 'data_retention_months',
        'invoice_prefix', 'booking_prefix',
    ];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            setSetting($f, trim($_POST[$f]));
        }
    }

    // Logo upload
    $logoUploaded = false;
    if (!empty($_FILES['hotel_logo']['tmp_name']) && $_FILES['hotel_logo']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['hotel_logo']['tmp_name']);
        finfo_close($finfo);
        if (isset($allowed[$mime])) {
            if (!is_dir(UPLOAD_LOGO_PATH)) mkdir(UPLOAD_LOGO_PATH, 0755, true);
            $destRel = 'assets/images/logo.png'; // overwrite active logo used across the app
            move_uploaded_file($_FILES['hotel_logo']['tmp_name'], ROOT_PATH . '/' . $destRel);
            $logoUploaded = true;
        } else {
            flash('error', 'Logo must be JPG, PNG or WEBP.');
        }
    }

    logActivity('settings_update', 'Hotel settings updated');
    flash('success', $logoUploaded ? 'Settings and logo updated successfully.' : 'Settings saved successfully.');
    redirect('settings.php');
}

require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-header">
  <div><h2>Hotel Settings</h2><div class="desc">Update your hotel's profile, branding and policy configuration.</div></div>
</div>

<form method="post" enctype="multipart/form-data">
  <?= csrfField() ?>

  <div class="card">
    <div class="card-head"><h3>🏨 Hotel Profile</h3></div>
    <div class="form-row">
      <div class="form-group"><label>Hotel Name</label><input type="text" name="hotel_name" class="form-control" value="<?= e(getSetting('hotel_name')) ?>"></div>
      <div class="form-group"><label>Tagline</label><input type="text" name="hotel_tagline" class="form-control" value="<?= e(getSetting('hotel_tagline')) ?>"></div>
    </div>
    <div class="form-group"><label>Address</label><input type="text" name="hotel_address" class="form-control" value="<?= e(getSetting('hotel_address')) ?>"></div>
    <div class="form-row-3">
      <div class="form-group"><label>Phone</label><input type="text" name="hotel_phone" class="form-control" value="<?= e(getSetting('hotel_phone')) ?>"></div>
      <div class="form-group"><label>WhatsApp</label><input type="text" name="hotel_whatsapp" class="form-control" value="<?= e(getSetting('hotel_whatsapp')) ?>"></div>
      <div class="form-group"><label>Email</label><input type="email" name="hotel_email" class="form-control" value="<?= e(getSetting('hotel_email')) ?>"></div>
    </div>
    <div class="form-group"><label>GST Number (optional)</label><input type="text" name="hotel_gst_number" class="form-control" value="<?= e(getSetting('hotel_gst_number')) ?>"></div>
    <div class="form-group">
      <label>Hotel Logo</label>
      <div style="display:flex;align-items:center;gap:16px;">
        <img src="<?= BASE_URL ?>/assets/images/logo.png" style="width:64px;height:64px;object-fit:contain;border:1px solid var(--cream-dark);border-radius:8px;padding:4px;">
        <input type="file" name="hotel_logo" class="form-control" accept="image/jpeg,image/png,image/webp">
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>💵 Billing Defaults</h3></div>
    <div class="form-row-3">
      <div class="form-group"><label>Currency Symbol</label><input type="text" name="currency_symbol" class="form-control" value="<?= e(getSetting('currency_symbol')) ?>"></div>
      <div class="form-group"><label>Default Tax % (GST)</label><input type="number" step="0.01" name="default_tax_percent" class="form-control" value="<?= e(getSetting('default_tax_percent')) ?>"></div>
      <div class="form-group"><label>Invoice Number Prefix</label><input type="text" name="invoice_prefix" class="form-control" value="<?= e(getSetting('invoice_prefix')) ?>"></div>
    </div>
    <div class="form-row-3">
      <div class="form-group"><label>Booking Code Prefix</label><input type="text" name="booking_prefix" class="form-control" value="<?= e(getSetting('booking_prefix')) ?>"></div>
      <div class="form-group"><label>Default Check-In Time</label><input type="time" name="default_checkin_time" class="form-control" value="<?= e(getSetting('default_checkin_time')) ?>"></div>
      <div class="form-group"><label>Default Check-Out Time</label><input type="time" name="default_checkout_time" class="form-control" value="<?= e(getSetting('default_checkout_time')) ?>"></div>
    </div>
  </div>

  <div class="card" style="border:1.5px solid var(--gold-pale);">
    <div class="card-head"><h3>🔐 Guest Data Retention Policy</h3></div>
    <p class="tag-note" style="margin-bottom:14px;">Guests with no stay in this many months will have their name, phone, email and ID documents permanently anonymised by the automated cleanup job — freeing up database storage and protecting guest privacy.</p>
    <div class="form-group" style="max-width:240px;">
      <label>Retention Period (months)</label>
      <input type="number" name="data_retention_months" class="form-control" value="<?= e(getSetting('data_retention_months')) ?>" min="1">
    </div>
    <p class="tag-note">⚙️ To activate the automated cleanup, schedule <code>cron/data_retention_cleanup.php</code> to run daily on your server (see README.md for the exact cron command).</p>
  </div>

  <div class="card" style="text-align:right;">
    <button type="submit" class="btn btn-gold" style="min-width:180px;">💾 Save Settings</button>
  </div>
</form>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>

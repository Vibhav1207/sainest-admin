<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk']);

$pageTitle = 'Guest Directory';
$activeNav = 'guests';

$search = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM guests WHERE is_anonymized = 0";
$params = [];
if ($search) {
    $sql .= " AND (full_name LIKE :q1 OR phone LIKE :q2 OR id_proof_number LIKE :q3)";
    $params['q1'] = "%$search%";
    $params['q2'] = "%$search%";
    $params['q3'] = "%$search%";
}
$sql .= " ORDER BY last_stay_date DESC LIMIT 200";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$guests = $stmt->fetchAll();

$retentionMonths = (int) getSetting('data_retention_months', (string) DEFAULT_DATA_RETENTION_MONTHS);
$anonymizedCount = (int) db()->query("SELECT COUNT(*) c FROM guests WHERE is_anonymized = 1")->fetch()['c'];

require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-header">
  <div>
    <h2>Guest Directory</h2>
    <div class="desc">All guest records with a stay in the last <?= $retentionMonths ?> months.</div>
  </div>
</div>

<div class="alert alert-info">🔐 <strong>Privacy Policy:</strong> To keep guest data safe and free up storage, any guest with no stay in the last <?= $retentionMonths ?> months has their name, phone, email and ID proof details automatically and permanently removed by the nightly data-retention job. <?= $anonymizedCount ?> guest record(s) have been anonymised so far.</div>

<form method="get" class="search-bar">
  <input type="text" name="q" class="form-control" placeholder="Search by name, phone or ID number..." value="<?= e($search) ?>">
  <button class="btn btn-outline">🔍 Search</button>
  <?php if ($search): ?><a href="<?= BASE_URL ?>/guests.php" class="btn btn-sm btn-red">✕ Clear</a><?php endif; ?>
</form>

<div class="card">
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Phone</th><th>City / State</th><th>ID Proof</th><th>Last Stay</th><th>Data Purge Due</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($guests as $g):
        $purgeDate = $g['last_stay_date'] ? date('d M Y', strtotime($g['last_stay_date'] . " +$retentionMonths months")) : '—';
        $daysLeft = $g['last_stay_date'] ? (int) ((strtotime($g['last_stay_date'] . " +$retentionMonths months") - time()) / 86400) : null;
      ?>
        <tr>
          <td><strong><?= e($g['full_name']) ?></strong></td>
          <td class="nowrap"><?= e($g['phone']) ?></td>
          <td><?= e(trim($g['city'] . ' ' . $g['state'])) ?></td>
          <td><?= $g['id_proof_type'] ? e(ucwords(str_replace('_',' ',$g['id_proof_type']))) . ': ' . e($g['id_proof_number']) : '—' ?></td>
          <td class="nowrap"><?= $g['last_stay_date'] ? date('d M Y', strtotime($g['last_stay_date'])) : '—' ?></td>
          <td class="nowrap">
            <?= $purgeDate ?>
            <?php if ($daysLeft !== null && $daysLeft <= 30): ?><span class="badge badge-red">Soon</span><?php endif; ?>
          </td>
          <td><a href="<?= BASE_URL ?>/bookings.php?q=<?= urlencode($g['phone'] ?: $g['full_name']) ?>" class="btn btn-sm btn-outline">Bookings</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$guests): ?>
        <tr><td colspan="7"><div class="empty-state"><div class="empty-icon">👥</div>No guests found.</div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>

<?php
/**
 * =====================================================================
 * DATA RETENTION CLEANUP JOB
 * =====================================================================
 * Purpose: Guest identity data (name, phone, email, address, ID proof
 * number, and ID proof photos) is personal data that should not be
 * kept forever. This script finds every guest whose most recent stay
 * (last_stay_date) is older than the configured retention period
 * (Settings > Guest Data Retention Policy, default 12 months) and:
 *
 *   1. Deletes their uploaded ID proof photo files from disk
 *      (frees up server storage space)
 *   2. Overwrites their name/phone/email/address/ID number in the
 *      database with anonymised placeholders
 *   3. Marks the record as is_anonymized = 1 so it is excluded from
 *      the Guest Directory search
 *
 * Booking, invoice and payment records are intentionally KEPT (only
 * the personal identity fields on the `guests` row are removed) so
 * that historical revenue reports and accounting stay accurate.
 *
 * HOW TO SCHEDULE (cPanel / Linux cron — run once every day):
 *   0 3 * * *  /usr/bin/php /full/path/to/hotel-management/cron/data_retention_cleanup.php >> /full/path/to/hotel-management/cron/cleanup.log 2>&1
 *
 * You can also run it manually any time from the command line:
 *   php cron/data_retention_cleanup.php
 * =====================================================================
 */

require_once __DIR__ . '/../includes/functions.php';

$retentionMonths = (int) getSetting('data_retention_months', (string) DEFAULT_DATA_RETENTION_MONTHS);
if ($retentionMonths < 1) {
    $retentionMonths = DEFAULT_DATA_RETENTION_MONTHS;
}

$cutoffDate = date('Y-m-d', strtotime("-$retentionMonths months"));

echo "[" . date('Y-m-d H:i:s') . "] Starting data retention cleanup. Cutoff date: $cutoffDate (retention = $retentionMonths months)\n";

$pdo = db();
$stmt = $pdo->prepare("
  SELECT * FROM guests
  WHERE is_anonymized = 0
    AND last_stay_date IS NOT NULL
    AND last_stay_date < :cutoff
    AND id NOT IN (SELECT primary_guest_id FROM bookings WHERE status = 'checked_in')
");
$stmt->execute(['cutoff' => $cutoffDate]);
$guests = $stmt->fetchAll();

$count = 0;
foreach ($guests as $g) {
    // Remove uploaded document files from disk
    deleteDocumentFile($g['id_proof_photo']);
    deleteDocumentFile($g['id_proof_photo_back']);

    $upd = $pdo->prepare("
      UPDATE guests SET
        full_name = 'Deleted Guest',
        phone = NULL,
        email = NULL,
        address = NULL,
        city = NULL,
        state = NULL,
        id_proof_number = NULL,
        id_proof_photo = NULL,
        id_proof_photo_back = NULL,
        is_anonymized = 1
      WHERE id = :id
    ");
    $upd->execute(['id' => $g['id']]);
    $count++;
    echo "  Anonymised guest #{$g['id']} (last stay: {$g['last_stay_date']})\n";
}

logActivity('data_retention_cleanup', "$count guest record(s) anonymised (cutoff $cutoffDate)");

echo "[" . date('Y-m-d H:i:s') . "] Done. $count guest record(s) anonymised.\n";

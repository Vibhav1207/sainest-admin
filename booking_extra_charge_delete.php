<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfCheck()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid or expired form submission.']);
    exit;
}

$chargeId = (int) ($_POST['charge_id'] ?? 0);

if ($chargeId <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid charge ID.']);
    exit;
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Get the charge details and booking_id before deleting
    $stmt = $pdo->prepare("SELECT booking_id, total_amount FROM booking_extra_charges WHERE id = :id");
    $stmt->execute(['id' => $chargeId]);
    $charge = $stmt->fetch();

    if (!$charge) {
        throw new RuntimeException('Extra charge not found.');
    }

    $bookingId = (int) $charge['booking_id'];

    // Delete the charge
    $delStmt = $pdo->prepare("DELETE FROM booking_extra_charges WHERE id = :id");
    $delStmt->execute(['id' => $chargeId]);

    // Recalculate total extra charges for this booking
    $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM booking_extra_charges WHERE booking_id = :b");
    $sumStmt->execute(['b' => $bookingId]);
    $newTotalExtra = (float) $sumStmt->fetchColumn();

    // Update bookings.extra_amount
    $updStmt = $pdo->prepare("UPDATE bookings SET extra_amount = :extra WHERE id = :b");
    $updStmt->execute(['extra' => $newTotalExtra, 'b' => $bookingId]);

    $pdo->commit();

    logActivity('extra_charge_delete', "Deleted extra charge #{$chargeId} from booking #{$bookingId}");

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'newTotalExtra' => $newTotalExtra]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Failed to delete: ' . $e->getMessage()]);
}
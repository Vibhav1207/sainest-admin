<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfCheck()) {
    flash('error', 'Invalid or expired form submission. Please try again.');
    redirect('rooms.php');
}

$bookingId = (int) ($_POST['booking_id'] ?? 0);
$chargeNames   = $_POST['charge_name'] ?? [];
$chargeQtys    = $_POST['charge_qty'] ?? [];
$chargePrices  = $_POST['charge_price'] ?? [];
$chargeRemarks = $_POST['charge_remarks'] ?? [];

if ($bookingId <= 0 || empty($chargeNames) || !is_array($chargeNames)) {
    flash('error', 'No charges were provided to add to this stay.');
    redirect('booking_edit_stay.php?id=' . $bookingId);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $bStmt = $pdo->prepare("SELECT id, booking_code FROM bookings WHERE id = :id AND status = 'checked_in' FOR UPDATE");
    $bStmt->execute(['id' => $bookingId]);
    $booking = $bStmt->fetch();
    if (!$booking) {
        throw new RuntimeException('Booking not found or is no longer active.');
    }

    $addedCount = 0;
    foreach ($chargeNames as $i => $name) {
        $name = trim($name);
        $qty = max(0.01, (float) ($chargeQtys[$i] ?? 1));
        $price = max(0, (float) ($chargePrices[$i] ?? 0));
        $remarks = trim($chargeRemarks[$i] ?? '');

        if ($name === '') continue;

        addBookingExtraCharge($bookingId, $name, $qty, $price, $remarks, $_SESSION['user_id']);
        $addedCount++;
    }

    $pdo->commit();

    logActivity('edit_stay_add_charges', "Added $addedCount extra charge(s) to Booking {$booking['booking_code']}");

    flash('success', "$addedCount extra charge(s) added successfully to stay {$booking['booking_code']}.");
    redirect('booking_edit_stay.php?id=' . $bookingId);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', 'Failed to add extra charges: ' . $e->getMessage());
    redirect('booking_edit_stay.php?id=' . $bookingId);
}

<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfCheck()) {
    flash('error', 'Invalid or expired form submission. Please try again.');
    redirect('bookings.php');
}

$pdo = db();
$bookingId = (int) ($_POST['booking_id'] ?? 0);

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = :id AND status = 'reserved' FOR UPDATE");
    $stmt->execute(['id' => $bookingId]);
    $booking = $stmt->fetch();
    if (!$booking) {
        throw new RuntimeException('This reservation was not found, or is no longer in a cancellable state.');
    }

    $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = :id")->execute(['id' => $bookingId]);

    // Only free the room back to "available" if nothing else still needs it —
    // i.e. no other reserved or checked-in booking currently references it.
    $roomStmt = $pdo->prepare("SELECT * FROM rooms WHERE id = :id FOR UPDATE");
    $roomStmt->execute(['id' => $booking['room_id']]);
    $room = $roomStmt->fetch();

    if ($room && $room['status'] === 'reserved') {
        $otherStmt = $pdo->prepare("SELECT id FROM bookings WHERE room_id = :id AND status IN ('checked_in','reserved') LIMIT 1");
        $otherStmt->execute(['id' => $booking['room_id']]);
        if (!$otherStmt->fetch()) {
            $pdo->prepare("UPDATE rooms SET status = 'available' WHERE id = :id")->execute(['id' => $booking['room_id']]);
        }
    }

    logActivity('booking_cancel', "Reservation {$booking['booking_code']} cancelled");

    $pdo->commit();

    flash('success', "Reservation {$booking['booking_code']} has been cancelled.");
    redirect('booking_view.php?id=' . $bookingId);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', 'Cancellation failed: ' . $e->getMessage());
    redirect('booking_view.php?id=' . $bookingId);
}

<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfCheck()) {
    error_log('CSRF FAIL in booking_delete: post_csrf=' . ($_POST['csrf_token'] ?? 'NONE') . ' sess_csrf=' . ($_SESSION['csrf_token'] ?? 'NONE'));
    flash('error', 'Invalid or expired form submission. Please try again.');
    redirect('bookings.php');
}

$pdo = db();
$bookingId = (int) ($_POST['booking_id'] ?? 0);

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = :id FOR UPDATE");
    $stmt->execute(['id' => $bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        throw new RuntimeException('Booking not found.');
    }

    if (!in_array($booking['status'], ['reserved', 'cancelled'], true)) {
        throw new RuntimeException('Only reserved or cancelled bookings can be deleted.');
    }

    $bookingCode = $booking['booking_code'];

    // Get all rooms associated with this booking
    $roomsStmt = $pdo->prepare("
        SELECT DISTINCT room_id FROM (
            SELECT room_id FROM bookings WHERE id = :id1
            UNION
            SELECT room_id FROM booking_rooms WHERE booking_id = :id2
        ) t WHERE room_id IS NOT NULL
    ");
    $roomsStmt->execute(['id1' => $bookingId, 'id2' => $bookingId]);
    $roomIds = $roomsStmt->fetchAll(PDO::FETCH_COLUMN);

    // Delete child records
    $pdo->prepare("DELETE FROM booking_rooms WHERE booking_id = :id")->execute(['id' => $bookingId]);
    $pdo->prepare("DELETE FROM booking_guests WHERE booking_id = :id")->execute(['id' => $bookingId]);
    try {
        $pdo->prepare("DELETE FROM booking_extra_charges WHERE booking_id = :id")->execute(['id' => $bookingId]);
    } catch (PDOException $e) { /* ignore if not present */ }
    $pdo->prepare("DELETE FROM payments WHERE booking_id = :id")->execute(['id' => $bookingId]);
    $pdo->prepare("DELETE FROM invoices WHERE booking_id = :id")->execute(['id' => $bookingId]);
    $pdo->prepare("DELETE FROM bookings WHERE id = :id")->execute(['id' => $bookingId]);

    // Recalculate and free room statuses for affected rooms
    foreach ($roomIds as $rId) {
        $checkStmt = $pdo->prepare("
            SELECT b.id FROM bookings b
            LEFT JOIN booking_rooms br ON br.booking_id = b.id
            WHERE (b.room_id = :r1 OR br.room_id = :r2)
              AND b.status IN ('checked_in', 'reserved')
            LIMIT 1
        ");
        $checkStmt->execute(['r1' => $rId, 'r2' => $rId]);
        if (!$checkStmt->fetch()) {
            $pdo->prepare("UPDATE rooms SET status = 'available' WHERE id = :id")->execute(['id' => $rId]);
        }
    }

    logActivity('booking_delete', "Deleted reservation/booking {$bookingCode} (ID: {$bookingId})");

    $pdo->commit();

    flash('success', "Booking {$bookingCode} deleted successfully.");
    redirect('bookings.php');

} catch (Throwable $e) {
    error_log('booking_delete EXCEPTION: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', 'Deletion failed: ' . $e->getMessage());
    redirect('booking_view.php?id=' . $bookingId);
}

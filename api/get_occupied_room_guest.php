<?php
/**
 * AJAX endpoint — retrieve active booking and guest details for an occupied room.
 * Returns JSON containing booking details and guest list.
 */
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

requireLogin();
if (!hasRole(['admin', 'manager', 'frontdesk', 'housekeeping'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to perform this action.']);
    exit;
}

$roomId = (int) ($_GET['room_id'] ?? 0);

if ($roomId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid room ID.']);
    exit;
}

try {
    $pdo = db();

    // Fetch the active check-in booking details for this room
    $bookingStmt = $pdo->prepare("
        SELECT DISTINCT b.id AS booking_id, b.booking_code, b.booking_type, b.company_name, b.company_gst_number, b.company_address, b.company_contact_person, b.company_phone, b.checkin_datetime, b.expected_checkout_date, b.extra_amount, b.status AS booking_status
        FROM bookings b
        LEFT JOIN booking_rooms br ON br.booking_id = b.id
        WHERE (b.room_id = :room_id OR br.room_id = :room_id2) AND b.status = 'checked_in'
        LIMIT 1
    ");
    $bookingStmt->execute(['room_id' => $roomId, 'room_id2' => $roomId]);
    $booking = $bookingStmt->fetch();

    if (!$booking) {
        echo json_encode(['success' => false, 'message' => 'No active booking found for this occupied room.']);
        exit;
    }

    $bookingRooms = getBookingRooms((int) $booking['booking_id']);
    $extraCharges = getBookingExtraCharges((int) $booking['booking_id']);

    // Fetch all guests registered under this active booking
    $guestsStmt = $pdo->prepare("
        SELECT g.*, bg.is_primary
        FROM booking_guests bg
        JOIN guests g ON g.id = bg.guest_id
        WHERE bg.booking_id = :booking_id
        ORDER BY bg.is_primary DESC, g.id ASC
    ");
    $guestsStmt->execute(['booking_id' => $booking['booking_id']]);
    $guests = $guestsStmt->fetchAll();

    echo json_encode([
        'success' => true,
        'booking' => $booking,
        'booking_rooms' => $bookingRooms,
        'extra_charges' => $extraCharges,
        'guests'  => $guests,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

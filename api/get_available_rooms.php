<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$checkinDate  = $_GET['checkin'] ?? date('Y-m-d');
$checkoutDate = $_GET['checkout'] ?? date('Y-m-d', strtotime('+1 day'));
$excludeId    = !empty($_GET['exclude_booking_id']) ? (int)$_GET['exclude_booking_id'] : null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkinDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkoutDate)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

try { ensureRoomTypesMigrated(); } catch (Throwable $ignored) {}

try {
    // Fetch all non-maintenance rooms (LEFT JOIN so rooms without room_type_id are still included)
    $rooms = db()->query("
        SELECT r.*, 
               COALESCE(rt.name, 'Standard') AS type_name, 
               COALESCE(rt.base_rate, 0) AS base_rate, 
               COALESCE(rt.max_guests, 2) AS max_guests
        FROM rooms r
        LEFT JOIN room_types rt ON rt.id = r.room_type_id
        WHERE r.status != 'maintenance'
        ORDER BY CAST(r.room_number AS UNSIGNED) ASC
    ")->fetchAll();

    $availableRooms = [];
    foreach ($rooms as $r) {
        // Check for overlapping bookings
        $conflict = getRoomBookingConflict((int)$r['id'], $checkinDate, $checkoutDate, $excludeId);

        $availableRooms[] = [
            'id'           => (int)$r['id'],
            'room_number'  => $r['room_number'],
            'room_type_id' => (int)$r['room_type_id'],
            'type_name'    => $r['type_name'],
            'base_rate'    => (float)$r['base_rate'],
            'max_guests'   => (int)$r['max_guests'],
            'status'       => $r['status'],
            'is_available' => !$conflict,
            'conflict'     => $conflict ? ('Booking ' . $conflict['booking_code']) : null,
        ];
    }

    echo json_encode([
        'success' => true,
        'rooms'   => $availableRooms,
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'DB error: ' . $e->getMessage(),
        'rooms'   => [],
    ]);
}

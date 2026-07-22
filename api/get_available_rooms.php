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

ensureRoomTypesMigrated();

// Fetch all non-maintenance rooms
$rooms = db()->query("
    SELECT r.*, rt.name AS type_name, rt.base_rate, rt.max_guests
    FROM rooms r
    JOIN room_types rt ON rt.id = r.room_type_id
    WHERE r.status != 'maintenance'
    ORDER BY CAST(r.room_number AS UNSIGNED) ASC
")->fetchAll();

$availableRooms = [];
foreach ($rooms as $r) {
    // If check-in date is today and room is currently dirty, or maintenance, not available
    if ($r['status'] === 'maintenance') {
        continue;
    }
    
    // Check for overlapping bookings
    $conflict = getRoomBookingConflict((int)$r['id'], $checkinDate, $checkoutDate, $excludeId);
    if (!$conflict) {
        $availableRooms[] = [
            'id'           => (int)$r['id'],
            'room_number'  => $r['room_number'],
            'room_type_id' => (int)$r['room_type_id'],
            'type_name'    => $r['type_name'],
            'base_rate'    => (float)$r['base_rate'],
            'max_guests'   => (int)$r['max_guests'],
            'status'       => $r['status'],
        ];
    }
}

echo json_encode([
    'success' => true,
    'rooms'   => $availableRooms,
]);

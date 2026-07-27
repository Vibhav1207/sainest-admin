<?php
/**
 * AJAX endpoint — quick room status change from the Housekeeping room
 * dashboard (click a room box -> pick a status). Returns JSON.
 *
 * Kept intentionally strict: a room with an active (checked_in) booking
 * can only be "occupied" here — freeing it must go through Check-Out so
 * invoices/payments are never skipped. Likewise you cannot force a room
 * to "occupied" here without a real booking — use Check-In for that.
 */
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

requireLogin();
if (!hasRole(['admin', 'manager', 'frontdesk', 'housekeeping'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to perform this action.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfCheck()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired request. Please refresh the page and try again.']);
    exit;
}

$roomId = (int) ($_POST['room_id'] ?? 0);
$status = $_POST['status'] ?? '';
$allowedStatuses = ['available', 'occupied', 'dirty', 'maintenance', 'reserved'];

if ($roomId <= 0 || !in_array($status, $allowedStatuses, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid room or status value.']);
    exit;
}

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = :id FOR UPDATE");
    $stmt->execute(['id' => $roomId]);
    $room = $stmt->fetch();
    if (!$room) {
        throw new RuntimeException('Room not found.');
    }

    $activeStmt = $pdo->prepare("
        SELECT 1 FROM bookings b
        LEFT JOIN booking_rooms br ON br.booking_id = b.id
        WHERE (b.room_id = :id OR br.room_id = :id2) AND b.status = 'checked_in'
        LIMIT 1
    ");
    $activeStmt->execute(['id' => $roomId, 'id2' => $roomId]);
    $hasActiveBooking = (bool) $activeStmt->fetch();

    if ($hasActiveBooking && $status !== 'occupied') {
        throw new RuntimeException('This room has a guest currently checked in. Use Check-Out to free it first.');
    }
    if (!$hasActiveBooking && $status === 'occupied') {
        throw new RuntimeException('This room has no active booking. Use Check-In to occupy it.');
    }

    $pdo->prepare("UPDATE rooms SET status = :s WHERE id = :id")->execute(['s' => $status, 'id' => $roomId]);

    // Marking a room "available" (i.e. cleaned) closes out any open cleaning tasks for it.
    if ($status === 'available') {
        $pdo->prepare("UPDATE housekeeping_tasks SET status = 'completed', completed_at = NOW() WHERE room_id = :id AND status != 'completed'")
            ->execute(['id' => $roomId]);
    }

    logActivity('room_status', "Room {$room['room_number']} status set to '$status' from Housekeeping dashboard");

    $pdo->commit();

    $labels = [
        'available'   => 'Available',
        'occupied'    => 'Occupied',
        'dirty'       => 'Needs Cleaning',
        'maintenance' => 'Maintenance',
        'reserved'    => 'Reserved',
    ];

    echo json_encode([
        'success'     => true,
        'room_id'     => $roomId,
        'room_number' => $room['room_number'],
        'status'      => $status,
        'label'       => $labels[$status],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

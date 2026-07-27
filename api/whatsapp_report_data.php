<?php
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

requireLogin();
if (!hasRole(['admin', 'manager'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to perform this action.']);
    exit;
}

$preset        = $_GET['preset'] ?? 'this_month';
$customFrom    = $_GET['from'] ?? '';
$customTo      = $_GET['to'] ?? '';
$reportType    = $_GET['report_type'] ?? 'all';
$roomNumber    = trim($_GET['room_number'] ?? '');
$roomTypeId    = (int)($_GET['room_type_id'] ?? 0);
$floor         = trim($_GET['floor'] ?? '');
$bookingStatus = $_GET['booking_status'] ?? '';
$guestName     = trim($_GET['guest_name'] ?? '');
$mobileNumber  = trim($_GET['mobile_number'] ?? '');
$paymentStatus = $_GET['payment_status'] ?? '';
$paymentMethod = $_GET['payment_method'] ?? '';
$corporate     = $_GET['corporate'] ?? 'all';

// Resolve Preset Dates
$today = date('Y-m-d');
if ($preset === 'today') {
    $from = $today; $to = $today;
} elseif ($preset === 'yesterday') {
    $from = date('Y-m-d', strtotime('-1 day')); $to = $from;
} elseif ($preset === 'this_week') {
    $from = date('Y-m-d', strtotime('monday this week')); $to = $today;
} elseif ($preset === 'this_month') {
    $from = date('Y-m-01'); $to = $today;
} else {
    $from = !empty($customFrom) ? $customFrom : date('Y-m-01');
    $to   = !empty($customTo) ? $customTo : $today;
}

// Build SQL filters
$bWhere = ["DATE(b.created_at) BETWEEN :f AND :t"];
$bParams = ['f' => $from, 't' => $to];

if ($bookingStatus) {
    $bWhere[] = "b.status = :bstatus";
    $bParams['bstatus'] = $bookingStatus;
}
if ($corporate === 'yes') {
    $bWhere[] = "b.booking_type = 'corporate'";
} elseif ($corporate === 'no') {
    $bWhere[] = "b.booking_type = 'regular'";
}

if ($reportType === 'reservations') {
    $bWhere[] = "b.status = 'reserved'";
} elseif ($reportType === 'checkin') {
    $bWhere[] = "b.status = 'checked_in'";
} elseif ($reportType === 'checkout') {
    $bWhere[] = "b.status = 'checked_out'";
}

$joinExtra = "";
if ($roomNumber || $roomTypeId || $floor) {
    $joinExtra .= " LEFT JOIN booking_rooms br ON br.booking_id = b.id LEFT JOIN rooms r ON (r.id = br.room_id OR r.id = b.room_id)";
    if ($roomNumber) { $bWhere[] = "r.room_number = :rmnum"; $bParams['rmnum'] = $roomNumber; }
    if ($roomTypeId > 0) { $bWhere[] = "r.room_type_id = :rmtype"; $bParams['rmtype'] = $roomTypeId; }
    if ($floor !== '') { $bWhere[] = "r.floor = :rmfloor"; $bParams['rmfloor'] = $floor; }
}

if ($guestName || $mobileNumber) {
    $joinExtra .= " JOIN guests g ON g.id = b.primary_guest_id";
    if ($guestName) { $bWhere[] = "(g.full_name LIKE :gname OR b.company_name LIKE :gname2)"; $bParams['gname'] = "%$guestName%"; $bParams['gname2'] = "%$guestName%"; }
    if ($mobileNumber) { $bWhere[] = "g.phone LIKE :gphone"; $bParams['gphone'] = "%$mobileNumber%"; }
}

if ($paymentStatus || $paymentMethod) {
    $joinExtra .= " LEFT JOIN invoices inv ON inv.booking_id = b.id";
    if ($paymentStatus === 'paid') { $bWhere[] = "inv.balance_amount <= 0 AND inv.total_amount > 0"; }
    elseif ($paymentStatus === 'partial') { $bWhere[] = "inv.paid_amount > 0 AND inv.balance_amount > 0"; }
    elseif ($paymentStatus === 'pending') { $bWhere[] = "(inv.paid_amount = 0 OR inv.id IS NULL)"; }
    if ($paymentMethod) { $bWhere[] = "inv.id IN (SELECT invoice_id FROM payments WHERE payment_method = :pmethod)"; $bParams['pmethod'] = $paymentMethod; }
}

$fullWhereSql = "WHERE " . implode(" AND ", $bWhere);

// 1. Total rooms in hotel
$totalRooms = db()->query("SELECT COUNT(*) FROM rooms")->fetchColumn();

// 2. Occupied rooms (checked_in bookings)
$occupiedStmt = db()->prepare("SELECT COUNT(DISTINCT b.room_id) FROM bookings b WHERE b.status = 'checked_in' AND DATE(b.created_at) BETWEEN :f AND :t");
$occupiedStmt->execute(['f' => $from, 't' => $to]);
$occupiedRooms = $occupiedStmt->fetchColumn();

// 3. Booking status counts
$statusStmt = db()->prepare("
  SELECT b.status, COUNT(DISTINCT b.id) cnt
  FROM bookings b
  $joinExtra
  $fullWhereSql
  GROUP BY b.status
");
$statusStmt->execute($bParams);
$statusRows = $statusStmt->fetchAll();
$statusCounts = ['reserved' => 0, 'checked_in' => 0, 'checked_out' => 0, 'cancelled' => 0];
foreach ($statusRows as $sr) {
    $statusCounts[$sr['status']] = (int)$sr['cnt'];
}

// 4. Revenue summary
$revStmt = db()->prepare("
  SELECT COALESCE(SUM(inv.total_amount),0) total_rev,
         COALESCE(SUM(inv.paid_amount),0) total_paid,
         COALESCE(SUM(inv.balance_amount),0) total_balance,
         COUNT(DISTINCT inv.id) invoice_count,
         COUNT(DISTINCT b.id) booking_count
  FROM bookings b
  LEFT JOIN invoices inv ON inv.booking_id = b.id
  $joinExtra
  $fullWhereSql
");
$revStmt->execute($bParams);
$revSummary = $revStmt->fetch();

// 5. Guest summary
$guestStmt = db()->prepare("
  SELECT COUNT(DISTINCT g.id) total_guests,
         COUNT(DISTINCT CASE WHEN b.booking_type = 'corporate' THEN g.id END) corporate_guests,
         COUNT(DISTINCT CASE WHEN b.booking_type = 'regular' THEN g.id END) regular_guests
  FROM bookings b
  JOIN guests g ON g.id = b.primary_guest_id
  $joinExtra
  $fullWhereSql
");
$guestStmt->execute($bParams);
$guestSummary = $guestStmt->fetch();

// 6. Source breakdown
$srcStmt = db()->prepare("
  SELECT b.booking_source, COUNT(DISTINCT b.id) c, COALESCE(SUM(b.commission_amount),0) commission
  FROM bookings b
  $joinExtra
  $fullWhereSql
  GROUP BY b.booking_source ORDER BY c DESC
");
$srcStmt->execute($bParams);
$bySource = $srcStmt->fetchAll();

// 7. Room breakdown
$roomStmt = db()->prepare("
  SELECT r.room_number, r.floor, rt.name AS room_type_name,
         COUNT(DISTINCT b.id) bookings_count,
         COALESCE(SUM(inv.total_amount),0) revenue
  FROM rooms r
  JOIN room_types rt ON rt.id = r.room_type_id
  LEFT JOIN booking_rooms br ON br.room_id = r.id
  LEFT JOIN bookings b ON (b.id = br.booking_id OR b.room_id = r.id)
  LEFT JOIN invoices inv ON inv.booking_id = b.id
  " . implode(" AND ", array_merge(["WHERE 1=1"], array_map(fn($w) => str_replace('WHERE ', '', $w), $bWhere))) . "
  GROUP BY r.id ORDER BY revenue DESC, CAST(r.room_number AS UNSIGNED) ASC
");
$roomStmt->execute($bParams);
$byRoom = $roomStmt->fetchAll();

// 8. Housekeeping status
$hkStmt = db()->prepare("
  SELECT h.status, COUNT(*) cnt
  FROM housekeeping_tasks h
  WHERE DATE(h.created_at) BETWEEN :f AND :t
  GROUP BY h.status
");
$hkStmt->execute(['f' => $from, 't' => $to]);
$hkRows = $hkStmt->fetchAll();
$housekeeping = ['pending' => 0, 'in_progress' => 0, 'completed' => 0];
foreach ($hkRows as $hr) {
    $housekeeping[$hr['status']] = (int)$hr['cnt'];
}

// Build filter description
$filterParts = [];
if ($roomNumber) $filterParts[] = "Room: $roomNumber";
if ($roomTypeId > 0) {
    $rtName = db()->prepare("SELECT name FROM room_types WHERE id = ?");
    $rtName->execute([$roomTypeId]);
    $rtName = $rtName->fetchColumn();
    if ($rtName) $filterParts[] = "Type: $rtName";
}
if ($floor) $filterParts[] = "Floor: $floor";
if ($bookingStatus) $filterParts[] = "Status: " . ucfirst($bookingStatus);
if ($guestName) $filterParts[] = "Guest: $guestName";
if ($mobileNumber) $filterParts[] = "Phone: $mobileNumber";
if ($paymentStatus) $filterParts[] = "Payment: " . ucfirst($paymentStatus);
if ($paymentMethod) $filterParts[] = "Method: " . strtoupper($paymentMethod);
if ($corporate !== 'all') $filterParts[] = "Corporate: " . ucfirst($corporate);
if ($reportType !== 'all') $filterParts[] = "Report: " . ucfirst($reportType);

$filterSummary = $filterParts ? implode(' | ', $filterParts) : null;

echo json_encode([
    'success' => true,
    'period' => [
        'from' => $from,
        'to' => $to,
        'label' => $from === $to ? date('d M Y', strtotime($from)) : date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to)),
    ],
    'filters' => $filterSummary,
    'report_type' => $reportType,
    'total_rooms' => (int)$totalRooms,
    'occupied_rooms' => (int)$occupiedRooms,
    'status_counts' => $statusCounts,
    'revenue' => [
        'total' => money((float)$revSummary['total_rev']),
        'paid' => money((float)$revSummary['total_paid']),
        'balance' => money((float)$revSummary['total_balance']),
        'raw_total' => (float)$revSummary['total_rev'],
        'raw_paid' => (float)$revSummary['total_paid'],
        'raw_balance' => (float)$revSummary['total_balance'],
        'invoice_count' => (int)$revSummary['invoice_count'],
        'booking_count' => (int)$revSummary['booking_count'],
    ],
    'guests' => [
        'total' => (int)$guestSummary['total_guests'],
        'corporate' => (int)$guestSummary['corporate_guests'],
        'regular' => (int)$guestSummary['regular_guests'],
    ],
    'by_source' => $bySource,
    'by_room' => $byRoom,
    'housekeeping' => $housekeeping,
]);

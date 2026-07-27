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
    $from = $today;
    $to   = $today;
} elseif ($preset === 'yesterday') {
    $from = date('Y-m-d', strtotime('-1 day'));
    $to   = $from;
} elseif ($preset === 'this_week') {
    $from = date('Y-m-d', strtotime('monday this week'));
    $to   = $today;
} elseif ($preset === 'this_month') {
    $from = date('Y-m-01');
    $to   = $today;
} else {
    $from = !empty($customFrom) ? $customFrom : date('Y-m-01');
    $to   = !empty($customTo) ? $customTo : $today;
}

// Build common SQL filter conditions for bookings (alias `b`)
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

$bWhereSql = "WHERE " . implode(" AND ", $bWhere);

// Build Joins for extra filter properties (rooms `r`, guests `g`, invoices `inv`)
$joinExtra = "";
if ($roomNumber || $roomTypeId || $floor) {
    $joinExtra .= " LEFT JOIN booking_rooms br ON br.booking_id = b.id LEFT JOIN rooms r ON (r.id = br.room_id OR r.id = b.room_id)";
    if ($roomNumber) {
        $bWhere[] = "r.room_number = :rmnum";
        $bParams['rmnum'] = $roomNumber;
    }
    if ($roomTypeId > 0) {
        $bWhere[] = "r.room_type_id = :rmtype";
        $bParams['rmtype'] = $roomTypeId;
    }
    if ($floor !== '') {
        $bWhere[] = "r.floor = :rmfloor";
        $bParams['rmfloor'] = $floor;
    }
}

if ($guestName || $mobileNumber) {
    $joinExtra .= " JOIN guests g ON g.id = b.primary_guest_id";
    if ($guestName) {
        $bWhere[] = "(g.full_name LIKE :gname OR b.company_name LIKE :gname2)";
        $bParams['gname']  = "%$guestName%";
        $bParams['gname2'] = "%$guestName%";
    }
    if ($mobileNumber) {
        $bWhere[] = "g.phone LIKE :gphone";
        $bParams['gphone'] = "%$mobileNumber%";
    }
}

if ($paymentStatus || $paymentMethod) {
    $joinExtra .= " LEFT JOIN invoices inv ON inv.booking_id = b.id";
    if ($paymentStatus === 'paid') {
        $bWhere[] = "inv.balance_amount <= 0 AND inv.total_amount > 0";
    } elseif ($paymentStatus === 'partial') {
        $bWhere[] = "inv.paid_amount > 0 AND inv.balance_amount > 0";
    } elseif ($paymentStatus === 'pending') {
        $bWhere[] = "(inv.paid_amount = 0 OR inv.id IS NULL)";
    }

    if ($paymentMethod) {
        $bWhere[] = "inv.id IN (SELECT invoice_id FROM payments WHERE payment_method = :pmethod)";
        $bParams['pmethod'] = $paymentMethod;
    }
}

$fullWhereSql = "WHERE " . implode(" AND ", $bWhere);

// 1. Invoices & Financial Summary
$invSql = "
  SELECT inv.*, b.booking_code, b.booking_type, b.company_name, g.full_name AS guest_name, g.phone AS guest_phone
  FROM invoices inv
  JOIN bookings b ON b.id = inv.booking_id
  JOIN guests g ON g.id = b.primary_guest_id
  $joinExtra
  $fullWhereSql
  ORDER BY inv.created_at DESC
";

$invStmt = db()->prepare($invSql);
$invStmt->execute($bParams);
$invoices = $invStmt->fetchAll();

$totRevenue = 0; $totPaid = 0; $totBal = 0; $totComm = 0; $totRoomRev = 0;
foreach ($invoices as $inv) {
    $totRevenue += (float)$inv['total_amount'];
    $totPaid    += (float)$inv['paid_amount'];
    $totBal     += (float)$inv['balance_amount'];
    $totComm    += (float)$inv['commission_amount'];
    $totRoomRev += ((float)$inv['room_charges'] - (float)$inv['commission_amount']);
}

// 2. Bookings by Source
$srcSql = "
  SELECT b.booking_source, COUNT(DISTINCT b.id) c, COALESCE(SUM(b.commission_amount),0) commission
  FROM bookings b
  $joinExtra
  $fullWhereSql
  GROUP BY b.booking_source ORDER BY c DESC
";
$srcStmt = db()->prepare($srcSql);
$srcStmt->execute($bParams);
$bySource = $srcStmt->fetchAll();

// 3. Revenue by Room
$roomSql = "
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
";
$roomStmt = db()->prepare($roomSql);
$roomStmt->execute($bParams);
$byRoom = $roomStmt->fetchAll();

// 4. Booking Type Breakdown
$typeSql = "
  SELECT b.booking_type, COUNT(DISTINCT b.id) cnt, COALESCE(SUM(inv.total_amount), 0) total_rev
  FROM bookings b
  LEFT JOIN invoices inv ON inv.booking_id = b.id
  $joinExtra
  $fullWhereSql
  GROUP BY b.booking_type
";
$typeStmt = db()->prepare($typeSql);
$typeStmt->execute($bParams);
$typeBreakdown = $typeStmt->fetchAll();

echo json_encode([
    'success' => true,
    'filters' => [
        'from' => $from,
        'to'   => $to,
        'preset' => $preset,
        'summary' => "Date: $from to $to" . ($corporate !== 'all' ? " | Corporate: " . ucfirst($corporate) : "")
    ],
    'kpis' => [
        'total_revenue'      => money($totRevenue),
        'invoices_count'     => count($invoices),
        'actual_room_rev'    => money($totRoomRev),
        'commission_payable' => money($totComm),
    ],
    'bySource'      => $bySource,
    'byRoom'        => $byRoom,
    'typeBreakdown' => $typeBreakdown,
    'invoices'      => $invoices,
]);

<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager']);
require_once __DIR__ . '/includes/XlsxWriter.php';

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
$category      = $_GET['category'] ?? 'all';

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

$hotelName = getSetting('hotel_name', 'Hotel Sai Nest');

// Build human-readable filter summary string for Excel header
$filterParts = ['Date: ' . date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to))];
if ($reportType !== 'all') $filterParts[] = 'Report: ' . ucfirst($reportType);
if ($roomNumber) $filterParts[] = "Room: $roomNumber";
if ($floor) $filterParts[] = "Floor: $floor";
if ($bookingStatus) $filterParts[] = "Status: " . ucfirst($bookingStatus);
if ($guestName) $filterParts[] = "Guest: $guestName";
if ($mobileNumber) $filterParts[] = "Phone: $mobileNumber";
if ($paymentStatus) $filterParts[] = "Payment Status: " . ucfirst($paymentStatus);
if ($paymentMethod) $filterParts[] = "Payment Method: " . strtoupper($paymentMethod);
if ($corporate !== 'all') $filterParts[] = "Corporate: " . ucfirst($corporate);

$filterSummary = implode(' | ', $filterParts);

$reportTitle = 'Filtered HMS Reports';
if ($category === 'invoices') $reportTitle = 'Filtered Invoices Report';
elseif ($category === 'source') $reportTitle = 'Filtered Bookings by Source Report';
elseif ($category === 'rooms') $reportTitle = 'Filtered Revenue by Room Report';
elseif ($category === 'booking_types') $reportTitle = 'Filtered Corporate vs Regular Report';
elseif ($category === 'guests') $reportTitle = 'Filtered Guest Stay Report';

$writer = new XlsxWriter($hotelName, $reportTitle, $filterSummary);

// Base Filter Clauses for SQL
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

// ---- 1. Filtered Invoices & Revenue Sheet ----
if (in_array($category, ['all', 'invoices', 'revenue'], true)) {
    $invoicesSql = "
      SELECT inv.*, b.booking_code, b.booking_type, b.company_name, g.full_name AS guest_name
      FROM invoices inv
      JOIN bookings b ON b.id = inv.booking_id
      JOIN guests g ON g.id = b.primary_guest_id
      $joinExtra
      $fullWhereSql
      ORDER BY inv.created_at DESC
    ";

    $invoicesStmt = db()->prepare($invoicesSql);
    $invoicesStmt->execute($bParams);
    $invoices = $invoicesStmt->fetchAll();

    $headers = ['Invoice #', 'Guest / Company Name', 'Booking Code', 'Type', 'Actual Room Revenue', 'Commission', 'Total Amount', 'Paid Amount', 'Balance Due', 'Invoice Date'];
    $colTypes = ['string', 'string', 'string', 'string', 'currency', 'currency', 'currency', 'currency', 'currency', 'string'];
    $rows = [];

    $totNetRev = 0; $totComm = 0; $totAmount = 0; $totPaid = 0; $totBal = 0;

    foreach ($invoices as $inv) {
        $guestOrComp = $inv['guest_name'];
        if (($inv['booking_type'] ?? '') === 'corporate' && !empty($inv['company_name'])) {
            $guestOrComp .= ' (' . $inv['company_name'] . ')';
        }
        $netRev = (float)$inv['room_charges'] - (float)$inv['commission_amount'];
        $comm   = (float)$inv['commission_amount'];
        $tot    = (float)$inv['total_amount'];
        $paid   = (float)$inv['paid_amount'];
        $bal    = (float)$inv['balance_amount'];

        $totNetRev += $netRev;
        $totComm   += $comm;
        $totAmount += $tot;
        $totPaid   += $paid;
        $totBal    += $bal;

        $rows[] = [
            $inv['invoice_number'],
            $guestOrComp,
            $inv['booking_code'],
            ucfirst($inv['booking_type'] ?? 'regular'),
            $netRev,
            $comm,
            $tot,
            $paid,
            $bal,
            date('d M Y', strtotime($inv['created_at'])),
        ];
    }

    $totals = ['Total (' . count($rows) . ' invoices)', '', '', '', $totNetRev, $totComm, $totAmount, $totPaid, $totBal, ''];
    $writer->addSheet('Invoices & Revenue', $headers, $rows, $colTypes, $totals);
}

// ---- 2. Bookings by Source Sheet ----
if (in_array($category, ['all', 'source', 'bookings'], true)) {
    $sourceStmt = db()->prepare("
      SELECT b.booking_source, COUNT(DISTINCT b.id) c, COALESCE(SUM(b.commission_amount),0) commission
      FROM bookings b
      $joinExtra
      $fullWhereSql
      GROUP BY b.booking_source ORDER BY c DESC
    ");
    $sourceStmt->execute($bParams);
    $bySource = $sourceStmt->fetchAll();

    $headers = ['Booking Source', 'Total Bookings', 'Commission Amount'];
    $colTypes = ['string', 'number', 'currency'];
    $rows = [];
    $totB = 0; $totC = 0;
    foreach ($bySource as $s) {
        $cnt  = (int)$s['c'];
        $comm = (float)$s['commission'];
        $totB += $cnt;
        $totC += $comm;
        $rows[] = [ucwords(str_replace('_', ' ', $s['booking_source'])), $cnt, $comm];
    }
    $totals = ['Total', $totB, $totC];
    $writer->addSheet('Bookings by Source', $headers, $rows, $colTypes, $totals);
}

// ---- 3. Revenue by Room Sheet ----
if (in_array($category, ['all', 'rooms', 'occupancy'], true)) {
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

    $headers = ['Room Number', 'Floor', 'Room Type', 'Bookings Count', 'Total Revenue'];
    $colTypes = ['string', 'string', 'string', 'number', 'currency'];
    $rows = [];
    $totBk = 0; $totRev = 0;
    foreach ($byRoom as $r) {
        $cnt = (int)$r['bookings_count'];
        $rev = (float)$r['revenue'];
        $totBk  += $cnt;
        $totRev += $rev;
        $rows[] = ['Room ' . $r['room_number'], 'Floor ' . $r['floor'], $r['room_type_name'], $cnt, $rev];
    }
    $totals = ['Total (' . count($rows) . ' rooms)', '', '', $totBk, $totRev];
    $writer->addSheet('Revenue by Room', $headers, $rows, $colTypes, $totals);
}

// ---- 4. Guest Stay Sheet ----
if (in_array($category, ['all', 'guests'], true)) {
    $guestSql = "
      SELECT b.booking_code, b.booking_type, b.company_name, b.checkin_datetime, b.expected_checkout_date, b.actual_checkout_datetime, b.status, b.rate_per_night, b.extra_amount,
             g.full_name AS guest_name, g.phone, g.email, g.city, g.state,
             COALESCE(inv.total_amount, (b.rate_per_night + b.extra_amount)) AS total_bill
      FROM bookings b
      JOIN guests g ON g.id = b.primary_guest_id
      LEFT JOIN invoices inv ON inv.booking_id = b.id
      $joinExtra
      $fullWhereSql
      ORDER BY b.created_at DESC
    ";

    $guestStmt = db()->prepare($guestSql);
    $guestStmt->execute($bParams);
    $guestRows = $guestStmt->fetchAll();

    $headers = ['Booking Code', 'Guest Name', 'Phone', 'Email', 'City / State', 'Check-In', 'Check-Out', 'Status', 'Total Bill'];
    $colTypes = ['string', 'string', 'string', 'string', 'string', 'string', 'string', 'string', 'currency'];
    $rows = [];
    $totBillSum = 0;

    foreach ($guestRows as $gr) {
        $name = $gr['guest_name'];
        if ($gr['booking_type'] === 'corporate' && $gr['company_name']) {
            $name .= ' (' . $gr['company_name'] . ')';
        }
        $location = implode(', ', array_filter([$gr['city'], $gr['state']])) ?: '—';
        $checkoutStr = $gr['actual_checkout_datetime'] ? date('d M Y', strtotime($gr['actual_checkout_datetime'])) : date('d M Y', strtotime($gr['expected_checkout_date']));
        $bill = (float)$gr['total_bill'];
        $totBillSum += $bill;

        $rows[] = [
            $gr['booking_code'],
            $name,
            $gr['phone'] ?: '—',
            $gr['email'] ?: '—',
            $location,
            date('d M Y', strtotime($gr['checkin_datetime'])),
            $checkoutStr,
            ucwords(str_replace('_', ' ', $gr['status'])),
            $bill
        ];
    }
    $totals = ['Total (' . count($rows) . ' guests)', '', '', '', '', '', '', '', $totBillSum];
    $writer->addSheet('Guest Stay Report', $headers, $rows, $colTypes, $totals);
}

// Download formatted Excel file
$filename = 'Filtered_HMS_Report_' . date('Ymd_His') . '.xlsx';
$writer->download($filename);

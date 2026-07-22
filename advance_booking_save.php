<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfCheck()) {
    flash('error', 'Invalid or expired form submission. Please try again.');
    redirect('advance_booking.php');
}

$pdo = db();

try {
    $postRoomIds   = $_POST['room_ids'] ?? [];
    $postRoomRates = $_POST['room_rates'] ?? [];

    $selectedRooms = [];
    if (!empty($postRoomIds) && is_array($postRoomIds)) {
        foreach ($postRoomIds as $idx => $rId) {
            $id = (int) $rId;
            $rate = (float) ($postRoomRates[$idx] ?? 0);
            if ($id > 0) {
                $selectedRooms[] = ['room_id' => $id, 'rate' => $rate];
            }
        }
    }

    // Fallback for single-room submissions if any
    if (empty($selectedRooms)) {
        $legacyRoomId = (int) ($_POST['room_id'] ?? 0);
        $legacyRate   = (float) ($_POST['rate_per_night'] ?? 0);
        if ($legacyRoomId > 0) {
            $selectedRooms[] = ['room_id' => $legacyRoomId, 'rate' => $legacyRate];
        }
    }

    $checkinDate      = $_POST['checkin_date'] ?? '';
    $checkinTime      = trim($_POST['checkin_time'] ?? '') ?: getSetting('default_checkin_time', '12:00');
    $checkoutDate     = $_POST['expected_checkout_date'] ?? '';
    $numGuests        = max(1, (int) ($_POST['num_guests'] ?? 1));
    $advanceAmount    = (float) ($_POST['advance_amount'] ?? 0);
    $chargeNames      = $_POST['charge_name'] ?? [];
    $chargeQtys       = $_POST['charge_qty'] ?? [];
    $chargePrices     = $_POST['charge_price'] ?? [];
    $chargeRemarks    = $_POST['charge_remarks'] ?? [];
    $extraAmount      = 0;
    $chargeRows       = [];
    if (is_array($chargeNames)) {
        foreach ($chargeNames as $ci => $cname) {
            $cname  = trim($cname);
            if (!$cname) continue;
            $cqty   = max(0, (float)($chargeQtys[$ci] ?? 1));
            $cprice = max(0, (float)($chargePrices[$ci] ?? 0));
            $ctotal = $cqty * $cprice;
            $extraAmount += $ctotal;
            $chargeRows[] = ['name'=>$cname,'qty'=>$cqty,'price'=>$cprice,'total'=>$ctotal,'rem'=>trim($chargeRemarks[$ci]??'')];
        }
    }
    $taxPercent       = (float) ($_POST['tax_percent'] ?? 0);
    $specialRequests  = trim($_POST['special_requests'] ?? '');

    $bookingSource      = $_POST['booking_source'] ?? 'phone';
    $agentName          = trim($_POST['agent_or_ota_name'] ?? '');
    $commissionPercent  = (float) ($_POST['commission_percent'] ?? 0);
    $commissionAmount   = (float) ($_POST['commission_amount'] ?? 0);
    $commissionStatus   = $_POST['commission_status'] ?? 'not_applicable';

    $bookingType          = $_POST['booking_type'] ?? 'regular';
    $companyName          = trim($_POST['company_name'] ?? '');
    $companyGstNumber     = strtoupper(trim($_POST['company_gst_number'] ?? ''));
    $companyAddress       = trim($_POST['company_address'] ?? '');
    $companyContactPerson = trim($_POST['company_contact_person'] ?? '');
    $companyPhone         = trim($_POST['company_phone'] ?? '');

    if ($bookingType === 'corporate') {
        if (empty($companyName)) {
            throw new RuntimeException('Company Name is required for Corporate Bookings.');
        }
        if (empty($companyGstNumber)) {
            throw new RuntimeException('Company GST Number is required for Corporate Bookings.');
        }
    } else {
        $bookingType = 'regular';
        $companyName = null;
        $companyGstNumber = null;
        $companyAddress = null;
        $companyContactPerson = null;
        $companyPhone = null;
    }

    $guestName  = trim($_POST['guest_name'] ?? '');
    $guestPhone = trim($_POST['guest_phone'] ?? '');
    $guestEmail = trim($_POST['guest_email'] ?? '');
    $guestCity  = trim($_POST['guest_city'] ?? '');
    $guestState = trim($_POST['guest_state'] ?? '');

    // ---- Basic validation ----
    if (empty($selectedRooms) || $guestName === '') {
        throw new RuntimeException('Please select at least one room and fill in contact name.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkinDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkoutDate)) {
        throw new RuntimeException('Please provide valid check-in and check-out dates.');
    }
    if (!preg_match('/^\d{1,2}:\d{2}$/', $checkinTime)) {
        $checkinTime = '12:00';
    }
    if (strtotime($checkinDate) < strtotime(date('Y-m-d'))) {
        throw new RuntimeException('Check-in date cannot be in the past.');
    }
    if (strtotime($checkoutDate) < strtotime($checkinDate)) {
        throw new RuntimeException('Expected check-out date cannot be before the check-in date.');
    }

    $checkinDateTime = $checkinDate . ' ' . $checkinTime . ':00';

    $pdo->beginTransaction();

    // Lock selected room rows and validate dates
    $roomStmt = $pdo->prepare("SELECT * FROM rooms WHERE id = :id FOR UPDATE");
    $totalNightlyRate = 0;
    $roomNumbersArr = [];

    foreach ($selectedRooms as $sr) {
        $roomStmt->execute(['id' => $sr['room_id']]);
        $room = $roomStmt->fetch();
        if (!$room) {
            throw new RuntimeException('Selected room ID ' . $sr['room_id'] . ' does not exist.');
        }
        if ($room['status'] === 'maintenance') {
            throw new RuntimeException('Room ' . $room['room_number'] . ' is under maintenance and cannot be reserved.');
        }

        $conflict = getRoomBookingConflict($sr['room_id'], $checkinDate, $checkoutDate);
        if ($conflict) {
            throw new RuntimeException(
                'Room ' . $room['room_number'] . ' is already ' .
                ($conflict['status'] === 'checked_in' ? 'occupied' : 'reserved') .
                ' for an overlapping period (booking ' . $conflict['booking_code'] . ', ' .
                date('d M Y', strtotime($conflict['checkin_datetime'])) . ' to ' .
                date('d M Y', strtotime($conflict['expected_checkout_date'])) .
                '). Please choose different dates or another room.'
            );
        }
        $totalNightlyRate += $sr['rate'];
        $roomNumbersArr[] = $room['room_number'];
    }

    $primaryRoomId = $selectedRooms[0]['room_id'];

    // ---- Create a lightweight guest record (no ID proof yet — collected at actual check-in) ----
    $guestStmt = $pdo->prepare("
      INSERT INTO guests (full_name, phone, email, city, state, created_at)
      VALUES (:name, :phone, :email, :city, :state, NOW())
    ");
    $guestStmt->execute([
        'name'  => $guestName,
        'phone' => $guestPhone ?: null,
        'email' => $guestEmail ?: null,
        'city'  => $guestCity ?: null,
        'state' => $guestState ?: null,
    ]);
    $guestId = (int) $pdo->lastInsertId();

    $bookingCode = generateBookingCode();

    $stmt = $pdo->prepare("
      INSERT INTO bookings
        (booking_code, booking_type, company_name, company_gst_number, company_address, company_contact_person, company_phone,
         room_id, primary_guest_id, checkin_datetime, expected_checkout_date, num_guests,
         status, rate_per_night, advance_amount, extra_amount, booking_source, agent_or_ota_name,
         commission_percent, commission_amount, commission_status, tax_percent, discount_amount,
         special_requests, created_by)
      VALUES
        (:code, :btype, :cname, :cgst, :caddr, :ccontact, :cphone,
         :room_id, :guest_id, :checkin, :checkout, :num_guests,
         'reserved', :rate, :advance, :extra_amount, :source, :agent,
         :comm_pct, :comm_amt, :comm_status, :tax, 0,
         :notes, :created_by)
    ");
    $stmt->execute([
        'code'         => $bookingCode,
        'btype'        => $bookingType,
        'cname'        => $companyName,
        'cgst'         => $companyGstNumber,
        'caddr'        => $companyAddress ?: null,
        'ccontact'     => $companyContactPerson ?: null,
        'cphone'       => $companyPhone ?: null,
        'room_id'      => $primaryRoomId,
        'guest_id'     => $guestId,
        'checkin'      => $checkinDateTime,
        'checkout'     => $checkoutDate,
        'num_guests'   => $numGuests,
        'rate'         => $totalNightlyRate,
        'advance'      => $advanceAmount,
        'extra_amount' => $extraAmount,
        'source'       => $bookingSource,
        'agent'        => $agentName ?: null,
        'comm_pct'     => $commissionPercent,
        'comm_amt'     => $commissionAmount,
        'comm_status'  => $commissionAmount > 0 ? $commissionStatus : 'not_applicable',
        'tax'          => $taxPercent,
        'notes'        => $specialRequests ?: null,
        'created_by'   => $_SESSION['user_id'],
    ]);
    $bookingId = (int) $pdo->lastInsertId();

    // Insert all selected rooms into booking_rooms junction table
    $brStmt = $pdo->prepare("INSERT INTO booking_rooms (booking_id, room_id, rate_per_night) VALUES (:b, :r, :rate)");
    foreach ($selectedRooms as $sr) {
        $brStmt->execute(['b' => $bookingId, 'r' => $sr['room_id'], 'rate' => $sr['rate']]);
    }

    $pdo->prepare("INSERT INTO booking_guests (booking_id, guest_id, is_primary) VALUES (:b, :g, 1)")
        ->execute(['b' => $bookingId, 'g' => $guestId]);

    if ($advanceAmount > 0) {
        $payStmt = $pdo->prepare("INSERT INTO payments (booking_id, amount, mode, payment_type, received_by, note) VALUES (:b, :a, 'cash', 'advance', :u, 'Advance/token amount received for reservation')");
        $payStmt->execute(['b' => $bookingId, 'a' => $advanceAmount, 'u' => $_SESSION['user_id']]);
    }

    // Insert individual extra charges
    if (!empty($chargeRows)) {
        $ecStmt = $pdo->prepare("INSERT INTO booking_extra_charges (booking_id, charge_name, qty, unit_price, total_amount, remarks, created_by) VALUES (:bid,:name,:qty,:price,:total,:rem,:uid)");
        foreach ($chargeRows as $cr) {
            $ecStmt->execute(['bid'=>$bookingId,'name'=>$cr['name'],'qty'=>$cr['qty'],'price'=>$cr['price'],'total'=>$cr['total'],'rem'=>$cr['rem']?:null,'uid'=>$_SESSION['user_id']]);
        }
    }

    $roomsStr = implode(', ', $roomNumbersArr);
    logActivity('advance_booking', "Reservation $bookingCode created for room(s) {$roomsStr}, arriving " . date('d M Y', strtotime($checkinDateTime)));

    $pdo->commit();

    flash('success', "Advance booking confirmed. Booking code: $bookingCode. Complete Check-In from this booking's page once the guest arrives.");
    redirect('booking_view.php?id=' . $bookingId);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', 'Advance booking failed: ' . $e->getMessage());
    redirect('advance_booking.php');
}

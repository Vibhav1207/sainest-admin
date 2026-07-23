<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfCheck()) {
    flash('error', 'Invalid or expired form submission. Please try again.');
    redirect('checkin.php');
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

    // Fallback for legacy single-room submissions
    if (empty($selectedRooms)) {
        $legacyRoomId = (int) ($_POST['room_id'] ?? 0);
        $legacyRate   = (float) ($_POST['rate_per_night'] ?? 0);
        if ($legacyRoomId > 0) {
            $selectedRooms[] = ['room_id' => $legacyRoomId, 'rate' => $legacyRate];
        }
    }

    $checkoutDate     = $_POST['expected_checkout_date'] ?? '';
    $numGuestsClaimed = max(1, (int) ($_POST['num_guests'] ?? 1));
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

    $bookingSource      = $_POST['booking_source'] ?? 'walk_in';
    $agentName          = trim($_POST['agent_or_ota_name'] ?? '');
    $commissionPercent  = (float) ($_POST['commission_percent'] ?? 0);
    $commissionAmount   = (float) ($_POST['commission_amount'] ?? 0);
    $commissionStatus   = $_POST['commission_status'] ?? 'not_applicable';

    $names   = $_POST['guest_name'] ?? [];
    $phones  = $_POST['guest_phone'] ?? [];
    $ages    = $_POST['guest_age'] ?? [];
    $genders = $_POST['guest_gender'] ?? [];
    $cities  = $_POST['guest_city'] ?? [];
    $states  = $_POST['guest_state'] ?? [];
    $addrs   = $_POST['guest_address'] ?? [];
    $emails  = $_POST['guest_email'] ?? [];
    $idTypes = $_POST['guest_id_type'] ?? [];
    $idNums  = $_POST['guest_id_number'] ?? [];

    if (empty($selectedRooms) || empty($checkoutDate) || empty($names[0])) {
        throw new RuntimeException('Please select at least one room, expected checkout date, and primary guest details.');
    }

    $pdo->beginTransaction();

    // Confirm all selected rooms exist, are available, and have no conflicts
    $roomStmt = $pdo->prepare("SELECT * FROM rooms WHERE id = :id FOR UPDATE");
    $totalNightlyRate = 0;

    foreach ($selectedRooms as &$sr) {
        $roomStmt->execute(['id' => $sr['room_id']]);
        $room = $roomStmt->fetch();
        if (!$room) {
            throw new RuntimeException('Selected room ID ' . $sr['room_id'] . ' does not exist.');
        }
        $sr['room_type_id'] = (int) $room['room_type_id'];
        if ($room['status'] === 'occupied') {
            throw new RuntimeException('Room ' . $room['room_number'] . ' was just occupied by another guest. Please choose a different room.');
        }

        $conflict = getRoomBookingConflict($sr['room_id'], date('Y-m-d'), $checkoutDate);
        if ($conflict) {
            throw new RuntimeException(
                'Room ' . $room['room_number'] . ' has an upcoming reservation from ' .
                date('d-m-Y', strtotime($conflict['checkin_datetime'])) . '. The selected stay conflicts with this reservation.'
            );
        }
        $totalNightlyRate += $sr['rate'];
    }

    $primaryRoomId = $selectedRooms[0]['room_id'];
    $bookingCode = generateBookingCode();
    $checkinDateTime = date('Y-m-d H:i:s');

    $primaryGuestId = null;
    $guestIds = [];

    foreach ($names as $i => $name) {
        $name = trim($name);
        if ($name === '') continue;

        $idPhoto = handleIndexedUpload('guest_id_photo', $i);
        $idPhotoBack = handleIndexedUpload('guest_id_photo_back', $i);

        $stmt = $pdo->prepare("
          INSERT INTO guests (full_name, phone, email, address, city, state, age, gender, id_proof_type, id_proof_number, id_proof_photo, id_proof_photo_back, last_stay_date, created_at)
          VALUES (:full_name, :phone, :email, :address, :city, :state, :age, :gender, :id_type, :id_number, :id_photo, :id_photo_back, :last_stay, NOW())
        ");
        $stmt->execute([
            'full_name'     => $name,
            'phone'         => trim($phones[$i] ?? '') ?: null,
            'email'         => trim($emails[$i] ?? '') ?: null,
            'address'       => trim($addrs[$i] ?? '') ?: null,
            'city'          => trim($cities[$i] ?? '') ?: null,
            'state'         => trim($states[$i] ?? '') ?: null,
            'age'           => !empty($ages[$i]) ? (int)$ages[$i] : null,
            'gender'        => $genders[$i] ?? '',
            'id_type'       => $idTypes[$i] ?? '',
            'id_number'     => trim($idNums[$i] ?? '') ?: null,
            'id_photo'      => $idPhoto,
            'id_photo_back' => $idPhotoBack,
            'last_stay'     => date('Y-m-d'),
        ]);
        $guestId = (int) $pdo->lastInsertId();
        $guestIds[] = $guestId;
        if ($primaryGuestId === null) {
            $primaryGuestId = $guestId;
        }
    }

    if (!$primaryGuestId) {
        throw new RuntimeException('At least one guest with a name is required.');
    }

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
         'checked_in', :rate, :advance, :extra_amount, :source, :agent,
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
        'guest_id'     => $primaryGuestId,
        'checkin'      => $checkinDateTime,
        'checkout'     => $checkoutDate,
        'num_guests'   => max($numGuestsClaimed, count($guestIds)),
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

    // Insert all selected rooms into booking_rooms junction table and set occupied status
    $brStmt = $pdo->prepare("INSERT INTO booking_rooms (booking_id, room_id, room_type_id, rate_per_night) VALUES (:b, :r, :rt, :rate)");
    $updRoomStmt = $pdo->prepare("UPDATE rooms SET status = 'occupied' WHERE id = :id");

    foreach ($selectedRooms as $sr) {
        $brStmt->execute(['b' => $bookingId, 'r' => $sr['room_id'], 'rt' => $sr['room_type_id'] ?? null, 'rate' => $sr['rate']]);
        $updRoomStmt->execute(['id' => $sr['room_id']]);
    }

    $bgStmt = $pdo->prepare("INSERT INTO booking_guests (booking_id, guest_id, is_primary) VALUES (:b, :g, :p)");
    foreach ($guestIds as $i => $gid) {
        $bgStmt->execute(['b' => $bookingId, 'g' => $gid, 'p' => $i === 0 ? 1 : 0]);
    }

    if ($advanceAmount > 0) {
        $payStmt = $pdo->prepare("INSERT INTO payments (booking_id, amount, mode, payment_type, received_by, note) VALUES (:b, :a, 'cash', 'advance', :u, 'Advance received at check-in')");
        $payStmt->execute(['b' => $bookingId, 'a' => $advanceAmount, 'u' => $_SESSION['user_id']]);
    }

    // Insert individual extra charges
    if (!empty($chargeRows)) {
        $ecStmt = $pdo->prepare("INSERT INTO booking_extra_charges (booking_id, charge_name, qty, unit_price, total_amount, remarks, created_by) VALUES (:bid,:name,:qty,:price,:total,:rem,:uid)");
        foreach ($chargeRows as $cr) {
            $ecStmt->execute(['bid'=>$bookingId,'name'=>$cr['name'],'qty'=>$cr['qty'],'price'=>$cr['price'],'total'=>$cr['total'],'rem'=>$cr['rem']?:null,'uid'=>$_SESSION['user_id']]);
        }
    }

    logActivity('checkin', "Booking $bookingCode created for room {$room['room_number']}");

    $pdo->commit();

    flash('success', "Check-in completed successfully. Booking code: $bookingCode");
    redirect('booking_view.php?id=' . $bookingId);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', 'Check-in failed: ' . $e->getMessage());
    redirect('checkin.php');
}

/**
 * Helper for indexed multi-file inputs like guest_id_photo[] since
 * handleDocumentUpload() expects a single named field. This normalises
 * $_FILES['guest_id_photo']['name'][$i] style arrays into a single-file
 * upload and reuses the same validation/storage logic.
 */
function handleIndexedUpload(string $baseName, int $index): ?string {
    if (empty($_FILES[$baseName]) || !isset($_FILES[$baseName]['error'][$index])) {
        return null;
    }
    if ($_FILES[$baseName]['error'][$index] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $single = [
        'name'     => $_FILES[$baseName]['name'][$index],
        'type'     => $_FILES[$baseName]['type'][$index],
        'tmp_name' => $_FILES[$baseName]['tmp_name'][$index],
        'error'    => $_FILES[$baseName]['error'][$index],
        'size'     => $_FILES[$baseName]['size'][$index],
    ];
    $tempKey = '__indexed_upload_tmp';
    $_FILES[$tempKey] = $single;
    $result = handleDocumentUpload($tempKey);
    unset($_FILES[$tempKey]);
    return $result;
}

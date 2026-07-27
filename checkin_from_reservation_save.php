<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfCheck()) {
    flash('error', 'Invalid or expired form submission. Please try again.');
    redirect('bookings.php');
}

$pdo = db();
$bookingId = (int) ($_POST['booking_id'] ?? 0);

try {
    $ratePerNight     = (float) ($_POST['rate_per_night'] ?? 0);
    $checkoutDate     = $_POST['expected_checkout_date'] ?? '';
    $numGuestsClaimed = max(1, (int) ($_POST['num_guests'] ?? 1));
    $extraAdvance     = (float) ($_POST['advance_amount'] ?? 0);
    $extraAmount      = max(0, (float) ($_POST['extra_amount'] ?? 0));
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

    if ($bookingId <= 0 || $ratePerNight <= 0 || empty($checkoutDate) || empty($names[0])) {
        throw new RuntimeException('Please fill in all required fields (rate, checkout date and at least one guest name).');
    }
    if (empty($idTypes[0]) || empty($idNums[0])) {
        throw new RuntimeException("The primary guest's ID proof type and number are required to complete check-in.");
    }

    $pdo->beginTransaction();

    // Lock the reservation itself so it can't be converted or cancelled twice at once.
    $bStmt = $pdo->prepare("SELECT * FROM bookings WHERE id = :id AND status = 'reserved' FOR UPDATE");
    $bStmt->execute(['id' => $bookingId]);
    $booking = $bStmt->fetch();
    if (!$booking) {
        throw new RuntimeException('This reservation was not found, or has already been checked in / cancelled.');
    }

    // Load all rooms allocated to this booking
    $bookingRooms = getBookingRooms($bookingId);
    if (empty($bookingRooms)) {
        throw new RuntimeException('No rooms found for this reservation.');
    }

    // Block check-in if any room is unassigned (room_id is NULL)
    foreach ($bookingRooms as $br) {
        if (empty($br['room_id'])) {
            throw new RuntimeException('One or more rooms are not yet assigned. Please assign all rooms via the booking edit page before checking in.');
        }
    }

    $roomNumbersArr = [];

    // Lock all rooms and make sure no OTHER booking is presently checked in there right now.
    $roomStmt = $pdo->prepare("SELECT * FROM rooms WHERE id = :id FOR UPDATE");
    foreach ($bookingRooms as $br) {
        $roomStmt->execute(['id' => $br['room_id']]);
        $room = $roomStmt->fetch();
        if (!$room) {
            throw new RuntimeException('Room for this reservation no longer exists.');
        }

        $activeStmt = $pdo->prepare("
          SELECT b.id FROM bookings b
          LEFT JOIN booking_rooms br2 ON br2.booking_id = b.id
          WHERE (b.room_id = :r1 OR br2.room_id = :r2)
            AND b.status = 'checked_in'
            AND b.id != :bid
          LIMIT 1
        ");
        $activeStmt->execute(['r1' => $br['room_id'], 'r2' => $br['room_id'], 'bid' => $bookingId]);
        if ($activeStmt->fetch()) {
            throw new RuntimeException('Room ' . $room['room_number'] . ' currently has another guest checked in. Please check that guest out first.');
        }

        // Check for future/overlapping reservations (excluding this booking itself)
        $conflict = getRoomBookingConflict($br['room_id'], date('Y-m-d'), $checkoutDate, $bookingId);
        if ($conflict) {
            throw new RuntimeException(
                'Room ' . $room['room_number'] . ' has an upcoming reservation from ' .
                date('d-m-Y', strtotime($conflict['checkin_datetime'])) . '. The selected stay conflicts with this reservation.'
            );
        }
        $roomNumbersArr[] = $room['room_number'];
    }

    if (strtotime($checkoutDate) < strtotime(date('Y-m-d'))) {
        throw new RuntimeException('Expected check-out date cannot be in the past.');
    }

    // ---- Update the primary guest with real details + mandatory ID proof ----
    $pgStmt = $pdo->prepare("SELECT guest_id FROM booking_guests WHERE booking_id = :id AND is_primary = 1 LIMIT 1");
    $pgStmt->execute(['id' => $bookingId]);
    $primaryGuestRow = $pgStmt->fetch();
    if (!$primaryGuestRow) {
        throw new RuntimeException('Primary guest record for this reservation could not be found.');
    }
    $primaryGuestId = (int) $primaryGuestRow['guest_id'];

    $idPhoto     = handleIndexedUpload('guest_id_photo', 0);
    $idPhotoBack = handleIndexedUpload('guest_id_photo_back', 0);

    $updGuest = $pdo->prepare("
      UPDATE guests SET
        full_name = :name, phone = :phone, email = :email, address = :address, city = :city, state = :state,
        age = :age, gender = :gender, id_proof_type = :id_type, id_proof_number = :id_number,
        id_proof_photo = COALESCE(:id_photo, id_proof_photo),
        id_proof_photo_back = COALESCE(:id_photo_back, id_proof_photo_back),
        last_stay_date = :last_stay
      WHERE id = :id
    ");
    $updGuest->execute([
        'name'      => trim($names[0]),
        'phone'     => trim($phones[0] ?? '') ?: null,
        'email'     => trim($emails[0] ?? '') ?: null,
        'address'   => trim($addrs[0] ?? '') ?: null,
        'city'      => trim($cities[0] ?? '') ?: null,
        'state'     => trim($states[0] ?? '') ?: null,
        'age'       => !empty($ages[0]) ? (int) $ages[0] : null,
        'gender'    => $genders[0] ?? '',
        'id_type'   => $idTypes[0] ?? '',
        'id_number' => trim($idNums[0] ?? '') ?: null,
        'id_photo'      => $idPhoto,
        'id_photo_back' => $idPhotoBack,
        'last_stay' => date('Y-m-d'),
        'id'        => $primaryGuestId,
    ]);

    $guestIds = [$primaryGuestId];

    // ---- Any additional guests beyond the primary ----
    foreach ($names as $i => $name) {
        if ($i === 0) continue; // primary already handled above
        $name = trim($name);
        if ($name === '') continue;

        $extraIdPhoto = handleIndexedUpload('guest_id_photo', $i);
        $extraIdPhotoBack = handleIndexedUpload('guest_id_photo_back', $i);

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
            'age'           => !empty($ages[$i]) ? (int) $ages[$i] : null,
            'gender'        => $genders[$i] ?? '',
            'id_type'       => $idTypes[$i] ?? '',
            'id_number'     => trim($idNums[$i] ?? '') ?: null,
            'id_photo'      => $extraIdPhoto,
            'id_photo_back' => $extraIdPhotoBack,
            'last_stay'     => date('Y-m-d'),
        ]);
        $newGuestId = (int) $pdo->lastInsertId();
        $guestIds[] = $newGuestId;
        $pdo->prepare("INSERT INTO booking_guests (booking_id, guest_id, is_primary) VALUES (:b, :g, 0)")
            ->execute(['b' => $bookingId, 'g' => $newGuestId]);
    }

    // ---- Finalise the booking itself: reservation becomes an active stay ----
    $updBooking = $pdo->prepare("
      UPDATE bookings SET
        status = 'checked_in',
        booking_type = :btype,
        company_name = :cname,
        company_gst_number = :cgst,
        company_address = :caddr,
        company_contact_person = :ccontact,
        company_phone = :cphone,
        checkin_datetime = NOW(),
        expected_checkout_date = :checkout,
        rate_per_night = :rate,
        extra_amount = :extra_amount,
        tax_percent = :tax,
        num_guests = :num_guests,
        special_requests = :notes
      WHERE id = :id
    ");
    $updBooking->execute([
        'btype'        => $bookingType,
        'cname'        => $companyName,
        'cgst'         => $companyGstNumber,
        'caddr'        => $companyAddress ?: null,
        'ccontact'     => $companyContactPerson ?: null,
        'cphone'       => $companyPhone ?: null,
        'checkout'     => $checkoutDate,
        'rate'         => $ratePerNight,
        'extra_amount' => $extraAmount,
        'tax'          => $taxPercent,
        'num_guests'   => max(1, count($guestIds)),
        'notes'        => $specialRequests ?: null,
        'id'           => $bookingId,
    ]);

    if ($extraAdvance > 0) {
        $payStmt = $pdo->prepare("INSERT INTO payments (booking_id, amount, mode, payment_type, received_by, note) VALUES (:b, :a, 'cash', 'advance', :u, 'Additional advance collected at check-in')");
        $payStmt->execute(['b' => $bookingId, 'a' => $extraAdvance, 'u' => $_SESSION['user_id']]);
    }

    // Mark ALL rooms for this booking as 'occupied' and sync room_type_id on booking_rooms
    $updRoom = $pdo->prepare("UPDATE rooms SET status = 'occupied' WHERE id = :id");
    $updBrType = $pdo->prepare("UPDATE booking_rooms br JOIN rooms r ON r.id = br.room_id SET br.room_type_id = r.room_type_id WHERE br.booking_id = :bid AND br.room_id = :rid");
    foreach ($bookingRooms as $br) {
        if (!empty($br['room_id'])) {
            $updRoom->execute(['id' => $br['room_id']]);
            $updBrType->execute(['bid' => $bookingId, 'rid' => $br['room_id']]);
        }
    }

    $roomsStr = implode(', ', $roomNumbersArr);
    logActivity('checkin_from_reservation', "Reservation {$booking['booking_code']} converted to check-in for room(s) {$roomsStr}");

    $pdo->commit();

    flash('success', "Check-in completed successfully for booking {$booking['booking_code']}.");
    redirect('booking_view.php?id=' . $bookingId);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', 'Check-in failed: ' . $e->getMessage());
    redirect('checkin_from_reservation.php?id=' . $bookingId);
}

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

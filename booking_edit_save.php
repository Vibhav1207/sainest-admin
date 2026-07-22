<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfCheck()) {
    flash('error', 'Invalid or expired form submission. Please try again.');
    redirect('bookings.php');
}

$pdo = db();

function bookingEditChanges(array $oldValues, array $newValues): array {
    $changes = [];
    foreach ($newValues as $field => $newValue) {
        $oldValue = $oldValues[$field] ?? '';
        if ((string) $oldValue !== (string) $newValue) {
            $changes[] = $field . ': ' . $oldValue . ' -> ' . $newValue;
        }
    }
    return $changes;
}

try {
    $bookingId = (int) ($_POST['booking_id'] ?? 0);
    if ($bookingId <= 0) {
        throw new RuntimeException('Invalid booking ID.');
    }

    // ---- Load existing booking and old rooms ----
    $oldStmt = $pdo->prepare("
        SELECT b.*, g.full_name AS guest_name, g.phone AS guest_phone, g.email AS guest_email,
               g.address AS guest_address, g.city AS guest_city, g.state AS guest_state,
               g.id_proof_type, g.id_proof_number
        FROM bookings b
        JOIN guests g ON g.id = b.primary_guest_id
        WHERE b.id = :id
    ");
    $oldStmt->execute(['id' => $bookingId]);
    $oldBooking = $oldStmt->fetch();
    if (!$oldBooking) {
        throw new RuntimeException('Booking not found.');
    }
    if (in_array($oldBooking['status'], ['checked_out', 'cancelled'])) {
        throw new RuntimeException('Checked-out and cancelled bookings cannot be edited.');
    }

    $oldBookingRooms = getBookingRooms($bookingId);
    $oldRoomIds = array_map(fn($r) => (int)$r['room_id'], $oldBookingRooms);

    // ---- Parse POST rooms ----
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

    // Fallback if single room submitted
    if (empty($selectedRooms)) {
        $legacyRoomId = (int) ($_POST['room_id'] ?? 0);
        $legacyRate   = (float) ($_POST['rate_per_night'] ?? 0);
        if ($legacyRoomId > 0) {
            $selectedRooms[] = ['room_id' => $legacyRoomId, 'rate' => $legacyRate];
        }
    }

    if (empty($selectedRooms)) {
        throw new RuntimeException('At least one valid room must be selected.');
    }

    // ---- Parse other POST fields ----
    $numGuests           = max(1, (int) ($_POST['num_guests'] ?? 1));
    $checkinDatetime     = trim($_POST['checkin_datetime'] ?? '');
    $checkoutDate        = trim($_POST['expected_checkout_date'] ?? '');
    $advanceAmount       = max(0, (float) ($_POST['advance_amount'] ?? 0));
    $extraAmount         = max(0, (float) ($_POST['extra_amount'] ?? 0));
    $taxPercent          = max(0, (float) ($_POST['tax_percent'] ?? 0));
    $discountAmount      = max(0, (float) ($_POST['discount_amount'] ?? 0));
    $specialRequests     = trim($_POST['special_requests'] ?? '');
    $bookingSource       = $_POST['booking_source'] ?? 'walk_in';
    $agentName           = trim($_POST['agent_or_ota_name'] ?? '');
    $commissionPercent   = max(0, (float) ($_POST['commission_percent'] ?? 0));
    $commissionAmount    = max(0, (float) ($_POST['commission_amount'] ?? 0));
    $commissionStatus    = $_POST['commission_status'] ?? 'not_applicable';
    $bookingType         = $_POST['booking_type'] ?? 'regular';
    $companyName         = trim($_POST['company_name'] ?? '');
    $companyGst          = strtoupper(trim($_POST['company_gst_number'] ?? ''));
    $companyAddress      = trim($_POST['company_address'] ?? '');
    $companyContact      = trim($_POST['company_contact_person'] ?? '');
    $companyPhone        = trim($_POST['company_phone'] ?? '');

    // Guest fields
    $guestName           = trim($_POST['guest_name'] ?? '');
    $guestPhone          = trim($_POST['guest_phone'] ?? '');
    $guestEmail          = trim($_POST['guest_email'] ?? '');
    $guestAge            = !empty($_POST['guest_age']) ? (int)$_POST['guest_age'] : null;
    $guestGender         = $_POST['guest_gender'] ?? '';
    $guestCity           = trim($_POST['guest_city'] ?? '');
    $guestState          = trim($_POST['guest_state'] ?? '');
    $guestAddress        = trim($_POST['guest_address'] ?? '');
    $guestIdType         = trim($_POST['guest_id_type'] ?? '');
    $guestIdNumber       = trim($_POST['guest_id_number'] ?? '');

    // ---- Basic validation ----
    if (!$guestName || !$guestPhone) {
        throw new RuntimeException('Guest name and phone are required.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $checkinDatetime)) {
        throw new RuntimeException('Invalid check-in date/time format.');
    }
    $checkinMysql = str_replace('T', ' ', $checkinDatetime) . ':00';
    $checkinDate  = substr($checkinMysql, 0, 10);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkoutDate)) {
        throw new RuntimeException('Invalid check-out date format.');
    }
    if ($checkoutDate < $checkinDate) {
        throw new RuntimeException('Check-out date cannot be before check-in date.');
    }

    // Corporate validation
    if ($bookingType === 'corporate') {
        if (empty($companyName)) {
            throw new RuntimeException('Company Name is required for Corporate Bookings.');
        }
        if (empty($companyGst)) {
            throw new RuntimeException('GST Number is required for Corporate Bookings.');
        }
    } else {
        $bookingType = 'regular';
        $companyName = null;
        $companyGst  = null;
        $companyAddress = null;
        $companyContact = null;
        $companyPhone   = null;
    }

    $pdo->beginTransaction();

    // ---- Validate each selected room ----
    $roomStmt = $pdo->prepare("SELECT * FROM rooms WHERE id = :id FOR UPDATE");
    $totalNightlyRate = 0;
    $newRoomIds = [];

    foreach ($selectedRooms as $sr) {
        $roomStmt->execute(['id' => $sr['room_id']]);
        $room = $roomStmt->fetch();
        if (!$room) {
            throw new RuntimeException('Selected room ID ' . $sr['room_id'] . ' does not exist.');
        }
        if ($room['status'] === 'maintenance') {
            throw new RuntimeException('Room ' . $room['room_number'] . ' is under maintenance.');
        }

        // If booking is checked_in and room is newly added, make sure it isn't occupied by someone else
        $isNewlyAdded = !in_array($sr['room_id'], $oldRoomIds, true);
        if ($oldBooking['status'] === 'checked_in' && $isNewlyAdded && $room['status'] === 'occupied') {
            throw new RuntimeException('Room ' . $room['room_number'] . ' is currently occupied by another guest.');
        }

        // Conflict check
        $conflict = getRoomBookingConflict($sr['room_id'], $checkinDate, $checkoutDate, $bookingId);
        if ($conflict) {
            throw new RuntimeException(
                'Room ' . $room['room_number'] . ' has a conflicting booking (code: ' .
                $conflict['booking_code'] . ') from ' .
                date('d M Y', strtotime($conflict['checkin_datetime'])) . ' to ' .
                date('d M Y', strtotime($conflict['expected_checkout_date'])) .
                '. Please choose different dates or a different room.'
            );
        }

        $totalNightlyRate += $sr['rate'];
        $newRoomIds[] = $sr['room_id'];
    }

    $primaryRoomId = $selectedRooms[0]['room_id'];

    // ---- Update main booking record ----
    $pdo->prepare("
        UPDATE bookings SET
            booking_type         = :btype,
            company_name         = :cname,
            company_gst_number   = :cgst,
            company_address      = :caddr,
            company_contact_person = :ccontact,
            company_phone        = :cphone,
            room_id              = :room_id,
            checkin_datetime     = :checkin,
            expected_checkout_date = :checkout,
            num_guests           = :num_guests,
            rate_per_night       = :rate,
            advance_amount       = :advance,
            extra_amount         = :extra_amount,
            tax_percent          = :tax,
            discount_amount      = :discount,
            booking_source       = :source,
            agent_or_ota_name    = :agent,
            commission_percent   = :comm_pct,
            commission_amount    = :comm_amt,
            commission_status    = :comm_status,
            special_requests     = :notes,
            updated_at           = NOW()
        WHERE id = :id
    ")->execute([
        'btype'       => $bookingType,
        'cname'       => $companyName,
        'cgst'        => $companyGst,
        'caddr'       => $companyAddress ?: null,
        'ccontact'    => $companyContact ?: null,
        'cphone'      => $companyPhone ?: null,
        'room_id'     => $primaryRoomId,
        'checkin'     => $checkinMysql,
        'checkout'    => $checkoutDate,
        'num_guests'  => $numGuests,
        'rate'        => $totalNightlyRate,
        'advance'     => $advanceAmount,
        'extra_amount'=> $extraAmount,
        'tax'         => $taxPercent,
        'discount'    => $discountAmount,
        'source'      => $bookingSource,
        'agent'       => $agentName ?: null,
        'comm_pct'    => $commissionPercent,
        'comm_amt'    => $commissionAmount,
        'comm_status' => $commissionAmount > 0 ? $commissionStatus : 'not_applicable',
        'notes'       => $specialRequests ?: null,
        'id'          => $bookingId,
    ]);

    // ---- Sync booking_rooms table ----
    $pdo->prepare("DELETE FROM booking_rooms WHERE booking_id = :bid")->execute(['bid' => $bookingId]);
    $brStmt = $pdo->prepare("INSERT INTO booking_rooms (booking_id, room_id, rate_per_night) VALUES (:bid, :rid, :rate)");
    foreach ($selectedRooms as $sr) {
        $brStmt->execute(['bid' => $bookingId, 'rid' => $sr['room_id'], 'rate' => $sr['rate']]);
    }

    // If booking status is checked_in, update room statuses
    if ($oldBooking['status'] === 'checked_in') {
        // Rooms removed from stay -> mark available (if not used by another checked_in booking)
        $removedRoomIds = array_diff($oldRoomIds, $newRoomIds);
        foreach ($removedRoomIds as $rId) {
            $otherActive = $pdo->prepare("SELECT id FROM bookings WHERE (room_id = :r1 OR id IN (SELECT booking_id FROM booking_rooms WHERE room_id = :r2)) AND status = 'checked_in' LIMIT 1");
            $otherActive->execute(['r1' => $rId, 'r2' => $rId]);
            if (!$otherActive->fetch()) {
                $pdo->prepare("UPDATE rooms SET status = 'available' WHERE id = :id")->execute(['id' => $rId]);
            }
        }
        // Newly added rooms -> mark occupied
        $addedRoomIds = array_diff($newRoomIds, $oldRoomIds);
        foreach ($addedRoomIds as $rId) {
            $pdo->prepare("UPDATE rooms SET status = 'occupied' WHERE id = :id")->execute(['id' => $rId]);
        }
    }

    // ---- Update primary guest record ----
    $guestId = (int) $oldBooking['primary_guest_id'];

    $idPhoto     = handleDocumentUpload('guest_id_photo');
    $idPhotoBack = handleDocumentUpload('guest_id_photo_back');

    $guestUpdateSql = "
        UPDATE guests SET
            full_name        = :name,
            phone            = :phone,
            email            = :email,
            age              = :age,
            gender           = :gender,
            city             = :city,
            state            = :state,
            address          = :address,
            id_proof_type    = :id_type,
            id_proof_number  = :id_number
    ";
    $guestParams = [
        'name'      => $guestName,
        'phone'     => $guestPhone ?: null,
        'email'     => $guestEmail ?: null,
        'age'       => $guestAge,
        'gender'    => $guestGender ?: null,
        'city'      => $guestCity ?: null,
        'state'     => $guestState ?: null,
        'address'   => $guestAddress ?: null,
        'id_type'   => $guestIdType ?: null,
        'id_number' => $guestIdNumber ?: null,
        'id'        => $guestId,
    ];

    if ($idPhoto) {
        $guestUpdateSql .= ', id_proof_photo = :id_photo';
        $guestParams['id_photo'] = $idPhoto;
    }
    if ($idPhotoBack) {
        $guestUpdateSql .= ', id_proof_photo_back = :id_photo_back';
        $guestParams['id_photo_back'] = $idPhotoBack;
    }

    $guestUpdateSql .= ' WHERE id = :id';
    $pdo->prepare($guestUpdateSql)->execute($guestParams);

    // ---- Activity log ----
    logActivity('booking_edit', "Booking #{$bookingId} ({$oldBooking['booking_code']}) updated with " . count($selectedRooms) . " room(s).");

    $pdo->commit();

    unset($_SESSION['booking_edit_old_input']);
    flash('success', 'Booking updated successfully.');
    redirect('booking_view.php?id=' . $bookingId);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['booking_edit_old_input'] = $_POST;
    flash('error', 'Could not update booking: ' . $e->getMessage());
    redirect('booking_edit.php?id=' . ($bookingId ?? 0));
}

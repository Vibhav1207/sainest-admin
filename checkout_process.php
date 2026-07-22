<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfCheck()) {
    flash('error', 'Invalid or expired form submission.');
    redirect('checkout.php');
}

$pdo = db();

try {
    $bookingId = (int) ($_POST['booking_id'] ?? 0);
    $nights = max(1, (int) ($_POST['nights'] ?? 1));
    $ratePerNight = (float) ($_POST['rate_per_night'] ?? 0);
    $discount = (float) ($_POST['discount_amount'] ?? 0);
    $taxPercent = (float) ($_POST['tax_percent'] ?? 0);
    $paymentNow = (float) ($_POST['payment_now'] ?? 0);
    $paymentMode = $_POST['payment_mode'] ?? 'cash';

    $itemDescs = $_POST['item_desc'] ?? [];
    $itemQtys  = $_POST['item_qty'] ?? [];
    $itemRates = $_POST['item_rate'] ?? [];

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = :id AND status = 'checked_in' FOR UPDATE");
    $stmt->execute(['id' => $bookingId]);
    $booking = $stmt->fetch();
    if (!$booking) {
        throw new RuntimeException('Booking not found or already checked out.');
    }

    // The commission recorded on the booking (internal, set at check-in) is
    // silently folded into the guest-facing room charges here. The guest is
    // only ever shown the combined total on their invoice — never a separate
    // commission line. Internally we still keep the true split (via
    // invoices.commission_amount) so the dashboard/reports can show actual
    // room revenue vs. commission payable.
    $commissionAmount = (float) $booking['commission_amount'];
    $actualRoomCharges = $nights * $ratePerNight;
    $roomCharges = $actualRoomCharges + $commissionAmount;
    $extraCharges = 0.0;
    $lineItems = [];
    foreach ($itemDescs as $i => $desc) {
        $desc = trim($desc);
        $qty = (float) ($itemQtys[$i] ?? 0);
        $rate = (float) ($itemRates[$i] ?? 0);
        if ($desc === '' || $qty <= 0) continue;
        $amount = $qty * $rate;
        $extraCharges += $amount;
        $lineItems[] = ['desc' => $desc, 'qty' => $qty, 'rate' => $rate, 'amount' => $amount];
    }

    $taxable = max(0, $roomCharges + $extraCharges - $discount);
    $taxAmount = round(($taxable * $taxPercent) / 100, 2);
    $grandTotal = round($taxable + $taxAmount, 2);

    $priorPaidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) t FROM payments WHERE booking_id = :id AND payment_type != 'refund'");
    $priorPaidStmt->execute(['id' => $bookingId]);
    $priorPaid = (float) $priorPaidStmt->fetch()['t'];

    $totalPaid = $priorPaid + $paymentNow;
    $balance = round($grandTotal - $totalPaid, 2);

    $invoiceNumber = generateInvoiceNumber();
    $invStmt = $pdo->prepare("
      INSERT INTO invoices (booking_id, invoice_number, room_charges, commission_amount, extra_charges, discount_amount, tax_amount, total_amount, paid_amount, balance_amount, generated_by)
      VALUES (:b, :num, :room, :comm, :extra, :disc, :tax, :total, :paid, :bal, :u)
    ");
    $invStmt->execute([
        'b' => $bookingId, 'num' => $invoiceNumber, 'room' => $roomCharges, 'comm' => $commissionAmount, 'extra' => $extraCharges,
        'disc' => $discount, 'tax' => $taxAmount, 'total' => $grandTotal, 'paid' => $totalPaid, 'bal' => $balance, 'u' => $_SESSION['user_id'],
    ]);
    $invoiceId = (int) $pdo->lastInsertId();

    if ($lineItems) {
        $itemStmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id, description, qty, rate, amount) VALUES (:i, :d, :q, :r, :a)");
        foreach ($lineItems as $li) {
            $itemStmt->execute(['i' => $invoiceId, 'd' => $li['desc'], 'q' => $li['qty'], 'r' => $li['rate'], 'a' => $li['amount']]);
        }
    }

    if ($paymentNow > 0) {
        $payStmt = $pdo->prepare("INSERT INTO payments (booking_id, amount, mode, payment_type, received_by, note) VALUES (:b, :a, :m, 'final', :u, 'Collected at check-out')");
        $payStmt->execute(['b' => $bookingId, 'a' => $paymentNow, 'm' => $paymentMode, 'u' => $_SESSION['user_id']]);
    }

    $pdo->prepare("UPDATE bookings SET status = 'checked_out', actual_checkout_datetime = NOW(), checked_out_by = :u WHERE id = :id")
        ->execute(['u' => $_SESSION['user_id'], 'id' => $bookingId]);

    $bookingRooms = getBookingRooms($bookingId);
    $updRoom = $pdo->prepare("UPDATE rooms SET status = 'dirty' WHERE id = :id");
    $hkStmt = $pdo->prepare("INSERT INTO housekeeping_tasks (room_id, task_type, status, notes, created_by) VALUES (:r, 'cleaning', 'pending', 'Auto-created after guest check-out', :u)");

    foreach ($bookingRooms as $br) {
        $updRoom->execute(['id' => $br['room_id']]);
        $hkStmt->execute(['r' => $br['room_id'], 'u' => $_SESSION['user_id']]);
    }

    logActivity('checkout', "Booking {$booking['booking_code']} checked out. Invoice $invoiceNumber generated.");

    $pdo->commit();

    flash('success', "Check-out complete. Invoice $invoiceNumber generated.");
    redirect('invoice_print.php?id=' . $invoiceId);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', 'Check-out failed: ' . $e->getMessage());
    redirect('checkout.php?booking_id=' . ($bookingId ?? ''));
}

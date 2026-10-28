<?php
/**
 * OFFGRID - Self-Service Booking Cancellation Handler
 * 
 * Safely cancels an event registration and atomically restores occupied seats:
 * - Protected by require_login() and CSRF token validation
 * - Enforces authorization (user owns booking or possesses admin role)
 * - Prohibits cancellation of events that have already occurred
 * - Executes in an atomic database transaction with concurrency lock
 * - Replenishes available_seats up to the event's total_capacity
 * - Updates booking payment_status to 'cancelled'
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/notify.php';

require_login('login.php');

$currentUser = current_user();

// Reject non-POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    set_flash('error', 'Invalid request method. Cancellations must be submitted via POST.');
    redirect('my-bookings.php');
}

// Verify CSRF Security Token
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash('error', 'Security token expired or invalid. Please try again.');
    redirect('my-bookings.php');
}

$bookingCode = trim($_POST['booking_code'] ?? '');
$redirectUrl = trim($_POST['redirect'] ?? 'my-bookings.php');

if (empty($bookingCode)) {
    set_flash('error', 'Missing booking reference code.');
    redirect('my-bookings.php');
}

try {
    // 1. Fetch booking with joined event details
    $stmt = $pdo->prepare('
        SELECT b.id, b.booking_code, b.user_id, b.event_id, b.tickets_count, b.payment_status,
               e.id AS event_id, e.title AS event_title, e.event_date, e.available_seats, e.total_capacity
        FROM bookings b
        JOIN events e ON b.event_id = e.id
        WHERE b.booking_code = ?
        LIMIT 1
    ');
    $stmt->execute([$bookingCode]);
    $booking = $stmt->fetch();

    if (!$booking) {
        set_flash('error', 'The requested booking could not be found.');
        redirect('my-bookings.php');
    }

    // 2. Authorization check: User must own the booking OR be an administrator
    if ((int)$booking['user_id'] !== (int)$currentUser['id'] && !is_admin()) {
        http_response_code(403);
        set_flash('error', 'Access Denied: You are not authorized to cancel this reservation.');
        redirect('my-bookings.php');
    }

    // 3. Status check: Can only cancel active/confirmed bookings
    if ($booking['payment_status'] === 'cancelled') {
        set_flash('info', 'This booking has already been cancelled.');
        redirect('my-bookings.php');
    }

    // 4. Date check: Cannot cancel past events
    $todayDate = date('Y-m-d');
    if ($booking['event_date'] < $todayDate) {
        set_flash('error', 'Cannot cancel a booking for a gathering that has already taken place.');
        redirect('my-bookings.php');
    }

    // 5. Atomic Transaction: Cancel booking and restore available seats
    $pdo->beginTransaction();

    // Lock event row
    $lockStmt = $pdo->prepare('SELECT id, available_seats, total_capacity FROM events WHERE id = ? FOR UPDATE');
    $lockStmt->execute([$booking['event_id']]);
    $eventRow = $lockStmt->fetch();

    if (!$eventRow) {
        throw new Exception('Associated event record could not be locked.');
    }

    // Update booking status to 'cancelled'
    $updBk = $pdo->prepare('UPDATE bookings SET payment_status = "cancelled" WHERE id = ?');
    $updBk->execute([$booking['id']]);

    // Replenish seats: available_seats + tickets_count (capped at total_capacity)
    $restoredSeats = (int)$booking['tickets_count'];
    $updEv = $pdo->prepare('
        UPDATE events 
        SET available_seats = LEAST(total_capacity, available_seats + ?) 
        WHERE id = ?
    ');
    $updEv->execute([$restoredSeats, $booking['event_id']]);

    $pdo->commit();

    // ── Notification: Booking Cancelled ────────────────────────────────
    $eventDateFmt = date('D, d M Y', strtotime($booking['event_date']));
    $cancelNotifUserId = (int)$booking['user_id']; // always notify the booking owner
    create_notification(
        $pdo,
        $cancelNotifUserId,
        'booking_cancelled',
        '❌ Booking Cancelled: ' . $booking['event_title'],
        'Your registration for "' . $booking['event_title'] . '" (' . $eventDateFmt . ') '
            . 'has been cancelled. Ref: ' . $booking['booking_code'] . '. '
            . $restoredSeats . ($restoredSeats === 1 ? ' seat has' : ' seats have') . ' been released.',
        (int)$booking['id'],
        'my-bookings.php'
    );

    set_flash(
        'success', 
        'Your reservation for "' . $booking['event_title'] . '" has been cancelled. ' . 
        $restoredSeats . ($restoredSeats === 1 ? ' seat has' : ' seats have') . 
        ' been released back to the event.'
    );

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[OFFGRID BOOKING CANCELLATION ERROR] ' . $e->getMessage());
    set_flash('error', 'An unexpected error occurred while processing your cancellation. Please try again.');
}

// Redirect back to designated destination
if (strpos($redirectUrl, 'booking-confirmation.php') !== false) {
    redirect($redirectUrl);
} else {
    redirect('my-bookings.php');
}

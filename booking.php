<?php
/**
 * OFFGRID - Simulated Demo Booking & Checkout Flow
 * 
 * Secure, concurrency-safe registration processing:
 * - Strictly requires authenticated user session (user ID never trusted from POST)
 * - Protected by CSRF token verification
 * - Uses MySQL transactions with row-level locking (SELECT ... FOR UPDATE) to prevent race conditions & overbooking
 * - Enforces duplicate-booking prevention (one registration per user per event)
 * - Atomic seat deduction and booking insertion
 * - Strictly simulated demo payments (no real card details or banking data collected)
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/notify.php';

// 1. Strict Authentication Guard
require_login('login.php');

$currentUser = current_user();
$userId = $currentUser['id'];

// 2. Validate Event ID
$eventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT) 
        ?: filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);

if (!$eventId || $eventId <= 0) {
    set_flash('error', 'Invalid event selected for booking.');
    redirect('index.php');
}

// -------------------------------------------------------------
// POST REQUEST: Process Concurrency-Safe Booking
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 2.1 Verify CSRF Token
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submittedToken)) {
        set_flash('error', 'Security session expired (invalid CSRF token). Please try again.');
        redirect('event-details.php?id=' . $eventId);
    }

    // 2.2 Validate Ticket Count (Between 1 and 5 max per booking)
    $ticketsCount = filter_input(INPUT_POST, 'tickets_count', FILTER_VALIDATE_INT);
    if (!$ticketsCount || $ticketsCount < 1 || $ticketsCount > 5) {
        $ticketsCount = 1; // Default fallback
    }

    // 2.3 Execute Atomic Transaction with Row Locking
    try {
        $pdo->beginTransaction();

        // Lock event row with FOR UPDATE to prevent concurrency race conditions & overbooking
        $lockStmt = $pdo->prepare('
            SELECT id, title, price, available_seats, total_capacity, status 
            FROM events 
            WHERE id = ? 
            FOR UPDATE
        ');
        $lockStmt->execute([$eventId]);
        $event = $lockStmt->fetch();

        if (!$event || $event['status'] !== 'active') {
            throw new Exception('This event is no longer available for registration.');
        }

        // Check for duplicate active booking by this user inside the transaction
        $dupStmt = $pdo->prepare('
            SELECT id, booking_code, payment_status 
            FROM bookings 
            WHERE user_id = ? AND event_id = ? 
            FOR UPDATE
        ');
        $dupStmt->execute([$userId, $eventId]);
        $existingBooking = $dupStmt->fetch();

        if ($existingBooking && $existingBooking['payment_status'] === 'confirmed') {
            throw new Exception('You currently have an active registration for this event (Booking Ref: ' . $existingBooking['booking_code'] . '). Duplicate registrations are not permitted.');
        }

        // Check remaining seat capacity
        if ($event['available_seats'] < $ticketsCount) {
            if ($event['available_seats'] <= 0) {
                throw new Exception('Sorry, this event just sold out! No remaining seats.');
            } else {
                throw new Exception('Only ' . $event['available_seats'] . ' seat(s) remain open. You requested ' . $ticketsCount . '.');
            }
        }

        // Generate friendly unique booking code (e.g. OG-2026-A1B2C3)
        $bookingCode = 'OG-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $unitPrice = (float)$event['price'];
        $totalAmount = $unitPrice * $ticketsCount;
        $paymentReference = 'DEMO-TXN-' . strtoupper(bin2hex(random_bytes(4)));

        if ($existingBooking && $existingBooking['payment_status'] === 'cancelled') {
            // Reactivate previously cancelled reservation
            $reactivateStmt = $pdo->prepare('
                UPDATE bookings 
                SET booking_code = ?, tickets_count = ?, total_amount = ?, 
                    payment_status = "confirmed", payment_reference = ?, booking_date = NOW()
                WHERE id = ?
            ');
            $reactivateStmt->execute([
                $bookingCode,
                $ticketsCount,
                $totalAmount,
                $paymentReference,
                $existingBooking['id']
            ]);
        } else {
            // Insert new booking record
            $insertBooking = $pdo->prepare('
                INSERT INTO bookings (
                    booking_code, user_id, event_id, tickets_count, 
                    total_amount, payment_method, payment_status, payment_reference, booking_date
                ) VALUES (?, ?, ?, ?, ?, "Simulated Demo Payment (INR)", "confirmed", ?, NOW())
            ');
            $insertBooking->execute([
                $bookingCode,
                $userId,
                $eventId,
                $ticketsCount,
                $totalAmount,
                $paymentReference
            ]);
        }

        // Decrement available seats atomically with capacity safety guard
        $updateSeats = $pdo->prepare('
            UPDATE events 
            SET available_seats = available_seats - ? 
            WHERE id = ? AND available_seats >= ?
        ');
        $updateSeats->execute([$ticketsCount, $eventId, $ticketsCount]);

        if ($updateSeats->rowCount() !== 1) {
            throw new Exception('Capacity changed during reservation. No seats were deducted.');
        }

        // Commit transaction atomically
        $pdo->commit();

        // ── Notification: Booking Confirmed ──────────────────────────────
        // Fetch the new booking ID for ref_id deduplication
        $newBookingId = null;
        try {
            $nbStmt = $pdo->prepare('SELECT id FROM bookings WHERE booking_code = ? LIMIT 1');
            $nbStmt->execute([$bookingCode]);
            $newBookingId = (int)($nbStmt->fetchColumn() ?: 0) ?: null;
        } catch (PDOException $nbEx) { /* non-fatal */ }

        $eventDateFmt = isset($event['event_date']) ? date('D, d M Y', strtotime($event['event_date'])) : '';
        create_notification(
            $pdo,
            $userId,
            'booking_confirmed',
            '🎟️ Booking Confirmed: ' . $event['title'],
            'Your registration for "' . $event['title'] . '" (' . $eventDateFmt . ', ' . $event['city'] . ') '
                . 'is confirmed. Booking ref: ' . $bookingCode . '. '
                . 'Tickets: ' . $ticketsCount . '. Total: ₹' . number_format($totalAmount, 0),
            $newBookingId,
            'booking-confirmation.php?code=' . urlencode($bookingCode)
        );

        set_flash('success', 'Registration confirmed! Your simulated booking has been created.');
        redirect('booking-confirmation.php?code=' . urlencode($bookingCode));

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[OFFGRID BOOKING ERROR] ' . $e->getMessage());
        set_flash('error', $e->getMessage());
        redirect('event-details.php?id=' . $eventId);
    }
}

// -------------------------------------------------------------
// GET REQUEST: Render Simulated Checkout Confirmation Page
// -------------------------------------------------------------
try {
    $stmt = $pdo->prepare('
        SELECT id, title, category, city, location, event_date, event_time, price, available_seats, total_capacity, status 
        FROM events 
        WHERE id = ? 
        LIMIT 1
    ');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if (!$event || $event['status'] !== 'active') {
        set_flash('error', 'The requested event is not available for booking.');
        redirect('index.php');
    }

    // Check duplicate before rendering form
    $checkDup = $pdo->prepare('SELECT booking_code FROM bookings WHERE user_id = ? AND event_id = ? LIMIT 1');
    $checkDup->execute([$userId, $eventId]);
    if ($dup = $checkDup->fetch()) {
        set_flash('info', 'You already have an active registration for this event (Ref: ' . $dup['booking_code'] . ').');
        redirect('event-details.php?id=' . $eventId);
    }

    // Check if sold out
    if ($event['available_seats'] <= 0) {
        set_flash('error', 'Sorry, this event is currently sold out.');
        redirect('event-details.php?id=' . $eventId);
    }

} catch (PDOException $e) {
    error_log('[OFFGRID CHECKOUT LOAD ERROR] ' . $e->getMessage());
    set_flash('error', 'Unable to load booking details.');
    redirect('index.php');
}

$page_title = 'Review & Confirm Demo Registration';
$maxSelectableTickets = min(5, (int)$event['available_seats']);

require_once __DIR__ . '/includes/header.php';
?>

<div class="breadcrumb-nav">
    <a href="event-details.php?id=<?= (int)$event['id'] ?>">&larr; Back to Event Details</a>
</div>

<div class="checkout-card">
    <div style="text-align: center; margin-bottom: 1.5rem;">
        <span class="user-badge" style="display: inline-flex; border-color: var(--brand-accent);">
            <span class="brand-dot"></span> Simulated Demo Checkout
        </span>
        <h1 style="font-size: 1.8rem; font-weight: 800; margin-top: 0.75rem;">Confirm Registration</h1>
        <p style="color: var(--text-secondary); font-size: 0.95rem;">
            Review your booking details for <strong><?= e($event['title']) ?></strong>.
        </p>
    </div>

    <!-- Transparent Demo Payment Notice -->
    <div class="demo-payment-alert">
        <div class="alert-icon">🛡️</div>
        <div>
            <h4>Academic Project Demo Mode (India)</h4>
            <p>
                No real payments or banking credentials are ever processed. All fees are simulated in Indian Rupees (₹ INR). 
                Clicking <strong>Confirm & Complete Demo Booking</strong> creates an authenticated reservation in your local MySQL database.
            </p>
        </div>
    </div>

    <!-- Booking Form -->
    <form action="booking.php" method="POST" id="bookingForm">
        <?= csrf_field() ?>
        <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">

        <!-- Event Summary Box -->
        <div class="order-summary-box">
            <div class="order-summary-row">
                <span>Event</span>
                <strong style="color: var(--text-primary); text-align: right; max-width: 60%;"><?= e($event['title']) ?></strong>
            </div>
            <div class="order-summary-row">
                <span>Date & Time</span>
                <span><?= e(format_event_date($event['event_date'])) ?> at <?= e(format_event_time($event['event_time'])) ?></span>
            </div>
            <div class="order-summary-row">
                <span>City & Venue</span>
                <span><strong>📍 <?= e($event['city'] ?? 'India') ?></strong> &bull; <?= e($event['location']) ?></span>
            </div>
            <div class="order-summary-row">
                <span>Attendee</span>
                <span><?= e($currentUser['name']) ?> (<?= e($currentUser['email']) ?>)</span>
            </div>
            <div class="order-summary-row">
                <span>Price per Attendee</span>
                <span id="unitPriceDisplay"><?= e(format_price($event['price'])) ?></span>
            </div>
        </div>

        <!-- Ticket Quantity Selector -->
        <div class="form-group">
            <label for="tickets_count" class="form-label">Number of Tickets / Seats</label>
            <select name="tickets_count" id="tickets_count" class="form-control" style="cursor: pointer;">
                <?php for ($i = 1; $i <= $maxSelectableTickets; $i++): ?>
                    <option value="<?= $i ?>"><?= $i ?> <?= $i === 1 ? 'ticket' : 'tickets' ?></option>
                <?php endfor; ?>
            </select>
            <small style="color: var(--text-muted); font-size: 0.8rem; display: block; margin-top: 0.35rem;">
                Maximum <?= $maxSelectableTickets ?> tickets per booking (<?= (int)$event['available_seats'] ?> total open).
            </small>
        </div>

        <!-- Live Price Calculation Breakdown -->
        <div class="order-summary-box">
            <div class="order-summary-row">
                <span>Subtotal</span>
                <span id="subtotalDisplay"><?= e(format_price($event['price'])) ?></span>
            </div>
            <div class="order-summary-row">
                <span>Payment Method</span>
                <span style="color: var(--brand-accent); font-weight: 600;">Simulated Demo Payment (INR)</span>
            </div>
            <div class="order-summary-row total">
                <span>Total Due</span>
                <span id="totalDisplay" style="color: var(--brand-accent);"><?= e(format_price($event['price'])) ?></span>
            </div>
        </div>

        <!-- Submit & Actions -->
        <button type="submit" class="btn btn-primary btn-block" style="padding: 0.9rem; font-size: 1.05rem;">
            Confirm & Complete Demo Booking &rarr;
        </button>

        <div style="text-align: center; margin-top: 1rem;">
            <a href="event-details.php?id=<?= (int)$event['id'] ?>" class="nav-link" style="font-size: 0.875rem;">
                Cancel and return to event
            </a>
        </div>
    </form>
</div>

<!-- Vanilla JS for Live Price Calculation in Demo Review -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var select = document.getElementById('tickets_count');
    var subtotalDisplay = document.getElementById('subtotalDisplay');
    var totalDisplay = document.getElementById('totalDisplay');
    var unitPrice = <?= json_encode((float)$event['price']) ?>;

    function updateTotal() {
        var count = parseInt(select.value, 10) || 1;
        var total = unitPrice * count;
        var formatted = (total <= 0) ? 'FREE' : '₹' + Math.round(total).toLocaleString('en-IN');
        subtotalDisplay.textContent = formatted;
        totalDisplay.textContent = formatted;
    }

    if (select) {
        select.addEventListener('change', updateTotal);
    }
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>

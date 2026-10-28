<?php
/**
 * OFFGRID - Booking Confirmation & Digital QR Ticket Pass
 * 
 * Displays the verified registration receipt and digital event pass:
 * - Validates booking code against the database
 * - Enforces authorization (users view their own tickets; admins can inspect all)
 * - Dynamic scannable vector QR code verification for event gates
 * - Responsive visual status: Confirmed, Completed, and Cancelled
 * - Self-service cancellation action for upcoming reservations
 * - Printable pass formatting
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

require_login('login.php');

$currentUser = current_user();
$bookingCode = trim($_GET['code'] ?? '');

$booking = null;
if (!empty($bookingCode)) {
    try {
        $stmt = $pdo->prepare('
            SELECT b.*, 
                   e.id AS event_id, e.title AS event_title, e.city, e.location, e.event_date, e.event_time, e.category, e.price,
                   u.name AS attendee_name, u.email AS attendee_email
            FROM bookings b
            JOIN events e ON b.event_id = e.id
            JOIN users u ON b.user_id = u.id
            WHERE b.booking_code = ? AND (b.user_id = ? OR ? = \'admin\')
            LIMIT 1
        ');
        $stmt->execute([$bookingCode, $currentUser['id'], $currentUser['role'] ?? 'user']);
        $booking = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('[OFFGRID CONFIRMATION LOAD ERROR] ' . $e->getMessage());
    }
}

if (!$booking) {
    http_response_code(404);
    $page_title = 'Booking Not Found';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="empty-state">
        <div style="font-size: 3rem; margin-bottom: 1rem;">🎫</div>
        <h2>Booking Receipt Not Found</h2>
        <p>We could not locate this booking receipt under your active account. Please verify the booking code or check your booking history.</p>
        <a href="my-bookings.php" class="btn btn-primary btn-sm">Go to My Bookings</a>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$isCancelled = ($booking['payment_status'] === 'cancelled');
$isPast = ($booking['event_date'] < date('Y-m-d'));

if ($isCancelled) {
    $page_title = 'Reservation Cancelled — ' . $booking['booking_code'];
} else {
    $page_title = 'Event Pass — ' . $booking['booking_code'];
}

$verifyUrl = 'http://localhost/usha%20pro/booking-confirmation.php?code=' . urlencode($booking['booking_code']);

require_once __DIR__ . '/includes/header.php';
?>

<!-- Status Banner -->
<div style="text-align: center; margin-bottom: 2rem;">
    <?php if ($isCancelled): ?>
        <span class="badge-status-cancelled" style="display: inline-flex; margin-bottom: 0.75rem; padding: 0.35rem 0.85rem; font-size: 0.8rem;">
            🚫 RESERVATION CANCELLED &bull; VOID PASS
        </span>
        <h1 style="font-size: 2rem; font-weight: 800; color: var(--danger-color);">Registration Cancelled</h1>
        <p style="color: var(--text-secondary);">This booking was cancelled and the reserved seat(s) returned to the gathering.</p>
    <?php elseif ($isPast): ?>
        <span class="badge-status-completed" style="display: inline-flex; margin-bottom: 0.75rem; padding: 0.35rem 0.85rem; font-size: 0.8rem;">
            ⏳ COMPLETED GATHERING &bull; PAST EVENT
        </span>
        <h1 style="font-size: 2rem; font-weight: 800;">Gathering Completed</h1>
        <p style="color: var(--text-secondary);">This community event took place on <?= e(format_event_date($booking['event_date'])) ?>.</p>
    <?php else: ?>
        <span class="user-badge" style="display: inline-flex; border-color: var(--brand-accent); margin-bottom: 0.75rem;">
            <span class="brand-dot"></span> Registration Confirmed &bull; Verified Digital Pass
        </span>
        <h1 style="font-size: 2rem; font-weight: 800;">You're Going Off-Grid!</h1>
        <p style="color: var(--text-secondary);">Your seat is reserved. Save or present your digital QR pass below.</p>
    <?php endif; ?>
</div>

<!-- Ticket Pass Card -->
<div class="ticket-pass" id="printableTicket" style="position: relative; <?= $isCancelled ? 'border-color: rgba(239, 68, 68, 0.35);' : '' ?>">

    <!-- Watermark for Cancelled Tickets -->
    <?php if ($isCancelled): ?>
        <div style="position: absolute; top: 40%; left: 50%; transform: translate(-50%, -50%) rotate(-25deg); font-size: 4rem; font-weight: 900; color: rgba(239, 68, 68, 0.15); border: 6px dashed rgba(239, 68, 68, 0.25); padding: 0.5rem 2rem; border-radius: var(--radius-md); text-transform: uppercase; pointer-events: none; z-index: 10; letter-spacing: 0.1em;">
            VOID / CANCELLED
        </div>
    <?php endif; ?>

    <!-- Ticket Header -->
    <div class="ticket-header" style="<?= $isCancelled ? 'background: #2a1518; border-color: #521c21;' : ($isPast ? 'background: #0e2439; border-color: #174261;' : '') ?>">
        <div>
            <div style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-muted);">
                OFFGRID Event Pass
            </div>
            <div class="ticket-code" style="<?= $isCancelled ? 'color: var(--danger-color); text-decoration: line-through;' : ($isPast ? 'color: var(--info-color);' : '') ?>">
                <?= e($booking['booking_code']) ?>
            </div>
        </div>
        <div>
            <?php if ($isCancelled): ?>
                <span class="badge-status-cancelled" style="padding: 0.35rem 0.85rem; font-size: 0.8rem;">
                    CANCELLED
                </span>
            <?php elseif ($isPast): ?>
                <span class="badge-status-completed" style="padding: 0.35rem 0.85rem; font-size: 0.8rem;">
                    COMPLETED
                </span>
            <?php else: ?>
                <span class="badge-status-active" style="padding: 0.35rem 0.85rem; font-size: 0.8rem;">
                    CONFIRMED
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Ticket Body -->
    <div class="ticket-body">
        <h2 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 1.5rem; color: var(--text-primary);">
            <?= e($booking['event_title']) ?>
        </h2>

        <!-- Pass Info Grid + QR Code Area -->
        <div class="ticket-info-grid">
            
            <!-- Left: Event Details -->
            <div class="ticket-grid" style="border: none; margin: 0; padding: 0;">
                <div class="ticket-field">
                    <div class="label">Date & Time</div>
                    <div class="val"><?= e(format_event_date($booking['event_date'])) ?><br><span style="font-size: 0.85rem; color: var(--text-secondary);"><?= e(format_event_time($booking['event_time'])) ?></span></div>
                </div>

                <div class="ticket-field">
                    <div class="label">Category</div>
                    <div class="val"><?= e($booking['category']) ?></div>
                </div>

                <div class="ticket-field">
                    <div class="label">City & Venue</div>
                    <div class="val">📍 <?= e($booking['city'] ?? 'India') ?><br><span style="font-size: 0.85rem; color: var(--text-secondary);"><?= e($booking['location']) ?></span></div>
                </div>

                <div class="ticket-field">
                    <div class="label">Primary Attendee</div>
                    <div class="val"><?= e($booking['attendee_name']) ?><br><span style="font-size: 0.8rem; color: var(--text-secondary);"><?= e($booking['attendee_email']) ?></span></div>
                </div>

                <div class="ticket-field">
                    <div class="label">Seats Reserved</div>
                    <div class="val"><?= (int)$booking['tickets_count'] ?> <?= $booking['tickets_count'] === 1 ? 'Seat' : 'Seats' ?></div>
                </div>

                <div class="ticket-field">
                    <div class="label">Simulated Total Paid</div>
                    <div class="val" style="color: <?= $isCancelled ? 'var(--text-muted)' : 'var(--brand-accent)' ?>; font-weight: 800;">
                        <?= e(format_price($booking['total_amount'])) ?>
                    </div>
                </div>
            </div>

            <!-- Right: Dynamic Vector QR Code -->
            <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;">
                <div id="ticketQRCode" style="padding: 0.5rem; background: var(--bg-surface-elevated); border: 1px solid var(--border-subtle); border-radius: var(--radius-md); box-shadow: var(--shadow-soft);">
                    <!-- Generated via vanilla JS renderQRCodeSVG() below -->
                </div>
                <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.45rem; text-transform: uppercase; letter-spacing: 0.05em;">
                    <?= $isCancelled ? 'Invalid / Void QR' : 'Scan for Entry' ?>
                </div>
            </div>

        </div>

        <!-- Receipt Metadata -->
        <div style="background: var(--bg-surface-elevated); border: 1px dashed var(--border-subtle); border-radius: var(--radius-sm); padding: 0.85rem 1rem; font-size: 0.85rem; color: var(--text-secondary);">
            <strong>Transaction Reference:</strong> <code><?= e($booking['payment_reference']) ?></code><br>
            <strong>Payment Method:</strong> <?= e($booking['payment_method']) ?><br>
            <strong>Registered On:</strong> <?= e(date('d M Y, g:i A', strtotime($booking['booking_date']))) ?> IST
            <?php if ($isCancelled): ?>
                <br><strong style="color: var(--danger-color);">Status Note:</strong> Cancelled by member/administrator. Not valid for admission.
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Ticket Action Buttons -->
<div class="ticket-actions">
    <?php if (!$isCancelled): ?>
        <button type="button" onclick="window.print()" class="btn btn-outline">
            🖨️ Print / Save Pass
        </button>
    <?php endif; ?>

    <!-- Self-Service Cancel Reservation (If upcoming and confirmed) -->
    <?php if (!$isCancelled && !$isPast): ?>
        <form action="booking-cancel.php" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to cancel this reservation?\nYour seat(s) will be released back to the event.');">
            <?= csrf_field() ?>
            <input type="hidden" name="booking_code" value="<?= e($booking['booking_code']) ?>">
            <input type="hidden" name="redirect" value="booking-confirmation.php?code=<?= urlencode($booking['booking_code']) ?>">
            <button type="submit" class="btn btn-danger-outline">
                🚫 Cancel Reservation
            </button>
        </form>
    <?php endif; ?>

    <a href="my-bookings.php" class="btn btn-primary">
        View All My Bookings &rarr;
    </a>
    <a href="index.php" class="btn btn-outline">
        Explore More Events
    </a>
</div>

<!-- QR Code Offline Generator Script -->
<script src="assets/js/qrcode.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var isCancelled = <?= $isCancelled ? 'true' : 'false' ?>;
    var verifyUrl = <?= json_encode($verifyUrl) ?>;
    var qrColor = isCancelled ? '#ef4444' : '#10b981';
    var qrBg = '#141922';

    if (window.renderQRCodeSVG) {
        window.renderQRCodeSVG('ticketQRCode', verifyUrl, {
            size: 130,
            colorDark: qrColor,
            colorLight: qrBg
        });
    }
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>

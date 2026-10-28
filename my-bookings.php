<?php
/**
 * OFFGRID - Member Booking History & Self-Service Tickets
 * 
 * Lists all active and historical bookings made by the logged-in user:
 * - Protected by require_login()
 * - Tabbed filtering: All Bookings, Upcoming Events, Past Gatherings, Cancelled
 * - Personal booking search by code, title, or city
 * - Clear status badges: Confirmed (Active), Completed, and Cancelled
 * - Self-service cancellation action with atomic seat replenishment
 * - Quick access to digital QR passes
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

require_login('login.php?redirect=my-bookings.php');

$currentUser = current_user();
$page_title = 'My Bookings & Passes';

// 1. Read filter tab and search parameter
$tab    = trim($_GET['tab'] ?? 'all');
$search = trim($_GET['search'] ?? '');

$validTabs = ['all', 'upcoming', 'past', 'cancelled'];
if (!in_array($tab, $validTabs, true)) {
    $tab = 'all';
}

$today = date('Y-m-d');

// 2. Build SQL conditions
$where = ['b.user_id = ?'];
$params = [$currentUser['id']];

if ($tab === 'upcoming') {
    $where[] = 'b.payment_status = "confirmed"';
    $where[] = 'e.event_date >= ?';
    $params[] = $today;
} elseif ($tab === 'past') {
    $where[] = 'b.payment_status = "confirmed"';
    $where[] = 'e.event_date < ?';
    $params[] = $today;
} elseif ($tab === 'cancelled') {
    $where[] = 'b.payment_status = "cancelled"';
}

if ($search !== '') {
    $where[] = '(b.booking_code LIKE ? OR e.title LIKE ? OR e.city LIKE ? OR b.payment_reference LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $where);

// 3. Query bookings with joined event details
$bookings = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.id, b.booking_code, b.tickets_count, b.total_amount, b.payment_method, 
               b.payment_status, b.payment_reference, b.booking_date,
               e.id AS event_id, e.title AS event_title, e.city, e.location, e.event_date, 
               e.event_time, e.category, e.price, e.status AS event_status
        FROM bookings b
        JOIN events e ON b.event_id = e.id
        WHERE {$whereSql}
        ORDER BY b.booking_date DESC
    ");
    $stmt->execute($params);
    $bookings = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('[OFFGRID MY BOOKINGS ERROR] ' . $e->getMessage());
}

// 4. Query tab counts for badge indicators
$tabCounts = ['all' => 0, 'upcoming' => 0, 'past' => 0, 'cancelled' => 0];
try {
    $countStmt = $pdo->prepare("
        SELECT 
            COUNT(b.id) AS all_count,
            COUNT(CASE WHEN b.payment_status = 'confirmed' AND e.event_date >= ? THEN 1 END) AS upcoming_count,
            COUNT(CASE WHEN b.payment_status = 'confirmed' AND e.event_date < ? THEN 1 END) AS past_count,
            COUNT(CASE WHEN b.payment_status = 'cancelled' THEN 1 END) AS cancelled_count
        FROM bookings b
        JOIN events e ON b.event_id = e.id
        WHERE b.user_id = ?
    ");
    $countStmt->execute([$today, $today, $currentUser['id']]);
    $res = $countStmt->fetch();
    if ($res) {
        $tabCounts['all']       = (int)$res['all_count'];
        $tabCounts['upcoming']  = (int)$res['upcoming_count'];
        $tabCounts['past']      = (int)$res['past_count'];
        $tabCounts['cancelled'] = (int)$res['cancelled_count'];
    }
} catch (PDOException $e) {
    error_log('[OFFGRID TAB COUNTS ERROR] ' . $e->getMessage());
}

$isFiltered = ($tab !== 'all' || $search !== '');

require_once __DIR__ . '/includes/header.php';
?>

<!-- Page Header -->
<div style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem;">
    <div>
        <div style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--brand-accent); font-weight: 700; margin-bottom: 0.35rem;">
            My Account
        </div>
        <h1 style="font-size: 2rem; font-weight: 800; margin-bottom: 0.35rem;">My Bookings & Passes</h1>
        <p style="color: var(--text-secondary);">
            Logged in as <strong><?= e($currentUser['name']) ?></strong> &bull; Manage your tickets, view digital passes, or release seats.
        </p>
    </div>
    <div style="display: flex; gap: 0.75rem;">
        <a href="profile.php" class="btn btn-outline btn-sm">👤 My Profile</a>
        <a href="index.php" class="btn btn-primary btn-sm">+ Browse Gatherings</a>
    </div>
</div>

<!-- Tabs & Search Controls -->
<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
    <!-- Navigation Tabs -->
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;" role="tablist">
        <a href="my-bookings.php?tab=all<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" 
           class="btn btn-sm <?= ($tab === 'all') ? 'btn-primary' : 'btn-outline' ?>" style="border-radius: 9999px;">
            All (<?= $tabCounts['all'] ?>)
        </a>
        <a href="my-bookings.php?tab=upcoming<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" 
           class="btn btn-sm <?= ($tab === 'upcoming') ? 'btn-primary' : 'btn-outline' ?>" style="border-radius: 9999px;">
            Upcoming (<?= $tabCounts['upcoming'] ?>)
        </a>
        <a href="my-bookings.php?tab=past<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" 
           class="btn btn-sm <?= ($tab === 'past') ? 'btn-primary' : 'btn-outline' ?>" style="border-radius: 9999px;">
            Past Gatherings (<?= $tabCounts['past'] ?>)
        </a>
        <a href="my-bookings.php?tab=cancelled<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" 
           class="btn btn-sm <?= ($tab === 'cancelled') ? 'btn-primary' : 'btn-outline' ?>" style="border-radius: 9999px;">
            Cancelled (<?= $tabCounts['cancelled'] ?>)
        </a>
    </div>

    <!-- Personal Bookings Search -->
    <form action="my-bookings.php" method="GET" style="display: flex; gap: 0.5rem; align-items: center; min-width: 260px;">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <input 
            type="text" 
            name="search" 
            class="form-control" 
            placeholder="Search my bookings..." 
            value="<?= e($search) ?>" 
            style="padding: 0.45rem 0.85rem; font-size: 0.875rem;"
            autocomplete="off"
        >
        <button type="submit" class="btn btn-outline btn-sm">Search</button>
        <?php if ($search !== ''): ?>
            <a href="my-bookings.php?tab=<?= urlencode($tab) ?>" class="btn btn-outline btn-sm">&times;</a>
        <?php endif; ?>
    </form>
</div>

<?php if (!empty($bookings)): ?>
    <div class="bookings-table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Event</th>
                    <th>Date & Time</th>
                    <th>Location</th>
                    <th>Seats</th>
                    <th>Total Paid</th>
                    <th>Status</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $b): ?>
                    <?php
                    $isCancelled = ($b['payment_status'] === 'cancelled');
                    $isPast = ($b['event_date'] < $today);
                    ?>
                    <tr style="<?= $isCancelled ? 'opacity: 0.75;' : '' ?>">
                        <!-- Booking Reference -->
                        <td>
                            <code style="color: <?= $isCancelled ? 'var(--text-muted)' : 'var(--brand-accent)' ?>; font-weight: 700; font-size: 0.95rem;">
                                <?= e($b['booking_code']) ?>
                            </code>
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.15rem;">
                                <?= e(date('d M Y', strtotime($b['booking_date']))) ?>
                            </div>
                        </td>

                        <!-- Event Title & Category -->
                        <td>
                            <a href="event-details.php?id=<?= (int)$b['event_id'] ?>" style="color: var(--text-primary); font-weight: 600; text-decoration: none;">
                                <?= e($b['event_title']) ?>
                            </a>
                            <div style="font-size: 0.775rem; color: var(--text-muted); margin-top: 0.15rem;"><?= e($b['category']) ?></div>
                        </td>

                        <!-- Date & Time -->
                        <td>
                            <div><?= e(format_event_date($b['event_date'])) ?></div>
                            <div style="font-size: 0.775rem; color: var(--text-secondary);"><?= e(format_event_time($b['event_time'])) ?></div>
                        </td>

                        <!-- Location & City -->
                        <td>
                            <div style="font-weight: 600;"><span class="badge-city-solid">📍 <?= e($b['city'] ?? 'India') ?></span></div>
                            <div style="font-size: 0.775rem; color: var(--text-secondary); margin-top: 0.2rem;"><?= e($b['location']) ?></div>
                        </td>

                        <!-- Tickets Count -->
                        <td>
                            <strong><?= (int)$b['tickets_count'] ?></strong> <?= $b['tickets_count'] === 1 ? 'seat' : 'seats' ?>
                        </td>

                        <!-- Total Paid (INR) -->
                        <td>
                            <strong style="color: <?= $isCancelled ? 'var(--text-muted)' : 'var(--brand-accent)' ?>;">
                                <?= e(format_price($b['total_amount'])) ?>
                            </strong>
                            <div style="font-size: 0.725rem; color: var(--text-muted);">Simulated</div>
                        </td>

                        <!-- Status Badge -->
                        <td>
                            <?php if ($isCancelled): ?>
                                <span class="badge-status-cancelled" style="font-size: 0.7rem;">
                                    CANCELLED
                                </span>
                            <?php elseif ($isPast): ?>
                                <span class="badge-status-completed" style="font-size: 0.7rem;">
                                    COMPLETED
                                </span>
                            <?php else: ?>
                                <span class="badge-status-active" style="font-size: 0.7rem;">
                                    CONFIRMED
                                </span>
                            <?php endif; ?>
                        </td>

                        <!-- Action Buttons -->
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 0.4rem; align-items: center; justify-content: flex-end;">
                                <!-- View Pass Link -->
                                <a href="booking-confirmation.php?code=<?= urlencode($b['booking_code']) ?>" class="btn btn-outline btn-sm" style="padding: 0.35rem 0.65rem; font-size: 0.8rem;">
                                    🎟️ Pass
                                </a>

                                <!-- Cancel Reservation Button (Only for confirmed upcoming events) -->
                                <?php if (!$isCancelled && !$isPast): ?>
                                    <form action="booking-cancel.php" method="POST" style="display: inline;" onsubmit="return confirmCancelReservation(<?= htmlspecialchars(json_encode($b['event_title']), ENT_QUOTES, 'UTF-8') ?>, <?= (int)$b['tickets_count'] ?>);">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="booking_code" value="<?= e($b['booking_code']) ?>">
                                        <input type="hidden" name="redirect" value="my-bookings.php?tab=<?= urlencode($tab) ?>">
                                        <button type="submit" class="btn btn-danger-outline btn-sm" style="padding: 0.35rem 0.65rem; font-size: 0.8rem;" title="Cancel this reservation and release seats">
                                            Cancel
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php else: ?>
    <!-- Friendly Contextual Empty State -->
    <div class="empty-state" style="background: var(--bg-surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 3rem 2rem; text-align: center;">
        <div style="font-size: 3rem; margin-bottom: 1rem;">
            <?= $tab === 'cancelled' ? '🚫' : ($tab === 'past' ? '⏳' : '🎟️') ?>
        </div>
        
        <?php if ($search !== ''): ?>
            <h3>No Bookings Matched "<?= e($search) ?>"</h3>
            <p style="color: var(--text-secondary); margin-top: 0.5rem;">
                No reservations found under your account matching this keyword.
            </p>
            <div style="margin-top: 1.25rem;">
                <a href="my-bookings.php?tab=<?= urlencode($tab) ?>" class="btn btn-outline btn-sm">Clear Search Filter</a>
            </div>
        <?php elseif ($tab === 'upcoming'): ?>
            <h3>No Upcoming Gatherings</h3>
            <p style="color: var(--text-secondary); margin-top: 0.5rem;">
                You don't have any upcoming reservations at the moment. Browse the event catalog to find your next adventure!
            </p>
            <div style="margin-top: 1.25rem;">
                <a href="index.php" class="btn btn-primary btn-sm">Explore Upcoming Events &rarr;</a>
            </div>
        <?php elseif ($tab === 'past'): ?>
            <h3>No Past Gatherings</h3>
            <p style="color: var(--text-secondary); margin-top: 0.5rem;">
                You have not attended any completed OFFGRID community events yet.
            </p>
        <?php elseif ($tab === 'cancelled'): ?>
            <h3>No Cancelled Bookings</h3>
            <p style="color: var(--text-secondary); margin-top: 0.5rem;">
                You have not cancelled any ticket reservations.
            </p>
        <?php else: ?>
            <h3>No Bookings Yet</h3>
            <p style="color: var(--text-secondary); margin-top: 0.5rem;">
                You haven't reserved tickets for any OFFGRID events yet. Check out the event catalog and join an upcoming gathering!
            </p>
            <div style="margin-top: 1.25rem;">
                <a href="index.php" class="btn btn-primary">Explore Available Events &rarr;</a>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
function confirmCancelReservation(eventTitle, ticketsCount) {
    var seatText = ticketsCount === 1 ? '1 seat' : ticketsCount + ' seats';
    return confirm(
        'Are you sure you want to cancel your reservation for:\n"' + eventTitle + '"?\n\n' +
        'Your ' + seatText + ' will be immediately released back to the event capacity.\n' +
        'This action cannot be undone.'
    );
}
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>

<?php
/**
 * OFFGRID - Manage Event Bookings & Attendees
 * 
 * Administrative portal for inspecting participant registrations, seat reservations,
 * and simulated payment receipts across all Pan-India events.
 * 
 * Features:
 * - Strict require_admin() access control
 * - Real-time registration metrics & revenue summaries
 * - Multi-attribute search (booking code, attendee name/email, event title, city)
 * - Event and payment status filtering
 * - Secure attendee display (strictly no passwords or sensitive tokens)
 * - Direct access to digital event passes
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Strict Authorization Guard
require_admin();

$is_admin_area = true;
$admin_active_tab = 'bookings';
$page_title = 'Manage Bookings';

// 1. Fetch summary metrics
$totalBookingsCount = 0;
$totalTicketsReserved = 0;
$totalSimulatedRevenue = 0.00;
$activeEventsWithBookings = 0;

try {
    $metrics = $pdo->query("
        SELECT 
            COUNT(id) AS total_bookings,
            COALESCE(SUM(tickets_count), 0) AS total_tickets,
            COALESCE(SUM(total_amount), 0) AS total_revenue,
            COUNT(DISTINCT event_id) AS distinct_events
        FROM bookings
    ")->fetch();

    if ($metrics) {
        $totalBookingsCount     = (int)$metrics['total_bookings'];
        $totalTicketsReserved   = (int)$metrics['total_tickets'];
        $totalSimulatedRevenue  = (float)$metrics['total_revenue'];
        $activeEventsWithBookings = (int)$metrics['distinct_events'];
    }
} catch (PDOException $e) {
    error_log('[OFFGRID ADMIN BOOKINGS METRICS ERROR] ' . $e->getMessage());
}

// 2. Fetch list of all events for filter dropdown
$allEvents = [];
try {
    $allEvents = $pdo->query("
        SELECT id, title, city 
        FROM events 
        ORDER BY event_date DESC, title ASC
    ")->fetchAll();
} catch (PDOException $e) {
    error_log('[OFFGRID ADMIN BOOKINGS EVENTS LIST ERROR] ' . $e->getMessage());
}

// 3. Read search & filter parameters
$search  = trim($_GET['search'] ?? '');
$eventId = (int)($_GET['event_id'] ?? 0);
$status  = trim($_GET['status'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(
        b.booking_code LIKE ? 
        OR b.payment_reference LIKE ? 
        OR u.name LIKE ? 
        OR u.email LIKE ? 
        OR e.title LIKE ? 
        OR e.city LIKE ?
    )';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($eventId > 0) {
    $where[] = 'b.event_id = ?';
    $params[] = $eventId;
}

if ($status !== '' && in_array($status, ['confirmed', 'cancelled'], true)) {
    $where[] = 'b.payment_status = ?';
    $params[] = $status;
}

$whereSql = (!empty($where)) ? 'WHERE ' . implode(' AND ', $where) : '';

// 4. Count total matching rows for pagination
$totalFilteredBookings = 0;
try {
    $countSql = "
        SELECT COUNT(b.id)
        FROM bookings b
        JOIN events e ON b.event_id = e.id
        JOIN users u ON b.user_id = u.id
        {$whereSql}
    ";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalFilteredBookings = (int)$countStmt->fetchColumn();
} catch (PDOException $e) {
    error_log('[OFFGRID ADMIN BOOKINGS COUNT ERROR] ' . $e->getMessage());
}

$totalPages = max(1, (int)ceil($totalFilteredBookings / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

// 5. Query matching bookings with safe join (no password or sensitive tokens selected)
$bookings = [];
try {
    $sql = "
        SELECT 
            b.id, b.booking_code, b.tickets_count, b.total_amount, 
            b.payment_method, b.payment_status, b.payment_reference, b.booking_date,
            e.id AS event_id, e.title AS event_title, e.city AS event_city, 
            e.location AS event_location, e.event_date, e.event_time,
            u.id AS user_id, u.name AS user_name, u.email AS user_email
        FROM bookings b
        JOIN events e ON b.event_id = e.id
        JOIN users u ON b.user_id = u.id
        {$whereSql}
        ORDER BY b.booking_date DESC
        LIMIT " . (int)$perPage . " OFFSET " . (int)$offset . "
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('[OFFGRID ADMIN BOOKINGS QUERY ERROR] ' . $e->getMessage());
}

$isFiltered = ($search !== '' || $eventId > 0 || $status !== '');

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Reusable Admin Navigation Bar -->
<?php require_once __DIR__ . '/../includes/admin-nav.php'; ?>

<!-- Header -->
<div class="admin-header">
    <div class="admin-title">
        <h1>Manage Participant Bookings</h1>
        <p>Review community ticket reservations, attendee information, and simulated transaction receipts.</p>
    </div>
</div>

<!-- Summary Metrics Cards -->
<section class="admin-stats-grid">
    <div class="stat-card">
        <h3>Total Bookings</h3>
        <div class="stat-number"><?= $totalBookingsCount ?></div>
        <div class="stat-sub">Across all Indian events</div>
    </div>

    <div class="stat-card">
        <h3>Tickets Reserved</h3>
        <div class="stat-number"><?= $totalTicketsReserved ?></div>
        <div class="stat-sub">Total attendee seats booked</div>
    </div>

    <div class="stat-card">
        <h3>Simulated Volume</h3>
        <div class="stat-number"><?= e(format_price($totalSimulatedRevenue)) ?></div>
        <div class="stat-sub">Demo payments recorded</div>
    </div>

    <div class="stat-card">
        <h3>Active Gatherings</h3>
        <div class="stat-number"><?= $activeEventsWithBookings ?></div>
        <div class="stat-sub">Events with active bookings</div>
    </div>
</section>

<!-- Filter & Search Bar -->
<section class="admin-filter-bar">
    <form action="bookings.php" method="GET" class="admin-filter-form">
        <!-- Keyword Search -->
        <div style="flex: 2; min-width: 220px;">
            <input 
                type="text" 
                name="search" 
                class="form-control" 
                placeholder="Search booking code, attendee name/email, event, or city..." 
                value="<?= e($search) ?>"
                autocomplete="off"
            >
        </div>

        <!-- Event Filter Dropdown -->
        <div style="flex: 2; min-width: 200px;">
            <select name="event_id" class="form-control" style="cursor: pointer;">
                <option value="0">All Community Events</option>
                <?php foreach ($allEvents as $evOpt): ?>
                    <option value="<?= (int)$evOpt['id'] ?>" <?= ($eventId === (int)$evOpt['id']) ? 'selected' : '' ?>>
                        <?= e($evOpt['title']) ?> (<?= e($evOpt['city']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Status Filter -->
        <div style="flex: 1; min-width: 140px;">
            <select name="status" class="form-control" style="cursor: pointer;">
                <option value="">All Statuses</option>
                <option value="confirmed" <?= ($status === 'confirmed') ? 'selected' : '' ?>>Confirmed</option>
                <option value="cancelled" <?= ($status === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
            </select>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <?php if ($isFiltered): ?>
                <a href="bookings.php" class="btn btn-outline btn-sm">&times; Reset</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<!-- Bookings Table Card -->
<div class="admin-table-card">
    <div class="admin-table-header">
        <div>
            <span style="font-weight: 700; color: var(--text-primary);">
                Showing <?= count($bookings) ?> of <?= $totalFilteredBookings ?> <?= $totalFilteredBookings === 1 ? 'booking' : 'bookings' ?>
            </span>
            <?php if ($isFiltered): ?>
                <span style="color: var(--text-muted); font-size: 0.825rem;">(filtered results)</span>
            <?php endif; ?>
        </div>
        <div style="font-size: 0.85rem; color: var(--text-muted);">
            Page <?= $page ?> of <?= $totalPages ?>
        </div>
    </div>

    <?php if (!empty($bookings)): ?>
        <div class="admin-table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Booking Reference</th>
                        <th>Attendee Details</th>
                        <th>Event & Location</th>
                        <th>Date & Time</th>
                        <th>Seats</th>
                        <th>Simulated Total</th>
                        <th>Status</th>
                        <th style="text-align: right;">Digital Pass</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $b): ?>
                        <tr>
                            <!-- Booking Code & Payment Reference -->
                            <td>
                                <div>
                                    <code style="color: var(--brand-accent); font-weight: 700; font-size: 0.95rem;">
                                        <?= e($b['booking_code']) ?>
                                    </code>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.2rem;" title="Simulated Payment Reference">
                                    Ref: <?= e($b['payment_reference']) ?>
                                </div>
                            </td>

                            <!-- Attendee Information (No Passwords or Hashes) -->
                            <td>
                                <strong style="color: var(--text-primary); font-size: 0.95rem;">
                                    <?= e($b['user_name']) ?>
                                </strong>
                                <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.15rem;">
                                    ✉️ <?= e($b['user_email']) ?>
                                </div>
                            </td>

                            <!-- Event Title & City -->
                            <td style="max-width: 250px;">
                                <a href="../event-details.php?id=<?= (int)$b['event_id'] ?>" target="_blank" style="color: var(--text-primary); font-weight: 600; text-decoration: none;">
                                    <?= e($b['event_title']) ?> &nearr;
                                </a>
                                <div style="margin-top: 0.25rem;">
                                    <span class="badge-city-solid">📍 <?= e($b['event_city']) ?></span>
                                </div>
                            </td>

                            <!-- Event Date & Booking Timestamp -->
                            <td>
                                <div><?= e(format_event_date($b['event_date'])) ?></div>
                                <div style="font-size: 0.775rem; color: var(--text-muted);">
                                    Booked <?= e(date('d M Y', strtotime($b['booking_date']))) ?>
                                </div>
                            </td>

                            <!-- Tickets Count -->
                            <td>
                                <strong style="font-size: 1rem; color: var(--text-primary);">
                                    <?= (int)$b['tickets_count'] ?>
                                </strong>
                                <span style="font-size: 0.8rem; color: var(--text-muted);">
                                    <?= (int)$b['tickets_count'] === 1 ? 'seat' : 'seats' ?>
                                </span>
                            </td>

                            <!-- Total Simulated Fee -->
                            <td>
                                <strong style="color: var(--brand-accent); font-size: 0.95rem;">
                                    <?= e(format_price($b['total_amount'])) ?>
                                </strong>
                                <div style="font-size: 0.725rem; color: var(--text-muted);">
                                    <?= e($b['payment_method']) ?>
                                </div>
                            </td>

                            <!-- Status Badge -->
                            <td>
                                <?php if ($b['payment_status'] === 'confirmed'): ?>
                                    <span class="badge-status-active">Confirmed</span>
                                <?php else: ?>
                                    <span class="badge-status-cancelled"><?= e(ucfirst($b['payment_status'])) ?></span>
                                <?php endif; ?>
                            </td>

                            <!-- Pass Link -->
                            <td style="text-align: right;">
                                <a href="../booking-confirmation.php?code=<?= urlencode($b['booking_code']) ?>" class="btn btn-outline btn-sm" target="_blank" style="padding: 0.35rem 0.65rem; font-size: 0.8rem;">
                                    🎟️ View Pass
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?php if ($totalPages > 1): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 1.5rem; border-top: 1px solid var(--border-subtle); flex-wrap: wrap; gap: 0.75rem;">
                <div style="font-size: 0.875rem; color: var(--text-secondary);">
                    Page <?= $page ?> of <?= $totalPages ?> (Total: <?= $totalFilteredBookings ?> records)
                </div>

                <div style="display: flex; gap: 0.5rem;">
                    <?php if ($page > 1): ?>
                        <a href="bookings.php?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn btn-outline btn-sm">
                            &larr; Previous
                        </a>
                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="bookings.php?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn btn-outline btn-sm">
                            Next &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="empty-state">
            <div style="font-size: 2.5rem; margin-bottom: 0.75rem;">🎟️</div>
            <h3>No Bookings Found</h3>
            <?php if ($isFiltered): ?>
                <p>No participant registrations matched your search or filter parameters.</p>
                <a href="bookings.php" class="btn btn-outline btn-sm">Clear Filters</a>
            <?php else: ?>
                <p>No event ticket reservations have been made on the platform yet.</p>
                <a href="events.php" class="btn btn-primary btn-sm">View Community Events</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>

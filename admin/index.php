<?php
/**
 * OFFGRID - Administrator Dashboard & Control Center
 * 
 * Secure, authorization-guarded hub presenting real-time system metrics,
 * quick navigation, and recent activity overviews.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Strict Authorization Guard: Restrict access to authenticated administrators only
require_admin();

$is_admin_area = true;
$admin_active_tab = 'dashboard';
$page_title = 'Admin Dashboard';

// Real database statistics via prepared queries
$stats = [
    'total_events'      => 0,
    'active_events'     => 0,
    'draft_events'      => 0,
    'cancelled_events'  => 0,
    'total_users'       => 0,
    'total_bookings'    => 0,
    'total_seats_booked'=> 0,
    'simulated_revenue' => 0.00,
];

$recentEvents = [];
$recentBookings = [];

try {
    // 1. Event Counts
    $stats['total_events'] = (int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
    $stats['active_events'] = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE status = 'active'")->fetchColumn();
    $stats['draft_events'] = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE status = 'draft'")->fetchColumn();
    $stats['cancelled_events'] = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE status = 'cancelled'")->fetchColumn();

    // 2. User & Booking Metrics
    $stats['total_users'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
    $stats['suspended_users'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'suspended'")->fetchColumn();
    $stats['total_bookings'] = (int)$pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
    $stats['total_seats_booked'] = (int)$pdo->query('SELECT COALESCE(SUM(tickets_count), 0) FROM bookings')->fetchColumn();
    $stats['simulated_revenue'] = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM bookings WHERE payment_status = 'confirmed'")->fetchColumn();
    $stats['total_reviews'] = (int)$pdo->query('SELECT COUNT(*) FROM reviews')->fetchColumn();

    // 3. Recent Events (Last 5)
    $eStmt = $pdo->query('
        SELECT id, title, city, category, event_date, event_time, price, total_capacity, available_seats, status, image
        FROM events 
        ORDER BY id DESC 
        LIMIT 5
    ');
    $recentEvents = $eStmt->fetchAll();

    // 4. Recent Bookings (Last 5)
    $bStmt = $pdo->query('
        SELECT b.id, b.booking_code, b.tickets_count, b.total_amount, b.booking_date, b.payment_status,
               e.id AS event_id, e.title AS event_title, e.city AS event_city,
               u.name AS user_name, u.email AS user_email
        FROM bookings b
        JOIN events e ON b.event_id = e.id
        JOIN users u ON b.user_id = u.id
        ORDER BY b.booking_date DESC 
        LIMIT 5
    ');
    $recentBookings = $bStmt->fetchAll();

} catch (PDOException $e) {
    error_log('[OFFGRID ADMIN DASHBOARD ERROR] ' . $e->getMessage());
}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Reusable Admin Navigation Bar -->
<?php require_once __DIR__ . '/../includes/admin-nav.php'; ?>

<!-- Admin Header -->
<div class="admin-header">
    <div class="admin-title">
        <h1>Administrator Dashboard</h1>
        <p>Live metrics, community gathering capacity, and booking registrations across India.</p>
    </div>
    <div>
        <span class="admin-badge">
            <span>🛡️</span> Admin Privileges Active
        </span>
    </div>
</div>

<!-- Primary Statistics Grid -->
<section class="admin-stats-grid">
    <!-- Stat 1: Total Events -->
    <div class="stat-card">
        <h3>Total Events</h3>
        <div class="stat-number"><?= $stats['total_events'] ?></div>
        <div class="stat-sub">
            <span style="color: #6ee7b7; font-weight: 700;"><?= $stats['active_events'] ?></span> Active &bull; 
            <span style="color: #cbd5e1;"><?= $stats['draft_events'] ?></span> Drafts &bull;
            <span style="color: #fca5a5;"><?= $stats['cancelled_events'] ?></span> Inactive
        </div>
    </div>

    <!-- Stat 2: Active Events -->
    <div class="stat-card">
        <h3>Active Gatherings</h3>
        <div class="stat-number" style="color: #6ee7b7;"><?= $stats['active_events'] ?></div>
        <div class="stat-sub">Currently published in public catalog</div>
    </div>

    <!-- Stat 3: Total Bookings -->
    <div class="stat-card">
        <h3>Confirmed Bookings</h3>
        <div class="stat-number"><?= $stats['total_bookings'] ?></div>
        <div class="stat-sub">Distinct participant reservations</div>
    </div>

    <!-- Stat 4: Total Seats Booked -->
    <div class="stat-card">
        <h3>Total Seats Booked</h3>
        <div class="stat-number" style="color: #93c5fd;"><?= $stats['total_seats_booked'] ?></div>
        <div class="stat-sub">Cumulative attendee passes issued</div>
    </div>

    <!-- Stat 5: Community Members -->
    <div class="stat-card" style="cursor:pointer;" onclick="window.location='users.php'">
        <h3>Registered Members</h3>
        <div class="stat-number"><?= $stats['total_users'] ?></div>
        <div class="stat-sub">
            <?php if (($stats['suspended_users'] ?? 0) > 0): ?>
                <span style="color:#fbbf24;"><?= $stats['suspended_users'] ?> suspended</span> &bull;
            <?php endif; ?>
            <a href="users.php" style="color:var(--accent);text-decoration:none;">Manage &rarr;</a>
        </div>
    </div>

    <!-- Stat 6: Simulated Revenue -->
    <div class="stat-card">
        <h3>Simulated Volume</h3>
        <div class="stat-number" style="font-size: 1.85rem; color: var(--brand-accent);">
            <?= e(format_price($stats['simulated_revenue'])) ?>
        </div>
        <div class="stat-sub">Simulated demo payments (INR)</div>
    </div>
</section>

<!-- Quick Action Controls -->
<div class="admin-table-card" style="margin-bottom: 2rem;">
    <div class="admin-table-header">
        <div>
            <h3 style="font-size: 1.1rem; font-weight: 700;">Admin Quick Controls</h3>
            <p style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 0.2rem;">
                Create new community gatherings across Indian cities, manage event statuses, or inspect attendee lists.
            </p>
        </div>
        <div class="admin-actions" style="margin-top: 0;">
            <a href="event-create.php" class="btn btn-primary btn-sm">+ Create New Event</a>
            <a href="analytics.php" class="btn btn-primary btn-sm" style="background:#059669;border-color:#10b981;">📈 Analytics & Reports &rarr;</a>
            <a href="events.php" class="btn btn-outline btn-sm">Browse Events &rarr;</a>
            <a href="bookings.php" class="btn btn-outline btn-sm">Manage Bookings &rarr;</a>
            <a href="reviews.php" class="btn btn-outline btn-sm">⭐ Moderate Reviews (<?= $stats['total_reviews'] ?? 0 ?>) &rarr;</a>
            <a href="users.php" class="btn btn-outline btn-sm">👥 Manage Users &rarr;</a>
        </div>
    </div>
</div>

<!-- Grid: Recent Events & Recent Bookings -->
<div style="display: grid; grid-template-columns: 1fr; gap: 2rem;">
    <!-- Recent Events -->
    <div class="admin-table-card">
        <div class="admin-table-header">
            <div>
                <h3 style="font-size: 1.1rem; font-weight: 700;">Recently Added Events</h3>
                <span style="font-size: 0.8rem; color: var(--text-muted);">Last 5 events registered in database</span>
            </div>
            <a href="events.php" class="btn btn-outline btn-sm">View All (<?= $stats['total_events'] ?>)</a>
        </div>

        <?php if (!empty($recentEvents)): ?>
            <div class="admin-table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Cover</th>
                            <th>Title & Category</th>
                            <th>City</th>
                            <th>Date</th>
                            <th>Fee (INR)</th>
                            <th>Seats Remaining</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentEvents as $ev): ?>
                            <?php 
                            $imgUrl = get_event_image_url($ev['image'], '../');
                            $booked = $ev['total_capacity'] - $ev['available_seats'];
                            ?>
                            <tr>
                                <td>
                                    <img src="<?= e($imgUrl) ?>" alt="<?= e($ev['title']) ?>" class="table-thumb" loading="lazy">
                                </td>
                                <td>
                                    <strong style="color: var(--text-primary);"><?= e($ev['title']) ?></strong>
                                    <div style="font-size: 0.775rem; color: var(--text-muted);"><?= e($ev['category']) ?></div>
                                </td>
                                <td>
                                    <span class="badge-city-solid">📍 <?= e($ev['city']) ?></span>
                                </td>
                                <td>
                                    <?= e(format_event_date($ev['event_date'])) ?>
                                </td>
                                <td>
                                    <strong><?= e(format_price($ev['price'])) ?></strong>
                                </td>
                                <td>
                                    <span style="font-weight: 700; color: <?= $ev['available_seats'] <= 3 ? '#f59e0b' : 'var(--text-primary)' ?>;">
                                        <?= (int)$ev['available_seats'] ?>
                                    </span>
                                    <small style="color: var(--text-muted);">/ <?= (int)$ev['total_capacity'] ?></small>
                                </td>
                                <td>
                                    <?php if ($ev['status'] === 'active'): ?>
                                        <span class="badge-status-active">Active</span>
                                    <?php elseif ($ev['status'] === 'draft'): ?>
                                        <span class="badge-status-draft">Draft</span>
                                    <?php else: ?>
                                        <span class="badge-status-cancelled"><?= e(ucfirst($ev['status'])) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="admin-table-actions">
                                        <a href="event-edit.php?id=<?= (int)$ev['id'] ?>" class="btn btn-outline btn-sm" style="padding: 0.3rem 0.6rem; font-size: 0.8rem;">
                                            ✏️ Edit
                                        </a>
                                        <a href="../event-details.php?id=<?= (int)$ev['id'] ?>" class="btn btn-outline btn-sm" target="_blank" style="padding: 0.3rem 0.6rem; font-size: 0.8rem;">
                                            👁️ View
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="padding: 2.5rem; text-align: center; color: var(--text-muted);">
                No events currently recorded. Click <a href="event-create.php" style="color: var(--brand-accent);">Create New Event</a> to publish your first gathering.
            </div>
        <?php endif; ?>
    </div>

    <!-- Recent Bookings -->
    <div class="admin-table-card">
        <div class="admin-table-header">
            <div>
                <h3 style="font-size: 1.1rem; font-weight: 700;">Recent Participant Bookings</h3>
                <span style="font-size: 0.8rem; color: var(--text-muted);">Last 5 participant reservations across India</span>
            </div>
            <a href="bookings.php" class="btn btn-outline btn-sm">View All (<?= $stats['total_bookings'] ?>)</a>
        </div>

        <?php if (!empty($recentBookings)): ?>
            <div class="admin-table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Ref Code</th>
                            <th>Event & City</th>
                            <th>Attendee</th>
                            <th>Seats</th>
                            <th>Amount</th>
                            <th>Booking Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentBookings as $bk): ?>
                            <tr>
                                <td>
                                    <code style="color: var(--brand-accent); font-weight: 700;"><?= e($bk['booking_code']) ?></code>
                                </td>
                                <td>
                                    <strong style="color: var(--text-primary);"><?= e($bk['event_title']) ?></strong>
                                    <div style="font-size: 0.775rem; color: var(--text-secondary);">📍 <?= e($bk['event_city']) ?></div>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-primary);"><?= e($bk['user_name']) ?></div>
                                    <div style="font-size: 0.775rem; color: var(--text-muted);"><?= e($bk['user_email']) ?></div>
                                </td>
                                <td>
                                    <strong><?= (int)$bk['tickets_count'] ?></strong>
                                </td>
                                <td>
                                    <strong style="color: var(--brand-accent);"><?= e(format_price($bk['total_amount'])) ?></strong>
                                </td>
                                <td>
                                    <?= e(date('d M Y, g:i A', strtotime($bk['booking_date']))) ?> IST
                                </td>
                                <td>
                                    <span class="badge-status-active">
                                        <?= e(strtoupper($bk['payment_status'])) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="padding: 2.5rem; text-align: center; color: var(--text-muted);">
                No bookings recorded yet. Attendees will appear here as they register for community gatherings.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>

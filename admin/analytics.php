<?php
/**
 * OFFGRID - Administrator Analytics & Platform Intelligence Dashboard
 * 
 * Provides real-time telemetry, capacity tracking, booking velocity trends,
 * Indian city & category distribution, and community engagement metrics.
 * 
 * Features:
 * - Strict require_admin() access control
 * - Server-side date range validation and prepared SQL statements
 * - Accurate statistics: excludes cancelled bookings from confirmed seats & revenue
 * - Clear labeling of simulated financial totals (demonstration payments only)
 * - Zero-dependency vanilla JS and responsive CSS/SVG visualizations
 * - Direct one-click CSV exports with formula injection protection
 * - Full Indian localization (INR ₹, IST Asia/Kolkata timezone, Indian cities)
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Strict Authorization Guard: Restrict access to authenticated administrators only
require_admin();

$is_admin_area = true;
$admin_active_tab = 'analytics';
$page_title = 'Analytics & Reports | OFFGRID Admin';

// ---------------------------------------------------------------------
// 1. Date Range Handling & Server-side Validation
// ---------------------------------------------------------------------
$preset = $_GET['range'] ?? 'all';
$dateFromInput = trim($_GET['date_from'] ?? '');
$dateToInput = trim($_GET['date_to'] ?? '');
$dateValidationError = null;
$dateFilterApplied = false;
$validDateFrom = null;
$validDateTo = null;
$dateDisplayLabel = 'All Time';

$todayDate = date('Y-m-d');

// Handle presets
if (isset($_GET['range']) && !isset($_GET['date_from'])) {
    switch ($preset) {
        case '7d':
            $dateFromInput = date('Y-m-d', strtotime('-6 days'));
            $dateToInput = $todayDate;
            break;
        case '30d':
            $dateFromInput = date('Y-m-d', strtotime('-29 days'));
            $dateToInput = $todayDate;
            break;
        case '90d':
            $dateFromInput = date('Y-m-d', strtotime('-89 days'));
            $dateToInput = $todayDate;
            break;
        case 'this_month':
            $dateFromInput = date('Y-m-01');
            $dateToInput = $todayDate;
            break;
        case 'all':
        default:
            $preset = 'all';
            $dateFromInput = '';
            $dateToInput = '';
            break;
    }
}

// Validate date inputs if provided
if (!empty($dateFromInput) || !empty($dateToInput)) {
    if (empty($dateFromInput) || empty($dateToInput)) {
        $dateValidationError = 'Please provide both Start Date and End Date for custom filtering.';
    } else {
        $dFrom = DateTime::createFromFormat('Y-m-d', $dateFromInput);
        $dTo = DateTime::createFromFormat('Y-m-d', $dateToInput);

        if (!$dFrom || $dFrom->format('Y-m-d') !== $dateFromInput ||
            !$dTo || $dTo->format('Y-m-d') !== $dateToInput) {
            $dateValidationError = 'Invalid date format provided. Please use YYYY-MM-DD.';
        } else {
            if ($dateFromInput > $dateToInput) {
                // Inverted range: swap dates gracefully
                $temp = $dateFromInput;
                $dateFromInput = $dateToInput;
                $dateToInput = $temp;
                $dateValidationError = 'Start date was after end date; the date window has been automatically swapped.';
            }

            $validDateFrom = $dateFromInput . ' 00:00:00';
            $validDateTo = $dateToInput . ' 23:59:59';
            $dateFilterApplied = true;

            $dFromFmt = date('d M Y', strtotime($dateFromInput));
            $dToFmt = date('d M Y', strtotime($dateToInput));
            $dateDisplayLabel = "{$dFromFmt} &mdash; {$dToFmt}";
        }
    }
}

// ---------------------------------------------------------------------
// 2. Database Queries & Metric Aggregation
// ---------------------------------------------------------------------

// Metric Containers
$userStats = ['total' => 0, 'active' => 0, 'suspended' => 0, 'admins' => 0];
$eventStats = ['total' => 0, 'active' => 0, 'draft' => 0, 'completed' => 0, 'cancelled' => 0, 'total_capacity' => 0];
$bookingStats = [
    'total_bookings' => 0,
    'confirmed_bookings' => 0,
    'cancelled_bookings' => 0,
    'confirmed_seats' => 0,
    'simulated_revenue' => 0.00,
    'cancelled_revenue' => 0.00,
    'avg_seats_per_booking' => 0.0,
    'cancellation_rate' => 0.0
];
$reviewStats = [
    'total_reviews' => 0,
    'approved_reviews' => 0,
    'hidden_reviews' => 0,
    'avg_rating' => 0.0
];
$ratingCounts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$notificationStats = [
    'total' => 0,
    'read' => 0,
    'unread' => 0,
    'by_type' => []
];

$topEvents = [];
$cityDistribution = [];
$categoryDistribution = [];
$monthlyTrends = [];
$bookingsOverTime = [];
$recentBookings = [];

try {
    // 2.1 User Metrics
    $userRow = $pdo->query("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN role = 'user' AND status = 'active' THEN 1 ELSE 0 END) AS active_members,
            SUM(CASE WHEN role = 'user' AND status = 'suspended' THEN 1 ELSE 0 END) AS suspended_members,
            SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS admins,
            SUM(CASE WHEN role = 'user' THEN 1 ELSE 0 END) AS total_users
        FROM users
    ")->fetch(PDO::FETCH_ASSOC);

    if ($userRow) {
        $userStats['total'] = (int)$userRow['total_users'];
        $userStats['active'] = (int)$userRow['active_members'];
        $userStats['suspended'] = (int)$userRow['suspended_members'];
        $userStats['admins'] = (int)$userRow['admins'];
    }

    // 2.2 Event Metrics
    $eventRow = $pdo->query("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_events,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft_events,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_events,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_events,
            COALESCE(SUM(total_capacity), 0) AS total_capacity
        FROM events
    ")->fetch(PDO::FETCH_ASSOC);

    if ($eventRow) {
        $eventStats['total'] = (int)$eventRow['total'];
        $eventStats['active'] = (int)$eventRow['active_events'];
        $eventStats['draft'] = (int)$eventRow['draft_events'];
        $eventStats['completed'] = (int)$eventRow['completed_events'];
        $eventStats['cancelled'] = (int)$eventRow['cancelled_events'];
        $eventStats['total_capacity'] = (int)$eventRow['total_capacity'];
    }

    // 2.3 Booking & Financial Metrics (With date range filter)
    $bSql = "
        SELECT 
            COUNT(id) AS total_bookings,
            SUM(CASE WHEN payment_status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_bookings,
            SUM(CASE WHEN payment_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_bookings,
            COALESCE(SUM(CASE WHEN payment_status = 'confirmed' THEN tickets_count ELSE 0 END), 0) AS confirmed_seats,
            COALESCE(SUM(CASE WHEN payment_status = 'confirmed' THEN total_amount ELSE 0.00 END), 0.00) AS simulated_revenue,
            COALESCE(SUM(CASE WHEN payment_status = 'cancelled' THEN total_amount ELSE 0.00 END), 0.00) AS cancelled_revenue
        FROM bookings
    ";
    $bParams = [];
    if ($dateFilterApplied) {
        $bSql .= " WHERE booking_date >= :b_start AND booking_date <= :b_end";
        $bParams[':b_start'] = $validDateFrom;
        $bParams[':b_end'] = $validDateTo;
    }
    $bStmt = $pdo->prepare($bSql);
    $bStmt->execute($bParams);
    $bRow = $bStmt->fetch(PDO::FETCH_ASSOC);

    if ($bRow) {
        $bookingStats['total_bookings'] = (int)$bRow['total_bookings'];
        $bookingStats['confirmed_bookings'] = (int)$bRow['confirmed_bookings'];
        $bookingStats['cancelled_bookings'] = (int)$bRow['cancelled_bookings'];
        $bookingStats['confirmed_seats'] = (int)$bRow['confirmed_seats'];
        $bookingStats['simulated_revenue'] = (float)$bRow['simulated_revenue'];
        $bookingStats['cancelled_revenue'] = (float)$bRow['cancelled_revenue'];

        if ($bookingStats['confirmed_bookings'] > 0) {
            $bookingStats['avg_seats_per_booking'] = round(
                $bookingStats['confirmed_seats'] / $bookingStats['confirmed_bookings'],
                1
            );
        }
        if ($bookingStats['total_bookings'] > 0) {
            $bookingStats['cancellation_rate'] = round(
                ($bookingStats['cancelled_bookings'] / $bookingStats['total_bookings']) * 100,
                1
            );
        }
    }

    // 2.4 Reviews & Ratings Metrics (Public rating calculates approved reviews only)
    $revRow = $pdo->query("
        SELECT 
            COUNT(*) AS total_reviews,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_reviews,
            SUM(CASE WHEN status = 'hidden' THEN 1 ELSE 0 END) AS hidden_reviews,
            COALESCE(AVG(CASE WHEN status = 'approved' THEN rating ELSE NULL END), 0.0) AS avg_rating
        FROM reviews
    ")->fetch(PDO::FETCH_ASSOC);

    if ($revRow) {
        $reviewStats['total_reviews'] = (int)$revRow['total_reviews'];
        $reviewStats['approved_reviews'] = (int)$revRow['approved_reviews'];
        $reviewStats['hidden_reviews'] = (int)$revRow['hidden_reviews'];
        $reviewStats['avg_rating'] = round((float)$revRow['avg_rating'], 2);
    }

    // Rating breakdown for approved reviews
    $rbStmt = $pdo->query("
        SELECT rating, COUNT(*) AS count
        FROM reviews
        WHERE status = 'approved'
        GROUP BY rating
    ");
    while ($rb = $rbStmt->fetch(PDO::FETCH_ASSOC)) {
        $rVal = (int)$rb['rating'];
        if (isset($ratingCounts[$rVal])) {
            $ratingCounts[$rVal] = (int)$rb['count'];
        }
    }

    // 2.5 Notification Activity
    $notifRow = $pdo->query("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) AS read_count,
            SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_count
        FROM notifications
    ")->fetch(PDO::FETCH_ASSOC);

    if ($notifRow) {
        $notificationStats['total'] = (int)$notifRow['total'];
        $notificationStats['read'] = (int)$notifRow['read_count'];
        $notificationStats['unread'] = (int)$notifRow['unread_count'];
    }

    $ntStmt = $pdo->query("
        SELECT type, COUNT(*) AS count
        FROM notifications
        GROUP BY type
        ORDER BY count DESC
    ");
    $notificationStats['by_type'] = $ntStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2.6 Bookings Over Time (Daily aggregation)
    $botSql = "
        SELECT 
            DATE(booking_date) AS b_date,
            COUNT(id) AS booking_count,
            SUM(CASE WHEN payment_status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_count,
            SUM(CASE WHEN payment_status = 'confirmed' THEN tickets_count ELSE 0 END) AS confirmed_seats,
            SUM(CASE WHEN payment_status = 'confirmed' THEN total_amount ELSE 0.00 END) AS daily_revenue
        FROM bookings
    ";
    $botParams = [];
    if ($dateFilterApplied) {
        $botSql .= " WHERE booking_date >= :bot_start AND booking_date <= :bot_end";
        $botParams[':bot_start'] = $validDateFrom;
        $botParams[':bot_end'] = $validDateTo;
    }
    $botSql .= " GROUP BY DATE(booking_date) ORDER BY b_date ASC";
    $botStmt = $pdo->prepare($botSql);
    $botStmt->execute($botParams);
    $bookingsOverTime = $botStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2.7 Top Events by Confirmed Seats
    $teSql = "
        SELECT 
            e.id,
            e.title,
            e.city,
            e.category,
            e.price,
            e.total_capacity,
            e.available_seats,
            e.status,
            COALESCE(b_sub.confirmed_seats, 0) AS confirmed_seats,
            COALESCE(b_sub.confirmed_bookings, 0) AS confirmed_bookings,
            COALESCE(b_sub.simulated_revenue, 0.00) AS simulated_revenue
        FROM events e
        LEFT JOIN (
            SELECT 
                event_id,
                SUM(tickets_count) AS confirmed_seats,
                COUNT(id) AS confirmed_bookings,
                SUM(total_amount) AS simulated_revenue
            FROM bookings
            WHERE payment_status = 'confirmed'
    ";
    $teParams = [];
    if ($dateFilterApplied) {
        $teSql .= " AND booking_date >= :te_start AND booking_date <= :te_end";
        $teParams[':te_start'] = $validDateFrom;
        $teParams[':te_end'] = $validDateTo;
    }
    $teSql .= "
            GROUP BY event_id
        ) b_sub ON e.id = b_sub.event_id
        ORDER BY confirmed_seats DESC, e.total_capacity DESC
        LIMIT 8
    ";
    $teStmt = $pdo->prepare($teSql);
    $teStmt->execute($teParams);
    $topEvents = $teStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2.8 Events Grouped by Indian City
    $cityDistribution = $pdo->query("
        SELECT 
            city,
            COUNT(id) AS event_count,
            COALESCE(SUM(total_capacity), 0) AS total_capacity
        FROM events
        GROUP BY city
        ORDER BY event_count DESC, city ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 2.9 Events Grouped by Category
    $categoryDistribution = $pdo->query("
        SELECT 
            category,
            COUNT(id) AS event_count,
            COALESCE(SUM(total_capacity), 0) AS total_capacity
        FROM events
        GROUP BY category
        ORDER BY event_count DESC, category ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 2.10 Monthly Simulated Booking Volume (Past 12 months)
    $monthlyTrends = $pdo->query("
        SELECT 
            DATE_FORMAT(booking_date, '%Y-%m') AS month_key,
            DATE_FORMAT(booking_date, '%b %Y') AS month_label,
            COUNT(id) AS total_bookings,
            SUM(CASE WHEN payment_status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_bookings,
            SUM(CASE WHEN payment_status = 'confirmed' THEN tickets_count ELSE 0 END) AS confirmed_seats,
            SUM(CASE WHEN payment_status = 'confirmed' THEN total_amount ELSE 0.00 END) AS simulated_revenue
        FROM bookings
        GROUP BY DATE_FORMAT(booking_date, '%Y-%m'), DATE_FORMAT(booking_date, '%b %Y')
        ORDER BY month_key ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 2.11 Recent Bookings Log (Last 6 entries)
    $rbLogSql = "
        SELECT 
            b.id,
            b.booking_code,
            b.tickets_count,
            b.total_amount,
            b.payment_status,
            b.payment_method,
            b.booking_date,
            u.name AS user_name,
            u.email AS user_email,
            e.title AS event_title,
            e.city AS event_city
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        JOIN events e ON b.event_id = e.id
    ";
    $rbLogParams = [];
    if ($dateFilterApplied) {
        $rbLogSql .= " WHERE b.booking_date >= :rb_start AND b.booking_date <= :rb_end";
        $rbLogParams[':rb_start'] = $validDateFrom;
        $rbLogParams[':rb_end'] = $validDateTo;
    }
    $rbLogSql .= " ORDER BY b.booking_date DESC LIMIT 6";
    $rbLogStmt = $pdo->prepare($rbLogSql);
    $rbLogStmt->execute($rbLogParams);
    $recentBookings = $rbLogStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('[OFFGRID ANALYTICS QUERY ERROR] ' . $e->getMessage());
    set_flash('error', 'Database telemetry retrieval failed. Please inspect server error logs.');
}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Reusable Admin Navigation Bar -->
<?php require_once __DIR__ . '/../includes/admin-nav.php'; ?>

<!-- Admin Header -->
<div class="admin-header">
    <div class="admin-title">
        <h1>Analytics &amp; Platform Intelligence</h1>
        <p>Live capacity tracking, booking velocity, Indian regional distribution, and community engagement metrics.</p>
    </div>
    <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
        <span class="admin-badge">
            <span>📈</span> Real-Time Telemetry
        </span>
        <a href="analytics-export.php?report=bookings<?= $dateFilterApplied ? '&date_from=' . urlencode($dateFromInput) . '&date_to=' . urlencode($dateToInput) : '' ?>" class="btn btn-primary btn-sm">
            📥 Export Bookings CSV
        </a>
    </div>
</div>

<!-- Simulated Financial Totals Disclaimer Banner -->
<div class="simulated-callout">
    <span style="font-size:1.35rem;line-height:1;">⚠️</span>
    <div>
        <strong>Demonstration Payment Telemetry:</strong> All revenue figures and monetary amounts displayed represent 
        <em>simulated demo volume</em>. OFFGRID utilizes simulated checkout flows for college laboratory demonstration; no real financial 
        settlement or actual card charges take place.
    </div>
</div>

<?php if ($dateValidationError): ?>
    <div class="alert alert-warning" role="alert" style="margin-bottom: 1.5rem;">
        <?= e($dateValidationError) ?>
    </div>
<?php endif; ?>

<!-- Date Filter & Quick Range Selector Bar -->
<div class="admin-filter-bar">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
        <span style="font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em;">
            Active Reporting Window: <span style="color: var(--brand-accent);"><?= $dateDisplayLabel ?></span>
        </span>
        
        <!-- Preset Range Pills -->
        <div class="analytics-preset-bar" style="margin-bottom: 0;">
            <a href="analytics.php?range=all" class="preset-pill <?= ($preset === 'all' && empty($dateFromInput)) ? 'active' : '' ?>">All Time</a>
            <a href="analytics.php?range=7d" class="preset-pill <?= ($preset === '7d') ? 'active' : '' ?>">Last 7 Days</a>
            <a href="analytics.php?range=30d" class="preset-pill <?= ($preset === '30d') ? 'active' : '' ?>">Last 30 Days</a>
            <a href="analytics.php?range=90d" class="preset-pill <?= ($preset === '90d') ? 'active' : '' ?>">Last 90 Days</a>
            <a href="analytics.php?range=this_month" class="preset-pill <?= ($preset === 'this_month') ? 'active' : '' ?>">This Month</a>
        </div>
    </div>

    <form method="GET" action="analytics.php" class="admin-filter-form">
        <input type="hidden" name="range" value="custom">
        
        <div class="filter-group">
            <label for="date_from">Start Date (IST):</label>
            <input type="date" id="date_from" name="date_from" class="form-control" 
                   value="<?= e($dateFromInput) ?>" max="<?= e($todayDate) ?>">
        </div>

        <div class="filter-group">
            <label for="date_to">End Date (IST):</label>
            <input type="date" id="date_to" name="date_to" class="form-control" 
                   value="<?= e($dateToInput) ?>" max="<?= e($todayDate) ?>">
        </div>

        <div class="filter-group" style="align-self: flex-end;">
            <button type="submit" class="btn btn-primary btn-sm">Filter Reports</button>
            <a href="analytics.php" class="btn btn-outline btn-sm">Reset</a>
        </div>
    </form>
</div>

<!-- =====================================================================
     Primary Statistics Grid (Accurate database calculations)
     ===================================================================== -->
<section class="admin-stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));">
    <!-- Card 1: Community Members -->
    <div class="stat-card">
        <h3>User Community</h3>
        <div class="stat-number"><?= $userStats['total'] ?></div>
        <div class="stat-sub">
            <span style="color: #6ee7b7; font-weight: 700;"><?= $userStats['active'] ?></span> Active &bull;
            <span style="color: #fca5a5;"><?= $userStats['suspended'] ?></span> Suspended &bull;
            <span style="color: #cbd5e1;"><?= $userStats['admins'] ?></span> Admins
        </div>
    </div>

    <!-- Card 2: Event Catalog -->
    <div class="stat-card">
        <h3>Event Catalog</h3>
        <div class="stat-number" style="color: #6ee7b7;"><?= $eventStats['total'] ?></div>
        <div class="stat-sub">
            <span style="color: #6ee7b7; font-weight: 700;"><?= $eventStats['active'] ?></span> Active &bull;
            <span style="color: #93c5fd;"><?= $eventStats['completed'] ?></span> Done &bull;
            <span style="color: #fca5a5;"><?= $eventStats['cancelled'] ?></span> Cancelled
        </div>
    </div>

    <!-- Card 3: Total Bookings -->
    <div class="stat-card">
        <h3>Bookings Recorded</h3>
        <div class="stat-number"><?= $bookingStats['total_bookings'] ?></div>
        <div class="stat-sub">
            <span style="color: #6ee7b7; font-weight: 700;"><?= $bookingStats['confirmed_bookings'] ?></span> Confirmed &bull;
            <span style="color: #fca5a5;"><?= $bookingStats['cancelled_bookings'] ?></span> Cancelled
            <?php if ($bookingStats['total_bookings'] > 0): ?>
                (<?= $bookingStats['cancellation_rate'] ?>% rate)
            <?php endif; ?>
        </div>
    </div>

    <!-- Card 4: Confirmed Seats -->
    <div class="stat-card">
        <h3>Confirmed Seats</h3>
        <div class="stat-number" style="color: #93c5fd;"><?= $bookingStats['confirmed_seats'] ?></div>
        <div class="stat-sub">
            <span><?= $bookingStats['avg_seats_per_booking'] ?></span> avg seats / booking &bull;
            <span><?= $eventStats['total_capacity'] ?></span> total cap
        </div>
    </div>

    <!-- Card 5: Simulated Booking Volume -->
    <div class="stat-card">
        <h3>Simulated Volume</h3>
        <div class="stat-number" style="color: var(--brand-accent); font-size: 1.85rem;">
            <?= e(format_price($bookingStats['simulated_revenue'])) ?>
        </div>
        <div class="stat-sub">
            Confirmed demo checkout volume
            <?php if ($bookingStats['cancelled_revenue'] > 0): ?>
                &bull; <span style="color:#fca5a5;"><?= e(format_price($bookingStats['cancelled_revenue'])) ?></span> voided
            <?php endif; ?>
        </div>
    </div>

    <!-- Card 6: Community Reviews & Sentiment -->
    <div class="stat-card">
        <h3>Public Rating</h3>
        <div class="stat-number" style="color: #fde047;">
            <?= ($reviewStats['avg_rating'] > 0) ? $reviewStats['avg_rating'] . ' ★' : 'N/A' ?>
        </div>
        <div class="stat-sub">
            From <strong><?= $reviewStats['approved_reviews'] ?></strong> approved reviews
            <?php if ($reviewStats['hidden_reviews'] > 0): ?>
                &bull; <?= $reviewStats['hidden_reviews'] ?> hidden
            <?php endif; ?>
        </div>
    </div>

    <!-- Card 7: Notification Activity -->
    <div class="stat-card">
        <h3>Notifications Sent</h3>
        <div class="stat-number" style="color: #c084fc;"><?= $notificationStats['total'] ?></div>
        <div class="stat-sub">
            <span style="color: #6ee7b7; font-weight: 700;"><?= $notificationStats['read'] ?></span> Read &bull;
            <span style="color: #fbbf24;"><?= $notificationStats['unread'] ?></span> Pending Unread
        </div>
    </div>
</section>

<!-- =====================================================================
     Visual Charts Section (Vanilla JavaScript + Responsive SVG/CSS)
     ===================================================================== -->

<!-- Row 1: Bookings Velocity Over Time & Monthly Simulated Volume -->
<div class="analytics-grid-2">
    <!-- Chart A: Bookings Velocity Over Time -->
    <div class="chart-card">
        <div class="chart-card-header">
            <div class="chart-card-title">
                <h3>📈 Bookings Over Time</h3>
                <p>Daily booking cadence and attendee volume within reporting window</p>
            </div>
            <span class="badge-status-active"><?= count($bookingsOverTime) ?> Active Days</span>
        </div>
        <div class="chart-card-body">
            <?php if (empty($bookingsOverTime)): ?>
                <div class="chart-empty-state">
                    <p>No booking activity recorded for the selected date range.</p>
                </div>
            <?php else: ?>
                <!-- Vanilla SVG Bar / Sparkline Chart -->
                <div class="svg-chart-container">
                    <?php
                    $maxDailySeats = 1;
                    foreach ($bookingsOverTime as $bot) {
                        if ($bot['confirmed_seats'] > $maxDailySeats) {
                            $maxDailySeats = (int)$bot['confirmed_seats'];
                        }
                    }
                    ?>
                    <div style="display: flex; align-items: flex-end; gap: 12px; height: 180px; padding: 1.5rem 0 0.5rem 0; border-bottom: 1px solid var(--border-subtle); overflow-x: auto;">
                        <?php foreach ($bookingsOverTime as $bot): ?>
                            <?php 
                            $heightPct = max(8, round(($bot['confirmed_seats'] / $maxDailySeats) * 100));
                            $formattedDate = date('d M', strtotime($bot['b_date']));
                            ?>
                            <div style="flex: 1; min-width: 48px; display: flex; flex-direction: column; align-items: center; gap: 6px;" 
                                 title="<?= e($bot['b_date']) ?>: <?= $bot['confirmed_count'] ?> bookings, <?= $bot['confirmed_seats'] ?> confirmed seats, <?= e(format_price($bot['daily_revenue'])) ?> volume">
                                <span style="font-size: 0.75rem; font-weight: 700; color: #6ee7b7;"><?= $bot['confirmed_seats'] ?>s</span>
                                <div style="width: 100%; height: 120px; display: flex; align-items: flex-end; justify-content: center;">
                                    <div style="width: 24px; height: <?= $heightPct ?>%; background: linear-gradient(180deg, #10b981 0%, #059669 100%); border-radius: 4px 4px 0 0; transition: height 0.4s ease;"></div>
                                </div>
                                <span style="font-size: 0.725rem; color: var(--text-muted); white-space: nowrap;"><?= $formattedDate ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-top:0.75rem;font-size:0.775rem;color:var(--text-muted);">
                        <span>Metric: Confirmed Seats Booked (s)</span>
                        <span>Hover columns for simulated INR volume breakdown</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Chart B: Monthly Simulated Booking Volume -->
    <div class="chart-card">
        <div class="chart-card-header">
            <div class="chart-card-title">
                <h3>🗓️ Monthly Simulated Volume (INR)</h3>
                <p>Simulated demo transaction volume and reservation volume by month</p>
            </div>
            <span class="badge-status-completed">Past 12 Months</span>
        </div>
        <div class="chart-card-body">
            <?php if (empty($monthlyTrends)): ?>
                <div class="chart-empty-state">
                    <p>No monthly reservation history recorded yet.</p>
                </div>
            <?php else: ?>
                <?php
                $maxMonthRev = 1.0;
                foreach ($monthlyTrends as $mt) {
                    if ($mt['simulated_revenue'] > $maxMonthRev) {
                        $maxMonthRev = (float)$mt['simulated_revenue'];
                    }
                }
                ?>
                <div class="metric-bar-group">
                    <?php foreach ($monthlyTrends as $mt): ?>
                        <?php 
                        $revPct = $maxMonthRev > 0 ? round(((float)$mt['simulated_revenue'] / $maxMonthRev) * 100) : 0;
                        ?>
                        <div class="metric-bar-item">
                            <div class="metric-bar-labels">
                                <span class="metric-bar-title"><?= e($mt['month_label']) ?></span>
                                <span class="metric-bar-stats">
                                    <strong style="color:var(--brand-accent);"><?= e(format_price($mt['simulated_revenue'])) ?></strong> &bull;
                                    <?= $mt['confirmed_bookings'] ?> bookings (<?= $mt['confirmed_seats'] ?> seats)
                                </span>
                            </div>
                            <div class="metric-bar-track">
                                <div class="metric-bar-fill accent-cyan" style="width: <?= max(5, $revPct) ?>%;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Row 2: Event Popularity by Confirmed Seats & Review Rating Breakdown -->
<div class="analytics-grid-2">
    <!-- Chart C: Event Popularity by Confirmed Seats -->
    <div class="chart-card">
        <div class="chart-card-header">
            <div class="chart-card-title">
                <h3>🎟️ Event Popularity by Confirmed Seats</h3>
                <p>Top community gatherings ranked by confirmed attendee reservations</p>
            </div>
            <span class="badge-status-active">Seat Occupancy</span>
        </div>
        <div class="chart-card-body">
            <?php if (empty($topEvents)): ?>
                <div class="chart-empty-state">
                    <p>No events or bookings registered.</p>
                </div>
            <?php else: ?>
                <div class="metric-bar-group">
                    <?php foreach ($topEvents as $tev): ?>
                        <?php
                        $cap = (int)$tev['total_capacity'];
                        $cSeats = (int)$tev['confirmed_seats'];
                        $occPct = ($cap > 0) ? min(100, round(($cSeats / $cap) * 100)) : 0;
                        ?>
                        <div class="metric-bar-item">
                            <div class="metric-bar-labels">
                                <span class="metric-bar-title" style="max-width: 60%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?= e($tev['title']) ?>
                                    <span style="font-size:0.75rem;color:var(--text-muted);font-weight:normal;">(<?= e($tev['city']) ?>)</span>
                                </span>
                                <span class="metric-bar-stats">
                                    <strong style="color: #6ee7b7;"><?= $cSeats ?></strong> / <?= $cap ?> seats 
                                    (<?= $occPct ?>%)
                                </span>
                            </div>
                            <div class="metric-bar-track">
                                <div class="metric-bar-fill" style="width: <?= max(3, $occPct) ?>%;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Chart D: Review Ratings Distribution -->
    <div class="chart-card">
        <div class="chart-card-header">
            <div class="chart-card-title">
                <h3>⭐ Review Sentiment &amp; Rating Distribution</h3>
                <p>Public star breakdown for verified attendee community reviews</p>
            </div>
            <div style="font-size: 1.15rem; font-weight: 800; color: #fde047;">
                <?= ($reviewStats['avg_rating'] > 0) ? $reviewStats['avg_rating'] . ' / 5.0' : 'No ratings yet' ?>
            </div>
        </div>
        <div class="chart-card-body">
            <?php 
            $totalAppRev = max(1, $reviewStats['approved_reviews']); 
            ?>
            <div class="rating-distribution">
                <?php for ($stars = 5; $stars >= 1; $stars--): ?>
                    <?php 
                    $sCount = $ratingCounts[$stars] ?? 0;
                    $sPct = ($reviewStats['approved_reviews'] > 0) ? round(($sCount / $totalAppRev) * 100) : 0;
                    ?>
                    <div class="rating-row">
                        <span class="rating-star-label">
                            <?= str_repeat('★', $stars) ?>
                        </span>
                        <div class="rating-bar-container">
                            <div class="rating-bar-fill" style="width: <?= $sPct ?>%;"></div>
                        </div>
                        <span class="rating-count-label"><?= $sCount ?> (<?= $sPct ?>%)</span>
                    </div>
                <?php endfor; ?>
            </div>

            <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border-subtle); display: flex; justify-content: space-between; font-size: 0.85rem; color: var(--text-secondary);">
                <span>Approved Reviews: <strong style="color:var(--text-primary);"><?= $reviewStats['approved_reviews'] ?></strong></span>
                <span>Flagged / Hidden: <strong style="color:#fca5a5;"><?= $reviewStats['hidden_reviews'] ?></strong></span>
                <a href="reviews.php" style="color:var(--brand-accent);text-decoration:none;">Moderate Reviews &rarr;</a>
            </div>
        </div>
    </div>
</div>

<!-- Row 3: Events Grouped by City & Events Grouped by Category -->
<div class="analytics-grid-2">
    <!-- Chart E: Regional Indian City Distribution -->
    <div class="chart-card">
        <div class="chart-card-header">
            <div class="chart-card-title">
                <h3>🏙️ Events Grouped by Indian City</h3>
                <p>Pan-India regional coverage and community gathering hubs</p>
            </div>
            <span class="badge-status-completed"><?= count($cityDistribution) ?> Cities Represented</span>
        </div>
        <div class="chart-card-body">
            <?php if (empty($cityDistribution)): ?>
                <div class="chart-empty-state">
                    <p>No regional city data available.</p>
                </div>
            <?php else: ?>
                <?php
                $maxCityEvents = 1;
                foreach ($cityDistribution as $cd) {
                    if ($cd['event_count'] > $maxCityEvents) {
                        $maxCityEvents = (int)$cd['event_count'];
                    }
                }
                ?>
                <div class="metric-bar-group">
                    <?php foreach ($cityDistribution as $cd): ?>
                        <?php 
                        $cPct = round(($cd['event_count'] / $maxCityEvents) * 100);
                        ?>
                        <div class="metric-bar-item">
                            <div class="metric-bar-labels">
                                <span class="metric-bar-title"><?= e($cd['city']) ?></span>
                                <span class="metric-bar-stats">
                                    <strong><?= $cd['event_count'] ?></strong> <?= ($cd['event_count'] === 1) ? 'event' : 'events' ?>
                                    (<?= $cd['total_capacity'] ?> cap)
                                </span>
                            </div>
                            <div class="metric-bar-track">
                                <div class="metric-bar-fill accent-amber" style="width: <?= max(6, $cPct) ?>%;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Chart F: Events Grouped by Category -->
    <div class="chart-card">
        <div class="chart-card-header">
            <div class="chart-card-title">
                <h3>🏷️ Events Grouped by Category</h3>
                <p>Niche community tracks: Adventure, Eco, Art, Tech, Culinary, etc.</p>
            </div>
            <span class="badge-status-active"><?= count($categoryDistribution) ?> Categories</span>
        </div>
        <div class="chart-card-body">
            <?php if (empty($categoryDistribution)): ?>
                <div class="chart-empty-state">
                    <p>No category distribution data available.</p>
                </div>
            <?php else: ?>
                <?php
                $maxCatEvents = 1;
                foreach ($categoryDistribution as $cat) {
                    if ($cat['event_count'] > $maxCatEvents) {
                        $maxCatEvents = (int)$cat['event_count'];
                    }
                }
                ?>
                <div class="metric-bar-group">
                    <?php foreach ($categoryDistribution as $cat): ?>
                        <?php 
                        $catPct = round(($cat['event_count'] / $maxCatEvents) * 100);
                        ?>
                        <div class="metric-bar-item">
                            <div class="metric-bar-labels">
                                <span class="metric-bar-title"><?= e($cat['category']) ?></span>
                                <span class="metric-bar-stats">
                                    <strong><?= $cat['event_count'] ?></strong> gatherings
                                </span>
                            </div>
                            <div class="metric-bar-track">
                                <div class="metric-bar-fill accent-purple" style="width: <?= max(6, $catPct) ?>%;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- =====================================================================
     Data Tables & Detailed Audit Logs
     ===================================================================== -->

<!-- Table 1: Top Performing Gatherings Performance Table -->
<div class="admin-table-card">
    <div class="admin-table-header">
        <div>
            <h3 style="font-size: 1.1rem; font-weight: 700;">Top Gathering Occupancy &amp; Simulated Volume</h3>
            <span style="font-size: 0.8rem; color: var(--text-muted);">Capacity utilization and simulated financial breakdown per event</span>
        </div>
        <a href="analytics-export.php?report=events" class="btn btn-outline btn-sm">📥 Export Events CSV</a>
    </div>

    <?php if (!empty($topEvents)): ?>
        <div class="admin-table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Gathering</th>
                        <th>City &amp; Category</th>
                        <th>Price (INR)</th>
                        <th>Capacity</th>
                        <th>Confirmed Seats</th>
                        <th>Occupancy Rate</th>
                        <th>Simulated Volume</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topEvents as $ev): ?>
                        <?php
                        $cap = (int)$ev['total_capacity'];
                        $cSeats = (int)$ev['confirmed_seats'];
                        $occRate = ($cap > 0) ? round(($cSeats / $cap) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td>
                                <strong style="color: var(--text-primary);"><?= e($ev['title']) ?></strong>
                            </td>
                            <td>
                                <div><?= e($ev['city']) ?></div>
                                <span style="font-size: 0.75rem; color: var(--text-muted);"><?= e($ev['category']) ?></span>
                            </td>
                            <td>
                                <strong><?= e(format_price($ev['price'])) ?></strong>
                            </td>
                            <td><?= $cap ?></td>
                            <td>
                                <span style="color: #6ee7b7; font-weight: 700;"><?= $cSeats ?></span>
                            </td>
                            <td>
                                <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.3);">
                                    <?= $occRate ?>%
                                </span>
                            </td>
                            <td>
                                <span style="color: var(--brand-accent); font-weight: 700;">
                                    <?= e(format_price($ev['simulated_revenue'])) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge-status-<?= e($ev['status']) ?>">
                                    <?= strtoupper(e($ev['status'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="chart-empty-state">No gatherings found in database.</div>
    <?php endif; ?>
</div>

<!-- Table 2: Recent Bookings Audit Log -->
<div class="admin-table-card">
    <div class="admin-table-header">
        <div>
            <h3 style="font-size: 1.1rem; font-weight: 700;">Recent Reservation Telemetry Log</h3>
            <span style="font-size: 0.8rem; color: var(--text-muted);">Latest booking entries with attendee confirmation details</span>
        </div>
        <a href="bookings.php" class="btn btn-outline btn-sm">Inspect All Bookings &rarr;</a>
    </div>

    <?php if (!empty($recentBookings)): ?>
        <div class="admin-table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Booking Code</th>
                        <th>Attendee</th>
                        <th>Event &amp; City</th>
                        <th>Seats</th>
                        <th>Simulated Total</th>
                        <th>Status</th>
                        <th>Timestamp (IST)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentBookings as $rb): ?>
                        <tr>
                            <td>
                                <code style="color: var(--brand-accent); font-weight: 700;"><?= e($rb['booking_code']) ?></code>
                            </td>
                            <td>
                                <div><strong><?= e($rb['user_name']) ?></strong></div>
                                <span style="font-size: 0.775rem; color: var(--text-muted);"><?= e($rb['user_email']) ?></span>
                            </td>
                            <td>
                                <div><?= e($rb['event_title']) ?></div>
                                <span style="font-size: 0.75rem; color: var(--text-muted);"><?= e($rb['event_city']) ?></span>
                            </td>
                            <td>
                                <span style="font-weight: 700;"><?= (int)$rb['tickets_count'] ?></span>
                            </td>
                            <td>
                                <strong style="color: var(--brand-accent);"><?= e(format_price($rb['total_amount'])) ?></strong>
                            </td>
                            <td>
                                <?php if ($rb['payment_status'] === 'confirmed'): ?>
                                    <span class="badge-status-active">CONFIRMED</span>
                                <?php else: ?>
                                    <span class="badge-status-cancelled">CANCELLED</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-size: 0.825rem; color: var(--text-secondary);">
                                    <?= date('d M Y, h:i A', strtotime($rb['booking_date'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="chart-empty-state">No reservation records found for this period.</div>
    <?php endif; ?>
</div>

<!-- =====================================================================
     Export Reports Hub
     ===================================================================== -->
<div class="admin-table-card">
    <div class="admin-table-header">
        <div>
            <h3 style="font-size: 1.1rem; font-weight: 700;">📊 Export Intelligence Reports (CSV)</h3>
            <p style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 0.2rem;">
                Download sanitized spreadsheet files for offline audits. All exports exclude passwords, secrets, and session tokens,
                and feature spreadsheet formula injection protection.
            </p>
        </div>
    </div>
    <div style="padding: 1.25rem;">
        <div class="export-card-row">
            <a href="analytics-export.php?report=bookings<?= $dateFilterApplied ? '&date_from=' . urlencode($dateFromInput) . '&date_to=' . urlencode($dateToInput) : '' ?>" class="export-download-btn">
                <div>
                    <div style="font-weight: 700;">Bookings Report</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                        <?= $dateFilterApplied ? 'Filtered window' : 'Full history' ?>
                    </div>
                </div>
                <span>📥 CSV</span>
            </a>

            <a href="analytics-export.php?report=events" class="export-download-btn">
                <div>
                    <div style="font-weight: 700;">Events Catalog Summary</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Capacities, seats, occupancy</div>
                </div>
                <span>📥 CSV</span>
            </a>

            <a href="analytics-export.php?report=reviews<?= $dateFilterApplied ? '&date_from=' . urlencode($dateFromInput) . '&date_to=' . urlencode($dateToInput) : '' ?>" class="export-download-btn">
                <div>
                    <div style="font-weight: 700;">Community Reviews</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Ratings, comments, status</div>
                </div>
                <span>📥 CSV</span>
            </a>

            <a href="analytics-export.php?report=monthly" class="export-download-btn">
                <div>
                    <div style="font-weight: 700;">Monthly Performance</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Monthly bookings &amp; volume</div>
                </div>
                <span>📥 CSV</span>
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

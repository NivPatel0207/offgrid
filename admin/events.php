<?php
/**
 * OFFGRID - Manage Community Events
 * 
 * Administrative table with search, multi-attribute filters, capacity counters,
 * and quick access to edit, view, and safely archive/delete events.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Strict Authorization Guard
require_admin();

$is_admin_area = true;
$admin_active_tab = 'events';
$page_title = 'Manage Events';

// 1. Fetch distinct categories and cities for filters
$filterCategories = [];
$filterCities = [];
try {
    $filterCategories = $pdo->query("SELECT DISTINCT category FROM events WHERE category IS NOT NULL ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);
    $filterCities = $pdo->query("SELECT DISTINCT city FROM events WHERE city IS NOT NULL AND city != '' ORDER BY city ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log('[OFFGRID ADMIN EVENTS FILTER ERROR] ' . $e->getMessage());
}

// 2. Read and sanitize GET filter parameters
$search   = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$city     = trim($_GET['city'] ?? '');
$status   = trim($_GET['status'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(e.title LIKE ? OR e.city LIKE ? OR e.location LIKE ? OR e.description LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($category !== '') {
    $where[] = 'e.category = ?';
    $params[] = $category;
}

if ($city !== '') {
    $where[] = 'e.city = ?';
    $params[] = $city;
}

if ($status !== '' && in_array($status, ['active', 'draft', 'completed', 'cancelled'], true)) {
    $where[] = 'e.status = ?';
    $params[] = $status;
}

$whereSql = (!empty($where)) ? 'WHERE ' . implode(' AND ', $where) : '';

// 3. Query events with occupied seat calculation from bookings
$sql = "
    SELECT e.id, e.title, e.category, e.city, e.location, e.event_date, e.event_time, 
           e.price, e.total_capacity, e.available_seats, e.status, e.image,
           COALESCE(SUM(b.tickets_count), 0) AS booked_seats,
           COUNT(b.id) AS bookings_count
    FROM events e
    LEFT JOIN bookings b ON e.id = b.event_id
    {$whereSql}
    GROUP BY e.id
    ORDER BY e.event_date DESC, e.event_time DESC
";

$events = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('[OFFGRID ADMIN EVENTS QUERY ERROR] ' . $e->getMessage());
}

$isFiltered = ($search !== '' || $category !== '' || $city !== '' || $status !== '');

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Reusable Admin Navigation Bar -->
<?php require_once __DIR__ . '/../includes/admin-nav.php'; ?>

<!-- Header -->
<div class="admin-header">
    <div class="admin-title">
        <h1>Manage Community Events</h1>
        <p>Monitor event schedules, manage real-time participant capacity, and publish gatherings.</p>
    </div>
    <div class="admin-actions" style="margin-top: 0;">
        <a href="event-create.php" class="btn btn-primary btn-sm">+ Create New Event</a>
    </div>
</div>

<!-- Search & Multi-Filter Bar -->
<section class="admin-filter-bar">
    <form action="events.php" method="GET" class="admin-filter-form">
        <!-- Search -->
        <div class="filter-col-search">
            <input 
                type="text" 
                name="search" 
                class="form-control" 
                placeholder="Search title, city, or venue..." 
                value="<?= e($search) ?>"
                autocomplete="off"
            >
        </div>

        <!-- City Dropdown -->
        <div class="filter-col-select">
            <select name="city" class="form-control" style="cursor: pointer;">
                <option value="">All Indian Cities</option>
                <?php foreach ($filterCities as $cOpt): ?>
                    <option value="<?= e($cOpt) ?>" <?= ($city === $cOpt) ? 'selected' : '' ?>>
                        📍 <?= e($cOpt) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Category Dropdown -->
        <div class="filter-col-select">
            <select name="category" class="form-control" style="cursor: pointer;">
                <option value="">All Categories</option>
                <?php foreach ($filterCategories as $catOpt): ?>
                    <option value="<?= e($catOpt) ?>" <?= ($category === $catOpt) ? 'selected' : '' ?>>
                        <?= e($catOpt) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Status Dropdown -->
        <div class="filter-col-select">
            <select name="status" class="form-control" style="cursor: pointer;">
                <option value="">All Statuses</option>
                <option value="active" <?= ($status === 'active') ? 'selected' : '' ?>>Active</option>
                <option value="draft" <?= ($status === 'draft') ? 'selected' : '' ?>>Draft</option>
                <option value="completed" <?= ($status === 'completed') ? 'selected' : '' ?>>Completed</option>
                <option value="cancelled" <?= ($status === 'cancelled') ? 'selected' : '' ?>>Cancelled / Inactive</option>
            </select>
        </div>

        <!-- Filter Actions -->
        <div class="filter-col-actions">
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <?php if ($isFiltered): ?>
                <a href="events.php" class="btn btn-outline btn-sm">&times; Reset</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<!-- Events Table Card -->
<div class="admin-table-card">
    <div class="admin-table-header">
        <div>
            <span style="font-weight: 700; color: var(--text-primary);">
                Showing <?= count($events) ?> <?= count($events) === 1 ? 'event' : 'events' ?>
            </span>
            <?php if ($isFiltered): ?>
                <span style="color: var(--text-muted); font-size: 0.825rem;">matching your filter criteria</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($events)): ?>
        <div class="admin-table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width: 58px;">Cover</th>
                        <th style="min-width: 220px;">Title & Category</th>
                        <th style="min-width: 170px;">City & Venue</th>
                        <th style="width: 130px; white-space: nowrap;">Date & Time</th>
                        <th style="width: 90px; white-space: nowrap;">Fee</th>
                        <th style="width: 175px; white-space: nowrap;">Capacity / Booked</th>
                        <th style="width: 90px; white-space: nowrap;">Status</th>
                        <th style="width: 205px; min-width: 205px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $ev): ?>
                        <?php 
                        $imgUrl = get_event_image_url($ev['image'], '../');
                        $occupied = (int)$ev['booked_seats'];
                        $total = (int)$ev['total_capacity'];
                        $avail = (int)$ev['available_seats'];
                        ?>
                        <tr>
                            <td>
                                <img src="<?= e($imgUrl) ?>" alt="<?= e($ev['title']) ?>" class="table-thumb" loading="lazy">
                            </td>
                            <td>
                                <div style="font-weight: 700; color: var(--text-primary); font-size: 0.925rem; line-height: 1.35;">
                                    <?= e($ev['title']) ?>
                                </div>
                                <div style="font-size: 0.775rem; color: var(--text-muted); margin-top: 0.2rem;">
                                    <?= e($ev['category']) ?>
                                </div>
                            </td>
                            <td>
                                <div><span class="badge-city-solid">📍 <?= e($ev['city']) ?></span></div>
                                <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.2rem;"><?= e($ev['location']) ?></div>
                            </td>
                            <td>
                                <div><?= e(format_event_date($ev['event_date'])) ?></div>
                                <div style="font-size: 0.775rem; color: var(--text-muted); margin-top: 0.15rem;"><?= e(format_event_time($ev['event_time'])) ?></div>
                            </td>
                            <td>
                                <strong style="color: var(--brand-accent); font-weight: 800;">
                                    <?= e(format_price($ev['price'])) ?>
                                </strong>
                            </td>
                            <td>
                                <div>
                                    <strong style="color: <?= $avail <= 3 ? '#f59e0b' : 'var(--text-primary)' ?>;">
                                        <?= $avail ?>
                                    </strong>
                                    <span style="color: var(--text-muted); font-size: 0.8rem;">open / <?= $total ?> total</span>
                                </div>
                                <div style="font-size: 0.775rem; color: var(--text-secondary); margin-top: 0.2rem;">
                                    <?= $occupied ?> booked (<?= (int)$ev['bookings_count'] ?> orders)
                                </div>
                            </td>
                            <td>
                                <?php if ($ev['status'] === 'active'): ?>
                                    <span class="badge-status-active">Active</span>
                                <?php elseif ($ev['status'] === 'draft'): ?>
                                    <span class="badge-status-draft">Draft</span>
                                <?php elseif ($ev['status'] === 'completed'): ?>
                                    <span class="badge-status-completed">Completed</span>
                                <?php else: ?>
                                    <span class="badge-status-cancelled"><?= e(ucfirst($ev['status'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; width: 205px; min-width: 205px;">
                                <div class="admin-table-actions" style="justify-content: flex-end;">
                                    <!-- Edit Link -->
                                    <a href="event-edit.php?id=<?= (int)$ev['id'] ?>" class="btn btn-outline btn-sm table-action-btn" title="Edit Event Details">
                                        ✏️ Edit
                                    </a>

                                    <!-- Public Details Link -->
                                    <a href="../event-details.php?id=<?= (int)$ev['id'] ?>" class="btn btn-outline btn-sm table-action-btn" target="_blank" title="View on Public Site">
                                        👁️ View
                                    </a>

                                    <!-- Delete / Archive Form with CSRF Protection -->
                                    <form action="event-delete.php" method="POST" style="display: inline;" onsubmit="return confirmArchive(<?= (int)$ev['id'] ?>, <?= $occupied ?>, <?= htmlspecialchars(json_encode($ev['title']), ENT_QUOTES, 'UTF-8') ?>);">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
                                        <?php if ($occupied > 0): ?>
                                            <button type="submit" class="btn btn-danger-outline btn-sm table-action-btn" title="Deactivate Event (Preserves attendee passes)">
                                                🚫 Cancel
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" class="btn btn-danger-outline btn-sm table-action-btn" title="Delete Event">
                                                🗑️ Delete
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <div style="font-size: 2.5rem; margin-bottom: 0.75rem;">📅</div>
            <h3>No Events Found</h3>
            <?php if ($isFiltered): ?>
                <p>No community events matched your current search and filter criteria.</p>
                <a href="events.php" class="btn btn-outline btn-sm">Clear Filters</a>
            <?php else: ?>
                <p>There are no community events currently in the system.</p>
                <a href="event-create.php" class="btn btn-primary btn-sm">+ Create First Event</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function confirmArchive(eventId, occupiedSeats, eventTitle) {
    if (occupiedSeats > 0) {
        return confirm(
            'NOTICE: "' + eventTitle + '" has ' + occupiedSeats + ' booked attendee seat(s).\n\n' +
            'To protect attendee ticket passes and booking receipts, this event will NOT be permanently deleted.\n' +
            'Instead, its status will be changed to "cancelled" (inactive).\n\n' +
            'Do you wish to deactivate this event?'
        );
    } else {
        return confirm(
            'Are you sure you want to permanently delete "' + eventTitle + '"?\n\n' +
            'This event has 0 bookings and can be safely removed.'
        );
    }
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>

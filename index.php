<?php
/**
 * OFFGRID - Public Community Event Catalog & Discovery Portal
 * 
 * Step 10: Event Discovery & Personalization
 * - Advanced multi-attribute search across title, description, city, venue, and category
 * - Multi-criteria filters: City, Category, Date Range (with presets), Price Tier (Free, Under ₹500, ₹500+)
 * - Flexible Sorting: Upcoming Date, Latest Date, Price (Low-High / High-Low), Popularity (by confirmed seats)
 * - Pagination preserving active filters and query parameters
 * - Deterministic, database-driven Personalized Recommendations for logged-in members
 * - 1-Click Save/Bookmark button with CSRF protection and guest sign-in prompts
 * - Useful, friendly empty-result states with 1-click filter reset
 * - Full Indian localization (INR ₹, IST Asia/Kolkata, regional Indian cities)
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

init_session();
$currentUser = current_user();
$userId = $currentUser ? (int)$currentUser['id'] : null;

$page_title = 'Explore Community Events Across India';

// ---------------------------------------------------------------------
// 1. Fetch Dynamic Dropdown Options (Categories & Cities)
// ---------------------------------------------------------------------
$availableCategories = [];
$availableCities = [];

try {
    $catStmt = $pdo->query("SELECT DISTINCT category FROM events WHERE status = 'active' ORDER BY category ASC");
    $availableCategories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

    $cityStmt = $pdo->query("SELECT DISTINCT city FROM events WHERE status = 'active' AND city IS NOT NULL AND city != '' ORDER BY city ASC");
    $availableCities = $cityStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log('[OFFGRID CATALOG METADATA ERROR] ' . $e->getMessage());
}

// ---------------------------------------------------------------------
// 2. Fetch User's Saved Events Set (for Bookmark State Detection)
// ---------------------------------------------------------------------
$userSavedEventIds = [];
if ($userId) {
    try {
        $savedStmt = $pdo->prepare('SELECT event_id FROM saved_events WHERE user_id = ?');
        $savedStmt->execute([$userId]);
        $userSavedEventIds = $savedStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log('[OFFGRID SAVED EVENTS FETCH ERROR] ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// 3. Personalized Event Recommendations (Logged-in Members Only)
// ---------------------------------------------------------------------
$recommendedEvents = [];
if ($userId) {
    try {
        // A. Extract user's past confirmed bookings
        $prefStmt = $pdo->prepare("
            SELECT DISTINCT e.category, e.city
            FROM bookings b
            JOIN events e ON b.event_id = e.id
            WHERE b.user_id = ? AND b.payment_status = 'confirmed'
        ");
        $prefStmt->execute([$userId]);
        $userBookingPrefs = $prefStmt->fetchAll(PDO::FETCH_ASSOC);

        // B. Extract user's high-rated reviews (rating >= 4)
        $revPrefStmt = $pdo->prepare("
            SELECT DISTINCT e.category, e.city
            FROM reviews r
            JOIN events e ON r.event_id = e.id
            WHERE r.user_id = ? AND r.status = 'approved' AND r.rating >= 4
        ");
        $revPrefStmt->execute([$userId]);
        $userReviewPrefs = $revPrefStmt->fetchAll(PDO::FETCH_ASSOC);

        $preferredCategories = array_unique(array_filter(array_merge(
            array_column($userBookingPrefs, 'category'),
            array_column($userReviewPrefs, 'category')
        )));

        $preferredCities = array_unique(array_filter(array_merge(
            array_column($userBookingPrefs, 'city'),
            array_column($userReviewPrefs, 'city')
        )));

        // C. Fetch candidate recommendations:
        // Exclude cancelled/completed/draft events, past dates, and events user has already confirmed booked
        if (!empty($preferredCategories) || !empty($preferredCities)) {
            $catPlaceholders = !empty($preferredCategories) 
                ? implode(',', array_fill(0, count($preferredCategories), '?')) 
                : "''";
            $cityPlaceholders = !empty($preferredCities) 
                ? implode(',', array_fill(0, count($preferredCities), '?')) 
                : "''";

            $recSql = "
                SELECT e.*, 
                       (CASE WHEN e.category IN ({$catPlaceholders}) THEN 2 ELSE 0 END +
                        CASE WHEN e.city IN ({$cityPlaceholders}) THEN 1 ELSE 0 END) AS match_score
                FROM events e
                WHERE e.status = 'active'
                  AND e.event_date >= CURDATE()
                  AND e.id NOT IN (
                      SELECT event_id FROM bookings WHERE user_id = ? AND payment_status = 'confirmed'
                  )
                  AND (e.category IN ({$catPlaceholders}) OR e.city IN ({$cityPlaceholders}))
                ORDER BY match_score DESC, e.event_date ASC
                LIMIT 3
            ";

            $recParams = array_merge(
                $preferredCategories,
                $preferredCities,
                [$userId],
                $preferredCategories,
                $preferredCities
            );

            $recStmt = $pdo->prepare($recSql);
            $recStmt->execute($recParams);
            $recommendedEvents = $recStmt->fetchAll(PDO::FETCH_ASSOC);

            // Attach recommendation reason badge
            foreach ($recommendedEvents as &$recEv) {
                if (in_array($recEv['category'], $preferredCategories, true)) {
                    $recEv['rec_reason'] = 'Based on your interest in ' . $recEv['category'];
                } elseif (in_array($recEv['city'], $preferredCities, true)) {
                    $recEv['rec_reason'] = 'Popular gathering in ' . $recEv['city'];
                } else {
                    $recEv['rec_reason'] = 'Recommended Community Gathering';
                }
            }
            unset($recEv);
        }

        // D. Fallback default recommendations for new members or when few matches
        if (count($recommendedEvents) < 3) {
            $excludeIds = array_column($recommendedEvents, 'id');
            $excludeIds[] = 0; // Prevent empty IN clause
            $exclPlaceholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $needed = 3 - count($recommendedEvents);

            $defSql = "
                SELECT e.*, 0 AS match_score
                FROM events e
                WHERE e.status = 'active'
                  AND e.event_date >= CURDATE()
                  AND e.id NOT IN (
                      SELECT event_id FROM bookings WHERE user_id = ? AND payment_status = 'confirmed'
                  )
                  AND e.id NOT IN ({$exclPlaceholders})
                ORDER BY e.available_seats ASC, e.event_date ASC
                LIMIT {$needed}
            ";

            $defParams = array_merge([$userId], $excludeIds);
            $defStmt = $pdo->prepare($defSql);
            $defStmt->execute($defParams);
            $defaults = $defStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($defaults as &$defEv) {
                $defEv['rec_reason'] = 'Trending Community Gathering';
                $recommendedEvents[] = $defEv;
            }
            unset($defEv);
        }

    } catch (PDOException $e) {
        error_log('[OFFGRID RECOMMENDATIONS QUERY ERROR] ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// 4. Sanitize and Validate Search, Filter, Sort & Pagination Parameters
// ---------------------------------------------------------------------
$search     = trim($_GET['search'] ?? '');
$city       = trim($_GET['city'] ?? '');
$category   = trim($_GET['category'] ?? '');
$datePreset = trim($_GET['date_preset'] ?? '');
$dateFrom   = trim($_GET['date_from'] ?? '');
$dateTo     = trim($_GET['date_to'] ?? '');
$priceTier  = trim($_GET['price'] ?? 'all');
$minPrice   = trim($_GET['min_price'] ?? '');
$maxPrice   = trim($_GET['max_price'] ?? '');
$sort       = trim($_GET['sort'] ?? 'date_asc');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 6; // 6 cards per page for clean multi-page pagination

// Validate dropdown values
if (!empty($category) && !in_array($category, $availableCategories, true)) {
    $category = '';
}
if (!empty($city) && !in_array($city, $availableCities, true)) {
    $city = '';
}

// Process Date Presets
$todayStr = date('Y-m-d');
if ($datePreset === 'this_weekend') {
    // Current Friday to Sunday
    $dateFrom = date('Y-m-d', strtotime('friday this week'));
    $dateTo   = date('Y-m-d', strtotime('sunday this week'));
} elseif ($datePreset === 'this_month') {
    $dateFrom = date('Y-m-01');
    $dateTo   = date('Y-m-t');
} elseif ($datePreset === 'next_30') {
    $dateFrom = $todayStr;
    $dateTo   = date('Y-m-d', strtotime('+30 days'));
} elseif ($datePreset === 'upcoming') {
    $dateFrom = $todayStr;
    $dateTo   = '';
}

// Validate Date inputs
if (!empty($dateFrom)) {
    $dF = DateTime::createFromFormat('Y-m-d', $dateFrom);
    if (!$dF || $dF->format('Y-m-d') !== $dateFrom) {
        $dateFrom = '';
    }
}
if (!empty($dateTo)) {
    $dT = DateTime::createFromFormat('Y-m-d', $dateTo);
    if (!$dT || $dT->format('Y-m-d') !== $dateTo) {
        $dateTo = '';
    }
}
if (!empty($dateFrom) && !empty($dateTo) && $dateFrom > $dateTo) {
    // Auto-swap inverted range
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}

// Validate Sort
$validSorts = ['date_asc', 'date_desc', 'price_asc', 'price_desc', 'popularity'];
if (!in_array($sort, $validSorts, true)) {
    $sort = 'date_asc';
}

// ---------------------------------------------------------------------
// 5. Build Dynamic SQL Where Clauses & Prepared Parameters
// ---------------------------------------------------------------------
$whereClauses = ["e.status = 'active'"];
$params = [];

// Multi-attribute search (title, description, venue location, city, category)
if ($search !== '') {
    $whereClauses[] = "(e.title LIKE ? OR e.description LIKE ? OR e.location LIKE ? OR e.city LIKE ? OR e.category LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// City filter
if ($city !== '') {
    $whereClauses[] = "e.city = ?";
    $params[] = $city;
}

// Category filter
if ($category !== '') {
    $whereClauses[] = "e.category = ?";
    $params[] = $category;
}

// Date Range filter
if ($dateFrom !== '') {
    $whereClauses[] = "e.event_date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $whereClauses[] = "e.event_date <= ?";
    $params[] = $dateTo;
}

// Price Tier & Numeric range filters
if ($priceTier === 'free') {
    $whereClauses[] = "e.price <= 0.00";
} elseif ($priceTier === 'under_300') {
    $whereClauses[] = "e.price > 0.00 AND e.price < 300.00";
} elseif ($priceTier === 'under_500') {
    $whereClauses[] = "e.price <= 500.00";
} elseif ($priceTier === '500_plus') {
    $whereClauses[] = "e.price >= 500.00";
}

if ($minPrice !== '' && is_numeric($minPrice)) {
    $whereClauses[] = "e.price >= ?";
    $params[] = (float)$minPrice;
}
if ($maxPrice !== '' && is_numeric($maxPrice)) {
    $whereClauses[] = "e.price <= ?";
    $params[] = (float)$maxPrice;
}

$whereSql = implode(' AND ', $whereClauses);

// Determine ORDER BY clause
$orderSql = "ORDER BY e.event_date ASC, e.event_time ASC";
if ($sort === 'date_desc') {
    $orderSql = "ORDER BY e.event_date DESC, e.event_time DESC";
} elseif ($sort === 'price_asc') {
    $orderSql = "ORDER BY e.price ASC, e.event_date ASC";
} elseif ($sort === 'price_desc') {
    $orderSql = "ORDER BY e.price DESC, e.event_date ASC";
} elseif ($sort === 'popularity') {
    $orderSql = "ORDER BY confirmed_seats DESC, e.event_date ASC";
}

// ---------------------------------------------------------------------
// 6. Execute Count Query for Pagination
// ---------------------------------------------------------------------
$totalEvents = 0;
try {
    $countSql = "SELECT COUNT(*) FROM events e WHERE {$whereSql}";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalEvents = (int)$countStmt->fetchColumn();
} catch (PDOException $e) {
    error_log('[OFFGRID COUNT QUERY ERROR] ' . $e->getMessage());
}

$totalPages = max(1, (int)ceil($totalEvents / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

// ---------------------------------------------------------------------
// 7. Execute Paginated Catalog Query
// ---------------------------------------------------------------------
$events = [];
try {
    $catalogSql = "
        SELECT e.*, 
               COALESCE(b_agg.confirmed_seats, 0) AS confirmed_seats,
               COALESCE(b_agg.confirmed_bookings, 0) AS confirmed_bookings
        FROM events e
        LEFT JOIN (
            SELECT event_id, 
                   SUM(tickets_count) AS confirmed_seats,
                   COUNT(id) AS confirmed_bookings
            FROM bookings
            WHERE payment_status = 'confirmed'
            GROUP BY event_id
        ) b_agg ON e.id = b_agg.event_id
        WHERE {$whereSql}
        {$orderSql}
        LIMIT {$perPage} OFFSET {$offset}
    ";

    $stmt = $pdo->prepare($catalogSql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('[OFFGRID CATALOG QUERY ERROR] ' . $e->getMessage());
}

// Helper to check if any filters are active
$isFiltered = ($search !== '' || $category !== '' || $city !== '' || 
               $datePreset !== '' || $dateFrom !== '' || $dateTo !== '' || 
               $priceTier !== 'all' || $minPrice !== '' || $maxPrice !== '' || 
               $sort !== 'date_asc');

/**
 * Builds a query string preserving all active filters while swapping page number.
 */
function build_filter_url(int $targetPage): string {
    $params = $_GET;
    $params['page'] = $targetPage;
    return 'index.php?' . http_build_query($params);
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Hero Section -->
<section class="catalog-hero">
    <span class="user-badge" style="display: inline-flex; margin-bottom: 1rem; border-color: var(--brand-accent);">
        <span class="brand-dot"></span> Reconnect Outdoors &bull; Community Gatherings Across India
    </span>
    <h1>Explore Real Community Events in India</h1>
    <p>Discover outdoor treks, bushcraft camps, solar workshops, heritage walks, culinary festivals, and acoustic gatherings across Indian cities.</p>
</section>

<!-- =====================================================================
     Personalized Event Recommendations (Logged-in Members Only)
     ===================================================================== -->
<?php if ($currentUser && !empty($recommendedEvents)): ?>
    <section class="recommendations-section">
        <div class="recommendations-header">
            <div class="recommendations-title">
                <h2>✨ Handpicked For You, <?= e($currentUser['name']) ?></h2>
                <p>Curated gatherings based on your confirmed registrations and community interests.</p>
            </div>
            <a href="saved-events.php" class="btn btn-outline btn-sm" style="font-size:0.8rem;">
                🔖 My Saved Events (<?= count($userSavedEventIds) ?>)
            </a>
        </div>

        <div class="recommendations-grid">
            <?php foreach ($recommendedEvents as $recEvent): ?>
                <?php
                $isSoldOut = ($recEvent['available_seats'] <= 0);
                $isSaved = in_array((int)$recEvent['id'], $userSavedEventIds, true);
                $eventDateFormatted = format_event_date($recEvent['event_date']);
                $eventTimeFormatted = format_event_time($recEvent['event_time']);
                $priceFormatted = format_price($recEvent['price']);
                $imageUrl = get_event_image_url($recEvent['image'] ?? null);
                ?>
                <article class="event-card <?= $isSoldOut ? 'is-soldout' : '' ?>" style="background: var(--bg-surface-elevated);">
                    <div class="event-card-media">
                        <a href="event-details.php?id=<?= (int)$recEvent['id'] ?>" tabindex="-1" aria-hidden="true">
                            <img src="<?= e($imageUrl) ?>" alt="<?= e($recEvent['title']) ?>" class="event-card-img" loading="lazy">
                        </a>

                        <div class="event-card-overlay-top">
                            <span class="badge-city">📍 <?= e($recEvent['city']) ?></span>
                            <div class="event-card-top-right">
                                <span class="price-pill-tag <?= ((float)$recEvent['price'] <= 0) ? 'free' : '' ?>">
                                    <?= e($priceFormatted) ?>
                                </span>
                                <form action="saved-events-action.php" method="POST" class="event-card-save-form" style="margin: 0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="event_id" value="<?= (int)$recEvent['id'] ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="return_url" value="index.php">
                                    <button type="submit" class="bookmark-form-btn <?= $isSaved ? 'is-saved' : '' ?>" 
                                            title="<?= $isSaved ? 'Remove from Saved' : 'Save Event' ?>" 
                                            aria-label="<?= $isSaved ? 'Remove from Saved' : 'Save Event' ?>">
                                        <?= $isSaved ? '❤️' : '🤍' ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="event-card-header">
                        <span class="badge-category"><?= e($recEvent['category']) ?></span>
                        <span class="rec-reason-tag"><?= e($recEvent['rec_reason'] ?? 'Recommended') ?></span>
                    </div>

                    <div class="event-card-body">
                        <h3 class="event-card-title" style="font-size: 1.05rem;">
                            <a href="event-details.php?id=<?= (int)$recEvent['id'] ?>" style="color: inherit; text-decoration: none;">
                                <?= e($recEvent['title']) ?>
                            </a>
                        </h3>
                        <ul class="event-meta-list" style="margin-top: 0.5rem;">
                            <li class="event-meta-item">
                                <span class="meta-icon">📅</span>
                                <span><?= e($eventDateFormatted) ?> &bull; <?= e($eventTimeFormatted) ?></span>
                            </li>
                            <li class="event-meta-item">
                                <span class="meta-icon">📍</span>
                                <span><strong><?= e($recEvent['city']) ?></strong> &bull; <?= e($recEvent['location']) ?></span>
                            </li>
                        </ul>
                    </div>

                    <div class="event-card-footer">
                        <span style="font-size: 0.8rem; color: var(--brand-accent); font-weight: 700;">
                            <?= (int)$recEvent['available_seats'] ?> seats left
                        </span>
                        <a href="event-details.php?id=<?= (int)$recEvent['id'] ?>" class="btn btn-primary btn-sm">
                            View Gathering &rarr;
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<!-- =====================================================================
     Advanced Multi-Attribute Search & Filter Bar
     ===================================================================== -->
<section class="filter-card">
    <form action="index.php" method="GET" class="filter-advanced-wrap">
        <!-- Row 1: Search Keyword, City, and Category -->
        <div class="filter-advanced-row">
            <!-- Keyword Search -->
            <div class="filter-input-search">
                <input 
                    type="text" 
                    name="search" 
                    class="form-control" 
                    placeholder="Search by title, description, venue, city, or topic..." 
                    value="<?= e($search) ?>"
                    autocomplete="off"
                >
            </div>

            <!-- Dynamic City Dropdown -->
            <div class="filter-select-item">
                <select name="city" class="form-control" style="cursor: pointer;">
                    <option value="">🇮🇳 All India (All Cities)</option>
                    <?php foreach ($availableCities as $cityOption): ?>
                        <option value="<?= e($cityOption) ?>" <?= ($city === $cityOption) ? 'selected' : '' ?>>
                            📍 <?= e($cityOption) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Dynamic Category Dropdown -->
            <div class="filter-select-item">
                <select name="category" class="form-control" style="cursor: pointer;">
                    <option value="">All Categories</option>
                    <?php foreach ($availableCategories as $catOption): ?>
                        <option value="<?= e($catOption) ?>" <?= ($category === $catOption) ? 'selected' : '' ?>>
                            <?= e($catOption) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Row 2: Date Filters, Price Range, and Sorting -->
        <div class="filter-advanced-row">
            <!-- Start Date -->
            <div style="flex: 1; min-width: 140px;">
                <input 
                    type="date" 
                    name="date_from" 
                    class="form-control" 
                    value="<?= e($dateFrom) ?>"
                    placeholder="From Date"
                    title="From Date (IST)"
                >
            </div>

            <!-- End Date -->
            <div style="flex: 1; min-width: 140px;">
                <input 
                    type="date" 
                    name="date_to" 
                    class="form-control" 
                    value="<?= e($dateTo) ?>"
                    placeholder="To Date"
                    title="To Date (IST)"
                >
            </div>

            <!-- Price Tier -->
            <div style="flex: 1; min-width: 150px;">
                <select name="price" class="form-control" style="cursor: pointer;">
                    <option value="all" <?= ($priceTier === 'all') ? 'selected' : '' ?>>All Price Ranges</option>
                    <option value="free" <?= ($priceTier === 'free') ? 'selected' : '' ?>>Free Gatherings (₹0)</option>
                    <option value="under_300" <?= ($priceTier === 'under_300') ? 'selected' : '' ?>>Under ₹300</option>
                    <option value="under_500" <?= ($priceTier === 'under_500') ? 'selected' : '' ?>>Under ₹500</option>
                    <option value="500_plus" <?= ($priceTier === '500_plus') ? 'selected' : '' ?>>₹500 &amp; Above</option>
                </select>
            </div>

            <!-- Sort By -->
            <div style="flex: 1.2; min-width: 170px;">
                <select name="sort" class="form-control" style="cursor: pointer;">
                    <option value="date_asc" <?= ($sort === 'date_asc') ? 'selected' : '' ?>>📅 Date: Soonest First</option>
                    <option value="date_desc" <?= ($sort === 'date_desc') ? 'selected' : '' ?>>📅 Date: Latest First</option>
                    <option value="price_asc" <?= ($sort === 'price_asc') ? 'selected' : '' ?>>💰 Price: Low to High</option>
                    <option value="price_desc" <?= ($sort === 'price_desc') ? 'selected' : '' ?>>💰 Price: High to Low</option>
                    <option value="popularity" <?= ($sort === 'popularity') ? 'selected' : '' ?>>🔥 Most Popular</option>
                </select>
            </div>

            <!-- Submit & Reset Buttons -->
            <div class="filter-actions" style="margin-top: 0;">
                <button type="submit" class="btn btn-primary btn-sm">
                    Apply Filters
                </button>
                <?php if ($isFiltered): ?>
                    <a href="index.php" class="btn btn-outline btn-sm" title="Clear all active filters">
                        &times; Reset
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Date Preset Shortcuts -->
        <div class="filter-pill-list">
            <span style="font-size: 0.775rem; color: var(--text-muted); font-weight: 600;">Date Quick Pick:</span>
            <a href="index.php?date_preset=upcoming" class="filter-pill-btn <?= ($datePreset === 'upcoming') ? 'active' : '' ?>">Upcoming</a>
            <a href="index.php?date_preset=this_weekend" class="filter-pill-btn <?= ($datePreset === 'this_weekend') ? 'active' : '' ?>">This Weekend</a>
            <a href="index.php?date_preset=this_month" class="filter-pill-btn <?= ($datePreset === 'this_month') ? 'active' : '' ?>">This Month</a>
            <a href="index.php?date_preset=next_30" class="filter-pill-btn <?= ($datePreset === 'next_30') ? 'active' : '' ?>">Next 30 Days</a>
            <a href="index.php?price=free" class="filter-pill-btn <?= ($priceTier === 'free') ? 'active' : '' ?>">Free Events (₹0)</a>
            <a href="index.php?sort=popularity" class="filter-pill-btn <?= ($sort === 'popularity') ? 'active' : '' ?>">🔥 Most Popular</a>
        </div>
    </form>

    <!-- Filter Status Summary & Active Tags -->
    <div class="filter-badge-count">
        <span>
            Showing <strong><?= count($events) ?></strong> of <strong><?= $totalEvents ?></strong> <?= $totalEvents === 1 ? 'gathering' : 'gatherings' ?>
            <?php if ($isFiltered): ?>
                matching active filters (Page <?= $page ?> of <?= $totalPages ?>)
            <?php else: ?>
                across India (Page <?= $page ?> of <?= $totalPages ?>)
            <?php endif; ?>
        </span>

        <?php if ($isFiltered): ?>
            <div style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
                <?php if ($city !== ''): ?>
                    <span class="filter-active-tag">📍 <?= e($city) ?></span>
                <?php endif; ?>
                <?php if ($category !== ''): ?>
                    <span class="filter-active-tag">🏷️ <?= e($category) ?></span>
                <?php endif; ?>
                <?php if ($search !== ''): ?>
                    <span class="filter-active-tag">🔍 "<?= e($search) ?>"</span>
                <?php endif; ?>
                <?php if ($priceTier !== 'all'): ?>
                    <span class="filter-active-tag">💰 <?= ucfirst(str_replace('_', ' ', e($priceTier))) ?></span>
                <?php endif; ?>
                <?php if ($dateFrom !== '' || $dateTo !== ''): ?>
                    <span class="filter-active-tag">📅 <?= e($dateFrom ?: 'Any') ?> &rarr; <?= e($dateTo ?: 'Future') ?></span>
                <?php endif; ?>
                <?php if ($sort !== 'date_asc'): ?>
                    <span class="filter-active-tag">Sort: <?= ucfirst(str_replace('_', ' ', e($sort))) ?></span>
                <?php endif; ?>
                <a href="index.php" style="font-size: 0.775rem; color: #f87171; text-decoration: none; margin-left: 0.25rem;">[Clear All]</a>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- =====================================================================
     Main Events Catalog Grid
     ===================================================================== -->
<?php if (!empty($events)): ?>
    <section class="events-grid">
        <?php foreach ($events as $event): ?>
            <?php
            $isSoldOut = ($event['available_seats'] <= 0);
            $isSaved = in_array((int)$event['id'], $userSavedEventIds, true);
            $eventDateFormatted = format_event_date($event['event_date']);
            $eventTimeFormatted = format_event_time($event['event_time']);
            $priceFormatted = format_price($event['price']);
            $imageUrl = get_event_image_url($event['image'] ?? null);
            ?>
            <article class="event-card <?= $isSoldOut ? 'is-soldout' : '' ?>">
                <!-- Event Cover Photo with City, Price, and Bookmark Button -->
                <div class="event-card-media">
                    <a href="event-details.php?id=<?= (int)$event['id'] ?>" tabindex="-1" aria-hidden="true">
                        <img 
                            src="<?= e($imageUrl) ?>" 
                            alt="<?= e($event['title']) ?> in <?= e($event['city']) ?>" 
                            class="event-card-img" 
                            loading="lazy"
                        >
                    </a>

                    <div class="event-card-overlay-top">
                        <span class="badge-city">📍 <?= e($event['city']) ?></span>
                        <div class="event-card-top-right">
                            <span class="price-pill-tag <?= ((float)$event['price'] <= 0) ? 'free' : '' ?>">
                                <?= e($priceFormatted) ?>
                            </span>
                            <form action="saved-events-action.php" method="POST" class="event-card-save-form" style="margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="return_url" value="<?= e($_SERVER['REQUEST_URI'] ?? 'index.php') ?>">
                                <button type="submit" class="bookmark-form-btn <?= $isSaved ? 'is-saved' : '' ?>" 
                                        title="<?= $isSaved ? 'Remove from Saved Events' : 'Save Event to Wishlist' ?>" 
                                        aria-label="<?= $isSaved ? 'Remove from Saved Events' : 'Save Event to Wishlist' ?>">
                                    <?= $isSaved ? '❤️' : '🤍' ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Card Header: Category & Availability State -->
                <div class="event-card-header">
                    <span class="badge-category"><?= e($event['category']) ?></span>
                    <?php if ($isSoldOut): ?>
                        <span class="badge-soldout" style="padding: 0.15rem 0.5rem; font-size: 0.7rem;">Sold Out</span>
                    <?php else: ?>
                        <span class="seats-badge <?= ($event['available_seats'] <= 3) ? 'low' : '' ?>" style="font-size: 0.775rem;">
                            <?= (int)$event['available_seats'] ?> <?= $event['available_seats'] === 1 ? 'seat' : 'seats' ?> left
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Card Body -->
                <div class="event-card-body">
                    <h2 class="event-card-title">
                        <a href="event-details.php?id=<?= (int)$event['id'] ?>" style="color: inherit; text-decoration: none;">
                            <?= e($event['title']) ?>
                        </a>
                    </h2>

                    <p class="event-card-desc">
                        <?= e(mb_strimwidth($event['description'], 0, 130, '...')) ?>
                    </p>

                    <ul class="event-meta-list">
                        <li class="event-meta-item">
                            <span class="meta-icon">📅</span>
                            <span><?= e($eventDateFormatted) ?> &bull; <?= e($eventTimeFormatted) ?></span>
                        </li>
                        <li class="event-meta-item">
                            <span class="meta-icon">📍</span>
                            <span><strong><?= e($event['city']) ?></strong> &bull; <?= e($event['location']) ?></span>
                        </li>
                        <?php if ((int)($event['confirmed_seats'] ?? 0) > 0): ?>
                            <li class="event-meta-item" style="color: #6ee7b7; font-size: 0.775rem;">
                                <span class="meta-icon">🔥</span>
                                <span><?= (int)$event['confirmed_seats'] ?> confirmed attendee passes</span>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Card Footer -->
                <div class="event-card-footer">
                    <div>
                        <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; display: block;">Registration</span>
                        <div style="font-size: 1.05rem; font-weight: 800; color: var(--brand-accent);">
                            <?= e($priceFormatted) ?>
                        </div>
                    </div>

                    <a href="event-details.php?id=<?= (int)$event['id'] ?>" class="btn <?= $isSoldOut ? 'btn-outline' : 'btn-primary' ?> btn-sm">
                        View Details <?= $isSoldOut ? '' : '&rarr;' ?>
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <!-- =====================================================================
         Preserving Pagination Controls
         ===================================================================== -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination-wrapper">
            <div class="pagination-info">
                Showing page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong> (Total <?= $totalEvents ?> gatherings)
            </div>

            <nav class="pagination-nav" aria-label="Event Catalog Pagination">
                <!-- Previous Button -->
                <?php if ($page > 1): ?>
                    <a href="<?= build_filter_url($page - 1) ?>" class="pagination-btn" aria-label="Previous Page">&laquo; Prev</a>
                <?php else: ?>
                    <span class="pagination-btn disabled" aria-disabled="true">&laquo; Prev</span>
                <?php endif; ?>

                <!-- Numbered Page Buttons -->
                <?php 
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                if ($startPage > 1): ?>
                    <a href="<?= build_filter_url(1) ?>" class="pagination-btn">1</a>
                    <?php if ($startPage > 2): ?>
                        <span style="color: var(--text-muted); padding: 0 0.25rem;">...</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                    <a href="<?= build_filter_url($p) ?>" 
                       class="pagination-btn <?= ($p === $page) ? 'active' : '' ?>"
                       <?= ($p === $page) ? 'aria-current="page"' : '' ?>>
                        <?= $p ?>
                    </a>
                <?php endfor; ?>

                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <span style="color: var(--text-muted); padding: 0 0.25rem;">...</span>
                    <?php endif; ?>
                    <a href="<?= build_filter_url($totalPages) ?>" class="pagination-btn"><?= $totalPages ?></a>
                <?php endif; ?>

                <!-- Next Button -->
                <?php if ($page < $totalPages): ?>
                    <a href="<?= build_filter_url($page + 1) ?>" class="pagination-btn" aria-label="Next Page">Next &raquo;</a>
                <?php else: ?>
                    <span class="pagination-btn disabled" aria-disabled="true">Next &raquo;</span>
                <?php endif; ?>
            </nav>
        </div>
    <?php endif; ?>

<?php else: ?>
    <!-- Friendly and Useful Empty State -->
    <div class="empty-state">
        <div style="font-size: 3rem; margin-bottom: 1rem;">🌲</div>
        <h3>No Gatherings Matched Your Filters</h3>
        <?php if ($isFiltered): ?>
            <p style="max-width: 520px; margin: 0 auto 1.5rem auto;">
                We couldn't find any active community events matching your active criteria
                <?= $search !== '' ? ' for "<strong>' . e($search) . '</strong>"' : '' ?>.
                Try adjusting your city selection, broadening the date range, or resetting filters.
            </p>
            <a href="index.php" class="btn btn-primary btn-sm">Reset All Filters</a>
        <?php else: ?>
            <p>There are no active community events scheduled right now. Check back soon for upcoming gatherings!</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
require_once __DIR__ . '/includes/footer.php';
?>

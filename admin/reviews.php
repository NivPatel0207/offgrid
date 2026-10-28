<?php
/**
 * OFFGRID - Admin Community Reviews & Moderation
 * 
 * Administrative portal for inspecting, moderating, hiding, restoring, and removing
 * attendee reviews across all Pan-India events.
 * 
 * Features:
 * - Strict require_admin() access control
 * - Real-time review metrics and platform average rating
 * - Filter by status (approved/hidden), rating (1-5 stars), and event
 * - Keyword search across review text, attendee name/email, and event title
 * - Audit tracking (records moderated_by and moderated_at timestamps)
 * - Strict CSRF protection on all moderation state changes
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Strict Authorization Guard
require_admin();

$currentUser = current_user();
$is_admin_area = true;
$admin_active_tab = 'reviews';
$page_title = 'Manage Community Reviews';

// 1. Handle Moderation Actions (Hide, Restore, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Security token expired or invalid. Please try again.');
        redirect('reviews.php');
    }

    $action   = trim($_POST['action'] ?? '');
    $reviewId = (int)($_POST['review_id'] ?? 0);
    $note     = trim($_POST['moderation_note'] ?? '');

    if ($reviewId <= 0) {
        set_flash('error', 'Invalid review identifier.');
        redirect('reviews.php');
    }

    try {
        if ($action === 'hide') {
            $stmt = $pdo->prepare('
                UPDATE reviews 
                SET status = "hidden", 
                    moderated_by = ?, 
                    moderated_at = NOW(), 
                    moderation_note = ? 
                WHERE id = ?
            ');
            $stmt->execute([$currentUser['id'], !empty($note) ? $note : 'Hidden by administrator', $reviewId]);
            set_flash('success', "Review #{$reviewId} is now hidden from public event listings.");

        } elseif ($action === 'restore') {
            $stmt = $pdo->prepare('
                UPDATE reviews 
                SET status = "approved", 
                    moderated_by = ?, 
                    moderated_at = NOW(), 
                    moderation_note = NULL 
                WHERE id = ?
            ');
            $stmt->execute([$currentUser['id'], $reviewId]);
            set_flash('success', "Review #{$reviewId} has been restored and is now publicly visible.");

        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM reviews WHERE id = ?');
            $stmt->execute([$reviewId]);
            set_flash('success', "Review #{$reviewId} has been permanently deleted.");
        }
    } catch (PDOException $e) {
        error_log('[OFFGRID ADMIN REVIEW ACTION ERROR] ' . $e->getMessage());
        set_flash('error', 'An unexpected error occurred while processing the moderation action.');
    }

    redirect('reviews.php');
}

// 2. Fetch Review Summary Metrics
$metrics = [
    'total_reviews'    => 0,
    'approved_reviews' => 0,
    'hidden_reviews'   => 0,
    'avg_rating'       => 0.0,
];

try {
    $statQuery = $pdo->query('
        SELECT 
            COUNT(*) AS total_reviews,
            COALESCE(SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END), 0) AS approved_reviews,
            COALESCE(SUM(CASE WHEN status = "hidden" THEN 1 ELSE 0 END), 0) AS hidden_reviews,
            COALESCE(AVG(CASE WHEN status = "approved" THEN rating ELSE NULL END), 0) AS avg_rating
        FROM reviews
    ')->fetch();

    if ($statQuery) {
        $metrics = [
            'total_reviews'    => (int)$statQuery['total_reviews'],
            'approved_reviews' => (int)$statQuery['approved_reviews'],
            'hidden_reviews'   => (int)$statQuery['hidden_reviews'],
            'avg_rating'       => round((float)$statQuery['avg_rating'], 1),
        ];
    }
} catch (PDOException $e) {
    error_log('[OFFGRID ADMIN REVIEW METRICS ERROR] ' . $e->getMessage());
}

// 3. Fetch Event List for Filter Dropdown
$allEvents = [];
try {
    $allEvents = $pdo->query('SELECT id, title, city FROM events ORDER BY title ASC')->fetchAll();
} catch (PDOException $e) {
    error_log('[OFFGRID ADMIN REVIEW EVENTS ERROR] ' . $e->getMessage());
}

// 4. Read Search & Filter Parameters
$search  = trim($_GET['search'] ?? '');
$status  = trim($_GET['status'] ?? '');
$rating  = (int)($_GET['rating'] ?? 0);
$eventId = (int)($_GET['event_id'] ?? 0);

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(r.comment LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR e.title LIKE ? OR e.city LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($status !== '' && in_array($status, ['approved', 'hidden'], true)) {
    $where[] = 'r.status = ?';
    $params[] = $status;
}

if ($rating >= 1 && $rating <= 5) {
    $where[] = 'r.rating = ?';
    $params[] = $rating;
}

if ($eventId > 0) {
    $where[] = 'r.event_id = ?';
    $params[] = $eventId;
}

$whereSql = (!empty($where)) ? 'WHERE ' . implode(' AND ', $where) : '';

// 5. Query Filtered Reviews with joined Author & Event details
$reviews = [];
try {
    $sql = "
        SELECT 
            r.id, r.event_id, r.user_id, r.rating, r.comment, r.status, 
            r.created_at, r.updated_at, r.moderated_at, r.moderation_note,
            u.name AS user_name, u.email AS user_email,
            e.title AS event_title, e.city AS event_city,
            m.name AS moderator_name
        FROM reviews r
        JOIN users u ON r.user_id = u.id
        JOIN events e ON r.event_id = e.id
        LEFT JOIN users m ON r.moderated_by = m.id
        {$whereSql}
        ORDER BY r.created_at DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reviews = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('[OFFGRID ADMIN REVIEWS QUERY ERROR] ' . $e->getMessage());
}

$isFiltered = ($search !== '' || $status !== '' || $rating > 0 || $eventId > 0);

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Reusable Admin Navigation Bar -->
<?php require_once __DIR__ . '/../includes/admin-nav.php'; ?>

<!-- Header -->
<div class="admin-header">
    <div class="admin-title">
        <h1>Community Reviews & Moderation</h1>
        <p>Audit verified attendee ratings, maintain platform trust, and moderate inappropriate feedback.</p>
    </div>
</div>

<!-- Summary Metrics Cards -->
<section class="admin-stats-grid">
    <div class="stat-card">
        <h3>Total Reviews</h3>
        <div class="stat-number"><?= $metrics['total_reviews'] ?></div>
        <div class="stat-sub">Submitted across all events</div>
    </div>

    <div class="stat-card">
        <h3>Approved & Public</h3>
        <div class="stat-number" style="color: #6ee7b7;"><?= $metrics['approved_reviews'] ?></div>
        <div class="stat-sub">Visible to public catalog</div>
    </div>

    <div class="stat-card">
        <h3>Hidden / Moderated</h3>
        <div class="stat-number" style="color: #fca5a5;"><?= $metrics['hidden_reviews'] ?></div>
        <div class="stat-sub">Filtered from public view</div>
    </div>

    <div class="stat-card">
        <h3>Platform Avg Rating</h3>
        <div class="stat-number" style="color: #fde047;">
            <?= $metrics['total_reviews'] > 0 ? number_format($metrics['avg_rating'], 1) . ' ★' : '—' ?>
        </div>
        <div class="stat-sub">From approved reviews</div>
    </div>
</section>

<!-- Filter & Search Bar -->
<section class="admin-filter-bar">
    <form action="reviews.php" method="GET" class="admin-filter-form">
        <!-- Search Keyword -->
        <div style="flex: 2; min-width: 220px;">
            <input 
                type="text" 
                name="search" 
                class="form-control" 
                placeholder="Search review text, attendee name/email, or event..." 
                value="<?= e($search) ?>"
                autocomplete="off"
            >
        </div>

        <!-- Filter by Event -->
        <div style="flex: 2; min-width: 200px;">
            <select name="event_id" class="form-control" style="cursor: pointer;">
                <option value="0">All Events</option>
                <?php foreach ($allEvents as $evOpt): ?>
                    <option value="<?= (int)$evOpt['id'] ?>" <?= ($eventId === (int)$evOpt['id']) ? 'selected' : '' ?>>
                        <?= e($evOpt['title']) ?> (<?= e($evOpt['city']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Filter by Status -->
        <div style="flex: 1; min-width: 130px;">
            <select name="status" class="form-control" style="cursor: pointer;">
                <option value="">All Statuses</option>
                <option value="approved" <?= ($status === 'approved') ? 'selected' : '' ?>>Approved</option>
                <option value="hidden" <?= ($status === 'hidden') ? 'selected' : '' ?>>Hidden</option>
            </select>
        </div>

        <!-- Filter by Rating -->
        <div style="flex: 1; min-width: 120px;">
            <select name="rating" class="form-control" style="cursor: pointer;">
                <option value="0">All Ratings</option>
                <?php for ($star = 5; $star >= 1; $star--): ?>
                    <option value="<?= $star ?>" <?= ($rating === $star) ? 'selected' : '' ?>>
                        <?= $star ?> Stars (<?= str_repeat('★', $star) ?>)
                    </option>
                <?php endfor; ?>
            </select>
        </div>

        <!-- Filter Actions -->
        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <?php if ($isFiltered): ?>
                <a href="reviews.php" class="btn btn-outline btn-sm">&times; Reset</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<!-- Reviews Table Card -->
<div class="admin-table-card">
    <div class="admin-table-header">
        <div>
            <span style="font-weight: 700; color: var(--text-primary);">
                Showing <?= count($reviews) ?> <?= count($reviews) === 1 ? 'review' : 'reviews' ?>
            </span>
            <?php if ($isFiltered): ?>
                <span style="color: var(--text-muted); font-size: 0.825rem;">(filtered results)</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($reviews)): ?>
        <div class="admin-table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Attendee</th>
                        <th>Event & Location</th>
                        <th>Rating</th>
                        <th>Comment / Feedback</th>
                        <th>Status</th>
                        <th>Date & Moderation</th>
                        <th style="text-align: right;">Moderation Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reviews as $rev): ?>
                        <?php
                        $isApproved = ($rev['status'] === 'approved');
                        ?>
                        <tr style="<?= !$isApproved ? 'opacity: 0.8; background: rgba(239, 68, 68, 0.03);' : '' ?>">
                            <!-- Attendee Info -->
                            <td>
                                <strong style="color: var(--text-primary); font-size: 0.95rem;">
                                    <?= e($rev['user_name']) ?>
                                </strong>
                                <div style="font-size: 0.775rem; color: var(--text-secondary); margin-top: 0.15rem;">
                                    ✉️ <?= e($rev['user_email']) ?>
                                </div>
                            </td>

                            <!-- Event Info -->
                            <td style="max-width: 220px;">
                                <a href="../event-details.php?id=<?= (int)$rev['event_id'] ?>#reviews" target="_blank" style="color: var(--text-primary); font-weight: 600; text-decoration: none;">
                                    <?= e($rev['event_title']) ?> &nearr;
                                </a>
                                <div style="margin-top: 0.2rem;">
                                    <span class="badge-city-solid">📍 <?= e($rev['event_city']) ?></span>
                                </div>
                            </td>

                            <!-- Star Rating -->
                            <td>
                                <div style="color: #f59e0b; font-size: 1rem; letter-spacing: 0.05em; font-weight: 700;">
                                    <?= str_repeat('★', (int)$rev['rating']) ?>
                                    <span style="font-size: 0.8rem; color: var(--text-secondary);">(<?= (int)$rev['rating'] ?>)</span>
                                </div>
                            </td>

                            <!-- Review Comment -->
                            <td style="max-width: 320px;">
                                <div style="font-size: 0.875rem; color: var(--text-secondary); line-height: 1.5;">
                                    <?= nl2br(e($rev['comment'])) ?>
                                </div>
                            </td>

                            <!-- Status Badge -->
                            <td>
                                <?php if ($isApproved): ?>
                                    <span class="badge-status-active">Approved</span>
                                <?php else: ?>
                                    <span class="badge-status-cancelled">Hidden</span>
                                <?php endif; ?>
                            </td>

                            <!-- Date & Moderation Audit Info -->
                            <td style="font-size: 0.8rem;">
                                <div><?= e(date('d M Y', strtotime($rev['created_at']))) ?></div>
                                <?php if (!$isApproved && !empty($rev['moderated_at'])): ?>
                                    <div style="font-size: 0.725rem; color: #fca5a5; margin-top: 0.25rem;">
                                        Hidden by <?= e($rev['moderator_name'] ?? 'Admin') ?><br>
                                        <em>"<?= e($rev['moderation_note'] ?? 'Flagged') ?>"</em>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- Moderation Actions -->
                            <td style="text-align: right;">
                                <div class="admin-table-actions" style="justify-content: flex-end;">
                                    <?php if ($isApproved): ?>
                                        <!-- Hide Review Form -->
                                        <form action="reviews.php" method="POST" style="display: inline;" onsubmit="return confirmHideReview(<?= (int)$rev['id'] ?>);">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="hide">
                                            <input type="hidden" name="review_id" value="<?= (int)$rev['id'] ?>">
                                            <input type="hidden" name="moderation_note" value="Hidden by administrator for content review">
                                            <button type="submit" class="btn btn-outline btn-sm" style="padding: 0.35rem 0.65rem; font-size: 0.8rem; color: #f87171;" title="Hide review from public display">
                                                🚫 Hide
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <!-- Restore Review Form -->
                                        <form action="reviews.php" method="POST" style="display: inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="restore">
                                            <input type="hidden" name="review_id" value="<?= (int)$rev['id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm" style="padding: 0.35rem 0.65rem; font-size: 0.8rem; color: #6ee7b7;" title="Restore review to public catalog">
                                                👁️ Restore
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- Delete Review Form -->
                                    <form action="reviews.php" method="POST" style="display: inline;" onsubmit="return confirm('Permanently delete review #<?= (int)$rev['id'] ?>? This cannot be undone.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="review_id" value="<?= (int)$rev['id'] ?>">
                                        <button type="submit" class="btn btn-danger-outline btn-sm" style="padding: 0.35rem 0.65rem; font-size: 0.8rem;" title="Permanently delete review">
                                            🗑️
                                        </button>
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
            <div style="font-size: 2.5rem; margin-bottom: 0.75rem;">⭐</div>
            <h3>No Reviews Found</h3>
            <?php if ($isFiltered): ?>
                <p>No community reviews matched your current search or filter criteria.</p>
                <a href="reviews.php" class="btn btn-outline btn-sm">Clear Filters</a>
            <?php else: ?>
                <p>No community reviews have been submitted by attendees yet.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function confirmHideReview(reviewId) {
    return confirm(
        'Are you sure you want to hide review #' + reviewId + '?\n\n' +
        'It will be immediately removed from the public event page and excluded from aggregate star ratings.'
    );
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>

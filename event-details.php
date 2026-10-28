<?php
/**
 * OFFGRID - Event Details Page with Community Reviews & Ratings
 * 
 * Displays deep-dive information for a single community event:
 * - Validates event ID as a positive integer
 * - Fetches verified event details via prepared statement
 * - Real-time seat capacity tracking
 * - User authentication & confirmed booking status detection
 * - Reviews & Ratings module with confirmed attendee eligibility check
 * - Aggregated rating distribution (1 to 5 stars) and approved reviews display
 * - Interactive review submission and editing for confirmed attendees
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

init_session();

// 1. Validate Event ID from query string
$event_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$event = null;
$existingBooking = null;
$userHasConfirmedBooking = false;
$userReview = null;
$isEventSaved = false;
$ratingStats = [
    'total_reviews' => 0,
    'avg_rating'    => 0.0,
    'stars_5'       => 0,
    'stars_4'       => 0,
    'stars_3'       => 0,
    'stars_2'       => 0,
    'stars_1'       => 0,
];
$reviewsList = [];

if ($event_id && $event_id > 0) {
    try {
        $stmt = $pdo->prepare('
            SELECT id, title, category, description, city, location, event_date, event_time, 
                   price, total_capacity, available_seats, image, status, created_by, created_at 
            FROM events 
            WHERE id = ? 
            LIMIT 1
        ');
        $stmt->execute([$event_id]);
        $event = $stmt->fetch();

        if ($event) {
            // 2. Check attendee confirmed booking status
            if (is_logged_in()) {
                $currentUser = current_user();
                $checkStmt = $pdo->prepare('
                    SELECT id, booking_code, tickets_count, total_amount, payment_reference, booking_date 
                    FROM bookings 
                    WHERE user_id = ? AND event_id = ? AND payment_status = "confirmed"
                    LIMIT 1
                ');
                $checkStmt->execute([$currentUser['id'], $event_id]);
                $existingBooking = $checkStmt->fetch();
                $userHasConfirmedBooking = (bool)$existingBooking;

                // Check if current user has already submitted a review
                $uRevStmt = $pdo->prepare('SELECT * FROM reviews WHERE user_id = ? AND event_id = ? LIMIT 1');
                $uRevStmt->execute([$currentUser['id'], $event_id]);
                $userReview = $uRevStmt->fetch();

                // Check if current user has bookmarked/saved this event
                $saveStmt = $pdo->prepare('SELECT id FROM saved_events WHERE user_id = ? AND event_id = ? LIMIT 1');
                $saveStmt->execute([$currentUser['id'], $event_id]);
                $isEventSaved = (bool)$saveStmt->fetchColumn();
            }

            // 3. Fetch rating statistics (strictly approved reviews)
            $statStmt = $pdo->prepare("
                SELECT 
                    COUNT(*) AS total_reviews,
                    COALESCE(AVG(rating), 0) AS avg_rating,
                    COALESCE(SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END), 0) AS stars_5,
                    COALESCE(SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END), 0) AS stars_4,
                    COALESCE(SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END), 0) AS stars_3,
                    COALESCE(SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END), 0) AS stars_2,
                    COALESCE(SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END), 0) AS stars_1
                FROM reviews 
                WHERE event_id = ? AND status = 'approved'
            ");
            $statStmt->execute([$event_id]);
            $res = $statStmt->fetch();
            if ($res) {
                $ratingStats = [
                    'total_reviews' => (int)$res['total_reviews'],
                    'avg_rating'    => round((float)$res['avg_rating'], 1),
                    'stars_5'       => (int)$res['stars_5'],
                    'stars_4'       => (int)$res['stars_4'],
                    'stars_3'       => (int)$res['stars_3'],
                    'stars_2'       => (int)$res['stars_2'],
                    'stars_1'       => (int)$res['stars_1'],
                ];
            }

            // 4. Fetch list of approved reviews for public display
            $revStmt = $pdo->prepare("
                SELECT r.id, r.user_id, r.rating, r.comment, r.created_at, r.updated_at,
                       u.name AS user_name
                FROM reviews r
                JOIN users u ON r.user_id = u.id
                WHERE r.event_id = ? AND r.status = 'approved'
                ORDER BY r.created_at DESC
            ");
            $revStmt->execute([$event_id]);
            $reviewsList = $revStmt->fetchAll();
        }
    } catch (PDOException $e) {
        error_log('[OFFGRID EVENT DETAILS ERROR] ' . $e->getMessage());
    }
}

// 5. Handle Invalid or Nonexistent Event
if (!$event) {
    http_response_code(404);
    $page_title = 'Event Not Found';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="empty-state">
        <div style="font-size: 3rem; margin-bottom: 1rem;">🔍</div>
        <h2>Event Not Found</h2>
        <p>The community gathering you are looking for does not exist or may have been unlisted by the administrator.</p>
        <a href="index.php" class="btn btn-primary btn-sm">&larr; Back to All Events</a>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$page_title = $event['title'] . ' — ' . $event['city'];
$isSoldOut = ($event['available_seats'] <= 0);
$capacityPercent = ($event['total_capacity'] > 0) 
    ? round((($event['total_capacity'] - $event['available_seats']) / $event['total_capacity']) * 100) 
    : 100;
$imageUrl = get_event_image_url($event['image'] ?? null);

require_once __DIR__ . '/includes/header.php';
?>

<!-- Breadcrumb Navigation -->
<nav class="breadcrumb-nav">
    <a href="index.php">&larr; Back to Event Catalog</a>
</nav>

<div class="event-detail-grid">
    <!-- Main Article: Detailed Description, Hero Image & Itinerary -->
    <article class="detail-card">
        <!-- Event Hero Cover Image -->
        <div class="event-detail-hero-wrapper">
            <img 
                src="<?= e($imageUrl) ?>" 
                alt="<?= e($event['title']) ?> in <?= e($event['city']) ?>" 
                class="event-detail-hero-img" 
                loading="lazy"
            >
        </div>

        <header class="detail-header">
            <div style="display: flex; gap: 0.75rem; align-items: center; margin-bottom: 0.75rem; flex-wrap: wrap;">
                <span class="badge-category"><?= e($event['category']) ?></span>
                <span class="badge-city-solid">📍 <?= e($event['city']) ?></span>
                <?php if ($ratingStats['total_reviews'] > 0): ?>
                    <span class="user-badge" style="border-color: rgba(245, 158, 11, 0.4); color: #fde047; font-size: 0.8rem;">
                        ★ <?= number_format($ratingStats['avg_rating'], 1) ?> (<?= $ratingStats['total_reviews'] ?>)
                    </span>
                <?php endif; ?>
                <?php if ($isSoldOut): ?>
                    <span class="badge-soldout">Sold Out</span>
                <?php else: ?>
                    <span class="seats-badge <?= ($event['available_seats'] <= 3) ? 'low' : '' ?>">
                        <?= (int)$event['available_seats'] ?> slots remaining
                    </span>
                <?php endif; ?>
            </div>

            <h1><?= e($event['title']) ?></h1>

            <div class="detail-meta-pills">
                <div class="detail-meta-pill">
                    <span>📅</span>
                    <strong><?= e(format_event_date($event['event_date'])) ?></strong>
                </div>
                <div class="detail-meta-pill">
                    <span>⏰</span>
                    <strong><?= e(format_event_time($event['event_time'])) ?></strong>
                </div>
                <div class="detail-meta-pill">
                    <span>📍</span>
                    <strong><?= e($event['city']) ?></strong>
                </div>
                <div class="detail-meta-pill">
                    <span>🏢</span>
                    <span><?= e($event['location']) ?></span>
                </div>
            </div>
        </header>

        <section class="detail-content">
            <h3>About This Community Event</h3>
            <p><?= nl2br(e($event['description'])) ?></p>

            <h3>What to Expect</h3>
            <p>
                OFFGRID events emphasize self-reliance, local environmental stewardship, and genuine community collaboration. 
                Please arrive 15 minutes before the scheduled time to check in with the host. Bring suitable clothing, 
                hydration, and an eager mindset to learn and connect with fellow community members.
            </p>

            <h3>Location & Venue Directions</h3>
            <p>
                <strong>City:</strong> <?= e($event['city']) ?><br>
                <strong>Venue:</strong> <?= e($event['location']) ?><br>
                Detailed meeting point directions and host contact information are issued immediately upon registration.
            </p>
        </section>
    </article>

    <!-- Sidebar: Pricing, Capacity Meter, & Booking Call to Action -->
    <aside class="booking-sidebar-card">
        <div class="booking-price-header">
            <div class="label">Registration Fee</div>
            <div class="amount"><?= e(format_price($event['price'])) ?></div>
            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.25rem;">
                Per attendee &bull; Simulated demo checkout
            </div>
        </div>

        <!-- Capacity Meter -->
        <div style="margin-bottom: 1.5rem;">
            <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: var(--text-secondary);">
                <span>Event Capacity</span>
                <strong><?= (int)$event['available_seats'] ?> / <?= (int)$event['total_capacity'] ?> seats open</strong>
            </div>

            <div class="capacity-meter">
                <div 
                    class="capacity-fill <?= $isSoldOut ? 'full' : (($event['available_seats'] <= 3) ? 'low' : '') ?>" 
                    style="width: <?= min(100, max(5, $capacityPercent)) ?>%;"
                ></div>
            </div>
            <small style="color: var(--text-muted); font-size: 0.775rem;">
                <?= $capacityPercent ?>% filled
            </small>
        </div>

        <!-- Dynamic Booking Actions -->
        <?php if ($existingBooking): ?>
            <!-- State 1: User is ALREADY registered -->
            <div class="alert alert-success" style="flex-direction: column; align-items: flex-start; gap: 0.5rem;">
                <div style="font-weight: 700;">✅ You are Registered!</div>
                <div style="font-size: 0.85rem;">
                    Booking Reference: <code><?= e($existingBooking['booking_code']) ?></code><br>
                    Tickets Reserved: <strong><?= (int)$existingBooking['tickets_count'] ?></strong>
                </div>
            </div>
            <a href="booking-confirmation.php?code=<?= urlencode($existingBooking['booking_code']) ?>" class="btn btn-outline btn-block">
                View Digital Pass &rarr;
            </a>

        <?php elseif ($isSoldOut): ?>
            <!-- State 2: Event is SOLD OUT -->
            <button class="btn btn-outline btn-block" disabled style="opacity: 0.6; cursor: not-allowed;">
                Sold Out — No Seats Available
            </button>
            <p style="font-size: 0.8rem; color: var(--text-muted); text-align: center; margin-top: 0.75rem;">
                All slots have been reserved. Check back if attendee plans change.
            </p>

        <?php elseif (!is_logged_in()): ?>
            <!-- State 3: Guest Visitor (Must log in to book) -->
            <a 
                href="login.php?redirect=<?= urlencode('event-details.php?id=' . $event['id']) ?>" 
                class="btn btn-primary btn-block"
            >
                Sign In to Register
            </a>
            <div class="auth-footer" style="margin-top: 0.75rem; font-size: 0.85rem;">
                Don't have an account? <a href="register.php">Create an Account</a>
            </div>

        <?php else: ?>
            <!-- State 4: Logged in & Seats Available -> Proceed to Simulated Booking -->
            <a href="booking.php?event_id=<?= (int)$event['id'] ?>" class="btn btn-primary btn-block">
                Register Now &rarr;
            </a>
            <p style="font-size: 0.8rem; color: var(--text-muted); text-align: center; margin-top: 0.75rem;">
                Simulated demo registration &bull; Instant confirmation
            </p>
        <?php endif; ?>

        <!-- Wishlist / Save Event Action -->
        <form action="saved-events-action.php" method="POST" style="margin-top: 0.75rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="return_url" value="event-details.php?id=<?= (int)$event['id'] ?>">
            <button type="submit" class="btn btn-outline btn-block" style="display:flex;align-items:center;justify-content:center;gap:0.45rem;font-size:0.9rem;" title="<?= $isEventSaved ? 'Remove from Saved Events' : 'Save to Wishlist' ?>">
                <?php if ($isEventSaved): ?>
                    <span style="color:#f43f5e;">❤️</span> Saved to Wishlist (Unsave)
                <?php else: ?>
                    <span>🤍</span> Save to Wishlist
                <?php endif; ?>
            </button>
        </form>

        <!-- Demo Platform Notice -->
        <div class="demo-hint-box" style="margin-top: 1.5rem;">
            <strong>College Demo Notice (India):</strong>
            All event fees are displayed in Indian Rupees (₹ INR). Bookings use simulated payment confirmation; no real money or bank credentials are ever requested.
        </div>
    </aside>
</div>

<!-- ====================================================================
     Community Reviews & Ratings Module
     ==================================================================== -->
<section class="reviews-section" id="reviews">
    <div class="reviews-header">
        <div>
            <h2>Community Reviews & Ratings</h2>
            <p style="color: var(--text-secondary); margin-top: 0.25rem;">
                Verified attendee experiences and feedback from our community.
            </p>
        </div>
        <?php if ($userHasConfirmedBooking && !$userReview): ?>
            <a href="#reviewForm" class="btn btn-primary btn-sm">⭐ Write a Review</a>
        <?php endif; ?>
    </div>

    <!-- Rating Overview & Breakdown Card -->
    <div class="rating-overview-card">
        <div class="rating-overview-grid">
            <!-- Left: Aggregate Score -->
            <div class="rating-score-box">
                <div class="rating-big-num">
                    <?= $ratingStats['total_reviews'] > 0 ? number_format($ratingStats['avg_rating'], 1) : '—' ?>
                </div>
                <div class="rating-stars-gold">
                    <?php
                    $avgFloor = floor($ratingStats['avg_rating']);
                    for ($s = 1; $s <= 5; $s++):
                        echo ($s <= $avgFloor) ? '★' : '☆';
                    endfor;
                    ?>
                </div>
                <div class="rating-count-label">
                    <?= $ratingStats['total_reviews'] ?> <?= $ratingStats['total_reviews'] === 1 ? 'verified review' : 'verified reviews' ?>
                </div>
            </div>

            <!-- Right: 5-Star Breakdown Bars -->
            <div class="rating-breakdown-bars">
                <?php 
                $totalRev = max(1, $ratingStats['total_reviews']);
                for ($star = 5; $star >= 1; $star--):
                    $count = $ratingStats['stars_' . $star];
                    $pct = ($ratingStats['total_reviews'] > 0) ? round(($count / $totalRev) * 100) : 0;
                ?>
                    <div class="breakdown-row">
                        <div class="breakdown-label"><?= $star ?> ★</div>
                        <div class="breakdown-track">
                            <div class="breakdown-fill" style="width: <?= $pct ?>%;"></div>
                        </div>
                        <div class="breakdown-count"><?= $count ?></div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <!-- ================================================================
         Review Submission / Editing Section (Verified Attendees Only)
         ================================================================ -->
    <?php if ($userHasConfirmedBooking): ?>
        <div class="review-form-card" id="reviewForm">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.5rem;">
                <h3 style="font-size: 1.2rem; font-weight: 700; color: var(--text-primary);">
                    <?= $userReview ? '✏️ Edit Your Community Review' : '⭐ Share Your Attendee Experience' ?>
                </h3>
                <span class="verified-attendee-tag">✓ Confirmed Attendee</span>
            </div>

            <form action="review-action.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="submit_review">
                <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">

                <!-- Star Rating Selector (1 to 5) -->
                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label class="form-label">Your Rating (1 to 5 Stars)</label>
                    <div class="rating-input-group" id="starSelector">
                        <?php 
                        $selectedRating = (int)($userReview['rating'] ?? 5);
                        for ($r = 5; $r >= 1; $r--): 
                        ?>
                            <label class="star-radio-label <?= ($selectedRating === $r) ? 'selected' : '' ?>">
                                <input 
                                    type="radio" 
                                    name="rating" 
                                    value="<?= $r ?>" 
                                    <?= ($selectedRating === $r) ? 'checked' : '' ?>
                                    required
                                >
                                <?= str_repeat('★', $r) ?> (<?= $r ?>)
                            </label>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- Review Comment -->
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label for="reviewComment" class="form-label">Review Comment & Community Feedback</label>
                    <textarea 
                        id="reviewComment" 
                        name="comment" 
                        class="form-control" 
                        rows="4" 
                        placeholder="Tell fellow community members what made this gathering memorable, what to bring, and tips for future attendees..."
                        required
                        minlength="5"
                        maxlength="1500"
                    ><?= e($userReview['comment'] ?? '') ?></textarea>
                    <div style="font-size: 0.775rem; color: var(--text-muted); margin-top: 0.35rem;">
                        Minimum 5 characters &bull; Max 1500 characters
                    </div>
                </div>

                <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary">
                        <?= $userReview ? 'Update My Review' : 'Submit Verified Review' ?>
                    </button>
                    <?php if ($userReview): ?>
                        <!-- Delete Review Form -->
                        <form action="review-action.php" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete your review?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_review">
                            <input type="hidden" name="review_id" value="<?= (int)$userReview['id'] ?>">
                            <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                            <button type="submit" class="btn btn-danger-outline btn-sm">
                                🗑️ Remove Review
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </form>
        </div>

    <?php elseif (is_logged_in()): ?>
        <!-- Logged in, but has not booked this event -->
        <div style="background: var(--bg-surface); border: 1px dashed var(--border-subtle); border-radius: var(--radius-lg); padding: 1.5rem 1.75rem; margin-bottom: 2.5rem; display: flex; align-items: center; gap: 1rem;">
            <div style="font-size: 2rem;">🎟️</div>
            <div>
                <strong style="color: var(--text-primary); font-size: 0.95rem;">Verified Attendee Reviews Only</strong>
                <p style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 0.2rem;">
                    To ensure authenticity and trust, only community members with a confirmed reservation for this event may leave a star rating and review.
                </p>
            </div>
        </div>

    <?php else: ?>
        <!-- Guest Visitor Notice -->
        <div style="background: var(--bg-surface); border: 1px dashed var(--border-subtle); border-radius: var(--radius-lg); padding: 1.5rem 1.75rem; margin-bottom: 2.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <div>
                <strong style="color: var(--text-primary); font-size: 0.95rem;">Have you attended this gathering?</strong>
                <p style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 0.2rem;">
                    Sign in to your account to leave a verified attendee review if you registered for this event.
                </p>
            </div>
            <a href="login.php?redirect=<?= urlencode('event-details.php?id=' . $event['id'] . '#reviews') ?>" class="btn btn-outline btn-sm">
                Sign In to Review &rarr;
            </a>
        </div>
    <?php endif; ?>

    <!-- ================================================================
         Approved Reviews List
         ================================================================ -->
    <?php if (!empty($reviewsList)): ?>
        <div class="reviews-list">
            <?php foreach ($reviewsList as $rev): ?>
                <?php 
                $isOwnReview = is_logged_in() && ((int)$rev['user_id'] === (int)current_user()['id']);
                $initial = strtoupper(mb_substr($rev['user_name'], 0, 1));
                ?>
                <div class="review-item-card" <?= $isOwnReview ? 'style="border-color: rgba(16, 185, 129, 0.4);"' : '' ?>>
                    <div class="review-item-top">
                        <div class="review-author-info">
                            <div class="review-avatar"><?= e($initial) ?></div>
                            <div>
                                <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                    <span class="review-author-name"><?= e($rev['user_name']) ?></span>
                                    <span class="verified-attendee-tag">✓ Verified Attendee</span>
                                    <?php if ($isOwnReview): ?>
                                        <span class="badge-category" style="font-size: 0.65rem; background: var(--brand-accent-glow); color: var(--brand-accent);">
                                            Your Review
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="review-date">
                                    <?= e(date('d F Y', strtotime($rev['created_at']))) ?>
                                    <?php if (!empty($rev['updated_at']) && $rev['updated_at'] !== $rev['created_at']): ?>
                                        &bull; <em>(Edited)</em>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Review Stars -->
                        <div class="review-stars">
                            <?php
                            $rVal = (int)$rev['rating'];
                            echo str_repeat('★', $rVal) . str_repeat('☆', 5 - $rVal);
                            ?>
                        </div>
                    </div>

                    <!-- Review Text -->
                    <div class="review-comment-text">
                        <?= nl2br(e($rev['comment'])) ?>
                    </div>

                    <?php if ($isOwnReview): ?>
                        <div style="margin-top: 1rem; padding-top: 0.75rem; border-top: 1px dashed var(--border-subtle); display: flex; gap: 0.5rem;">
                            <a href="#reviewForm" class="btn btn-outline btn-sm" style="padding: 0.25rem 0.6rem; font-size: 0.75rem;">
                                ✏️ Edit
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

    <?php else: ?>
        <!-- Empty Reviews State -->
        <div class="empty-state" style="background: var(--bg-surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 3rem 2rem; text-align: center;">
            <div style="font-size: 2.75rem; margin-bottom: 0.75rem;">⭐</div>
            <h3 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 0.35rem;">No Reviews Yet</h3>
            <p style="color: var(--text-secondary); max-width: 500px; margin: 0 auto;">
                Be the first verified attendee to share your experience from this gathering once you have registered!
            </p>
        </div>
    <?php endif; ?>
</section>

<!-- Vanilla JS Star Rating Radio Selector Visual Sync -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var starLabels = document.querySelectorAll('.star-radio-label');
    starLabels.forEach(function(label) {
        var radio = label.querySelector('input[type="radio"]');
        if (radio) {
            radio.addEventListener('change', function() {
                starLabels.forEach(function(l) { l.classList.remove('selected'); });
                if (this.checked) {
                    label.classList.add('selected');
                }
            });
        }
    });
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>

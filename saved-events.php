<?php
/**
 * OFFGRID - Saved Events (Wishlist) Dashboard
 * 
 * Member-only hub presenting all events bookmarked by the authenticated user.
 * Features:
 * - Strict require_login() access guard with safe guest redirect
 * - Verified ownership enforcement on all queries
 * - Real-time seat capacity tracking and Sold Out status detection
 * - Immediate "Remove from Saved" and "Book Now" actions
 * - CSRF-guarded removal requests
 * - Full Indian localization (INR ₹, IST timestamps, regional cities)
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// Strict Authorization Guard: Logged-in members only
require_login('login.php');

$currentUser = current_user();
$userId = (int)$currentUser['id'];
$page_title = 'My Saved Events';

$savedEvents = [];

try {
    $stmt = $pdo->prepare('
        SELECT 
            e.id, 
            e.title, 
            e.category, 
            e.description, 
            e.city, 
            e.location, 
            e.event_date, 
            e.event_time, 
            e.price, 
            e.total_capacity, 
            e.available_seats, 
            e.image, 
            e.status,
            s.created_at AS saved_at
        FROM saved_events s
        JOIN events e ON s.event_id = e.id
        WHERE s.user_id = ?
        ORDER BY s.created_at DESC
    ');
    $stmt->execute([$userId]);
    $savedEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('[OFFGRID SAVED EVENTS QUERY ERROR] ' . $e->getMessage());
    set_flash('error', 'Unable to retrieve your saved events. Please try again.');
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Saved Events Page Header -->
<div class="admin-header" style="border-bottom: 1px solid var(--border-subtle); margin-bottom: 2.25rem; padding-bottom: 1.25rem;">
    <div class="admin-title">
        <span class="user-badge" style="display: inline-flex; margin-bottom: 0.5rem; border-color: var(--brand-accent);">
            <span class="brand-dot"></span> Personal Wishlist &bull; <?= count($savedEvents) ?> <?= count($savedEvents) === 1 ? 'Gathering Bookmarked' : 'Gatherings Bookmarked' ?>
        </span>
        <h1 style="font-size: 1.85rem; font-weight: 800; color: var(--text-primary);">My Saved Events</h1>
        <p style="color: var(--text-secondary); font-size: 0.95rem; margin-top: 0.25rem;">
            Keep track of upcoming gatherings you're interested in attending. Reserve your pass early before available seats fill up.
        </p>
    </div>
    <div>
        <a href="index.php" class="btn btn-outline btn-sm">
            &larr; Discover More Events
        </a>
    </div>
</div>

<?php if (!empty($savedEvents)): ?>
    <section class="events-grid">
        <?php foreach ($savedEvents as $event): ?>
            <?php
            $isSoldOut = ($event['available_seats'] <= 0 || $event['status'] === 'completed' || $event['status'] === 'cancelled');
            $eventDateFormatted = format_event_date($event['event_date']);
            $eventTimeFormatted = format_event_time($event['event_time']);
            $priceFormatted = format_price($event['price']);
            $imageUrl = get_event_image_url($event['image'] ?? null);
            $savedDateFormatted = date('d M Y', strtotime($event['saved_at']));
            ?>
            <article class="event-card <?= $isSoldOut ? 'is-soldout' : '' ?>">
                <!-- Cover Image & City Badge -->
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
                                <input type="hidden" name="action" value="unsave">
                                <input type="hidden" name="return_url" value="saved-events.php">
                                <button type="submit" class="bookmark-form-btn is-saved" title="Remove from Saved Events" aria-label="Remove from Saved Events">
                                    ❤️
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Header: Category & Availability -->
                <div class="event-card-header">
                    <span class="badge-category"><?= e($event['category']) ?></span>
                    <?php if ($event['status'] === 'cancelled'): ?>
                        <span class="badge-status-cancelled" style="font-size: 0.725rem;">Cancelled</span>
                    <?php elseif ($isSoldOut): ?>
                        <span class="badge-soldout" style="padding: 0.15rem 0.5rem; font-size: 0.7rem;">Sold Out</span>
                    <?php else: ?>
                        <span class="seats-badge <?= ($event['available_seats'] <= 3) ? 'low' : '' ?>" style="font-size: 0.775rem;">
                            <?= (int)$event['available_seats'] ?> <?= $event['available_seats'] === 1 ? 'seat' : 'seats' ?> left
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Body -->
                <div class="event-card-body">
                    <h2 class="event-card-title">
                        <a href="event-details.php?id=<?= (int)$event['id'] ?>" style="color: inherit; text-decoration: none;">
                            <?= e($event['title']) ?>
                        </a>
                    </h2>

                    <p class="event-card-desc">
                        <?= e(mb_strimwidth($event['description'], 0, 120, '...')) ?>
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
                        <li class="event-meta-item" style="font-size: 0.775rem; color: var(--text-muted); margin-top: 0.25rem;">
                            <span class="meta-icon">🔖</span>
                            <span>Saved on <?= e($savedDateFormatted) ?></span>
                        </li>
                    </ul>
                </div>

                <!-- Footer Actions -->
                <div class="event-card-footer" style="gap: 0.5rem; flex-wrap: wrap;">
                    <div>
                        <span style="font-size: 0.725rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; display: block;">Registration Fee</span>
                        <div style="font-size: 1.05rem; font-weight: 800; color: var(--brand-accent);">
                            <?= e($priceFormatted) ?>
                        </div>
                    </div>

                    <div style="display: flex; gap: 0.4rem; align-items: center;">
                        <form action="saved-events-action.php" method="POST" style="margin: 0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                            <input type="hidden" name="action" value="unsave">
                            <input type="hidden" name="return_url" value="saved-events.php">
                            <button type="submit" class="btn btn-outline btn-sm" style="font-size: 0.775rem; padding: 0.45rem 0.65rem;" title="Remove">
                                &times; Remove
                            </button>
                        </form>

                        <a href="event-details.php?id=<?= (int)$event['id'] ?>" class="btn <?= $isSoldOut ? 'btn-outline' : 'btn-primary' ?> btn-sm">
                            <?= $isSoldOut ? 'View Details' : 'Book Pass &rarr;' ?>
                        </a>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
<?php else: ?>
    <!-- Friendly Empty State -->
    <div class="empty-state" style="padding: 4rem 1.5rem; text-align: center;">
        <div style="font-size: 3.5rem; margin-bottom: 1rem;">🔖</div>
        <h3 style="font-size: 1.4rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">
            No Saved Events Yet
        </h3>
        <p style="color: var(--text-secondary); max-width: 480px; margin: 0 auto 1.75rem auto; line-height: 1.6;">
            You haven't bookmarked any community gatherings. Explore upcoming bushcraft camps, solar workshops, heritage walks, and live acoustic nights across Indian cities and tap the bookmark button to save gatherings you love!
        </p>
        <a href="index.php" class="btn btn-primary">
            Explore All Upcoming Gatherings &rarr;
        </a>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

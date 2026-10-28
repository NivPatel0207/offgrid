<?php
/**
 * OFFGRID - Review Action Handler
 * 
 * Handles community review submissions, edits, and deletions with strict server-side eligibility checks:
 * - Requires authenticated user (require_login)
 * - Requires valid CSRF security token
 * - Strictly enforces eligibility: Only users with confirmed event bookings may review
 * - Prohibits reviews from users with cancelled bookings or no booking records
 * - Enforces 1 to 5 integer ratings and minimum review comment length
 * - Implements upsert logic (insert or edit existing review)
 * - Authorizes deletions strictly to review owner or platform administrator
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

require_login('login.php');

$currentUser = current_user();

// Reject non-POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    set_flash('error', 'Invalid request method. Reviews must be submitted via POST.');
    redirect('index.php');
}

// Verify CSRF Security Token
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash('error', 'Security token expired or invalid. Please try again.');
    redirect('index.php');
}

$action = trim($_POST['action'] ?? 'submit_review');

if ($action === 'submit_review') {
    $eventId = (int)($_POST['event_id'] ?? 0);
    $rating  = (int)($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if ($eventId <= 0) {
        set_flash('error', 'Invalid event identifier.');
        redirect('index.php');
    }

    // 1. Verify Event Exists and is Active
    try {
        $evStmt = $pdo->prepare('SELECT id, title, status FROM events WHERE id = ?');
        $evStmt->execute([$eventId]);
        $event = $evStmt->fetch();

        if (!$event) {
            set_flash('error', 'Event not found.');
            redirect('index.php');
        }
    } catch (PDOException $e) {
        error_log('[OFFGRID REVIEW EVENT CHECK ERROR] ' . $e->getMessage());
        set_flash('error', 'Database error checking event.');
        redirect('index.php');
    }

    // 2. Strict Server-Side Eligibility Enforcement:
    // Only users with a confirmed booking for this event may review
    try {
        $bStmt = $pdo->prepare('
            SELECT id, booking_code, payment_status 
            FROM bookings 
            WHERE user_id = ? AND event_id = ? AND payment_status = "confirmed"
            LIMIT 1
        ');
        $bStmt->execute([$currentUser['id'], $eventId]);
        $confirmedBooking = $bStmt->fetch();

        if (!$confirmedBooking) {
            set_flash('error', 'Eligibility check failed: Only verified attendees with a confirmed booking may leave a review for this gathering.');
            redirect('event-details.php?id=' . $eventId . '#reviews');
        }
    } catch (PDOException $e) {
        error_log('[OFFGRID REVIEW ELIGIBILITY CHECK ERROR] ' . $e->getMessage());
        set_flash('error', 'An error occurred while verifying your attendance eligibility.');
        redirect('event-details.php?id=' . $eventId . '#reviews');
    }

    // 3. Validate Rating: Must be an integer between 1 and 5
    if ($rating < 1 || $rating > 5) {
        set_flash('error', 'Rating must be a whole number between 1 and 5 stars.');
        redirect('event-details.php?id=' . $eventId . '#reviews');
    }

    // 4. Validate Review Comment
    if (mb_strlen($comment) < 5) {
        set_flash('error', 'Please provide a helpful review comment of at least 5 characters.');
        redirect('event-details.php?id=' . $eventId . '#reviews');
    } elseif (mb_strlen($comment) > 1500) {
        set_flash('error', 'Review comments are limited to 1500 characters.');
        redirect('event-details.php?id=' . $eventId . '#reviews');
    }

    // 5. Upsert: Check if this user already reviewed this event
    try {
        $chkRev = $pdo->prepare('SELECT id FROM reviews WHERE user_id = ? AND event_id = ? LIMIT 1');
        $chkRev->execute([$currentUser['id'], $eventId]);
        $existingReview = $chkRev->fetch();

        if ($existingReview) {
            // Update existing review (resets status to 'approved')
            $updStmt = $pdo->prepare('
                UPDATE reviews 
                SET rating = ?, comment = ?, status = "approved", updated_at = NOW() 
                WHERE id = ? AND user_id = ?
            ');
            $updStmt->execute([$rating, $comment, $existingReview['id'], $currentUser['id']]);

            set_flash('success', 'Your review for "' . $event['title'] . '" has been updated.');
        } else {
            // Insert new review
            $insStmt = $pdo->prepare('
                INSERT INTO reviews (event_id, user_id, rating, comment, status) 
                VALUES (?, ?, ?, ?, "approved")
            ');
            $insStmt->execute([$eventId, $currentUser['id'], $rating, $comment]);

            set_flash('success', 'Thank you! Your verified community review has been posted.');
        }

    } catch (PDOException $e) {
        error_log('[OFFGRID REVIEW SUBMISSION ERROR] ' . $e->getMessage());
        set_flash('error', 'An unexpected error occurred while saving your review.');
    }

    redirect('event-details.php?id=' . $eventId . '#reviews');

} elseif ($action === 'delete_review') {
    $reviewId = (int)($_POST['review_id'] ?? 0);
    $eventId  = (int)($_POST['event_id'] ?? 0);

    if ($reviewId <= 0) {
        set_flash('error', 'Invalid review identifier.');
        redirect('index.php');
    }

    try {
        $chkStmt = $pdo->prepare('SELECT id, user_id, event_id FROM reviews WHERE id = ?');
        $chkStmt->execute([$reviewId]);
        $revRow = $chkStmt->fetch();

        if (!$revRow) {
            set_flash('error', 'Review not found.');
            redirect('index.php');
        }

        // Authorization check: Must be the review author OR an administrator
        if ((int)$revRow['user_id'] !== (int)$currentUser['id'] && !is_admin()) {
            http_response_code(403);
            set_flash('error', 'Access Denied: You are not authorized to delete this review.');
            redirect('event-details.php?id=' . (int)$revRow['event_id'] . '#reviews');
        }

        $delStmt = $pdo->prepare('DELETE FROM reviews WHERE id = ?');
        $delStmt->execute([$reviewId]);

        set_flash('success', 'Your review has been removed.');
        redirect('event-details.php?id=' . (int)$revRow['event_id'] . '#reviews');

    } catch (PDOException $e) {
        error_log('[OFFGRID REVIEW DELETE ERROR] ' . $e->getMessage());
        set_flash('error', 'An error occurred while removing the review.');
        redirect('index.php');
    }

} else {
    set_flash('error', 'Unknown review action.');
    redirect('index.php');
}

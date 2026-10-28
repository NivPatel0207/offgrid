<?php
/**
 * OFFGRID - Safe Event Delete & Archive Handler
 * 
 * Secure POST-only endpoint for handling event removal or status archiving:
 * - Strictly guarded by require_admin()
 * - Strictly requires valid CSRF token
 * - Never deletes an event with active or past bookings (sets status to 'cancelled' to preserve attendee passes)
 * - Safely hard-deletes events with 0 bookings
 * - Prevents deleting shared or default images
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Strict Authorization Guard
require_admin();

// Enforce POST Request Only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    set_flash('error', 'Invalid request method. Event deletion must be submitted via POST.');
    redirect('events.php');
}

// Verify CSRF Token
$csrfToken = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($csrfToken)) {
    set_flash('error', 'Security token expired or invalid (CSRF). Action aborted.');
    redirect('events.php');
}

// Validate Event ID
$eventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
if (!$eventId || $eventId <= 0) {
    set_flash('error', 'Invalid event ID specified for deletion.');
    redirect('events.php');
}

try {
    // 1. Fetch event and check existing bookings
    $stmt = $pdo->prepare('
        SELECT e.id, e.title, e.image, e.status,
               COUNT(b.id) AS bookings_count,
               COALESCE(SUM(b.tickets_count), 0) AS total_tickets
        FROM events e
        LEFT JOIN bookings b ON e.id = b.event_id
        WHERE e.id = ?
        GROUP BY e.id
        LIMIT 1
    ');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if (!$event) {
        set_flash('error', 'Event not found or has already been removed.');
        redirect('events.php');
    }

    $bookingsCount = (int)$event['bookings_count'];
    $eventTitle = $event['title'];
    $imageFile = $event['image'];

    // 2. Branch: If Event HAS Bookings -> ARCHIVE / CANCEL (DO NOT DELETE)
    if ($bookingsCount > 0) {
        $cancelStmt = $pdo->prepare("UPDATE events SET status = 'cancelled' WHERE id = ?");
        $cancelStmt->execute([$eventId]);

        set_flash(
            'info', 
            'Event "' . $eventTitle . '" has ' . $bookingsCount . ' attendee reservation(s). ' .
            'To protect attendee ticket passes and historical receipts, the event was marked as Cancelled (Inactive) instead of being deleted.'
        );
        redirect('events.php');
    }

    // 3. Branch: If Event HAS ZERO Bookings -> SAFE HARD DELETE
    $pdo->beginTransaction();

    $deleteStmt = $pdo->prepare('DELETE FROM events WHERE id = ?');
    $deleteStmt->execute([$eventId]);

    $pdo->commit();

    // Clean up uploaded image if it is unique and not default
    if (!empty($imageFile) && $imageFile !== 'default-event.jpg') {
        // Check if any other event is referencing the same image file
        $refStmt = $pdo->prepare('SELECT COUNT(*) FROM events WHERE image = ?');
        $refStmt->execute([$imageFile]);
        $otherRefs = (int)$refStmt->fetchColumn();

        if ($otherRefs === 0) {
            $imagePath = __DIR__ . '/../assets/images/events/' . basename($imageFile);
            if (file_exists($imagePath) && is_file($imagePath)) {
                @unlink($imagePath);
            }
        }
    }

    set_flash('success', 'Event "' . $eventTitle . '" was permanently removed since it had 0 registered bookings.');
    redirect('events.php');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[OFFGRID EVENT DELETE ERROR] ' . $e->getMessage());
    set_flash('error', 'An error occurred while processing the event action: ' . $e->getMessage());
    redirect('events.php');
}

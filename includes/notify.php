<?php
/**
 * OFFGRID - Notification Helper
 *
 * Provides a single create_notification() function used throughout the
 * application to insert user notifications safely.
 *
 * Duplicate prevention: the notifications table has a UNIQUE KEY on
 * (user_id, type, ref_id), so INSERT IGNORE silently skips retries.
 *
 * Dependencies: $pdo (PDO) must be in scope or passed explicitly.
 */

/**
 * Creates a notification for a user.
 * Safe to call multiple times for the same (user_id, type, ref_id) —
 * duplicates are silently ignored by INSERT IGNORE.
 *
 * @param PDO    $pdo      Active PDO connection.
 * @param int    $userId   Target user ID.
 * @param string $type     One of: booking_confirmed, booking_cancelled,
 *                          event_cancelled, event_updated, event_reminder.
 * @param string $title    Short notification heading (max ~200 chars).
 * @param string $message  Full notification body text.
 * @param int|null $refId  Related booking.id or event.id (for deduplication).
 * @param string|null $link Relative URL for "View" action (e.g. booking-confirmation.php?code=X).
 * @return bool            True if inserted, false if duplicate or error.
 */
function create_notification(
    PDO    $pdo,
    int    $userId,
    string $type,
    string $title,
    string $message,
    ?int   $refId = null,
    ?string $link  = null
): bool {
    $allowedTypes = [
        'booking_confirmed',
        'booking_cancelled',
        'event_cancelled',
        'event_updated',
        'event_reminder',
    ];

    if (!in_array($type, $allowedTypes, true)) {
        error_log("[OFFGRID NOTIFY] Unknown notification type: $type");
        return false;
    }

    try {
        $stmt = $pdo->prepare('
            INSERT IGNORE INTO notifications
                (user_id, type, title, message, ref_id, link, is_read, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, 0, NOW())
        ');
        $stmt->execute([$userId, $type, $title, $message, $refId, $link]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log('[OFFGRID NOTIFY CREATE] ' . $e->getMessage());
        return false;
    }
}

/**
 * Returns the unread notification count for a given user.
 *
 * @param PDO $pdo    Active PDO connection.
 * @param int $userId Target user ID.
 * @return int
 */
function get_unread_notification_count(PDO $pdo, int $userId): int {
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[OFFGRID NOTIFY COUNT] ' . $e->getMessage());
        return 0;
    }
}

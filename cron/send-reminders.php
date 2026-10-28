<?php
/**
 * OFFGRID - Upcoming Event Reminder Script
 * =========================================
 * CLI-only script (must NOT be web-accessible).
 * Run manually or schedule via Windows Task Scheduler.
 *
 * What it does
 * ------------
 * Finds confirmed bookings whose event_date is 1, 3, or 7 days from today
 * and inserts an event_reminder notification for each eligible user.
 *
 * Duplicate prevention
 * --------------------
 * The notifications table has a UNIQUE KEY on (user_id, type, ref_id).
 * ref_id is set to the booking.id, so INSERT IGNORE ensures each booking
 * receives at most one reminder regardless of how many times this script runs.
 *
 * How to schedule (Windows Task Scheduler)
 * -----------------------------------------
 * 1. Open Task Scheduler → Create Basic Task
 * 2. Name: "OFFGRID Event Reminders"
 * 3. Trigger: Daily at 08:00 AM
 * 4. Action: Start a program
 *    Program: C:\xampp\php\php.exe
 *    Arguments: C:\xampp\htdocs\usha pro\cron\send-reminders.php
 * 5. Run whether user is logged on or not
 *
 * Notes
 * -----
 * - XAMPP Apache does NOT need to be running for this script.
 * - Reminders do NOT run automatically unless Task Scheduler is configured.
 * - Notifications appear in the user's Notification Center (/notifications.php).
 */

// ── CLI Guard ─────────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "403 Forbidden: This script may only be run from the command line.\n";
    exit(1);
}

date_default_timezone_set('Asia/Kolkata');

// ── Bootstrap ─────────────────────────────────────────────────────────────
$rootDir = dirname(__DIR__); // project root (one level up from cron/)
require_once $rootDir . '/config/db.php';
require_once $rootDir . '/includes/notify.php';

echo "[OFFGRID REMINDER] Starting at " . date('Y-m-d H:i:s T') . "\n";

// ── Reminder windows (days before event) ─────────────────────────────────
$reminderDays = [1, 3, 7];

$totalInserted = 0;
$totalSkipped  = 0;

foreach ($reminderDays as $daysBefore) {
    $targetDate = date('Y-m-d', strtotime("+{$daysBefore} days"));

    echo "[OFFGRID REMINDER] Checking events on {$targetDate} ({$daysBefore} day(s) away)…\n";

    try {
        // Fetch all confirmed bookings for events on the target date
        // Only notify active (non-suspended) users
        $stmt = $pdo->prepare("
            SELECT 
                b.id         AS booking_id,
                b.user_id,
                b.booking_code,
                b.tickets_count,
                e.id         AS event_id,
                e.title      AS event_title,
                e.city,
                e.location,
                e.event_date,
                e.event_time,
                u.name       AS user_name,
                u.status     AS user_status
            FROM bookings b
            JOIN events   e ON b.event_id = e.id
            JOIN users    u ON b.user_id  = u.id
            WHERE b.payment_status = 'confirmed'
              AND e.status         = 'active'
              AND e.event_date     = ?
              AND u.status         = 'active'
            ORDER BY b.user_id
        ");
        $stmt->execute([$targetDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $eventDateFormatted = date('D, d M Y', strtotime($row['event_date']));
            $eventTimeFormatted = date('g:i A', strtotime($row['event_time']));

            $title = "⏰ Reminder: \"{$row['event_title']}\" is in {$daysBefore} day(s)";
            $message = "Hi {$row['user_name']}, don't forget your upcoming booking!\n"
                . "Event: {$row['event_title']}\n"
                . "Date:  {$eventDateFormatted} at {$eventTimeFormatted} IST\n"
                . "Venue: {$row['location']}, {$row['city']}\n"
                . "Booking: {$row['booking_code']} ({$row['tickets_count']} ticket(s))";

            $link = "booking-confirmation.php?code=" . urlencode($row['booking_code']);

            $inserted = create_notification(
                $pdo,
                (int)$row['user_id'],
                'event_reminder',
                $title,
                $message,
                (int)$row['booking_id'],
                $link
            );

            if ($inserted) {
                $totalInserted++;
                echo "  ✔ Reminder created for user #{$row['user_id']} → booking {$row['booking_code']}\n";
            } else {
                $totalSkipped++;
                echo "  ↷ Skipped (duplicate) for user #{$row['user_id']} → booking {$row['booking_code']}\n";
            }
        }

    } catch (PDOException $e) {
        echo "[OFFGRID REMINDER ERROR] {$e->getMessage()}\n";
        error_log('[OFFGRID REMINDER] ' . $e->getMessage());
    }
}

echo "[OFFGRID REMINDER] Done. Inserted: {$totalInserted} | Skipped (duplicates): {$totalSkipped}\n";

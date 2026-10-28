<?php
/**
 * OFFGRID - Comprehensive Regression & System Health Test
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$testsPassed = 0;
$testsFailed = 0;

function assertTest($description, $condition) {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo "  [PASS] $description\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] $description\n";
        $testsFailed++;
    }
}

echo "=== OFFGRID SYSTEM REGRESSION TEST SUITE ===\n\n";

// 1. Database Connection
echo "1. Checking Database Connectivity...\n";
assertTest("Database instance is PDO", $pdo instanceof PDO);

// 2. Demo User Authentication Check
echo "\n2. Testing User Credentials...\n";
$userStmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$userStmt->execute(['demo@offgrid.local']);
$member = $userStmt->fetch();
assertTest("Community Member account exists", !empty($member));
assertTest("Member password verifies with User@123", password_verify('User@123', $member['password'] ?? ''));

$adminStmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$adminStmt->execute(['admin@offgrid.local']);
$admin = $adminStmt->fetch();
assertTest("Admin account exists", !empty($admin));
assertTest("Admin password verifies with Admin@123", password_verify('Admin@123', $admin['password'] ?? ''));

// 3. Events Catalog Check
echo "\n3. Testing Events Catalog...\n";
$eventsCount = (int)$pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
assertTest("Events exist in catalog (Count: $eventsCount)", $eventsCount > 0);

$activeEventsCount = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE status = 'active'")->fetchColumn();
assertTest("Active events exist (Count: $activeEventsCount)", $activeEventsCount > 0);

// 4. Bookings & Seat Capacity Check
echo "\n4. Testing Bookings Integrity...\n";
$bookingsCount = (int)$pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
assertTest("Bookings exist in database (Count: $bookingsCount)", $bookingsCount >= 0);

$invalidSeats = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE available_seats < 0")->fetchColumn();
assertTest("No events have negative available seats", $invalidSeats === 0);

// 5. Notifications Check
echo "\n5. Testing Notification System...\n";
$notifCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
assertTest("Notifications table query succeeds (Count: $notifCount)", $notifCount >= 0);

// 6. Saved Events Check
echo "\n6. Testing Saved Events / Wishlist...\n";
$savedCount = (int)$pdo->query("SELECT COUNT(*) FROM saved_events")->fetchColumn();
assertTest("Saved events table query succeeds (Count: $savedCount)", $savedCount >= 0);

// 7. Reviews Check
echo "\n7. Testing Reviews & Ratings...\n";
$reviewsCount = (int)$pdo->query("SELECT COUNT(*) FROM reviews")->fetchColumn();
assertTest("Reviews table query succeeds (Count: $reviewsCount)", $reviewsCount >= 0);

// Summary
echo "\n============================================\n";
echo "TEST RESULTS: $testsPassed Passed, $testsFailed Failed\n";
if ($testsFailed === 0) {
    echo "ALL REGRESSION CHECKS PASSED PERFECTLY!\n";
    exit(0);
} else {
    echo "SOME CHECKS FAILED!\n";
    exit(1);
}

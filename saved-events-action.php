<?php
/**
 * OFFGRID - Saved Events (Wishlist) Action Controller
 * 
 * Handles adding, removing, and toggling saved events for authenticated members.
 * Features:
 * - Guest access control with clear sign-in prompts
 * - Strict CSRF verification on all POST requests
 * - Ownership verification and duplicate prevention via unique database constraint
 * - Safe relative redirect or structured JSON response for AJAX requests
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

init_session();

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

// 1. Authorization Guard: Require logged-in member
if (!is_logged_in()) {
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success'  => false,
            'message'  => 'Please log in to save events to your wishlist.',
            'redirect' => 'login.php'
        ]);
        exit;
    }

    set_flash('error', 'Please log in to save events to your wishlist.');
    $eventId = (int)($_POST['event_id'] ?? $_GET['event_id'] ?? 0);
    $redir = ($eventId > 0) ? 'event-details.php?id=' . $eventId : 'index.php';
    redirect('login.php?redirect=' . urlencode($redir));
}

enforce_not_suspended();
$currentUser = current_user();
$userId = (int)$currentUser['id'];

// 2. Validate HTTP Request Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    set_flash('error', 'Invalid request method.');
    redirect('saved-events.php');
}

// 3. CSRF Verification
$csrfToken = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($csrfToken)) {
    if ($isAjax) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'message' => 'Security token expired or invalid. Please refresh the page.'
        ]);
        exit;
    }

    set_flash('error', 'Security token expired or invalid. Please try again.');
    redirect('saved-events.php');
}

// 4. Validate Event ID
$eventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
if (!$eventId || $eventId <= 0) {
    if ($isAjax) {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'Invalid event identifier.']);
        exit;
    }

    set_flash('error', 'Invalid event selected.');
    redirect('index.php');
}

// 5. Verify Event Exists in Database
try {
    $eStmt = $pdo->prepare('SELECT id, title FROM events WHERE id = ? LIMIT 1');
    $eStmt->execute([$eventId]);
    $targetEvent = $eStmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetEvent) {
        if ($isAjax) {
            http_response_code(404);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'message' => 'Event not found.']);
            exit;
        }

        set_flash('error', 'Selected event does not exist.');
        redirect('index.php');
    }
} catch (PDOException $e) {
    error_log('[OFFGRID SAVED EVENT VERIFY ERROR] ' . $e->getMessage());
    set_flash('error', 'Database communication error. Please try again.');
    redirect('index.php');
}

// 6. Perform Save / Unsave Action
$action = trim($_POST['action'] ?? 'toggle');
$isSaved = false;
$msg = '';

try {
    // Check if event is currently saved by this user
    $checkStmt = $pdo->prepare('SELECT id FROM saved_events WHERE user_id = ? AND event_id = ? LIMIT 1');
    $checkStmt->execute([$userId, $eventId]);
    $alreadySaved = (bool)$checkStmt->fetchColumn();

    if ($action === 'unsave' || ($action === 'toggle' && $alreadySaved)) {
        // Remove from saved events
        $delStmt = $pdo->prepare('DELETE FROM saved_events WHERE user_id = ? AND event_id = ?');
        $delStmt->execute([$userId, $eventId]);
        $isSaved = false;
        $msg = 'Event removed from your saved list.';
    } else {
        // Add to saved events (INSERT IGNORE or catch unique constraint)
        $insStmt = $pdo->prepare('INSERT IGNORE INTO saved_events (user_id, event_id) VALUES (?, ?)');
        $insStmt->execute([$userId, $eventId]);
        $isSaved = true;
        $msg = 'Event saved to your wishlist! View it anytime under Saved Events.';
    }

} catch (PDOException $e) {
    error_log('[OFFGRID SAVED EVENT MUTATION ERROR] ' . $e->getMessage());
    if ($isAjax) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'Failed to update saved event.']);
        exit;
    }

    set_flash('error', 'Unable to update saved event.');
    redirect('index.php');
}

// 7. Output Response
if ($isAjax) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success'  => true,
        'saved'    => $isSaved,
        'message'  => $msg,
        'eventId'  => $eventId
    ]);
    exit;
}

set_flash('success', $msg);

// Safe return URL validation (must be relative path, no open redirects)
$returnUrl = trim($_POST['return_url'] ?? '');
if (!empty($returnUrl) && preg_match('#^[a-zA-Z0-9_\-\./\?=&]+$#', $returnUrl) && 
    strpos($returnUrl, '://') === false && substr($returnUrl, 0, 2) !== '//') {
    redirect($returnUrl);
}

redirect('saved-events.php');

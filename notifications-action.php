<?php
/**
 * OFFGRID - Notification Action Handler
 *
 * Processes POST actions for the notification center:
 *   mark_read      – Mark a single notification as read (ownership verified).
 *   mark_all_read  – Mark all of the authenticated user's notifications as read.
 *
 * Security:
 * - require_login() + enforce_not_suspended()
 * - CSRF token on every POST
 * - Ownership enforced: WHERE user_id = current user
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

require_login('login.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('notifications.php');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash('error', 'Security token expired. Please try again.');
    redirect('notifications.php');
}

$currentUser = current_user();
$userId      = (int)$currentUser['id'];
$action      = trim($_POST['action'] ?? '');

try {
    if ($action === 'mark_read') {
        $notifId = (int)($_POST['notif_id'] ?? 0);
        if ($notifId <= 0) {
            set_flash('error', 'Invalid notification reference.');
            redirect('notifications.php');
        }
        // Ownership: WHERE user_id = ? ensures the user can only mark their own
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        $stmt->execute([$notifId, $userId]);

    } elseif ($action === 'mark_all_read') {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        set_flash('success', 'All notifications marked as read.');

    } else {
        set_flash('error', 'Unknown action.');
    }
} catch (PDOException $e) {
    error_log('[OFFGRID NOTIF ACTION] ' . $e->getMessage());
    set_flash('error', 'A database error occurred.');
}

// Redirect back, preserving any filter params
$ref = $_SERVER['HTTP_REFERER'] ?? 'notifications.php';
// Safety: only allow redirects within same host
$parsedRef = parse_url($ref);
if (!empty($parsedRef['host']) && $parsedRef['host'] !== $_SERVER['HTTP_HOST']) {
    $ref = 'notifications.php';
}
redirect($ref);

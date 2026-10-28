<?php
/**
 * OFFGRID - Admin User Action Handler
 *
 * Processes all user management state-change POST requests:
 * - suspend     : Set user status = 'suspended' (blocks login)
 * - reactivate  : Set user status = 'active'
 * - promote     : Set user role = 'admin'
 * - demote      : Set user role = 'user'
 *
 * Safety Guards:
 * - Admin cannot act on their own account
 * - Demote/suspend actions check for last active admin
 * - Strict CSRF verification on every request
 * - All IDs validated as positive integers
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();

// Accept POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('users.php');
}

// 1. CSRF verification
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash('error', 'Security token expired or invalid. Please try again.');
    redirect('users.php');
}

$currentUser = current_user();
$action      = trim($_POST['action']  ?? '');
$targetId    = (int)($_POST['user_id'] ?? 0);

// 2. Validate target ID
if ($targetId <= 0) {
    set_flash('error', 'Invalid user identifier.');
    redirect('users.php');
}

// 3. Prevent self-action
if ($targetId === (int)$currentUser['id']) {
    set_flash('error', 'You cannot modify your own account from this panel.');
    redirect('users.php');
}

// 4. Load target user from database
try {
    $stmt = $pdo->prepare('SELECT id, name, email, role, status FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[OFFGRID ADMIN USER ACTION] ' . $e->getMessage());
    set_flash('error', 'Database error loading user record.');
    redirect('users.php');
}

if (!$target) {
    set_flash('error', 'User not found.');
    redirect('users.php');
}

/**
 * Helper: count currently active administrators (excluding the target user).
 */
function count_active_admins_except(PDO $pdo, int $excludeId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?");
    $stmt->execute([$excludeId]);
    return (int)$stmt->fetchColumn();
}

// ── Process Action ────────────────────────────────────────────────────────────
try {
    switch ($action) {

        case 'suspend':
            // Cannot suspend another admin unless at least one other active admin remains
            if ($target['role'] === 'admin') {
                $remaining = count_active_admins_except($pdo, $targetId);
                if ($remaining < 1) {
                    set_flash('error', 'Cannot suspend the only active administrator. Promote another admin first.');
                    redirect('users.php');
                }
            }
            $stmt = $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ?");
            $stmt->execute([$targetId]);
            set_flash('success', e($target['name']) . '\'s account has been suspended. They can no longer log in.');
            break;

        case 'reactivate':
            $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
            $stmt->execute([$targetId]);
            set_flash('success', e($target['name']) . '\'s account has been reactivated.');
            break;

        case 'promote':
            if ($target['role'] === 'admin') {
                set_flash('error', e($target['name']) . ' is already an administrator.');
                redirect('users.php');
            }
            $stmt = $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
            $stmt->execute([$targetId]);
            set_flash('success', e($target['name']) . ' has been promoted to Administrator.');
            break;

        case 'demote':
            if ($target['role'] !== 'admin') {
                set_flash('error', e($target['name']) . ' is already a regular member.');
                redirect('users.php');
            }
            // Prevent demoting the last active admin
            $remaining = count_active_admins_except($pdo, $targetId);
            if ($remaining < 1) {
                set_flash('error', 'Cannot demote the only remaining administrator. Promote another user first.');
                redirect('users.php');
            }
            $stmt = $pdo->prepare("UPDATE users SET role = 'user' WHERE id = ?");
            $stmt->execute([$targetId]);
            set_flash('success', e($target['name']) . ' has been demoted to Community Member.');
            break;

        default:
            set_flash('error', 'Unrecognised action.');
            break;
    }
} catch (PDOException $e) {
    error_log('[OFFGRID ADMIN USER ACTION] ' . $e->getMessage());
    set_flash('error', 'A database error occurred while applying the action.');
}

redirect('users.php');

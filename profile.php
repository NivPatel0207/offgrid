<?php
/**
 * OFFGRID - Member Profile & Security Dashboard
 * 
 * Provides self-service account management for registered members and administrators:
 * - View personal details, role, joined date, and platform statistics
 * - Update full name and email address with duplicate-email protection
 * - Securely change account password with current password verification
 * - Protected by require_login() and CSRF token validation
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

require_login('login.php?redirect=profile.php');

$currentUser = current_user();
$page_title = 'My Profile & Security';

$errors = [];
$successMessage = '';

// 1. Fetch live user account details
$user = null;
try {
    $stmt = $pdo->prepare('SELECT id, name, email, role, password, created_at FROM users WHERE id = ?');
    $stmt->execute([$currentUser['id']]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[OFFGRID PROFILE LOAD ERROR] ' . $e->getMessage());
}

if (!$user) {
    set_flash('error', 'Account could not be loaded. Please log in again.');
    redirect('login.php');
}

// 2. Fetch member registration metrics
$stats = [
    'total_bookings' => 0,
    'confirmed_seats' => 0,
    'upcoming_count' => 0,
    'cancelled_count' => 0,
];

try {
    $bStmt = $pdo->prepare('
        SELECT 
            COUNT(b.id) AS total_bookings,
            COALESCE(SUM(CASE WHEN b.payment_status = "confirmed" THEN b.tickets_count ELSE 0 END), 0) AS confirmed_seats,
            COUNT(CASE WHEN b.payment_status = "confirmed" AND e.event_date >= CURDATE() THEN 1 END) AS upcoming_count,
            COUNT(CASE WHEN b.payment_status = "cancelled" THEN 1 END) AS cancelled_count
        FROM bookings b
        JOIN events e ON b.event_id = e.id
        WHERE b.user_id = ?
    ');
    $bStmt->execute([$currentUser['id']]);
    $metrics = $bStmt->fetch();
    if ($metrics) {
        $stats['total_bookings']  = (int)$metrics['total_bookings'];
        $stats['confirmed_seats'] = (int)$metrics['confirmed_seats'];
        $stats['upcoming_count']  = (int)$metrics['upcoming_count'];
        $stats['cancelled_count'] = (int)$metrics['cancelled_count'];
    }
} catch (PDOException $e) {
    error_log('[OFFGRID PROFILE STATS ERROR] ' . $e->getMessage());
}

// 3. Handle Form Submissions (Profile Update or Password Change)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // CSRF Protection
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors['csrf'] = 'Invalid or expired security token. Please refresh and try again.';
    } elseif ($action === 'update_profile') {
        // --- Form A: Update Profile Details ---
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (empty($name)) {
            $errors['name'] = 'Full name is required.';
        } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors['name'] = 'Name must be between 2 and 100 characters.';
        }

        if (empty($email)) {
            $errors['email'] = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        } else {
            // Check for duplicate email across other users
            try {
                $dupStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
                $dupStmt->execute([$email, $currentUser['id']]);
                if ($dupStmt->fetch()) {
                    $errors['email'] = 'This email address is already in use by another account.';
                }
            } catch (PDOException $e) {
                $errors['general'] = 'Database error verifying email availability.';
            }
        }

        if (empty($errors)) {
            try {
                $updStmt = $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
                $updStmt->execute([$name, $email, $currentUser['id']]);

                // Update active session values
                $_SESSION['user_name'] = $name;
                $_SESSION['user_email'] = $email;
                $user['name'] = $name;
                $user['email'] = $email;

                set_flash('success', 'Your personal profile details have been updated successfully.');
                redirect('profile.php');
            } catch (PDOException $e) {
                error_log('[OFFGRID PROFILE UPDATE ERROR] ' . $e->getMessage());
                $errors['general'] = 'An unexpected error occurred while saving profile changes.';
            }
        }

    } elseif ($action === 'change_password') {
        // --- Form B: Change Account Password ---
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword     = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if (empty($currentPassword)) {
            $errors['current_password'] = 'Current password is required.';
        } elseif (!password_verify($currentPassword, $user['password'])) {
            $errors['current_password'] = 'The current password you entered is incorrect.';
        }

        if (empty($newPassword)) {
            $errors['new_password'] = 'New password is required.';
        } elseif (strlen($newPassword) < 6) {
            $errors['new_password'] = 'New password must be at least 6 characters long.';
        }

        if ($newPassword !== $confirmPassword) {
            $errors['confirm_password'] = 'New password and confirmation do not match.';
        }

        if (empty($errors)) {
            try {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $updPass = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                $updPass->execute([$newHash, $currentUser['id']]);

                // Update local cached hash
                $user['password'] = $newHash;

                set_flash('success', 'Your account password has been updated successfully.');
                redirect('profile.php');
            } catch (PDOException $e) {
                error_log('[OFFGRID PASSWORD CHANGE ERROR] ' . $e->getMessage());
                $errors['general'] = 'An unexpected error occurred while updating your password.';
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Profile Header -->
<div style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem;">
    <div>
        <div style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--brand-accent); font-weight: 700; margin-bottom: 0.35rem;">
            Member Portal
        </div>
        <h1 style="font-size: 2rem; font-weight: 800; margin-bottom: 0.35rem;">Profile & Account Security</h1>
        <p style="color: var(--text-secondary);">
            Manage your personal contact details, security credentials, and ticket reservation activity.
        </p>
    </div>
    <div style="display: flex; gap: 0.75rem;">
        <a href="my-bookings.php" class="btn btn-outline btn-sm">🎟️ View My Bookings</a>
        <?php if (is_admin()): ?>
            <a href="admin/index.php" class="btn btn-primary btn-sm">⚙️ Admin Portal</a>
        <?php endif; ?>
    </div>
</div>

<!-- Account Metrics Summary Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; margin-bottom: 2rem;">
    <div class="stat-card" style="background: var(--bg-surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 1.25rem; box-shadow: var(--shadow-soft);">
        <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); font-weight: 700; margin-bottom: 0.4rem;">
            Account Role
        </div>
        <div style="font-size: 1.35rem; font-weight: 800; color: var(--text-primary); text-transform: capitalize;">
            <?= e($user['role']) === 'admin' ? '🛡️ Administrator' : '👤 Community Member' ?>
        </div>
        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.3rem;">
            Member since <?= e(date('d M Y', strtotime($user['created_at']))) ?>
        </div>
    </div>

    <div class="stat-card" style="background: var(--bg-surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 1.25rem; box-shadow: var(--shadow-soft);">
        <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); font-weight: 700; margin-bottom: 0.4rem;">
            Upcoming Gatherings
        </div>
        <div style="font-size: 1.85rem; font-weight: 800; color: var(--brand-accent);">
            <?= $stats['upcoming_count'] ?>
        </div>
        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.3rem;">
            Active reservations ahead
        </div>
    </div>

    <div class="stat-card" style="background: var(--bg-surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 1.25rem; box-shadow: var(--shadow-soft);">
        <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); font-weight: 700; margin-bottom: 0.4rem;">
            Reserved Seats
        </div>
        <div style="font-size: 1.85rem; font-weight: 800; color: var(--text-primary);">
            <?= $stats['confirmed_seats'] ?>
        </div>
        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.3rem;">
            Confirmed event tickets
        </div>
    </div>

    <div class="stat-card" style="background: var(--bg-surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 1.25rem; box-shadow: var(--shadow-soft);">
        <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); font-weight: 700; margin-bottom: 0.4rem;">
            Total Bookings
        </div>
        <div style="font-size: 1.85rem; font-weight: 800; color: var(--text-primary);">
            <?= $stats['total_bookings'] ?>
        </div>
        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.3rem;">
            <?= $stats['cancelled_count'] > 0 ? "Includes {$stats['cancelled_count']} cancelled" : "All lifetime orders" ?>
        </div>
    </div>
</div>

<!-- Forms Grid: Profile Information & Password Security -->
<div class="profile-forms-grid">

    <!-- Left Card: Personal Details -->
    <div style="background: var(--bg-surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 2rem; box-shadow: var(--shadow-soft);">
        <div style="display: flex; align-items: center; gap: 0.85rem; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border-subtle);">
            <div style="width: 48px; height: 48px; border-radius: 50%; background: var(--brand-accent-glow); border: 1px solid rgba(16, 185, 129, 0.35); display: flex; align-items: center; justify-content: center; font-size: 1.35rem; color: var(--brand-accent);">
                👤
            </div>
            <div>
                <h2 style="font-size: 1.2rem; font-weight: 700; color: var(--text-primary);">Personal Details</h2>
                <p style="font-size: 0.85rem; color: var(--text-secondary);">Update your name and primary email address.</p>
            </div>
        </div>

        <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-error" style="margin-bottom: 1.25rem;"><?= e($errors['general']) ?></div>
        <?php endif; ?>

        <?php if (!empty($errors['csrf'])): ?>
            <div class="alert alert-error" style="margin-bottom: 1.25rem;"><?= e($errors['csrf']) ?></div>
        <?php endif; ?>

        <form action="profile.php" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_profile">

            <!-- Full Name -->
            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label for="name" class="form-label">Full Name</label>
                <input 
                    type="text" 
                    id="name" 
                    name="name" 
                    class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" 
                    value="<?= e($_POST['name'] ?? $user['name']) ?>" 
                    required
                >
                <?php if (isset($errors['name'])): ?>
                    <div style="color: var(--danger-color); font-size: 0.8rem; margin-top: 0.35rem;"><?= e($errors['name']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Email Address -->
            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label for="email" class="form-label">Email Address</label>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" 
                    value="<?= e($_POST['email'] ?? $user['email']) ?>" 
                    required
                >
                <?php if (isset($errors['email'])): ?>
                    <div style="color: var(--danger-color); font-size: 0.8rem; margin-top: 0.35rem;"><?= e($errors['email']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Account Role (Read-only) -->
            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label class="form-label">Account Privilege Level</label>
                <input 
                    type="text" 
                    class="form-control" 
                    value="<?= e(strtoupper($user['role'])) ?> (Server Managed)" 
                    disabled 
                    style="opacity: 0.65; cursor: not-allowed;"
                >
            </div>

            <!-- Registration Date (Read-only) -->
            <div class="form-group" style="margin-bottom: 1.75rem;">
                <label class="form-label">Joined On</label>
                <input 
                    type="text" 
                    class="form-control" 
                    value="<?= e(date('d F Y, g:i A', strtotime($user['created_at']))) ?> IST" 
                    disabled 
                    style="opacity: 0.65; cursor: not-allowed;"
                >
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%;">
                Save Profile Changes
            </button>
        </form>
    </div>

    <!-- Right Card: Password & Security -->
    <div style="background: var(--bg-surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 2rem; box-shadow: var(--shadow-soft);">
        <div style="display: flex; align-items: center; gap: 0.85rem; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border-subtle);">
            <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(56, 189, 248, 0.15); border: 1px solid rgba(56, 189, 248, 0.35); display: flex; align-items: center; justify-content: center; font-size: 1.35rem; color: #38bdf8;">
                🔒
            </div>
            <div>
                <h2 style="font-size: 1.2rem; font-weight: 700; color: var(--text-primary);">Security & Password</h2>
                <p style="font-size: 0.85rem; color: var(--text-secondary);">Update your authentication password.</p>
            </div>
        </div>

        <form action="profile.php" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_password">

            <!-- Current Password -->
            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label for="current_password" class="form-label">Current Password</label>
                <input 
                    type="password" 
                    id="current_password" 
                    name="current_password" 
                    class="form-control <?= isset($errors['current_password']) ? 'is-invalid' : '' ?>" 
                    placeholder="Enter current password" 
                    required
                >
                <?php if (isset($errors['current_password'])): ?>
                    <div style="color: var(--danger-color); font-size: 0.8rem; margin-top: 0.35rem;"><?= e($errors['current_password']) ?></div>
                <?php endif; ?>
            </div>

            <!-- New Password -->
            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label for="new_password" class="form-label">New Password</label>
                <input 
                    type="password" 
                    id="new_password" 
                    name="new_password" 
                    class="form-control <?= isset($errors['new_password']) ? 'is-invalid' : '' ?>" 
                    placeholder="At least 6 characters" 
                    required
                >
                <?php if (isset($errors['new_password'])): ?>
                    <div style="color: var(--danger-color); font-size: 0.8rem; margin-top: 0.35rem;"><?= e($errors['new_password']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Confirm New Password -->
            <div class="form-group" style="margin-bottom: 1.75rem;">
                <label for="confirm_password" class="form-label">Confirm New Password</label>
                <input 
                    type="password" 
                    id="confirm_password" 
                    name="confirm_password" 
                    class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" 
                    placeholder="Re-type new password" 
                    required
                >
                <?php if (isset($errors['confirm_password'])): ?>
                    <div style="color: var(--danger-color); font-size: 0.8rem; margin-top: 0.35rem;"><?= e($errors['confirm_password']) ?></div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-outline" style="width: 100%;">
                Update Password
            </button>
        </form>
    </div>

</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>

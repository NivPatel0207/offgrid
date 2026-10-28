<?php
/**
 * OFFGRID - Reusable Site Header & Navigation Bar
 */

// Ensure core helpers are loaded and session is ready
require_once __DIR__ . '/functions.php';
init_session();

$currentUser = current_user();
$base = isset($is_admin_area) && $is_admin_area ? '../' : '';
$pageTitle = isset($page_title) ? e($page_title) . ' | OFFGRID' : 'OFFGRID — Community Event Booking Platform';

// Notification count — only for logged-in non-admin pages (lightweight)
$_notifUnread = 0;
if ($currentUser) {
    require_once __DIR__ . '/notify.php';
    global $pdo;
    if ($pdo instanceof PDO) {
        $_notifUnread = get_unread_notification_count($pdo, (int)$currentUser['id']);
    }
}
?>
<!DOCTYPE html>
<html lang="en-IN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="<?= $base ?>assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <?php if (isset($is_admin_area) && $is_admin_area): ?>
    <link rel="stylesheet" href="<?= $base ?>assets/css/admin.css?v=<?= @filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <?php endif; ?>
</head>
<body>

<header class="site-header">
    <div class="nav-container">
        <a href="<?= $base ?>index.php" class="site-brand">
            OFFGRID <span class="brand-dot"></span>
        </a>

        <!-- Mobile Quick Actions & Hamburger Toggle -->
        <div class="nav-mobile-bar">
            <?php if ($currentUser): ?>
                <a href="<?= $base ?>notifications.php" class="mobile-notif-btn" title="Notifications" aria-label="Notifications (<?= $_notifUnread ?> unread)">
                    🔔
                    <?php if ($_notifUnread > 0): ?>
                        <span class="notif-nav-badge"><?= $_notifUnread > 99 ? '99+' : $_notifUnread ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>

            <button class="nav-toggle-btn" id="navToggleBtn" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="primaryNavLinks">
                <span class="hamburger-bar"></span>
                <span class="hamburger-bar"></span>
                <span class="hamburger-bar"></span>
            </button>
        </div>

        <nav id="siteNav">
            <ul class="nav-links" id="primaryNavLinks">
                <li><a href="<?= $base ?>index.php" class="nav-link">Events</a></li>

                <?php if ($currentUser): ?>
                    <?php if (is_admin()): ?>
                        <li><a href="<?= $base ?>admin/index.php" class="nav-link admin-link">🛡️ Admin Portal</a></li>
                    <?php endif; ?>
                    <li><a href="<?= $base ?>saved-events.php" class="nav-link">🔖 Saved Events</a></li>
                    <li><a href="<?= $base ?>my-bookings.php" class="nav-link">🎟️ My Bookings</a></li>
                    <li class="desktop-only">
                        <a href="<?= $base ?>notifications.php" class="nav-link notif-bell-link" title="Notifications" aria-label="Notifications (<?= $_notifUnread ?> unread)">
                            <span class="notif-bell-wrap">
                                🔔
                                <?php if ($_notifUnread > 0): ?>
                                    <span class="notif-nav-badge" aria-live="polite"><?= $_notifUnread > 99 ? '99+' : $_notifUnread ?></span>
                                <?php endif; ?>
                            </span>
                        </a>
                    </li>
                    <li><a href="<?= $base ?>profile.php" class="nav-link">👤 Profile</a></li>
                    <li>
                        <a href="<?= $base ?>profile.php" class="user-badge" style="text-decoration: none;" title="Manage Profile & Security">
                            <strong><?= e($currentUser['name']) ?></strong>
                            <small>(<?= e(ucfirst($currentUser['role'])) ?>)</small>
                        </a>
                    </li>
                    <li class="nav-logout-item">
                        <a href="<?= $base ?>logout.php" class="btn btn-outline btn-sm" style="width: 100%; text-align: center;">Log Out</a>
                    </li>
                <?php else: ?>
                    <li><a href="<?= $base ?>login.php" class="nav-link">Log In</a></li>
                    <li><a href="<?= $base ?>register.php" class="btn btn-primary btn-sm" style="width: 100%; text-align: center;">Join OFFGRID</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
</header>

<script>
// Accessible Mobile Navigation Toggle
(function() {
    var toggleBtn = document.getElementById('navToggleBtn');
    var navLinks = document.getElementById('primaryNavLinks');
    if (!toggleBtn || !navLinks) return;

    toggleBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        var isOpen = navLinks.classList.toggle('is-open');
        toggleBtn.classList.toggle('is-active', isOpen);
        toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    document.addEventListener('click', function(e) {
        if (!navLinks.contains(e.target) && !toggleBtn.contains(e.target)) {
            navLinks.classList.remove('is-open');
            toggleBtn.classList.remove('is-active');
            toggleBtn.setAttribute('aria-expanded', 'false');
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            navLinks.classList.remove('is-open');
            toggleBtn.classList.remove('is-active');
            toggleBtn.setAttribute('aria-expanded', 'false');
        }
    });
})();
</script>

<main class="main-wrapper">
    <div class="page-container">
        <!-- Automatic Flash Notification Rendering -->
        <?= render_flash() ?>

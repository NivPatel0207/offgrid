<?php
/**
 * OFFGRID - Reusable Admin Navigation Bar
 * 
 * Provides unified navigation across all administrative portal modules.
 */

$activeTab = $admin_active_tab ?? '';
?>
<nav class="admin-subnav" aria-label="Admin Navigation">
    <div class="admin-subnav-links">
        <a href="index.php" class="admin-subnav-link <?= ($activeTab === 'dashboard') ? 'active' : '' ?>">
            <span class="subnav-icon">📊</span> Dashboard
        </a>
        <a href="events.php" class="admin-subnav-link <?= ($activeTab === 'events') ? 'active' : '' ?>">
            <span class="subnav-icon">📅</span> Manage Events
        </a>
        <a href="event-create.php" class="admin-subnav-link <?= ($activeTab === 'create') ? 'active' : '' ?>">
            <span class="subnav-icon">➕</span> Add New Event
        </a>
        <a href="bookings.php" class="admin-subnav-link <?= ($activeTab === 'bookings') ? 'active' : '' ?>">
            <span class="subnav-icon">🎟️</span> Manage Bookings
        </a>
        <a href="reviews.php" class="admin-subnav-link <?= ($activeTab === 'reviews') ? 'active' : '' ?>">
            <span class="subnav-icon">⭐</span> Manage Reviews
        </a>
        <a href="users.php" class="admin-subnav-link <?= ($activeTab === 'users') ? 'active' : '' ?>">
            <span class="subnav-icon">👥</span> Manage Users
        </a>
        <a href="analytics.php" class="admin-subnav-link <?= ($activeTab === 'analytics') ? 'active' : '' ?>">
            <span class="subnav-icon">📈</span> Analytics
        </a>
    </div>

    <div class="admin-subnav-actions">
        <a href="../logout.php" class="btn btn-danger-outline btn-sm">
            Sign Out
        </a>
    </div>
</nav>

<?php
/**
 * OFFGRID - Member Notification Center
 *
 * Displays all notifications for the logged-in member:
 * - Unread count badge
 * - Mark individual or all as read
 * - Links to related bookings / events
 * - Ownership check: user sees only their own notifications
 * - Suspended users are rejected via require_login() → enforce_not_suspended()
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/notify.php';

require_login('login.php');

$currentUser = current_user();
$userId      = (int)$currentUser['id'];
$page_title  = 'My Notifications';

// ── Pagination ─────────────────────────────────────────────────────────────
$perPage = 20;
$page    = max(1, (int)($_GET['page'] ?? 1));

// ── Filter: unread only ────────────────────────────────────────────────────
$filterUnread = isset($_GET['unread']) && $_GET['unread'] === '1';

// ── Fetch counts ───────────────────────────────────────────────────────────
$totalCount  = 0;
$unreadCount = 0;
$notifications = [];
$totalPages  = 1;

try {
    $unreadCount = get_unread_notification_count($pdo, $userId);

    $whereClause = $filterUnread ? 'WHERE user_id = ? AND is_read = 0' : 'WHERE user_id = ?';
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications $whereClause");
    $countStmt->execute([$userId]);
    $totalCount = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalCount / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    $fetchStmt = $pdo->prepare("
        SELECT id, type, title, message, ref_id, link, is_read, created_at
        FROM notifications
        $whereClause
        ORDER BY is_read ASC, created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $fetchStmt->execute([$userId]);
    $notifications = $fetchStmt->fetchAll();

} catch (PDOException $e) {
    error_log('[OFFGRID NOTIFICATIONS PAGE] ' . $e->getMessage());
    set_flash('error', 'Unable to load notifications at this time.');
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="notif-page-header">
    <div class="notif-page-header-inner">
        <div>
            <h1 class="notif-page-title">
                🔔 My Notifications
                <?php if ($unreadCount > 0): ?>
                    <span class="notif-badge-lg"><?= $unreadCount ?></span>
                <?php endif; ?>
            </h1>
            <p class="notif-page-sub">Your booking confirmations, cancellations, and event reminders.</p>
        </div>
        <div class="notif-header-actions">
            <?php if ($unreadCount > 0): ?>
                <form method="POST" action="notifications-action.php" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="btn btn-outline btn-sm" id="btn-mark-all-read">
                        ✅ Mark All as Read
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($filterUnread): ?>
                <a href="notifications.php" class="btn btn-outline btn-sm">Show All</a>
            <?php else: ?>
                <a href="notifications.php?unread=1" class="btn btn-outline btn-sm">Unread Only</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="notif-container">

    <?php if (empty($notifications)): ?>
        <div class="notif-empty">
            <div class="notif-empty-icon">🔔</div>
            <h2>No notifications yet</h2>
            <p>
                <?= $filterUnread
                    ? 'You have no unread notifications. <a href="notifications.php">View all notifications</a>.'
                    : 'When you book an event or receive updates, your notifications will appear here.' ?>
            </p>
            <a href="index.php" class="btn btn-primary">Browse Events</a>
        </div>
    <?php else: ?>

        <div class="notif-meta">
            <?= number_format($totalCount) ?> notification<?= $totalCount !== 1 ? 's' : '' ?>
            <?= $filterUnread ? '(unread only)' : '' ?>
            — Page <?= $page ?> of <?= $totalPages ?>
        </div>

        <div class="notif-list">
            <?php foreach ($notifications as $n): ?>
                <?php
                    $isUnread = !(bool)$n['is_read'];
                    $typeIcon = match($n['type']) {
                        'booking_confirmed'  => '🎟️',
                        'booking_cancelled'  => '❌',
                        'event_cancelled'    => '🚫',
                        'event_updated'      => '✏️',
                        'event_reminder'     => '⏰',
                        default              => '🔔',
                    };
                    $typeLabel = match($n['type']) {
                        'booking_confirmed'  => 'Booking Confirmed',
                        'booking_cancelled'  => 'Booking Cancelled',
                        'event_cancelled'    => 'Event Cancelled',
                        'event_updated'      => 'Event Updated',
                        'event_reminder'     => 'Event Reminder',
                        default              => 'Notification',
                    };
                ?>
                <div class="notif-item <?= $isUnread ? 'notif-unread' : '' ?>" id="notif-<?= (int)$n['id'] ?>">
                    <div class="notif-icon-col">
                        <span class="notif-type-icon"><?= $typeIcon ?></span>
                        <?php if ($isUnread): ?>
                            <span class="notif-dot" title="Unread" aria-label="Unread"></span>
                        <?php endif; ?>
                    </div>
                    <div class="notif-content-col">
                        <div class="notif-item-header">
                            <span class="notif-type-label"><?= e($typeLabel) ?></span>
                            <span class="notif-time" title="<?= e(date('D, d M Y g:i A T', strtotime($n['created_at']))) ?>">
                                <?= e(date('d M Y, g:i A', strtotime($n['created_at']))) ?> IST
                            </span>
                        </div>
                        <div class="notif-title"><?= e($n['title']) ?></div>
                        <div class="notif-message"><?= e($n['message']) ?></div>
                        <div class="notif-actions">
                            <?php if (!empty($n['link'])): ?>
                                <a href="<?= e($n['link']) ?>" class="btn btn-primary btn-xs">View Details</a>
                            <?php endif; ?>
                            <?php if ($isUnread): ?>
                                <form method="POST" action="notifications-action.php" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action"  value="mark_read">
                                    <input type="hidden" name="notif_id" value="<?= (int)$n['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-xs">Mark as Read</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="notif-pagination">
                <?php
                    $qBase = $filterUnread ? '&unread=1' : '';
                ?>
                <?php if ($page > 1): ?>
                    <a href="notifications.php?page=<?= $page - 1 . $qBase ?>" class="page-btn">‹ Prev</a>
                <?php endif; ?>
                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                    <a href="notifications.php?page=<?= $p . $qBase ?>"
                       class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="notifications.php?page=<?= $page + 1 . $qBase ?>" class="page-btn">Next ›</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</div>

<style>
/* ── Notification Center styles ─────────────────────────────────────────── */
.notif-page-header {
    background: linear-gradient(135deg, #141922 0%, #0c1015 100%);
    border-bottom: 1px solid #242e3b;
    padding: 2rem 1.5rem;
    margin-bottom: 0;
}
.notif-page-header-inner {
    max-width: 860px;
    margin: 0 auto;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
}
.notif-page-title {
    font-size: 1.75rem;
    font-weight: 800;
    color: #f1f5f9;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.notif-badge-lg {
    background: #10b981;
    color: #000;
    font-size: 0.9rem;
    font-weight: 700;
    padding: 0.15rem 0.6rem;
    border-radius: 999px;
    min-width: 26px;
    text-align: center;
}
.notif-page-sub {
    color: #94a3b8;
    margin-top: 0.3rem;
    font-size: 0.95rem;
}
.notif-header-actions {
    display: flex;
    gap: 0.6rem;
    align-items: center;
    flex-wrap: wrap;
}

.notif-container {
    max-width: 860px;
    margin: 2rem auto;
    padding: 0 1.5rem;
}
.notif-meta {
    color: #64748b;
    font-size: 0.82rem;
    margin-bottom: 1rem;
}

/* Empty state */
.notif-empty {
    text-align: center;
    padding: 5rem 2rem;
    color: #94a3b8;
}
.notif-empty-icon { font-size: 3.5rem; margin-bottom: 1rem; }
.notif-empty h2 { color: #f1f5f9; margin-bottom: 0.5rem; }
.notif-empty p { margin-bottom: 1.5rem; }
.notif-empty a { color: #ffffffff; }

/* Notification list */
.notif-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
.notif-item {
    display: flex;
    gap: 1rem;
    padding: 1.2rem 1.4rem;
    background: #141922;
    border: 1px solid #242e3b;
    border-radius: 12px;
    transition: border-color 0.2s, background 0.2s;
    position: relative;
}
.notif-item:hover {
    border-color: #2d3f52;
    background: #18212d;
}
.notif-unread {
    border-left: 3px solid #10b981 !important;
    background: #0f1e18 !important;
}
.notif-icon-col {
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.4rem;
    padding-top: 0.1rem;
}
.notif-type-icon { font-size: 1.6rem; line-height: 1; }
.notif-dot {
    width: 8px;
    height: 8px;
    background: #10b981;
    border-radius: 50%;
    flex-shrink: 0;
    animation: pulse-dot 2s ease-in-out infinite;
}
@keyframes pulse-dot {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: 0.6; transform: scale(0.8); }
}
.notif-content-col { flex: 1; min-width: 0; }
.notif-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.3rem;
    flex-wrap: wrap;
}
.notif-type-label {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #10b981;
    background: rgba(16,185,129,0.12);
    padding: 0.15rem 0.55rem;
    border-radius: 4px;
}
.notif-time {
    font-size: 0.78rem;
    color: #64748b;
    white-space: nowrap;
}
.notif-title {
    font-size: 1rem;
    font-weight: 600;
    color: #f1f5f9;
    margin-bottom: 0.35rem;
    line-height: 1.4;
}
.notif-message {
    font-size: 0.88rem;
    color: #94a3b8;
    line-height: 1.5;
    margin-bottom: 0.75rem;
}
.notif-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }

/* Extra small buttons */
.btn-xs {
    font-size: 0.75rem !important;
    padding: 0.25rem 0.65rem !important;
    border-radius: 5px !important;
}

/* Pagination */
.notif-pagination {
    display: flex;
    justify-content: center;
    gap: 0.4rem;
    margin-top: 2rem;
}
.page-btn {
    padding: 0.4rem 0.85rem;
    border-radius: 6px;
    border: 1px solid #242e3b;
    background: #141922;
    color: #94a3b8;
    text-decoration: none;
    font-size: 0.85rem;
    transition: all 0.2s;
}
.page-btn:hover, .page-btn.active {
    background: #10b981;
    color: #000;
    border-color: #10b981;
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

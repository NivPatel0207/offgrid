<?php
/**
 * OFFGRID - Admin User Management
 *
 * Provides administrators with a full view of all registered members:
 * - Search by name or email
 * - Filter by role and account status
 * - Pagination (20 per page)
 * - Summary statistics
 * - Suspend / Reactivate accounts with CSRF protection
 * - Role promotion/demotion with safety guards
 *
 * Security: require_admin() guard, CSRF on all state changes,
 * prepared statements, no passwords/hashes ever exposed.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();

$currentUser    = current_user();
$is_admin_area  = true;
$admin_active_tab = 'users';
$page_title     = 'Manage Members';

// ── Pagination ────────────────────────────────────────────────────────────────
$perPage = 20;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// ── Filters ───────────────────────────────────────────────────────────────────
$search      = trim($_GET['search'] ?? '');
$filterRole  = trim($_GET['role']   ?? '');
$filterStatus= trim($_GET['status'] ?? '');

// Whitelist filter values
if (!in_array($filterRole,   ['', 'admin', 'user'],              true)) { $filterRole   = ''; }
if (!in_array($filterStatus, ['', 'active', 'suspended'],        true)) { $filterStatus = ''; }

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats = [
    'total'     => 0,
    'members'   => 0,
    'admins'    => 0,
    'suspended' => 0,
];

try {
    $stats['total']     = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $stats['members']   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
    $stats['admins']    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    $stats['suspended'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'suspended'")->fetchColumn();
} catch (PDOException $e) {
    error_log('[OFFGRID ADMIN USERS STATS] ' . $e->getMessage());
}

// ── Build Query ───────────────────────────────────────────────────────────────
$conditions = [];
$bindings   = [];

if ($search !== '') {
    $conditions[] = '(u.name LIKE ? OR u.email LIKE ?)';
    $like = '%' . $search . '%';
    $bindings[] = $like;
    $bindings[] = $like;
}
if ($filterRole !== '') {
    $conditions[] = 'u.role = ?';
    $bindings[] = $filterRole;
}
if ($filterStatus !== '') {
    $conditions[] = 'u.status = ?';
    $bindings[] = $filterStatus;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$users        = [];
$totalFiltered = 0;
$totalPages   = 1;

try {
    // Count with filters
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u $where");
    $countStmt->execute($bindings);
    $totalFiltered = (int)$countStmt->fetchColumn();
    $totalPages    = max(1, (int)ceil($totalFiltered / $perPage));
    $page          = min($page, $totalPages);
    $offset        = ($page - 1) * $perPage;

    // Fetch page with activity counts
    $dataStmt = $pdo->prepare("
        SELECT 
            u.id,
            u.name,
            u.email,
            u.role,
            u.status,
            u.created_at,
            (SELECT COUNT(*) FROM bookings b WHERE b.user_id = u.id)  AS booking_count,
            (SELECT COUNT(*) FROM reviews  r WHERE r.user_id = u.id)  AS review_count
        FROM users u
        $where
        ORDER BY u.created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $dataStmt->execute($bindings);
    $users = $dataStmt->fetchAll();

} catch (PDOException $e) {
    error_log('[OFFGRID ADMIN USERS QUERY] ' . $e->getMessage());
    set_flash('error', 'A database error occurred while loading user data.');
}

require_once __DIR__ . '/../includes/header.php';
?>

<?php require_once __DIR__ . '/../includes/admin-nav.php'; ?>

<!-- Header -->
<div class="admin-header">
    <div class="admin-title">
        <h1>Manage Community Members</h1>
        <p>Review registered users, audit role permissions, and manage account statuses.</p>
    </div>
    <div>
        <span class="admin-badge">
            <span>👥</span> <?= $stats['total'] ?> Total Accounts
        </span>
    </div>
</div>

<div class="admin-container">

    <!-- ── Stats Row ──────────────────────────────────────────────────────── -->
    <section class="admin-stats-grid">
        <div class="stat-card">
            <h3>Total Registered</h3>
            <div class="stat-number"><?= $stats['total'] ?></div>
            <div class="stat-sub"><?= $stats['members'] ?> Members &bull; <?= $stats['admins'] ?> Admins</div>
        </div>
        <div class="stat-card">
            <h3>Community Members</h3>
            <div class="stat-number" style="color: #6ee7b7;"><?= $stats['members'] ?></div>
            <div class="stat-sub">Active platform participants</div>
        </div>
        <div class="stat-card">
            <h3>Administrators</h3>
            <div class="stat-number" style="color: #fde047;"><?= $stats['admins'] ?></div>
            <div class="stat-sub">Platform managers</div>
        </div>
        <div class="stat-card" style="border-color: <?= $stats['suspended'] > 0 ? '#f59e0b' : 'var(--border-subtle)' ?>;">
            <h3>Suspended Accounts</h3>
            <div class="stat-number" style="color: <?= $stats['suspended'] > 0 ? '#f59e0b' : 'var(--text-muted)' ?>;">
                <?= $stats['suspended'] ?>
            </div>
            <div class="stat-sub"><?= $stats['suspended'] > 0 ? 'Restricted login access' : 'No suspended accounts' ?></div>
        </div>
    </section>

    <!-- ── Search & Filter Bar ───────────────────────────────────────────── -->
    <div class="admin-filter-bar" style="margin-bottom: 1.5rem;">
        <form method="GET" action="users.php" class="admin-filter-form">
            <div class="filter-group">
                <input
                    type="search"
                    name="search"
                    class="form-control"
                    placeholder="Search by name or email…"
                    value="<?= e($search) ?>"
                    style="min-width: 280px;"
                >
            </div>
            <div class="filter-group">
                <select name="role" class="form-control">
                    <option value="">All Roles</option>
                    <option value="user"  <?= ($filterRole === 'user')  ? 'selected' : '' ?>>Member</option>
                    <option value="admin" <?= ($filterRole === 'admin') ? 'selected' : '' ?>>Administrator</option>
                </select>
            </div>
            <div class="filter-group">
                <select name="status" class="form-control">
                    <option value="">All Statuses</option>
                    <option value="active"    <?= ($filterStatus === 'active')    ? 'selected' : '' ?>>Active</option>
                    <option value="suspended" <?= ($filterStatus === 'suspended') ? 'selected' : '' ?>>Suspended</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
            <?php if ($search !== '' || $filterRole !== '' || $filterStatus !== ''): ?>
                <a href="users.php" class="btn btn-outline btn-sm">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ── Results Summary ───────────────────────────────────────────────── -->
    <div style="margin-bottom: 1rem; color: var(--text-secondary); font-size: 0.875rem;">
        Showing <?= number_format($totalFiltered) ?> user<?= $totalFiltered !== 1 ? 's' : '' ?>
        <?php if ($search !== '' || $filterRole !== '' || $filterStatus !== ''): ?>
            (filtered)
        <?php endif; ?>
        — Page <?= $page ?> of <?= $totalPages ?>
    </div>

    <!-- ── Users Table ───────────────────────────────────────────────────── -->
    <div class="admin-table-wrapper">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Member</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Bookings</th>
                    <th>Reviews</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                            No users match your filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <?php
                            $isSelf      = ((int)$u['id'] === (int)$currentUser['id']);
                            $isActive    = ($u['status'] === 'active');
                            $isAdmin     = ($u['role']   === 'admin');
                        ?>
                        <tr class="<?= !$isActive ? 'row-suspended' : '' ?>">
                            <td><?= (int)$u['id'] ?></td>

                            <!-- Member Info -->
                            <td>
                                <div class="user-info-cell">
                                    <div class="user-avatar" aria-hidden="true">
                                        <?= mb_strtoupper(mb_substr($u['name'], 0, 1, 'UTF-8')) ?>
                                    </div>
                                    <div>
                                        <div class="user-name">
                                            <?= e($u['name']) ?>
                                            <?php if ($isSelf): ?>
                                                <span class="badge badge-info" style="font-size: 0.65rem;">You</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="user-email"><?= e($u['email']) ?></div>
                                    </div>
                                </div>
                            </td>

                            <!-- Role -->
                            <td>
                                <span class="badge <?= $isAdmin ? 'badge-admin' : 'badge-member' ?>">
                                    <?= $isAdmin ? '🛡️ Admin' : '🙋 Member' ?>
                                </span>
                            </td>

                            <!-- Status -->
                            <td>
                                <span class="badge <?= $isActive ? 'badge-active' : 'badge-suspended' ?>">
                                    <?= $isActive ? '✅ Active' : '🚫 Suspended' ?>
                                </span>
                            </td>

                            <!-- Activity -->
                            <td class="text-center"><?= (int)$u['booking_count'] ?></td>
                            <td class="text-center"><?= (int)$u['review_count'] ?></td>

                            <!-- Joined -->
                            <td style="white-space: nowrap; font-size: 0.82rem; color: var(--text-secondary);">
                                <?= date('d M Y', strtotime($u['created_at'])) ?>
                            </td>

                            <!-- Actions -->
                            <td>
                                <div class="action-buttons">
                                    <?php if (!$isSelf): ?>

                                        <!-- Suspend / Reactivate -->
                                        <?php if ($isActive): ?>
                                            <form method="POST" action="user-action.php"
                                                  onsubmit="return confirm('Suspend <?= e(addslashes($u['name'])) ?>? They will be unable to log in.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action"  value="suspend">
                                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                <button type="submit" class="btn btn-warning btn-sm" title="Suspend account">
                                                    🚫 Suspend
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" action="user-action.php"
                                                  onsubmit="return confirm('Reactivate <?= e(addslashes($u['name'])) ?>?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action"  value="reactivate">
                                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                <button type="submit" class="btn btn-success btn-sm" title="Reactivate account">
                                                    ✅ Reactivate
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Promote / Demote role -->
                                        <?php if (!$isAdmin): ?>
                                            <form method="POST" action="user-action.php"
                                                  onsubmit="return confirm('Promote <?= e(addslashes($u['name'])) ?> to Administrator? This grants full admin access.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action"  value="promote">
                                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                <button type="submit" class="btn btn-outline btn-sm" title="Promote to admin">
                                                    🛡️ Promote
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" action="user-action.php"
                                                  onsubmit="return confirm('Demote <?= e(addslashes($u['name'])) ?> from Administrator to Member?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action"  value="demote">
                                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                <button type="submit" class="btn btn-outline btn-sm" title="Demote to member">
                                                    👤 Demote
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                    <?php else: ?>
                                        <span style="color: var(--text-secondary); font-size: 0.8rem;">—</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Pagination ────────────────────────────────────────────────────── -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination-wrapper" style="margin-top: 1.5rem;">
            <div class="pagination">
                <?php
                    $queryBase = http_build_query(array_filter([
                        'search' => $search,
                        'role'   => $filterRole,
                        'status' => $filterStatus,
                    ]));
                    $queryBase = $queryBase ? '&' . $queryBase : '';
                ?>
                <?php if ($page > 1): ?>
                    <a href="users.php?page=<?= $page - 1 . $queryBase ?>" class="page-btn">‹ Previous</a>
                <?php endif; ?>

                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                    <a href="users.php?page=<?= $p . $queryBase ?>"
                       class="page-btn <?= $p === $page ? 'active' : '' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="users.php?page=<?= $page + 1 . $queryBase ?>" class="page-btn">Next ›</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

</div><!-- /.admin-container -->

<style>
/* ── User management page-specific styles ─────────────────────── */
.row-suspended td { opacity: 0.65; }

.user-info-cell {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.user-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent) 0%, #059669 100%);
    color: #fff;
    font-weight: 700;
    font-size: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.user-name  { font-weight: 600; color: var(--text-primary); font-size: 0.9rem; }
.user-email { font-size: 0.8rem; color: var(--text-secondary); }

.badge-admin     { background: rgba(99,102,241,0.15); color: #818cf8; border: 1px solid rgba(99,102,241,0.3); }
.badge-member    { background: rgba(16,185,129,0.12); color: var(--accent); border: 1px solid rgba(16,185,129,0.3); }
.badge-active    { background: rgba(16,185,129,0.12); color: #34d399; border: 1px solid rgba(16,185,129,0.3); }
.badge-suspended { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.3); }
.badge-info      { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); padding: 1px 5px; border-radius: 4px; }

.action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}
.btn-warning {
    background: rgba(245,158,11,0.15);
    color: #fbbf24;
    border: 1px solid rgba(245,158,11,0.4);
}
.btn-warning:hover { background: rgba(245,158,11,0.3); }
.btn-success {
    background: rgba(16,185,129,0.15);
    color: #34d399;
    border: 1px solid rgba(16,185,129,0.4);
}
.btn-success:hover { background: rgba(16,185,129,0.3); }

.text-center { text-align: center; }

/* Pagination */
.pagination-wrapper { display: flex; justify-content: center; }
.pagination { display: flex; gap: 0.4rem; align-items: center; }
.page-btn {
    padding: 0.4rem 0.85rem;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: var(--card-bg);
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 0.85rem;
    transition: all 0.2s;
}
.page-btn:hover,
.page-btn.active {
    background: var(--accent);
    color: #000;
    border-color: var(--accent);
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

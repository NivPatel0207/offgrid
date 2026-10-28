<?php
/**
 * OFFGRID - Member & Admin Authentication (Login)
 * 
 * Authenticates users using:
 * - Prepared PDO statements
 * - password_verify() against Bcrypt hashes
 * - session_regenerate_id(true) to mitigate session fixation attacks
 * - Safe generic error messages to prevent account enumeration
 * - Role-aware redirection (admins directed to admin dashboard)
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

init_session();

// If already authenticated, redirect to home
if (is_logged_in()) {
    redirect('index.php');
}

$page_title = 'Member Login';
$errors = [];
$email = '';

// If redirected here due to account suspension, pre-fill an error message
if (!empty($_GET['suspended'])) {
    $errors['general'] = 'Your account has been suspended. Please contact the OFFGRID team.';
}

// Check for safe redirect destination in query string
$redirect_target = $_GET['redirect'] ?? '';
// Restrict redirect to safe local paths only
if (!empty($redirect_target) && (strpos($redirect_target, '://') !== false || strpos($redirect_target, '//') === 0)) {
    $redirect_target = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // 1. Basic input validation
    if (empty($email)) {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if (empty($password)) {
        $errors['password'] = 'Password is required.';
    }

    // 2. Query user record with prepared statement
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare('SELECT id, name, email, password, role, status FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // 3. Verify password against hashed password
            if ($user && password_verify($password, $user['password'])) {

                // 3a. Reject suspended accounts before creating any session
                if (isset($user['status']) && $user['status'] === 'suspended') {
                    $errors['general'] = 'Your account has been suspended. Please contact the OFFGRID team.';
                    // Log the attempt (without exposing it to the user)
                    error_log('[OFFGRID LOGIN] Suspended account login attempt: ' . $email);
                    goto render_form;
                }

                // SECURITY: Regenerate session ID upon privilege elevation to prevent session fixation
                session_regenerate_id(true);

                // Store essential identity attributes in session
                $_SESSION['user_id']    = (int)$user['id'];
                $_SESSION['user_name']  = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role']  = $user['role'];

                set_flash('success', 'Welcome back, ' . $user['name'] . '!');

                // 4. Role-based or intended destination redirection
                if (!empty($redirect_target)) {
                    redirect($redirect_target);
                } elseif ($user['role'] === 'admin') {
                    redirect('admin/index.php');
                } else {
                    redirect('index.php');
                }
            } else {
                // Use a generic message to prevent username/email enumeration
                $errors['general'] = 'Invalid email address or password.';
            }
        } catch (PDOException $e) {
            error_log('[OFFGRID LOGIN ERROR] ' . $e->getMessage());
            $errors['general'] = 'A temporary database error occurred. Please try again.';
        }
    }
}

// Render header
render_form:
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <h1>Sign In</h1>
            <p>Access your OFFGRID bookings and community events.</p>
        </div>

        <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-error" role="alert">
                <?= e($errors['general']) ?>
            </div>
        <?php endif; ?>

        <form action="login.php<?= !empty($redirect_target) ? '?redirect=' . urlencode($redirect_target) : '' ?>" method="POST" novalidate>
            <!-- Email Address -->
            <div class="form-group">
                <label for="email" class="form-label">Email Address</label>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    class="form-control" 
                    placeholder="you@example.com" 
                    value="<?= e($email) ?>" 
                    required 
                    autocomplete="email"
                >
                <?php if (!empty($errors['email'])): ?>
                    <div class="form-error"><?= e($errors['email']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Password -->
            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    class="form-control" 
                    placeholder="Enter your password" 
                    required 
                    autocomplete="current-password"
                >
                <?php if (!empty($errors['password'])): ?>
                    <div class="form-error"><?= e($errors['password']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="btn btn-primary btn-block">
                Sign In
            </button>
        </form>

        <div class="auth-footer">
            Don't have an account yet? <a href="register.php">Create an Account</a>
        </div>

        <!-- Testing & Demonstration Hint Box -->
        <div class="demo-hint-box">
            <strong>Pre-Seeded Test Credentials:</strong>
            <ul>
                <li><strong>Admin:</strong> <code>admin@offgrid.local</code> / <code>Admin@123</code></li>
                <li><strong>User:</strong> <code>demo@offgrid.local</code> / <code>User@123</code></li>
            </ul>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>

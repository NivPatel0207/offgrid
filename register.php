<?php
/**
 * OFFGRID - Member Registration Page
 * 
 * Allows new members to join the OFFGRID community.
 * Features:
 * - Server-side validation (format, length, password strength & match)
 * - Prepared statements to prevent SQL Injection
 * - Duplicate email prevention
 * - Secure password hashing using BCRYPT
 * - Public registrations strictly hardcoded to role 'user' (never trust client input)
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

init_session();

// If already logged in, redirect to home page
if (is_logged_in()) {
    redirect('index.php');
}

$page_title = 'Create an Account';
$errors = [];
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Retrieve and sanitize input data
    $name             = trim($_POST['name'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // 2. Perform server-side validation
    if (empty($name)) {
        $errors['name'] = 'Full name is required.';
    } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        $errors['name'] = 'Name must be between 2 and 100 characters.';
    }

    if (empty($email)) {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    } elseif (mb_strlen($email) > 150) {
        $errors['email'] = 'Email must not exceed 150 characters.';
    }

    if (empty($password)) {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters long.';
    }

    if ($password !== $confirm_password) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    // 3. Check for duplicate email in database if no format errors
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            
            if ($stmt->fetch()) {
                $errors['email'] = 'An account with this email address already exists.';
            }
        } catch (PDOException $e) {
            error_log('[OFFGRID REG ERROR] ' . $e->getMessage());
            $errors['general'] = 'An unexpected error occurred. Please try again.';
        }
    }

    // 4. Insert new user if all validations pass
    if (empty($errors)) {
        try {
            // Hash password securely with Bcrypt
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            // SECURITY: Public registration is strictly hardcoded to role 'user'.
            // Role is never accepted from $_POST.
            $insertStmt = $pdo->prepare('
                INSERT INTO users (name, email, password, role) 
                VALUES (?, ?, ?, "user")
            ');
            $insertStmt->execute([$name, $email, $hashedPassword]);

            // Set success notification and redirect to login
            set_flash('success', 'Welcome to OFFGRID! Your account has been created. Please log in.');
            redirect('login.php');

        } catch (PDOException $e) {
            error_log('[OFFGRID REG INSERT ERROR] ' . $e->getMessage());
            $errors['general'] = 'Registration failed due to a system issue. Please try again later.';
        }
    }
}

// Render header
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <h1>Join OFFGRID</h1>
            <p>Connect with local outdoor events, workshops, and gatherings.</p>
        </div>

        <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-error" role="alert">
                <?= e($errors['general']) ?>
            </div>
        <?php endif; ?>

        <form action="register.php" method="POST" novalidate>
            <!-- Full Name -->
            <div class="form-group">
                <label for="name" class="form-label">Full Name</label>
                <input 
                    type="text" 
                    id="name" 
                    name="name" 
                    class="form-control" 
                    placeholder="e.g. Alex Woods" 
                    value="<?= e($name) ?>" 
                    required 
                    autocomplete="name"
                >
                <?php if (!empty($errors['name'])): ?>
                    <div class="form-error"><?= e($errors['name']) ?></div>
                <?php endif; ?>
            </div>

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
                    placeholder="Minimum 8 characters" 
                    required 
                    autocomplete="new-password"
                >
                <?php if (!empty($errors['password'])): ?>
                    <div class="form-error"><?= e($errors['password']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Confirm Password -->
            <div class="form-group">
                <label for="confirm_password" class="form-label">Confirm Password</label>
                <input 
                    type="password" 
                    id="confirm_password" 
                    name="confirm_password" 
                    class="form-control" 
                    placeholder="Re-enter your password" 
                    required 
                    autocomplete="new-password"
                >
                <?php if (!empty($errors['confirm_password'])): ?>
                    <div class="form-error"><?= e($errors['confirm_password']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="btn btn-primary btn-block">
                Create Account
            </button>
        </form>

        <div class="auth-footer">
            Already have an account? <a href="login.php">Sign In</a>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>

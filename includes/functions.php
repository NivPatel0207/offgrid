<?php
/**
 * OFFGRID - Core Helper Functions & Authentication Utilities
 * 
 * Contains reusable helpers for:
 * - Secure session management
 * - HTML output escaping (preventing XSS)
 * - User authentication & role checks
 * - Authorization guards (admin & user access control)
 * - Flash messaging for user notifications
 * - Safe HTTP redirection
 */

// Enforce India Standard Time (IST) globally
date_default_timezone_set('Asia/Kolkata');

/**
 * Initializes a secure session if not already active.
 */
function init_session() {
    if (session_status() === PHP_SESSION_NONE) {
        if (!headers_sent()) {
            // Enforce cookie-only sessions and HTTP-only cookies to mitigate XSS session hijacking
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_only_cookies', 1);
            session_start();
        } else {
            @session_start();
        }
    }
}


/**
 * Escapes strings for safe HTML output to prevent Cross-Site Scripting (XSS).
 *
 * @param mixed $value The string or value to escape.
 * @return string
 */
function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Checks if the current visitor is authenticated.
 *
 * @return bool
 */
function is_logged_in() {
    init_session();
    return !empty($_SESSION['user_id']);
}

/**
 * Retrieves the currently logged in user's profile array.
 *
 * @return array|null Returns user data array or null if guest.
 */
function current_user() {
    if (!is_logged_in()) {
        return null;
    }

    return [
        'id'    => (int)$_SESSION['user_id'],
        'name'  => $_SESSION['user_name'] ?? 'Member',
        'email' => $_SESSION['user_email'] ?? '',
        'role'  => $_SESSION['user_role'] ?? 'user',
    ];
}

/**
 * Checks if the authenticated user has an 'admin' role.
 *
 * @return bool
 */
function is_admin() {
    $user = current_user();
    return ($user !== null && isset($user['role']) && $user['role'] === 'admin');
}

/**
 * Authorization Guard: Requires the visitor to be logged in.
 * Redirects to the login page if not authenticated.
 * Also destroys the session if the account has been suspended.
 *
 * @param string $redirect_url Fallback redirect destination.
 */
function require_login($redirect_url = 'login.php') {
    if (!is_logged_in()) {
        set_flash('error', 'Please log in to access this page.');
        redirect($redirect_url);
    }
    enforce_not_suspended();
}

/**
 * Terminates the session and redirects if the currently-logged-in user's
 * account has been suspended since they last logged in.
 * Requires $pdo to be available in global scope.
 */
function enforce_not_suspended() {
    if (empty($_SESSION['user_id'])) {
        return;
    }
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return; // $pdo not available, skip check
    }
    try {
        $stmt = $pdo->prepare('SELECT status FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['status'] === 'suspended') {
            // Destroy session immediately
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
            // Determine correct login path (admin pages are one level deep)
            $loginPath = (strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false)
                ? '../login.php'
                : 'login.php';
            header('Location: ' . $loginPath . '?suspended=1');
            exit;
        }
    } catch (PDOException $e) {
        error_log('[OFFGRID SUSPENSION CHECK] ' . $e->getMessage());
        // Non-fatal: allow request to proceed if DB temporarily unavailable
    }
}

/**
 * Authorization Guard: Requires the user to have an 'admin' role.
 * Redirects guests to login, and unauthorized users away with a 403 Forbidden status.
 *
 * @param string $redirect_url Fallback redirect destination if unauthorized.
 */
function require_admin($redirect_url = '../index.php') {
    if (!is_logged_in()) {
        set_flash('error', 'Administrator login required.');
        redirect('../login.php');
    }

    enforce_not_suspended();

    if (!is_admin()) {
        http_response_code(403);
        set_flash('error', 'Access Denied: You do not possess administrator privileges.');
        redirect($redirect_url);
    }
}

/**
 * Safe HTTP redirection with script termination.
 *
 * @param string $url Target location.
 */
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

/**
 * Stores a one-time flash notification in the session.
 *
 * @param string $type Category: 'success', 'error', 'info', or 'warning'.
 * @param string $message The notification text.
 */
function set_flash($type, $message) {
    init_session();
    $_SESSION['flash'] = [
        'type'    => $type,
        'message' => $message,
    ];
}

/**
 * Retrieves and clears the current flash notification.
 *
 * @return array|null
 */
function get_flash() {
    init_session();
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Renders HTML alert markup for the current flash message if available.
 *
 * @return string HTML alert string or empty string.
 */
function render_flash() {
    $flash = get_flash();
    if (!$flash) {
        return '';
    }

    $type = e($flash['type']);
    $message = e($flash['message']);

    return "<div class=\"alert alert-{$type}\" role=\"alert\">{$message}</div>";
}

/**
 * Sanitizes plain string inputs by trimming and removing HTML tags.
 *
 * @param mixed $data
 * @return mixed
 */
function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    return trim(strip_tags((string)$data));
}

/**
 * Generates a cryptographically secure CSRF token and stores it in the session.
 *
 * @return string
 */
function generate_csrf_token() {
    init_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validates a submitted CSRF token against the session token.
 *
 * @param string|null $token
 * @return bool
 */
function verify_csrf_token($token) {
    init_session();
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], (string)$token);
}

/**
 * Generates a hidden HTML input field containing the current CSRF token.
 *
 * @return string
 */
function csrf_field() {
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
}

/**
 * Formats a SQL DATE string into an Indian-friendly readable format (e.g. Sat, 15 Nov 2026).
 *
 * @param string $dateStr
 * @return string
 */
function format_event_date($dateStr) {
    if (empty($dateStr)) {
        return 'TBD';
    }
    $timestamp = strtotime($dateStr);
    return $timestamp ? date('D, d M Y', $timestamp) : e($dateStr);
}

/**
 * Formats a SQL TIME string into Indian Standard Time 12-hour format (e.g. 6:30 PM IST).
 *
 * @param string $timeStr
 * @return string
 */
function format_event_time($timeStr) {
    if (empty($timeStr)) {
        return '';
    }
    $timestamp = strtotime($timeStr);
    return $timestamp ? date('g:i A', $timestamp) . ' IST' : e($timeStr);
}

/**
 * Formats a numeric price in Indian Rupees (INR) using the ₹ symbol.
 * Example: ₹499 or ₹1,299.
 *
 * @param float|string $price
 * @return string
 */
function format_price($price) {
    $val = (float)$price;
    if ($val <= 0) {
        return 'FREE';
    }
    // Round rupees formatted cleanly without trailing zero decimals
    if (floor($val) == $val) {
        return '₹' . number_format($val, 0, '.', ',');
    }
    return '₹' . number_format($val, 2, '.', ',');
}

/**
 * Formats an Indian mobile phone number into a standard +91 format.
 *
 * @param string|null $phone
 * @return string
 */
function format_indian_phone($phone) {
    if (empty($phone)) {
        return '';
    }
    $digits = preg_replace('/[^0-9]/', '', (string)$phone);
    if (strlen($digits) === 10) {
        return '+91 ' . substr($digits, 0, 5) . ' ' . substr($digits, 5);
    }
    if (strlen($digits) === 12 && substr($digits, 0, 2) === '91') {
        return '+91 ' . substr($digits, 2, 5) . ' ' . substr($digits, 7);
    }
    return (string)$phone;
}

/**
 * Resolves the URL path for an event cover photo with graceful local fallback.
 * Checks local assets/images/events/ first, allows remote URLs, and falls back to default-event.jpg.
 *
 * @param string|null $imageFilename
 * @param string $base Base path prefix (e.g. '../' for admin pages)
 * @return string
 */
function get_event_image_url($imageFilename, $base = '') {
    if (empty($imageFilename)) {
        return $base . 'assets/images/events/default-event.jpg';
    }

    // Check if it's already a full HTTP/HTTPS URL
    if (preg_match('#^https?://#i', $imageFilename)) {
        return $imageFilename;
    }

    // Local filesystem check in assets/images/events/
    $localFile = __DIR__ . '/../assets/images/events/' . basename($imageFilename);
    if (file_exists($localFile)) {
        return $base . 'assets/images/events/' . rawurlencode(basename($imageFilename));
    }

    // Fallback to default event banner
    return $base . 'assets/images/events/default-event.jpg';
}

/**
 * Handles secure event cover photo upload with MIME validation, size limits,
 * and random collision-free filename generation.
 *
 * @param string $inputName The $_FILES key name (e.g. 'event_image')
 * @param string|null &$error Output parameter for error message
 * @return string|null|false Returns filename on success, null if no file was uploaded, or false on validation error.
 */
function handle_event_image_upload($inputName, &$error = null) {
    if (!isset($_FILES[$inputName]) || !is_array($_FILES[$inputName])) {
        return null;
    }

    $file = $_FILES[$inputName];

    // If no file was selected, return null (caller will keep existing or default)
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    // Check for upload error codes
    if ($file['error'] !== UPLOAD_ERR_OK) {
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $error = 'The uploaded image exceeds the maximum permitted file size (5 MB).';
                break;
            case UPLOAD_ERR_PARTIAL:
                $error = 'The image file was only partially uploaded. Please try again.';
                break;
            default:
                $error = 'An error occurred during file upload (Error code: ' . $file['error'] . ').';
        }
        return false;
    }

    // Limit maximum size to 5 MB (5,242,880 bytes)
    $maxBytes = 5 * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        $error = 'The image file size exceeds the 5 MB limit. Please choose a smaller photo.';
        return false;
    }

    $tmpPath = $file['tmp_name'];
    if (!is_uploaded_file($tmpPath)) {
        $error = 'Invalid upload source detected.';
        return false;
    }

    // Validate real MIME content type using finfo
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    if (!isset($allowedMimes[$detectedMime])) {
        $error = 'Invalid image format (' . htmlspecialchars((string)$detectedMime) . '). Please upload a JPG, PNG, or WebP photo.';
        return false;
    }

    // Secondary check: verify valid image dimensions
    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        $error = 'Corrupt or unreadable image file. Please upload a valid image.';
        return false;
    }

    $extension = $allowedMimes[$detectedMime];

    // Generate collision-safe random filename
    $cleanFilename = 'event_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;

    $targetDir = __DIR__ . '/../assets/images/events';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $destination = $targetDir . '/' . $cleanFilename;

    if (!move_uploaded_file($tmpPath, $destination)) {
        $error = 'Failed to save uploaded file to the events media directory.';
        return false;
    }

    return $cleanFilename;
}




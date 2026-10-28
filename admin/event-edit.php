<?php
/**
 * OFFGRID - Edit Community Event
 * 
 * Secure administrator module for modifying event details and managing participant capacity.
 * Strictly enforces booking integrity, preserves existing ticket reservations, and recalculates
 * available seats atomically inside a database transaction.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notify.php';

// Strict Authorization Guard
require_admin();

$is_admin_area = true;
$admin_active_tab = 'events';
$page_title = 'Edit Event';

// 1. Validate Event ID from GET
$eventId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT)
        ?: filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);

if (!$eventId || $eventId <= 0) {
    set_flash('error', 'Invalid event selected for editing.');
    redirect('events.php');
}

// 2. Fetch current event and calculate already occupied seats
$event = null;
$occupiedSeats = 0;

try {
    $stmt = $pdo->prepare('
        SELECT e.*, COALESCE(SUM(b.tickets_count), 0) AS booked_seats 
        FROM events e 
        LEFT JOIN bookings b ON e.id = b.event_id 
        WHERE e.id = ? 
        GROUP BY e.id 
        LIMIT 1
    ');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if (!$event) {
        set_flash('error', 'Event not found or has been removed.');
        redirect('events.php');
    }

    $occupiedSeats = (int)$event['booked_seats'];

} catch (PDOException $e) {
    error_log('[OFFGRID EVENT EDIT LOAD ERROR] ' . $e->getMessage());
    set_flash('error', 'Unable to load event details.');
    redirect('events.php');
}

// Predefined cities and categories
$commonCities = [
    'Ahmedabad', 'Bengaluru', 'Chennai', 'Delhi', 'Goa',
    'Hyderabad', 'Jaipur', 'Kochi', 'Kolkata', 'Lucknow',
    'Mumbai', 'Pune', 'Surat', 'Vadodara', 'Chandigarh'
];

try {
    $existingCities = $pdo->query("SELECT DISTINCT city FROM events WHERE city IS NOT NULL AND city != ''")->fetchAll(PDO::FETCH_COLUMN);
    $allCities = array_unique(array_merge($commonCities, $existingCities));
    sort($allCities);
} catch (PDOException $e) {
    $allCities = $commonCities;
}

$commonCategories = [
    'Outdoor & Adventure',
    'Live Music & Concerts',
    'Hands-on Skills',
    'Workshops & Nature',
    'Technology & Coding',
    'Art & Photography',
    'Art & Crafts',
    'Sports & Fitness',
    'Culture & Heritage',
    'Food & Culinary',
    'Water Sports & Eco',
    'Community Meetup'
];

$errors = [];
$formData = [
    'title'          => $event['title'],
    'category'       => $event['category'],
    'custom_category'=> '',
    'description'    => $event['description'],
    'city'           => $event['city'],
    'custom_city'    => '',
    'location'       => $event['location'],
    'event_date'     => $event['event_date'],
    'event_time'     => substr($event['event_time'], 0, 5),
    'price'          => $event['price'],
    'total_capacity' => (string)$event['total_capacity'],
    'status'         => $event['status'],
];

// 3. Process POST Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 3.1 CSRF Verification
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        $errors[] = 'Security token validation failed (CSRF). Please refresh and resubmit.';
    }

    // 3.2 Sanitize Inputs
    $formData['title']          = trim($_POST['title'] ?? '');
    $categorySelect             = trim($_POST['category'] ?? '');
    $customCategory             = trim($_POST['custom_category'] ?? '');
    $formData['description']    = trim($_POST['description'] ?? '');
    $citySelect                 = trim($_POST['city'] ?? '');
    $customCity                 = trim($_POST['custom_city'] ?? '');
    $formData['location']       = trim($_POST['location'] ?? '');
    $formData['event_date']     = trim($_POST['event_date'] ?? '');
    $formData['event_time']     = trim($_POST['event_time'] ?? '');
    $priceInput                 = trim($_POST['price'] ?? '0');
    $capacityInput              = trim($_POST['total_capacity'] ?? '0');
    $statusInput                = trim($_POST['status'] ?? 'active');

    // Resolve Category
    $category = (!empty($customCategory)) ? $customCategory : $categorySelect;
    $formData['category'] = $category;
    $formData['custom_category'] = $customCategory;

    // Resolve City
    $city = (!empty($customCity)) ? $customCity : $citySelect;
    $formData['city'] = $city;
    $formData['custom_city'] = $customCity;

    // 3.3 Validation
    if (empty($formData['title'])) {
        $errors[] = 'Event title cannot be empty.';
    } elseif (mb_strlen($formData['title']) > 200) {
        $errors[] = 'Event title cannot exceed 200 characters.';
    }

    if (empty($category)) {
        $errors[] = 'Please select or enter an event category.';
    }

    if (empty($formData['description'])) {
        $errors[] = 'Event description cannot be empty.';
    }

    if (empty($city)) {
        $errors[] = 'Please specify an Indian city for this event.';
    }

    if (empty($formData['location'])) {
        $errors[] = 'Event venue location cannot be empty.';
    }

    if (empty($formData['event_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $formData['event_date'])) {
        $errors[] = 'Please provide a valid date in YYYY-MM-DD format.';
    }

    if (empty($formData['event_time'])) {
        $errors[] = 'Event start time is required.';
    } else {
        if (preg_match('/^\d{2}:\d{2}$/', $formData['event_time'])) {
            $formattedTime = $formData['event_time'] . ':00';
        } elseif (preg_match('/^\d{2}:\d{2}:\d{2}$/', $formData['event_time'])) {
            $formattedTime = $formData['event_time'];
        } else {
            $errors[] = 'Invalid time format. Please provide a standard time (e.g. 18:00).';
        }
    }

    if (!is_numeric($priceInput) || (float)$priceInput < 0) {
        $errors[] = 'Ticket price must be a non-negative number in INR.';
    } else {
        $price = round((float)$priceInput, 2);
        $formData['price'] = (string)$price;
    }

    // Capacity Validation & Integrity Protection
    $newTotalCapacity = filter_var($capacityInput, FILTER_VALIDATE_INT);
    if ($newTotalCapacity === false || $newTotalCapacity < 1) {
        $errors[] = 'Total capacity must be a positive integer.';
    } else {
        $formData['total_capacity'] = (string)$newTotalCapacity;
    }

    $allowedStatuses = ['active', 'draft', 'completed', 'cancelled'];
    if (!in_array($statusInput, $allowedStatuses, true)) {
        $statusInput = 'active';
    }
    $formData['status'] = $statusInput;

    // Handle Image: Optional New Upload or Retain Existing Image
    $currentImage = $event['image'] ?? 'default-event.jpg';
    $uploadError = null;
    $uploadedImage = handle_event_image_upload('event_image', $uploadError);

    if ($uploadedImage === false) {
        $errors[] = $uploadError;
    } elseif ($uploadedImage !== null) {
        // Administrator uploaded a new image
        $imageToSave = $uploadedImage;
    } else {
        // No new image selected: preserve current image
        $imageToSave = $currentImage;
    }

    // 3.4 Execute Atomic Transaction Update if Validation Passed
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Lock event row and re-count occupied seats under transaction lock
            $lockStmt = $pdo->prepare('SELECT id, total_capacity, available_seats FROM events WHERE id = ? FOR UPDATE');
            $lockStmt->execute([$eventId]);
            $lockedEvent = $lockStmt->fetch();

            if (!$lockedEvent) {
                throw new Exception('The event could not be locked for editing.');
            }

            // Calculate current occupied seats directly from bookings
            $occStmt = $pdo->prepare('SELECT COALESCE(SUM(tickets_count), 0) FROM bookings WHERE event_id = ?');
            $occStmt->execute([$eventId]);
            $currentBookedSeats = (int)$occStmt->fetchColumn();

            // Strict Capacity Integrity Guard:
            // New capacity can NEVER be lower than currently booked seats!
            if ($newTotalCapacity < $currentBookedSeats) {
                throw new Exception(
                    "Total capacity ({$newTotalCapacity}) cannot be reduced below the {$currentBookedSeats} seat(s) " .
                    "already booked by attendees. Please cancel individual bookings first or set capacity >= {$currentBookedSeats}."
                );
            }

            // Correct available seats formula:
            // available_seats = newTotalCapacity - currentBookedSeats
            $newAvailableSeats = $newTotalCapacity - $currentBookedSeats;

            // Execute Update
            $updateStmt = $pdo->prepare('
                UPDATE events 
                SET title = ?, 
                    category = ?, 
                    description = ?, 
                    city = ?, 
                    location = ?, 
                    event_date = ?, 
                    event_time = ?, 
                    price = ?, 
                    total_capacity = ?, 
                    available_seats = ?, 
                    image = ?, 
                    status = ?
                WHERE id = ?
            ');

            $updateStmt->execute([
                $formData['title'],
                $category,
                $formData['description'],
                $city,
                $formData['location'],
                $formData['event_date'],
                $formattedTime,
                $price,
                $newTotalCapacity,
                $newAvailableSeats,
                $imageToSave,
                $statusInput,
                $eventId
            ]);

            $pdo->commit();

            // ── Notification Fan-out: Event Cancelled ─────────────────────────
            // If the event status changed to 'cancelled', notify all confirmed bookers.
            $previousStatus = $event['status'];
            if ($statusInput === 'cancelled' && $previousStatus !== 'cancelled') {
                try {
                    $bookerStmt = $pdo->prepare("
                        SELECT b.id AS booking_id, b.user_id, b.booking_code,
                               u.name AS user_name
                        FROM bookings b
                        JOIN users u ON b.user_id = u.id
                        WHERE b.event_id = ? AND b.payment_status = 'confirmed'
                    ");
                    $bookerStmt->execute([$eventId]);
                    $bookers = $bookerStmt->fetchAll();

                    $eventDateFmt = date('D, d M Y', strtotime($formData['event_date']));
                    foreach ($bookers as $booker) {
                        create_notification(
                            $pdo,
                            (int)$booker['user_id'],
                            'event_cancelled',
                            '🚫 Event Cancelled: ' . $formData['title'],
                            '"' . $formData['title'] . '" scheduled on ' . $eventDateFmt
                                . ' has been cancelled by the organiser. '
                                . 'Your booking ref ' . $booker['booking_code'] . ' is affected.',
                            (int)$booker['booking_id'],
                            'my-bookings.php'
                        );
                    }
                } catch (PDOException $fanOutEx) {
                    // Non-fatal: log but do not block the edit success redirect
                    error_log('[OFFGRID EVENT EDIT NOTIFY] ' . $fanOutEx->getMessage());
                }
            }

            set_flash('success', 'Event "' . $formData['title'] . '" was successfully updated.');
            redirect('events.php');

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[OFFGRID EVENT UPDATE ERROR] ' . $e->getMessage());
            $errors[] = $e->getMessage();
        }
    }
}

$currentImgUrl = get_event_image_url($event['image'], '../');

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Reusable Admin Navigation Bar -->
<?php require_once __DIR__ . '/../includes/admin-nav.php'; ?>

<!-- Header -->
<div class="admin-header">
    <div class="admin-title">
        <h1>Edit Community Event</h1>
        <p>Update gathering itinerary, city, venue, and attendee capacity for Event #<?= (int)$event['id'] ?>.</p>
    </div>
    <div class="admin-actions" style="margin-top: 0;">
        <a href="events.php" class="btn btn-outline btn-sm">&larr; Back to Events List</a>
        <a href="../event-details.php?id=<?= (int)$event['id'] ?>" class="btn btn-outline btn-sm" target="_blank">
            👁️ View on Public Site &nearr;
        </a>
    </div>
</div>

<!-- Form Card -->
<div class="admin-form-card">
    <!-- Capacity Notice Banner -->
    <div style="background: var(--bg-surface-elevated); border: 1px solid var(--border-subtle); border-radius: var(--radius-md); padding: 1rem 1.25rem; margin-bottom: 1.75rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <span style="font-weight: 700; color: var(--text-primary); font-size: 0.95rem;">Current Reservation Status:</span>
            <span style="color: var(--brand-accent); font-weight: 800; margin-left: 0.5rem;"><?= $occupiedSeats ?> seats occupied</span>
            <span style="color: var(--text-muted); font-size: 0.85rem;">out of <?= (int)$event['total_capacity'] ?> total capacity (<?= (int)$event['available_seats'] ?> currently open)</span>
        </div>
        <div style="font-size: 0.8rem; color: var(--text-muted);">
            Minimum permissible capacity: <strong><?= max(1, $occupiedSeats) ?></strong>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error" style="margin-bottom: 1.5rem; flex-direction: column; align-items: flex-start;">
            <strong>Please resolve the following issues:</strong>
            <ul style="margin: 0.5rem 0 0 1.25rem;">
                <?php foreach ($errors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form action="event-edit.php?id=<?= (int)$eventId ?>" method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">

        <!-- Event Title -->
        <div class="form-group">
            <label for="title" class="form-label">Event Title <span style="color: #ef4444;">*</span></label>
            <input 
                type="text" 
                id="title" 
                name="title" 
                class="form-control" 
                value="<?= e($formData['title']) ?>" 
                required 
                maxlength="200"
            >
        </div>

        <!-- 2-Column: Category & Status -->
        <div class="form-grid-2">
            <!-- Category Selection -->
            <div class="form-group">
                <label for="category" class="form-label">Category <span style="color: #ef4444;">*</span></label>
                <select id="category" name="category" class="form-control" style="cursor: pointer;">
                    <?php foreach ($commonCategories as $catOption): ?>
                        <option value="<?= e($catOption) ?>" <?= ($formData['category'] === $catOption) ? 'selected' : '' ?>>
                            <?= e($catOption) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input 
                    type="text" 
                    name="custom_category" 
                    class="form-control" 
                    placeholder="Or enter custom category..." 
                    value="<?= e($formData['custom_category']) ?>"
                    style="margin-top: 0.5rem;"
                >
            </div>

            <!-- Status -->
            <div class="form-group">
                <label for="status" class="form-label">Publication Status</label>
                <select id="status" name="status" class="form-control" style="cursor: pointer;">
                    <option value="active" <?= ($formData['status'] === 'active') ? 'selected' : '' ?>>Active (Published on Site)</option>
                    <option value="draft" <?= ($formData['status'] === 'draft') ? 'selected' : '' ?>>Draft (Hidden from Public)</option>
                    <option value="completed" <?= ($formData['status'] === 'completed') ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= ($formData['status'] === 'cancelled') ? 'selected' : '' ?>>Cancelled / Inactive</option>
                </select>
            </div>
        </div>

        <!-- 2-Column: City & Venue -->
        <div class="form-grid-2">
            <!-- Indian City -->
            <div class="form-group">
                <label for="city" class="form-label">Indian City <span style="color: #ef4444;">*</span></label>
                <select id="city" name="city" class="form-control" style="cursor: pointer;">
                    <?php foreach ($allCities as $cOption): ?>
                        <option value="<?= e($cOption) ?>" <?= ($formData['city'] === $cOption) ? 'selected' : '' ?>>
                            📍 <?= e($cOption) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input 
                    type="text" 
                    name="custom_city" 
                    class="form-control" 
                    placeholder="Or change Indian city..." 
                    value="<?= e($formData['custom_city']) ?>"
                    style="margin-top: 0.5rem;"
                >
            </div>

            <!-- Venue / Location -->
            <div class="form-group">
                <label for="location" class="form-label">Venue / Meeting Point <span style="color: #ef4444;">*</span></label>
                <input 
                    type="text" 
                    id="location" 
                    name="location" 
                    class="form-control" 
                    value="<?= e($formData['location']) ?>" 
                    required 
                    maxlength="200"
                >
            </div>
        </div>

        <!-- 2-Column: Date & Time -->
        <div class="form-grid-2">
            <div class="form-group">
                <label for="event_date" class="form-label">Event Date <span style="color: #ef4444;">*</span></label>
                <input 
                    type="date" 
                    id="event_date" 
                    name="event_date" 
                    class="form-control" 
                    value="<?= e($formData['event_date']) ?>" 
                    required
                >
            </div>

            <div class="form-group">
                <label for="event_time" class="form-label">Start Time (IST) <span style="color: #ef4444;">*</span></label>
                <input 
                    type="time" 
                    id="event_time" 
                    name="event_time" 
                    class="form-control" 
                    value="<?= e($formData['event_time']) ?>" 
                    required
                >
            </div>
        </div>

        <!-- 2-Column: Price (INR) & Capacity -->
        <div class="form-grid-2">
            <div class="form-group">
                <label for="price" class="form-label">Registration Fee in INR (₹) <span style="color: #ef4444;">*</span></label>
                <input 
                    type="number" 
                    step="0.01" 
                    min="0" 
                    id="price" 
                    name="price" 
                    class="form-control" 
                    value="<?= e($formData['price']) ?>" 
                    required
                >
            </div>

            <div class="form-group">
                <label for="total_capacity" class="form-label">Total Capacity (Seats) <span style="color: #ef4444;">*</span></label>
                <input 
                    type="number" 
                    min="<?= max(1, $occupiedSeats) ?>" 
                    id="total_capacity" 
                    name="total_capacity" 
                    class="form-control" 
                    value="<?= e($formData['total_capacity']) ?>" 
                    required
                >
                <div class="form-help">
                    Must be at least <strong><?= max(1, $occupiedSeats) ?></strong> (the number of seats currently reserved).
                </div>
            </div>
        </div>

        <!-- Full Description -->
        <div class="form-group">
            <label for="description" class="form-label">Full Description & Itinerary <span style="color: #ef4444;">*</span></label>
            <textarea 
                id="description" 
                name="description" 
                rows="6" 
                class="form-control" 
                required
            ><?= e($formData['description']) ?></textarea>
        </div>

        <!-- Cover Photo Upload & Current Image Display -->
        <div class="form-group">
            <label for="event_image" class="form-label">Change Cover Photo (Optional)</label>
            <input 
                type="file" 
                id="event_image" 
                name="event_image" 
                class="form-control" 
                accept="image/jpeg,image/png,image/webp"
                style="cursor: pointer;"
            >
            <div class="form-help">Leave empty to keep the existing event photo. Formats: JPG, PNG, WebP (Max 5 MB).</div>

            <div class="image-preview-wrapper">
                <div>
                    <span style="font-size: 0.775rem; color: var(--text-muted); display: block; margin-bottom: 0.25rem;">Current Photo:</span>
                    <div class="image-preview-box">
                        <img id="imagePreview" src="<?= e($currentImgUrl) ?>" alt="Current Event Cover">
                    </div>
                </div>
                <div style="font-size: 0.85rem; color: var(--text-secondary); max-width: 320px;">
                    Current filename: <code><?= e($event['image'] ?? 'default-event.jpg') ?></code>.<br>
                    Selecting a new file updates the preview immediately.
                </div>
            </div>
        </div>

        <!-- Submit Controls -->
        <div class="admin-actions">
            <button type="submit" class="btn btn-primary">
                💾 Save Changes
            </button>
            <a href="events.php" class="btn btn-outline">
                Cancel
            </a>
        </div>
    </form>
</div>

<!-- Vanilla JS Live Image Preview Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var imageInput = document.getElementById('event_image');
    var imagePreview = document.getElementById('imagePreview');

    if (imageInput && imagePreview) {
        imageInput.addEventListener('change', function(e) {
            var file = e.target.files[0];
            if (file) {
                if (file.type.match(/^image\//)) {
                    var reader = new FileReader();
                    reader.onload = function(evt) {
                        imagePreview.src = evt.target.result;
                    };
                    reader.readAsDataURL(file);
                } else {
                    alert('Please select a valid image file (JPG, PNG, WebP).');
                    imageInput.value = '';
                }
            }
        });
    }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>

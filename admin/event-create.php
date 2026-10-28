<?php
/**
 * OFFGRID - Create New Community Event
 * 
 * Secure administrator module for publishing community events across India.
 * Features strict server-side validation, CSRF verification, and atomic insertion.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Strict Authorization Guard
require_admin();

$currentUser = current_user();
$is_admin_area = true;
$admin_active_tab = 'create';
$page_title = 'Create New Event';

// Predefined Indian cities and categories for easy selection
$commonCities = [
    'Ahmedabad', 'Bengaluru', 'Chennai', 'Delhi', 'Goa',
    'Hyderabad', 'Jaipur', 'Kochi', 'Kolkata', 'Lucknow',
    'Mumbai', 'Pune', 'Surat', 'Vadodara', 'Chandigarh'
];

// Fetch any additional distinct cities from existing database records
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
    'title'          => '',
    'category'       => 'Outdoor & Adventure',
    'custom_category'=> '',
    'description'    => '',
    'city'           => 'Mumbai',
    'custom_city'    => '',
    'location'       => '',
    'event_date'     => date('Y-m-d', strtotime('+7 days')),
    'event_time'     => '18:00',
    'price'          => '0.00',
    'total_capacity' => '30',
    'status'         => 'active',
];

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verify CSRF Token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        $errors[] = 'Security token validation failed (CSRF). Please refresh and resubmit.';
    }

    // 2. Extract and Sanitize Inputs
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

    // Resolve City (allows typing any Indian city)
    $city = (!empty($customCity)) ? $customCity : $citySelect;
    $formData['city'] = $city;
    $formData['custom_city'] = $customCity;

    // 3. Validation Rules
    if (empty($formData['title'])) {
        $errors[] = 'Event title is required.';
    } elseif (mb_strlen($formData['title']) > 200) {
        $errors[] = 'Event title cannot exceed 200 characters.';
    }

    if (empty($category)) {
        $errors[] = 'Please select or enter an event category.';
    }

    if (empty($formData['description'])) {
        $errors[] = 'Event description is required.';
    }

    if (empty($city)) {
        $errors[] = 'Please specify an Indian city for this event.';
    }

    if (empty($formData['location'])) {
        $errors[] = 'Event venue / meeting point location is required.';
    }

    // Validate Event Date
    if (empty($formData['event_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $formData['event_date'])) {
        $errors[] = 'Please provide a valid date in YYYY-MM-DD format.';
    }

    // Validate Event Time
    if (empty($formData['event_time'])) {
        $errors[] = 'Event start time is required.';
    } else {
        // Normalize time (e.g. HH:MM -> HH:MM:00)
        if (preg_match('/^\d{2}:\d{2}$/', $formData['event_time'])) {
            $formattedTime = $formData['event_time'] . ':00';
        } elseif (preg_match('/^\d{2}:\d{2}:\d{2}$/', $formData['event_time'])) {
            $formattedTime = $formData['event_time'];
        } else {
            $errors[] = 'Invalid time format. Please provide a standard time (e.g. 18:00).';
        }
    }

    // Validate Price
    if (!is_numeric($priceInput) || (float)$priceInput < 0) {
        $errors[] = 'Ticket price must be a non-negative number in INR (e.g. 0.00 for free).';
    } else {
        $price = round((float)$priceInput, 2);
        $formData['price'] = (string)$price;
    }

    // Validate Total Capacity
    $totalCapacity = filter_var($capacityInput, FILTER_VALIDATE_INT);
    if ($totalCapacity === false || $totalCapacity < 1) {
        $errors[] = 'Total capacity must be a positive integer of at least 1 seat.';
    } else {
        $formData['total_capacity'] = (string)$totalCapacity;
    }

    // Validate Status
    $allowedStatuses = ['active', 'draft', 'completed', 'cancelled'];
    if (!in_array($statusInput, $allowedStatuses, true)) {
        $statusInput = 'active';
    }
    $formData['status'] = $statusInput;

    // 4. Handle Optional Image Upload
    $imageFilename = 'default-event.jpg';
    $uploadError = null;
    $uploadedImage = handle_event_image_upload('event_image', $uploadError);

    if ($uploadedImage === false) {
        $errors[] = $uploadError;
    } elseif ($uploadedImage !== null) {
        $imageFilename = $uploadedImage;
    }

    // 5. Insert Record if No Errors
    if (empty($errors)) {
        try {
            // New events start with available_seats exactly equal to total_capacity
            $initialAvailableSeats = $totalCapacity;

            $insertStmt = $pdo->prepare('
                INSERT INTO events (
                    title, category, description, city, location, 
                    event_date, event_time, price, total_capacity, available_seats, 
                    image, status, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, NOW()
                )
            ');

            $insertStmt->execute([
                $formData['title'],
                $category,
                $formData['description'],
                $city,
                $formData['location'],
                $formData['event_date'],
                $formattedTime,
                $price,
                $totalCapacity,
                $initialAvailableSeats,
                $imageFilename,
                $statusInput,
                $currentUser['id']
            ]);

            $newEventId = $pdo->lastInsertId();

            set_flash('success', 'Event "' . $formData['title'] . '" was successfully created and published in ' . $city . '!');
            redirect('events.php');

        } catch (PDOException $e) {
            error_log('[OFFGRID EVENT CREATE ERROR] ' . $e->getMessage());
            $errors[] = 'A database error occurred while creating the event. Please check the inputs and try again.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Reusable Admin Navigation Bar -->
<?php require_once __DIR__ . '/../includes/admin-nav.php'; ?>

<!-- Header -->
<div class="admin-header">
    <div class="admin-title">
        <h1>Create New Community Event</h1>
        <p>Publish a new workshop, trek, cultural evening, or meetup in any city across India.</p>
    </div>
    <div>
        <a href="events.php" class="btn btn-outline btn-sm">&larr; Back to Events List</a>
    </div>
</div>

<!-- Form Card -->
<div class="admin-form-card">
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

    <form action="event-create.php" method="POST" enctype="multipart/form-data" id="eventCreateForm">
        <?= csrf_field() ?>

        <!-- Event Title -->
        <div class="form-group">
            <label for="title" class="form-label">Event Title <span style="color: #ef4444;">*</span></label>
            <input 
                type="text" 
                id="title" 
                name="title" 
                class="form-control" 
                placeholder="e.g. Western Ghats Stargazing & Bushcraft Camp" 
                value="<?= e($formData['title']) ?>" 
                required 
                maxlength="200"
            >
            <div class="form-help">A concise, engaging title for the community gathering (max 200 characters).</div>
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
                    placeholder="Or enter custom category name..." 
                    value="<?= e($formData['custom_category']) ?>"
                    style="margin-top: 0.5rem;"
                >
            </div>

            <!-- Status -->
            <div class="form-group">
                <label for="status" class="form-label">Publication Status</label>
                <select id="status" name="status" class="form-control" style="cursor: pointer;">
                    <option value="active" <?= ($formData['status'] === 'active') ? 'selected' : '' ?>>Active (Visible on Public Catalog)</option>
                    <option value="draft" <?= ($formData['status'] === 'draft') ? 'selected' : '' ?>>Draft (Saved Privately)</option>
                </select>
                <div class="form-help">Active events appear immediately in search and city filters across India.</div>
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
                    placeholder="Or enter new Indian city (e.g. Mysuru, Indore, Shimla)..." 
                    value="<?= e($formData['custom_city']) ?>"
                    style="margin-top: 0.5rem;"
                >
                <div class="form-help">Select an existing hub or type any Indian city.</div>
            </div>

            <!-- Venue / Location -->
            <div class="form-group">
                <label for="location" class="form-label">Venue / Meeting Point <span style="color: #ef4444;">*</span></label>
                <input 
                    type="text" 
                    id="location" 
                    name="location" 
                    class="form-control" 
                    placeholder="e.g. Bandra Amphitheatre, Bandstand Promenade" 
                    value="<?= e($formData['location']) ?>" 
                    required 
                    maxlength="200"
                >
                <div class="form-help">Specific meeting landmark, studio, or trail entrance.</div>
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
                <div class="form-help">Time is scheduled in Indian Standard Time (IST, UTC+05:30).</div>
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
                    placeholder="0.00" 
                    value="<?= e($formData['price']) ?>" 
                    required
                >
                <div class="form-help">Enter 0.00 for free community gatherings. Simulated demo checkout.</div>
            </div>

            <div class="form-group">
                <label for="total_capacity" class="form-label">Total Capacity (Seats) <span style="color: #ef4444;">*</span></label>
                <input 
                    type="number" 
                    min="1" 
                    id="total_capacity" 
                    name="total_capacity" 
                    class="form-control" 
                    placeholder="30" 
                    value="<?= e($formData['total_capacity']) ?>" 
                    required
                >
                <div class="form-help">Maximum participants allowed. Available seats will be initialized to this value.</div>
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
                placeholder="Describe the activity, itinerary, what attendees need to bring, and host guidelines..." 
                required
            ><?= e($formData['description']) ?></textarea>
        </div>

        <!-- Cover Photo Upload with Vanilla JS Live Preview -->
        <div class="form-group">
            <label for="event_image" class="form-label">Event Cover Photo (Optional)</label>
            <input 
                type="file" 
                id="event_image" 
                name="event_image" 
                class="form-control" 
                accept="image/jpeg,image/png,image/webp"
                style="cursor: pointer;"
            >
            <div class="form-help">Supported formats: JPG, PNG, WebP (Max 5 MB). If omitted, a default banner is assigned.</div>

            <!-- Image Preview Box -->
            <div class="image-preview-wrapper">
                <div>
                    <span style="font-size: 0.775rem; color: var(--text-muted); display: block; margin-bottom: 0.25rem;">Preview:</span>
                    <div class="image-preview-box">
                        <img id="imagePreview" src="../assets/images/events/default-event.jpg" alt="Preview Image">
                    </div>
                </div>
                <div style="font-size: 0.85rem; color: var(--text-secondary); max-width: 320px;">
                    A sharp 16:9 photo helps participants visualize the gathering. High-resolution images are scaled responsively on desktop and mobile.
                </div>
            </div>
        </div>

        <!-- Submit Controls -->
        <div class="admin-actions">
            <button type="submit" class="btn btn-primary">
                ➕ Publish Event
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
                // Verify file type matches image before preview
                if (file.type.match(/^image\//)) {
                    var reader = new FileReader();
                    reader.onload = function(evt) {
                        imagePreview.src = evt.target.result;
                    };
                    reader.readAsDataURL(file);
                } else {
                    alert('Please select an image file (JPG, PNG, WebP).');
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

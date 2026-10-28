# OFFGRID — Community Event Booking Platform

OFFGRID is a full-stack, lightweight community event booking website built from scratch with native **PHP**, **MySQL**, **HTML5**, **Vanilla CSS**, and **Vanilla JavaScript**. It is designed specifically for local deployment using **XAMPP** without any heavy external frameworks or dependencies.

---

## 📁 Project Architecture & Folder Structure

```text
usha pro(2)/ (or C:/xampp/htdocs/offgrid/)
│
├── admin/                     # Administrator portal scripts
│   ├── .gitkeep
│   ├── index.php              # Admin dashboard & stats overview (Step 4)
│   ├── create-event.php       # Form to publish new events (Step 4)
│   ├── events.php             # Manage / edit / cancel events (Step 4)
│   └── bookings.php           # View all participant bookings (Step 4)
│
├── assets/                    # Static frontend assets
│   ├── css/
│   │   ├── .gitkeep
│   │   ├── style.css          # Main stylesheet (minimalist & responsive)
│   │   └── admin.css          # Admin panel styling
│   ├── js/
│   │   ├── .gitkeep
│   │   └── main.js            # Client-side scripts & simulated checkout UI
│   └── images/
│       └── .gitkeep           # Static site illustrations & event icons
│
├── config/
│   ├── .gitkeep
│   └── db.php                 # Single PDO database configuration (Step 2)
│
├── database/
│   └── schema.sql             # MySQL database schema, constraints & seed data
│
├── includes/                  # Reusable PHP modular components
│   ├── .gitkeep
│   ├── header.php             # Navigation bar and meta tags
│   ├── footer.php             # Page footer & scripts
│   └── functions.php          # Session helper, CSRF, and sanitization utilities
│
├── uploads/                   # Uploaded event cover posters
│   └── .gitkeep
│
├── index.php                  # Visitor event discovery & homepage (Step 3)
├── event-details.php          # Single event details & booking action (Step 3)
├── booking.php                # Booking processing & simulated demo payment (Step 3)
├── my-bookings.php            # Registered member booking history & receipts (Step 3)
├── login.php                  # Authentication page for users & admins (Step 2)
├── register.php               # Member account registration (Step 2)
├── logout.php                 # Safe session termination (Step 2)
└── README.md                  # Comprehensive setup instructions & guide
```

---

## 🗄️ Database Architecture & Relationships

The database is named `offgrid_db` and uses the `InnoDB` storage engine with `utf8mb4` encoding to ensure full UTF-8 character support and foreign key enforcement.

### 1. `users` Table
Stores registered community members and administrators. Passwords are securely stored using PHP's standard `password_hash()` (Bcrypt).
- `id` (INT, Primary Key, Auto Increment)
- `name` (VARCHAR 100) — Full name of the user
- `email` (VARCHAR 150, UNIQUE) — Used for login
- `password` (VARCHAR 255) — Bcrypt hash string
- `role` (ENUM: `'admin'`, `'user'`) — Access control level
- `created_at` (TIMESTAMP)

### 2. `events` Table
Stores all community events published by the administrator.
- `id` (INT, Primary Key, Auto Increment)
- `title` (VARCHAR 200) — Title of the gathering
- `category` (VARCHAR 50) — Category (e.g. *Outdoor & Adventure*, *Workshop*)
- `description` (TEXT) — Event description and itinerary
- `location` (VARCHAR 200) — Meeting venue or outdoor coordinates
- `event_date` (DATE) — Date of the event
- `event_time` (TIME) — Start time
- `price` (DECIMAL 10,2) — Registration fee in Indian Rupees (₹0.00 for free events)
- `total_capacity` (INT) — Maximum allowed attendees
- `available_seats` (INT) — Remaining open slots
- `image` (VARCHAR 255) — Banner filename
- `status` (ENUM: `'active'`, `'completed'`, `'cancelled'`)
- `created_by` (INT, Foreign Key referencing `users.id`)
- `created_at` (TIMESTAMP)

### 3. `bookings` Table
Maintains participant reservations and simulated demo payment records.
- `id` (INT, Primary Key, Auto Increment)
- `booking_code` (VARCHAR 32, UNIQUE) — Friendly reference (e.g. `OG-2026-8491`)
- `user_id` (INT, Foreign Key referencing `users.id`)
- `event_id` (INT, Foreign Key referencing `events.id`)
- `tickets_count` (INT) — Number of seats reserved
- `total_amount` (DECIMAL 10,2) — Total fee charged
- `payment_method` (VARCHAR 50) — Marked clearly as `Simulated Demo Payment`
- `payment_status` (ENUM: `'confirmed'`, `'cancelled'`)
- `payment_reference` (VARCHAR 64) — Simulated transaction receipt number
- `booking_date` (TIMESTAMP)

> **Key Integrity Constraints:**
> - **Duplicate Prevention:** A `UNIQUE (user_id, event_id)` constraint ensures that a user cannot accidentally register for the same event more than once.
> - **Overbooking Prevention:** Handled in application logic via `available_seats` tracking and atomic database transactions.
> - **Cascade Safety:** Deleting a user or event cascades to bookings or sets creator references appropriately.

---

## 🚀 Initial Setup Guide (XAMPP on Windows)

Follow these steps to set up the database and project on your local machine:

### Step 1: Start XAMPP Services
1. Open the **XAMPP Control Panel**.
2. Click **Start** next to **Apache**.
3. Click **Start** next to **MySQL**.
4. Verify both show green indicator status.

### Step 2: Import the Database Schema
1. Open your web browser and navigate to:
   ```text
   http://localhost/phpmyadmin/
   ```
2. Click on the **Import** tab located in the top navigation bar.
3. Under **File to import**, click **Choose File** (or **Browse**).
4. Select the file:
   ```text
   usha pro(2)/database/schema.sql
   ```
5. Leave all settings at their defaults and click the **Import** button at the bottom of the page.
6. You will see a success message: `Import has been successfully finished...` and a new database named `offgrid_db` will appear in the left sidebar.

### Step 3: Link Project to XAMPP `htdocs`
Apache serves files from `C:\xampp\htdocs\`. You can link or copy the project using either of the following two options:

#### Option A (Recommended — Direct Copy):
Copy this project folder into `C:\xampp\htdocs\` and rename the folder to `offgrid`:
```text
Source:      c:\Users\nivpa\OneDrive\Desktop\usha pro(2)
Destination: C:\xampp\htdocs\offgrid
```

#### Option B (Advanced — Directory Link via PowerShell as Administrator):
Create a directory link so edits in your Desktop folder reflect directly in Apache:
```powershell
New-Item -ItemType SymbolicLink -Path "C:\xampp\htdocs\offgrid" -Target "c:\Users\nivpa\OneDrive\Desktop\usha pro(2)"
```

### Step 4: Access the Website
Once configured and files are built, access the project in your browser at:
```text
http://localhost/offgrid/
```

---

## 🔑 Pre-Configured Test Credentials

The database schema includes pre-seeded demo accounts with encrypted passwords ready for testing:

| Role | Email | Password | Access Rights |
| :--- | :--- | :--- | :--- |
| **Administrator** | `admin@offgrid.local` | `Admin@123` | Full Admin Dashboard, Create/Edit/Cancel Events, View Attendees |
| **Community Member** | `demo@offgrid.local` | `User@123` | Browse Events, Register/Book Seats, Simulated Payment, View Booking History |

---

## 🛡️ Security & Demo Payment Note
- All database queries will use **PHP PDO prepared statements** to protect against SQL injection.
- Passwords are encrypted using native PHP `password_hash()` with `PASSWORD_BCRYPT`.
- **Demo Payment Simulation:** As required, NO real financial transactions take place, and NO real credit card or sensitive bank details are requested or recorded.

---

## 🗺️ Next Implementation Steps
1. **Step 2:** Database connection (`config/db.php`), session helper functions (`includes/functions.php`), and authentication screens (`login.php`, `register.php`, `logout.php`).
2. **Step 3:** Design system (`assets/css/style.css`), header/footer templates, public event browsing (`index.php`), event details page (`event-details.php`), and simulated checkout (`booking.php`).
3. **Step 4:** User booking history (`my-bookings.php`), admin portal (`admin/index.php`, `admin/create-event.php`, `admin/events.php`, `admin/bookings.php`).

-- ====================================================================
-- OFFGRID Community Event Booking Platform
-- Database Schema & Initial Seed Data
-- ====================================================================

-- 1. Create database if it does not already exist
CREATE DATABASE IF NOT EXISTS `offgrid_db`
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE `offgrid_db`;

-- Disable foreign key checks temporarily while dropping/creating tables
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `bookings`;
DROP TABLE IF EXISTS `events`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- ====================================================================
-- Table: users
-- Stores both community members and administrative accounts.
-- Passwords must ALWAYS be hashed using PHP's password_hash().
-- ====================================================================
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    `status` ENUM('active', 'suspended') NOT NULL DEFAULT 'active'
        COMMENT 'active = normal access; suspended = login blocked',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- Table: events
-- Stores all community events published by the admin.
-- Tracks capacity and available seats to prevent overbooking.
-- ====================================================================
CREATE TABLE `events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `category` VARCHAR(50) NOT NULL DEFAULT 'Outdoor & Adventure',
    `description` TEXT NOT NULL,
    `city` VARCHAR(100) NOT NULL DEFAULT 'All India',
    `location` VARCHAR(200) NOT NULL,
    `event_date` DATE NOT NULL,
    `event_time` TIME NOT NULL,
    `price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `total_capacity` INT NOT NULL,
    `available_seats` INT NOT NULL,
    `image` VARCHAR(255) DEFAULT 'default-event.jpg',
    `status` ENUM('active', 'draft', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_events_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT `chk_seats_non_negative`
        CHECK (`available_seats` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- Table: bookings
-- Records participant registrations and simulated payment receipts.
-- Includes a UNIQUE constraint on (user_id, event_id) to prevent
-- duplicate registrations by the same user for the same event.
-- ====================================================================
CREATE TABLE `bookings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `booking_code` VARCHAR(32) NOT NULL UNIQUE,
    `user_id` INT NOT NULL,
    `event_id` INT NOT NULL,
    `tickets_count` INT NOT NULL DEFAULT 1,
    `total_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Simulated Demo Payment',
    `payment_status` ENUM('confirmed', 'cancelled') NOT NULL DEFAULT 'confirmed',
    `payment_reference` VARCHAR(64) NOT NULL,
    `booking_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_bookings_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_bookings_event`
        FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    -- Prevent duplicate bookings by the same user for the same event
    CONSTRAINT `unique_user_event_booking`
        UNIQUE (`user_id`, `event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- Table: reviews
-- Stores attendee star ratings and community testimonials for events.
-- Strictly limited to users with confirmed registrations.
-- ====================================================================
CREATE TABLE IF NOT EXISTS `reviews` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `rating` TINYINT NOT NULL,
    `comment` TEXT NOT NULL,
    `status` ENUM('approved', 'hidden') NOT NULL DEFAULT 'approved',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `moderated_by` INT NULL,
    `moderated_at` TIMESTAMP NULL,
    `moderation_note` VARCHAR(255) NULL,
    CONSTRAINT `fk_reviews_event`
        FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_reviews_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_reviews_moderated_by`
        FOREIGN KEY (`moderated_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    -- Exactly one review per user per event
    CONSTRAINT `unique_user_event_review`
        UNIQUE (`user_id`, `event_id`),
    CONSTRAINT `chk_review_rating`
        CHECK (`rating` >= 1 AND `rating` <= 5),
    INDEX `idx_reviews_event_status` (`event_id`, `status`),
    INDEX `idx_reviews_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- Table: notifications
-- Stores in-app notifications for community members.
-- Unique key on (user_id, type, ref_id) prevents duplicate notifications
-- from retried requests or multiple admin actions.
-- ====================================================================
CREATE TABLE IF NOT EXISTS `notifications` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT NOT NULL,
    `type`       ENUM('booking_confirmed','booking_cancelled','event_cancelled','event_updated','event_reminder') NOT NULL,
    `title`      VARCHAR(200) NOT NULL,
    `message`    TEXT NOT NULL,
    `ref_id`     INT NULL    COMMENT 'booking.id or event.id depending on type',
    `link`       VARCHAR(300) NULL COMMENT 'relative URL for View Details action',
    `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_notif_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_notif_user_read` (`user_id`, `is_read`),
    INDEX `idx_notif_created`   (`created_at`),
    UNIQUE KEY `uq_notif_dedup` (`user_id`, `type`, `ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- Table: saved_events
-- Records saved/bookmarked events for logged-in community members.
-- Unique key on (user_id, event_id) prevents duplicate saves.
-- ====================================================================
CREATE TABLE IF NOT EXISTS `saved_events` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT NOT NULL,
    `event_id`   INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_saved_events_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_saved_events_event`
        FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `unique_user_saved_event`
        UNIQUE (`user_id`, `event_id`),
    INDEX `idx_saved_user` (`user_id`),
    INDEX `idx_saved_event` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- Initial Seed Data
-- ====================================================================

-- 1. Default Administrator and Demo Community Member
-- Passwords:
--   Admin: Admin@123
--   Demo User: User@123
INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`) VALUES
(1, 'OFFGRID Admin', 'admin@offgrid.local', '$2y$10$WVUqXcpN22OXjBfv6q81pOpYHlxdGU.I5ox9Pp0qu/63j/sb8fEl2', 'admin'),
(2, 'Alex Woods', 'demo@offgrid.local', '$2y$10$CYpx50Z2Nw1Q7Dg9ngVze.3hqLY.P8e1lEoEJgQbmyVNVfVYjPnFy', 'user');

-- 2. Sample Community Events Across India
INSERT INTO `events` (`id`, `title`, `category`, `description`, `city`, `location`, `event_date`, `event_time`, `price`, `total_capacity`, `available_seats`, `image`, `status`, `created_by`) VALUES
(
    1,
    'Western Ghats Stargazing & Bushcraft Camp',
    'Outdoor & Trekking',
    'Join our community under the pristine dark skies of the Western Ghats. Learn stargazing, night navigation, primitive campcraft, and outdoor survival skills led by experienced local trek leaders.',
    'Pune',
    'Lonavala Hills, Western Ghats',
    '2026-11-15',
    '18:30:00',
    499.00,
    20,
    19,
    'stargazing.jpg',
    'active',
    1
),
(
    2,
    'Sabarmati Riverfront Kayaking & Eco Clean-up',
    'Water Sports & Eco',
    'Morning community paddle and river conservation drive along Ahmedabad\'s Sabarmati Riverfront. We combine peaceful kayaking with community shoreline clean-up.',
    'Ahmedabad',
    'Riverfront Watersports Pier, Sabarmati Riverfront',
    '2026-11-22',
    '07:30:00',
    350.00,
    15,
    15,
    'kayaking.jpg',
    'active',
    1
),
(
    3,
    'Bengaluru DIY Solar & Rooftop Battery Masterclass',
    'Technology & Clean Energy',
    'Practical hands-on workshop on sizing solar PV panels, hybrid inverters, and LiFePO4 battery banks for homes, cabins, and field trips in India.',
    'Bengaluru',
    'Koramangala Community MakerSpace',
    '2026-11-28',
    '10:00:00',
    299.00,
    25,
    25,
    'solar-workshop.jpg',
    'active',
    1
),
(
    4,
    'Delhi Urban Permaculture & Native Seed Workshop',
    'Workshops & Crafts',
    'Practical masterclass on rooftop gardening, composting, organic pest management, and indigenous seed conservation for North Indian climates.',
    'Delhi',
    'Sunder Nursery Eco Pavilion',
    '2026-12-05',
    '09:30:00',
    199.00,
    30,
    30,
    'permaculture.jpg',
    'active',
    1
),
(
    5,
    'Surat Coastal Mangrove Trail & Birdwatching Walk',
    'Nature & Wildlife',
    'Early morning guided mangrove walk, wetland photography, and winter migratory bird spotting along the Tapi river estuary.',
    'Surat',
    'Dumas Shore & Tapi Estuary',
    '2026-12-12',
    '06:30:00',
    0.00,
    20,
    20,
    'mangrove-trail.jpg',
    'active',
    1
),
(
    6,
    'Mumbai Indie Acoustic Night & Sunset Jam',
    'Live Music & Concerts',
    'Unwind by the Arabian Sea with soulful indie acoustic performances, acoustic guitars, and open-mic jam sessions by emerging local musicians.',
    'Mumbai',
    'Bandra Amphitheatre, Bandstand Promenade',
    '2026-12-18',
    '18:00:00',
    499.00,
    35,
    35,
    'music-concert.jpg',
    'active',
    1
),
(
    7,
    'Hyderabad Open Source Hackathon & AI Sprint',
    'Technology & Coding',
    'A weekend gathering for developers, students, and tech enthusiasts. Collaborate on open-source tools, machine learning pipelines, and civic tech solutions.',
    'Hyderabad',
    'T-Hub Innovation Hub, Gachibowli',
    '2026-12-20',
    '09:00:00',
    0.00,
    40,
    40,
    'tech-hackathon.jpg',
    'active',
    1
),
(
    8,
    'Kolkata Heritage Street Photography Walk',
    'Art & Photography',
    'Explore the timeless lanes, antique bookshops, and artisanal workshops of North Kolkata with a vibrant community of photographers and visual storytellers.',
    'Kolkata',
    'College Street & Kumartuli Ghats, North Kolkata',
    '2026-12-26',
    '07:00:00',
    250.00,
    20,
    20,
    'photography-walk.jpg',
    'active',
    1
),
(
    9,
    'Jaipur Handcrafted Blue Pottery Workshop',
    'Art & Crafts',
    'Discover the ancient Persian-influenced ceramic art of Rajasthan. Learn clay shaping, natural mineral glazing, and traditional floral brushwork from master potters.',
    'Jaipur',
    'Jawahar Kala Kendra Art Studio, JLN Marg',
    '2027-01-09',
    '10:30:00',
    599.00,
    18,
    18,
    'blue-pottery.jpg',
    'active',
    1
),
(
    10,
    'Goa Sunset Beach Volleyball & Fitness Social',
    'Sports & Fitness',
    'A friendly community beach volleyball meet and sunset wellness movement session on North Goa sands. Open to all skill levels with cool-down refreshments.',
    'Goa',
    'Morjim Beach Coastal Arena, North Goa',
    '2027-01-16',
    '16:30:00',
    200.00,
    24,
    24,
    'beach-sports.jpg',
    'active',
    1
),
(
    11,
    'Chennai Classical Carnatic & Bharatanatyam Evening',
    'Culture & Heritage',
    'Immerse yourself in authentic Indian classical arts under evening temple lights. Featuring live violin, mridangam, and expressive classical dance performances.',
    'Chennai',
    'Kalakshetra Foundation Open Amphitheatre, Thiruvanmiyur',
    '2027-01-23',
    '18:00:00',
    350.00,
    50,
    50,
    'cultural-dance.jpg',
    'active',
    1
),
(
    12,
    'Lucknow Awadhi Street Food & Culinary Heritage Walk',
    'Food & Culinary',
    'A guided community walk through the culinary heart of Old Lucknow. Sample authentic Galouti kebabs, roomali rotis, aromatic biryanis, and historic sweets.',
    'Lucknow',
    'Historic Chowk Square, Old Lucknow',
    '2027-01-30',
    '17:30:00',
    450.00,
    25,
    25,
    'food-festival.jpg',
    'active',
    1
);

-- 3. Sample Seed Booking (User 2 booked Event 1)
INSERT INTO `bookings` (`id`, `booking_code`, `user_id`, `event_id`, `tickets_count`, `total_amount`, `payment_method`, `payment_status`, `payment_reference`, `booking_date`) VALUES
(
    1,
    'OG-2026-8491',
    2,
    1,
    1,
    499.00,
    'Simulated Demo Payment (INR)',
    'confirmed',
    'DEMO-TXN-719302',
    NOW()
);

-- ====================================================================
-- Step 9: Admin Analytics & Reporting Module
-- Aggregates telemetry across users, events, bookings, reviews, and
-- notifications. Utilizes standard indexes; no schema migrations needed.
-- ====================================================================

-- ====================================================================
-- Step 10: Event Discovery, Personalization & Saved Events Module
-- Introduces `saved_events` table for member bookmarks/wishlists with
-- unique constraint (user_id, event_id), foreign keys, and indexes.
-- Personalization recommendations and advanced filters query active
-- tables deterministically.
-- ====================================================================




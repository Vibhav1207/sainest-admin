-- =====================================================================
--  HOTEL SAI NEST — HOTEL MANAGEMENT SYSTEM
--  Database Schema
--  Import this file into a fresh MySQL/MariaDB database before use.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Staff / user accounts (Admin, Manager, Front Desk, Housekeeping)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(120) NOT NULL,
  `username` VARCHAR(60) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','manager','frontdesk','housekeeping') NOT NULL DEFAULT 'frontdesk',
  `phone` VARCHAR(20) DEFAULT NULL,
  `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `last_login` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Room types
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `room_types` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `base_rate` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `max_guests` TINYINT UNSIGNED NOT NULL DEFAULT 2,
  `description` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_type_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Rooms
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rooms` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_number` VARCHAR(20) NOT NULL,
  `room_type_id` INT UNSIGNED NOT NULL,
  `floor` VARCHAR(20) DEFAULT NULL,
  `status` ENUM('available','occupied','dirty','maintenance','reserved') NOT NULL DEFAULT 'available',
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_room_number` (`room_number`),
  KEY `fk_room_type` (`room_type_id`),
  CONSTRAINT `fk_room_type` FOREIGN KEY (`room_type_id`) REFERENCES `room_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Guests (identity / KYC data — subject to 1-year auto purge policy)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `guests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(120) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `email` VARCHAR(120) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `city` VARCHAR(80) DEFAULT NULL,
  `state` VARCHAR(80) DEFAULT NULL,
  `nationality` VARCHAR(80) DEFAULT 'Indian',
  `age` TINYINT UNSIGNED DEFAULT NULL,
  `gender` ENUM('male','female','other','') DEFAULT '',
  `id_proof_type` ENUM('aadhar','pan','passport','driving_license','voter_id','other','') DEFAULT '',
  `id_proof_number` VARCHAR(60) DEFAULT NULL,
  `id_proof_photo` VARCHAR(255) DEFAULT NULL,
  `id_proof_photo_back` VARCHAR(255) DEFAULT NULL,
  `last_stay_date` DATE DEFAULT NULL COMMENT 'Most recent check-in date, used for 1-year data retention purge',
  `is_anonymized` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_last_stay` (`last_stay_date`),
  KEY `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Bookings (one booking = one room stay, may hold multiple guests)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bookings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_code` VARCHAR(20) NOT NULL,
  `booking_type` ENUM('regular','corporate') NOT NULL DEFAULT 'regular',
  `company_name` VARCHAR(150) DEFAULT NULL,
  `company_gst_number` VARCHAR(30) DEFAULT NULL,
  `company_address` TEXT DEFAULT NULL,
  `company_contact_person` VARCHAR(100) DEFAULT NULL,
  `company_phone` VARCHAR(30) DEFAULT NULL,
  `room_id` INT UNSIGNED NOT NULL,
  `primary_guest_id` INT UNSIGNED NOT NULL,
  `checkin_datetime` DATETIME NOT NULL,
  `expected_checkout_date` DATE NOT NULL,
  `actual_checkout_datetime` DATETIME DEFAULT NULL,
  `num_guests` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `status` ENUM('checked_in','checked_out','cancelled','reserved') NOT NULL DEFAULT 'checked_in',
  `rate_per_night` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `advance_amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `extra_amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `booking_source` ENUM('walk_in','phone','online','agent','ota_mmt','ota_goibibo','ota_booking_com','ota_other','other') NOT NULL DEFAULT 'walk_in',
  `agent_or_ota_name` VARCHAR(120) DEFAULT NULL,
  `commission_percent` DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Internal only — never printed on guest invoice',
  `commission_amount` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Internal only — never printed on guest invoice',
  `commission_status` ENUM('not_applicable','pending','paid') NOT NULL DEFAULT 'not_applicable',
  `tax_percent` DECIMAL(5,2) NOT NULL DEFAULT 12.00,
  `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `special_requests` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `checked_out_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_code` (`booking_code`),
  KEY `fk_booking_room` (`room_id`),
  KEY `fk_booking_guest` (`primary_guest_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_booking_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
  CONSTRAINT `fk_booking_guest` FOREIGN KEY (`primary_guest_id`) REFERENCES `guests` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Guests attached to a booking (supports more than one guest per room)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `booking_guests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id` INT UNSIGNED NOT NULL,
  `guest_id` INT UNSIGNED NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_bg_booking` (`booking_id`),
  KEY `fk_bg_guest` (`guest_id`),
  CONSTRAINT `fk_bg_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bg_guest` FOREIGN KEY (`guest_id`) REFERENCES `guests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Rooms attached to a booking (supports multi-room bookings)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `booking_rooms` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id` INT UNSIGNED NOT NULL,
  `room_id` INT UNSIGNED NOT NULL,
  `rate_per_night` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `fk_br_booking` (`booking_id`),
  KEY `fk_br_room` (`room_id`),
  CONSTRAINT `fk_br_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_br_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Extra charges added during guest stay (Edit Stay feature)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `booking_extra_charges` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id` INT UNSIGNED NOT NULL,
  `charge_name` VARCHAR(100) NOT NULL,
  `qty` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `remarks` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_bec_booking` (`booking_id`),
  CONSTRAINT `fk_bec_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Payments received against a booking
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `mode` ENUM('cash','card','upi','bank_transfer','online','other') NOT NULL DEFAULT 'cash',
  `payment_type` ENUM('advance','partial','final','refund') NOT NULL DEFAULT 'partial',
  `received_by` INT UNSIGNED DEFAULT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_pay_booking` (`booking_id`),
  CONSTRAINT `fk_pay_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Housekeeping tasks
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `housekeeping_tasks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id` INT UNSIGNED NOT NULL,
  `task_type` ENUM('cleaning','maintenance','inspection','turndown') NOT NULL DEFAULT 'cleaning',
  `status` ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
  `priority` ENUM('normal','urgent') NOT NULL DEFAULT 'normal',
  `assigned_to` INT UNSIGNED DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_hk_room` (`room_id`),
  CONSTRAINT `fk_hk_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Invoices generated at checkout
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id` INT UNSIGNED NOT NULL,
  `invoice_number` VARCHAR(30) NOT NULL,
  `room_charges` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Guest-facing room amount (base room rate + hidden commission markup, if any). Shown as one figure on the invoice.',
  `commission_amount` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Internal only — the portion of room_charges that is commission, never itemised or shown to the guest. Actual room revenue = room_charges - commission_amount.',
  `extra_charges` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `tax_amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `paid_amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `balance_amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `generated_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_number` (`invoice_number`),
  KEY `fk_inv_booking` (`booking_id`),
  CONSTRAINT `fk_inv_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Invoice line items (extra charges: food, laundry, etc.)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` INT UNSIGNED NOT NULL,
  `description` VARCHAR(150) NOT NULL,
  `qty` DECIMAL(6,2) NOT NULL DEFAULT 1,
  `rate` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_ii_invoice` (`invoice_id`),
  CONSTRAINT `fk_ii_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Activity log (audit trail)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(80) NOT NULL,
  `details` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Key/value settings (hotel profile, tax, retention policy, etc.)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` VARCHAR(60) NOT NULL,
  `setting_value` TEXT,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- SEED DATA
-- =====================================================================

-- Default admin login  ->  username: admin   password: Admin@123
-- (Change this immediately from Settings > Users after first login)
-- INSERT IGNORE is used throughout this seed section so that re-running
-- this file (e.g. after a partial/interrupted import) is always safe and
-- will never fail with "duplicate entry" errors on data that already exists.
INSERT IGNORE INTO `users` (`full_name`, `username`, `password_hash`, `role`, `status`) VALUES
('Hotel Administrator', 'admin', '$2y$10$iUkrTAJbYxGHmydWhcw97OO6z2X4g67fE3qbPUBo4nyfu8c9OzdeK' ,'admin', 'active');
-- NOTE: the hash above corresponds to password: Admin@123

INSERT IGNORE INTO `room_types` (`name`, `base_rate`, `max_guests`, `description`) VALUES
('Standard Double Bed', 1200.00, 2, 'Comfortable AC room with standard double bed'),
('Standard Deluxe', 1800.00, 3, 'Spacious standard deluxe room with premium amenities'),
('Standard Three Bed', 2800.00, 5, 'Large room with three beds ideal for families and groups');

INSERT IGNORE INTO `rooms` (`room_number`, `room_type_id`, `floor`, `status`) VALUES
('101', 1, 'Ground', 'available'),
('102', 1, 'Ground', 'available'),
('103', 1, 'Ground', 'available'),
('104', 1, 'Ground', 'available'),
('105', 1, 'Ground', 'available'),
('201', 2, 'First', 'available'),
('202', 2, 'First', 'available'),
('203', 2, 'First', 'available'),
('204', 2, 'First', 'available'),
('205', 2, 'First', 'available'),
('206', 2, 'First', 'available'),
('207', 2, 'First', 'available'),
('208', 2, 'First', 'available'),
('301', 3, 'Second', 'available'),
('302', 3, 'Second', 'available'),
('303', 3, 'Second', 'available'),
('304', 3, 'Second', 'available'),
('305', 3, 'Second', 'available'),
('306', 3, 'Second', 'available'),
('307', 3, 'Second', 'available'),
('308', 3, 'Second', 'available'),
('401', 2, 'Third', 'available'),
('402', 2, 'Third', 'available'),
('403', 2, 'Third', 'available'),
('404', 2, 'Third', 'available'),
('405', 2, 'Third', 'available'),
('406', 2, 'Third', 'available'),
('501', 2, 'Fourth', 'available'),
('502', 2, 'Fourth', 'available'),
('503', 2, 'Fourth', 'available'),
('504', 2, 'Fourth', 'available'),
('505', 2, 'Fourth', 'available'),
('506', 2, 'Fourth', 'available'),
('507', 2, 'Fourth', 'available');

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('hotel_name', 'Hotel Sai Nest'),
('hotel_tagline', 'Premium Comfort, Budget Price — Shirdi'),
('hotel_address', 'Ganpati Palace Chowk, Opp. Vasavi Bhavan, Shirdi 423109, Maharashtra, India'),
('hotel_phone', '9494670808'),
('hotel_whatsapp', '919494670808'),
('hotel_email', 'hotelsainest34@gmail.com'),
('hotel_gst_number', ''),
('currency_symbol', '₹'),
('default_tax_percent', '12'),
('default_checkin_time', '12:00'),
('default_checkout_time', '11:00'),
('data_retention_months', '12'),
('invoice_prefix', 'SNI'),
('booking_prefix', 'SN');

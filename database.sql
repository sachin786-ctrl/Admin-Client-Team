-- ============================================================================
-- CLIENTFLOW - COMPLETE DATABASE SCHEMA & SEED DATA (v2.0 Upgraded)
-- Database Name: `client_flow`
-- Character Set: utf8mb4 (Full Unicode & Emoji support)
-- Collation:     utf8mb4_unicode_ci
-- ============================================================================
--
-- DEFAULT LOGIN CREDENTIALS:
-- ----------------------------------------------------------------------------
-- 1. ADMIN PANEL:
--    URL:      http://localhost/client-flow/admin/login.php
--    Email:    admin@agency.com
--    Password: Admin@123
--
-- 2. CLIENT PORTAL:
--    URL:      http://localhost/client-flow/client/auth/login.php
--    Email:    client@sample.com
--    Password: Client@123
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `client_flow`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `client_flow`;

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. Table structure for table `admin`
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `admin`;
CREATE TABLE `admin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('super_admin','staff') NOT NULL DEFAULT 'super_admin',
  `permissions` text DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `force_password_change` tinyint(1) NOT NULL DEFAULT 0,
  `profile_pic` varchar(255) DEFAULT 'default-avatar.png',
  `cover_pic` varchar(255) DEFAULT 'default-cover.jpg',
  `otp` varchar(10) DEFAULT NULL,
  `otp_expiry` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin Seed Data (Password: Admin@123)
INSERT INTO `admin` (`id`, `name`, `email`, `password`, `role`, `permissions`, `status`, `force_password_change`, `profile_pic`, `cover_pic`, `otp`, `otp_expiry`, `created_at`, `updated_at`) VALUES
(1, 'Super Admin', 'admin@agency.com', '$2y$10$3tht2f23F6uURge/E9RcSOKp/26NiF32iRQl8Qfc63mZo/2Zr7kA6', 'super_admin', NULL, 1, 0, 'default-avatar.png', 'default-cover.jpg', NULL, NULL, NOW(), NOW()),
(2, 'Rahul Sharma - Project Manager', 'staff@agency.com', '$2y$10$3tht2f23F6uURge/E9RcSOKp/26NiF32iRQl8Qfc63mZo/2Zr7kA6', 'staff', '["clients_view","clients_manage","projects_view","projects_manage"]', 1, 0, 'default-avatar.png', 'default-cover.jpg', NULL, NULL, NOW(), NOW());

-- ----------------------------------------------------------------------------
-- 2. Table structure for table `clients`
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `clients`;
CREATE TABLE `clients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `company_name` varchar(150) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `address` text DEFAULT NULL,
  `service_type` varchar(100) DEFAULT 'Web & App Development',
  `profile_pic` varchar(255) DEFAULT 'default-avatar.png',
  `cover_pic` varchar(255) DEFAULT 'default-cover.jpg',
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_client_code` (`client_code`),
  UNIQUE KEY `uk_client_email` (`email`),
  KEY `idx_client_created_by` (`created_by`),
  KEY `idx_client_deleted` (`deleted_at`),
  CONSTRAINT `fk_clients_created_by` FOREIGN KEY (`created_by`) REFERENCES `admin` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample Client Seed Data (Password: Client@123)
INSERT INTO `clients` (`id`, `client_code`, `name`, `company_name`, `email`, `phone`, `password`, `address`, `service_type`, `profile_pic`, `status`, `created_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'BNCL001', 'Rahul Sharma', 'Apex Tech Solutions', 'client@sample.com', '+91 9876543210', '$2y$10$nbAmq6WYDnzBzo5aL1hW7..817.KPrw/miyALOfphB96PJFhn313K', 'Sector 62, Noida, Uttar Pradesh, India', 'Web & App Development', 'default-avatar.png', 1, 1, NOW(), NOW(), NULL);

-- ----------------------------------------------------------------------------
-- 3. Table structure for table `projects`
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `projects`;
CREATE TABLE `projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_code` varchar(20) NOT NULL,
  `client_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `deadline` date DEFAULT NULL,
  `status` enum('draft','proposal_sent','revision_requested','advance_pending','in_progress','on_hold','review','completed','cancelled') NOT NULL DEFAULT 'draft',
  `proposal_sent_at` datetime DEFAULT NULL,
  `agreed_at` datetime DEFAULT NULL,
  `agreed_by_name` varchar(150) DEFAULT NULL,
  `agreed_ip` varchar(50) DEFAULT NULL,
  `agreement_token` varchar(64) DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `client_feedback` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_project_code` (`project_code`),
  KEY `idx_project_client` (`client_id`),
  KEY `idx_project_created_by` (`created_by`),
  KEY `idx_project_status` (`status`),
  KEY `idx_agreement_token` (`agreement_token`),
  KEY `idx_token_expiry` (`token_expires_at`),
  KEY `idx_project_deleted` (`deleted_at`),
  CONSTRAINT `fk_projects_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_projects_admin` FOREIGN KEY (`created_by`) REFERENCES `admin` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample Project Seed Data
INSERT INTO `projects` (`id`, `project_code`, `client_id`, `title`, `description`, `total_amount`, `deadline`, `status`, `proposal_sent_at`, `agreed_at`, `agreed_by_name`, `agreed_ip`, `agreement_token`, `token_expires_at`, `client_feedback`, `created_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'PRJ001', 1, 'Corporate Website & Mobile App', 'Complete modern web application with client portal, milestone payments, and automated notification system.', 25000.00, DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY), 'in_progress', NOW(), NOW(), 'Rahul Sharma', '127.0.0.1', 'd8787f7a8c88e990c8b919a712f5a898b8c8d8e8f8182838485868788898a8b8', DATE_ADD(NOW(), INTERVAL 14 DAY), NULL, 1, NOW(), NOW(), NULL);

-- --------------------------------------------------------
-- 4. Table structure for table `project_logs`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `project_logs`;
CREATE TABLE `project_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `actor_type` enum('admin','client','system') NOT NULL,
  `actor_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_project` (`project_id`),
  CONSTRAINT `fk_logs_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample Project Audit Log Seed Data
INSERT INTO `project_logs` (`id`, `project_id`, `action`, `title`, `description`, `actor_type`, `actor_name`, `created_at`) VALUES
(1, 1, 'created', 'Project Created', 'Project PRJ001 created with 2 milestone payment invoices.', 'admin', 'Super Admin', NOW()),
(2, 1, 'agreed', 'Proposal Agreed & Signed', 'Client Rahul Sharma digitally agreed to project scope and milestone payment schedule.', 'client', 'Rahul Sharma', NOW());

-- --------------------------------------------------------
-- 5. Table structure for table `payments`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_no` varchar(30) NOT NULL,
  `project_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `due_date` date NOT NULL,
  `paid_date` date DEFAULT NULL,
  `status` enum('pending','partial','paid','overdue') NOT NULL DEFAULT 'pending',
  `work_status` enum('not_started','in_progress','delivered','approved') NOT NULL DEFAULT 'not_started',
  `is_advance` tinyint(1) NOT NULL DEFAULT 0,
  `payment_method` varchar(50) DEFAULT NULL,
  `deliverable_url` varchar(500) DEFAULT NULL,
  `deliverable_notes` text DEFAULT NULL,
  `payment_proof` varchar(255) DEFAULT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `last_reminder_sent_at` datetime DEFAULT NULL,
  `overdue_notified_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payment_invoice` (`invoice_no`),
  KEY `idx_payment_project` (`project_id`),
  KEY `idx_payment_client` (`client_id`),
  KEY `idx_payment_status` (`status`),
  KEY `idx_payment_work_status` (`work_status`),
  KEY `idx_payment_deleted` (`deleted_at`),
  CONSTRAINT `fk_payments_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payments_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample Milestone Invoices Seed Data
INSERT INTO `payments` (`id`, `invoice_no`, `project_id`, `client_id`, `title`, `amount`, `paid_amount`, `due_date`, `paid_date`, `status`, `work_status`, `is_advance`, `payment_method`, `deliverable_url`, `deliverable_notes`, `payment_proof`, `transaction_id`, `last_reminder_sent_at`, `overdue_notified_at`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'INV-1001', 1, 1, 'Milestone 1: Advance / Initial Setup & Requirements', 10000.00, 10000.00, DATE_SUB(CURRENT_DATE, INTERVAL 10 DAY), DATE_SUB(CURRENT_DATE, INTERVAL 9 DAY), 'paid', 'approved', 1, 'UPI / GPay', 'https://github.com/agency/project-setup', 'Initial setup and requirements gathered.', NULL, 'UPI-987293847', NULL, NULL, NOW(), NOW(), NULL),
(2, 'INV-1002', 1, 1, 'Milestone 2: UI/UX Wireframes & Frontend Development', 15000.00, 5000.00, DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY), NULL, 'partial', 'in_progress', 0, 'Bank Transfer', 'https://figma.com/file/sample-preview', 'Figma layout delivered. Under review.', NULL, NULL, NULL, NULL, NOW(), NOW(), NULL);

-- --------------------------------------------------------
-- 6. Table structure for table `reminder_logs`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `reminder_logs`;
CREATE TABLE `reminder_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `type` enum('email','whatsapp','both') NOT NULL DEFAULT 'both',
  `sent_by` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reminder_payment` (`payment_id`),
  KEY `idx_reminder_client` (`client_id`),
  KEY `idx_reminder_admin` (`sent_by`),
  CONSTRAINT `fk_reminder_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reminder_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reminder_admin` FOREIGN KEY (`sent_by`) REFERENCES `admin` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 7. Table structure for table `notifications`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_type` enum('admin','client') NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `type` varchar(50) DEFAULT 'info',
  `icon` varchar(50) DEFAULT 'bi-bell-fill',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_lookup` (`user_type`, `user_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample Notifications Seed Data
INSERT INTO `notifications` (`id`, `user_type`, `user_id`, `title`, `message`, `link`, `type`, `icon`, `is_read`, `created_at`) VALUES
(1, 'admin', 1, 'Workspace Initialized', 'Welcome to ClientFlow! System is ready with upgraded dual status tracking.', '/client-flow/admin/dashboard.php', 'success', 'bi-check-circle-fill', 0, NOW()),
(2, 'client', 1, 'Project Proposal Active', 'Your project "Corporate Website & Mobile App" is configured with milestone tracking.', '/client-flow/client/projects.php', 'info', 'bi-folder-check', 0, NOW());

SET FOREIGN_KEY_CHECKS = 1;

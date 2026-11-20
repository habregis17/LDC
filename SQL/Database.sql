CREATE DATABASE IF NOT EXISTS `LDC` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `LDC`;

-- Roles Table
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `role_code` VARCHAR(50) NOT NULL UNIQUE,
    `role_name` VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

INSERT INTO `roles` (`role_code`, `role_name`) VALUES 
('ADMIN', 'System Administrator'),
('PRIMARY_VERIFIER', 'Draw Verification Officer'),
('PARTNER', 'Partner - Risk Advisory'),
('STAKEHOLDER', 'External Stakeholder');

-- Users Table
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role_id` INT NOT NULL,
    `signature_path` VARCHAR(255) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Login Audit Logs Table (IP throttle & MFA logs)
CREATE TABLE IF NOT EXISTS `login_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(150) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `device_user_agent` TEXT NOT NULL,
    `status` ENUM('Ok', 'No') NOT NULL,
    `attempt_time` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- MFA Codes Table
CREATE TABLE IF NOT EXISTS `mfa_codes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `action_type` ENUM('LOGIN', 'SUBMIT_DRAW', 'APPROVE_DRAW') NOT NULL,
    `code` VARCHAR(6) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `is_used` TINYINT(1) DEFAULT 0,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Daily Draws Table
CREATE TABLE IF NOT EXISTS `draws` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `draw_hash_code` CHAR(32) NOT NULL UNIQUE,
    `certificate_ref` VARCHAR(50) NOT NULL UNIQUE,
    `draw_date` DATE NOT NULL,
    `draw_session` VARCHAR(50) NOT NULL, -- e.g. Evening Draw
    `start_time` DATETIME NOT NULL,
    `end_time` DATETIME NULL,
    `venue_location` VARCHAR(255) NOT NULL,
    `gps_latitude` DECIMAL(10, 8) NULL,
    `gps_longitude` DECIMAL(11, 8) NULL,
    `primary_verifier_id` INT NOT NULL,
    `partner_id` INT NULL,
    `status` ENUM('IN_PROGRESS', 'PENDING_PARTNER_APPROVAL', 'CERTIFIED', 'REJECTED') DEFAULT 'IN_PROGRESS',
    
    -- Procedure Checklist
    `chk_pre_draw` TINYINT(1) DEFAULT 0,
    `chk_draw_obs` TINYINT(1) DEFAULT 0,
    `chk_post_draw` TINYINT(1) DEFAULT 0,
    `procedure_notes` TEXT NULL,
    
    -- Double-entered numbers & central image validation
    `num1_a` INT, `num2_a` INT, `num3_a` INT, `num4_a` INT, `num5_a` INT,
    `num1_b` INT, `num2_b` INT, `num3_b` INT, `num4_b` INT, `num5_b` INT,
    `central_system_photo` VARCHAR(255) NULL,
    
    `verifier_signed_at` DATETIME NULL,
    `partner_signed_at` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`primary_verifier_id`) REFERENCES `users`(`id`),
    FOREIGN KEY (`partner_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB;

-- Detailed Audit Logs
CREATE TABLE IF NOT EXISTS `system_audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `action` VARCHAR(255) NOT NULL,
    `draw_id` INT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `device_info` TEXT NOT NULL,
    `gps_coords` VARCHAR(100) NULL,
    `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
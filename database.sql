-- =====================================================================
--  Diwali Lottery Draw Management System - Database Schema
--  Compatible with MySQL 8.0+ and MariaDB 10.4+
-- =====================================================================
--  Usage:
--     mysql -u root -p < database.sql
--  Creates database `diwali_lottery`, all tables, the 50 prize slots,
--  the live-draw state row, and the default admin account:
--     username: admin      password: Admin@123   (change it after login!)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `diwali_lottery`
    DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `diwali_lottery`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `draw_session`;
DROP TABLE IF EXISTS `lottery_draws`;
DROP TABLE IF EXISTS `lottery_tickets`;
DROP TABLE IF EXISTS `prizes`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Administrators
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(50)  NOT NULL,
    `password`      VARCHAR(255) NOT NULL COMMENT 'password_hash() output',
    `last_login_at` DATETIME     NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Lottery tickets
-- ---------------------------------------------------------------------
CREATE TABLE `lottery_tickets` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lottery_number` VARCHAR(30)  NOT NULL,
    `sold_by`        VARCHAR(100) NOT NULL,
    `sold_to`        VARCHAR(100) NULL,
    `status`         ENUM('AVAILABLE','WON') NOT NULL DEFAULT 'AVAILABLE',
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tickets_number` (`lottery_number`),
    KEY `idx_tickets_status` (`status`, `id`),
    KEY `idx_tickets_sold_by` (`sold_by`),
    KEY `idx_tickets_sold_to` (`sold_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Prize master (exactly 50 rows, numbered 1..50)
-- ---------------------------------------------------------------------
CREATE TABLE `prizes` (
    `id`                INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `prize_number`      TINYINT UNSIGNED NOT NULL,
    `prize_name`        VARCHAR(150)     NOT NULL,
    `prize_description` VARCHAR(500)     NULL,
    `status`            ENUM('PENDING','DRAWN') NOT NULL DEFAULT 'PENDING',
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_prizes_number` (`prize_number`),
    KEY `idx_prizes_status_number` (`status`, `prize_number`),
    CONSTRAINT `chk_prize_number_range` CHECK (`prize_number` BETWEEN 1 AND 50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Confirmed winners. A row is written ONLY when the admin confirms.
-- UNIQUE(prize_id)          -> a prize can have only one winner
-- UNIQUE(lottery_ticket_id) -> a ticket can win only once
-- ---------------------------------------------------------------------
CREATE TABLE `lottery_draws` (
    `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `prize_id`               INT UNSIGNED NOT NULL,
    `lottery_ticket_id`      INT UNSIGNED NOT NULL,
    `winning_lottery_number` VARCHAR(30)  NOT NULL,
    `sold_by`                VARCHAR(100) NOT NULL,
    `sold_to`                VARCHAR(100) NULL,
    `draw_status`            ENUM('CONFIRMED') NOT NULL DEFAULT 'CONFIRMED',
    `drawn_at`               DATETIME     NOT NULL COMMENT 'when the random selection happened',
    `confirmed_at`           DATETIME     NOT NULL,
    `confirmed_by`           INT UNSIGNED NULL,
    `created_at`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_draws_prize` (`prize_id`),
    UNIQUE KEY `uq_draws_ticket` (`lottery_ticket_id`),
    KEY `idx_draws_confirmed_at` (`confirmed_at`),
    CONSTRAINT `fk_draws_prize`  FOREIGN KEY (`prize_id`)          REFERENCES `prizes` (`id`)          ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_draws_ticket` FOREIGN KEY (`lottery_ticket_id`) REFERENCES `lottery_tickets` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_draws_user`   FOREIGN KEY (`confirmed_by`)      REFERENCES `users` (`id`)           ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Live draw state machine (single row, id = 1).
-- Holds the in-progress (unconfirmed) draw. Nothing here is a permanent
-- result; it is cleared on confirm/cancel. `version` increments on every
-- change so the LED display can poll cheaply.
--   IDLE     -> waiting for START DRAW
--   ROLLING  -> animation running, no winner selected yet
--   REVEALED -> winner randomly selected (server side), awaiting confirmation
--   ENDED    -> lottery closed by admin
-- ---------------------------------------------------------------------
CREATE TABLE `draw_session` (
    `id`                TINYINT UNSIGNED NOT NULL,
    `state`             ENUM('IDLE','ROLLING','REVEALED','ENDED') NOT NULL DEFAULT 'IDLE',
    `prize_id`          INT UNSIGNED NULL,
    `lottery_ticket_id` INT UNSIGNED NULL,
    `started_at`        DATETIME NULL,
    `revealed_at`       DATETIME NULL,
    `last_draw_id`      INT UNSIGNED NULL,
    `version`           INT UNSIGNED NOT NULL DEFAULT 1,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_session_prize`  FOREIGN KEY (`prize_id`)          REFERENCES `prizes` (`id`)          ON DELETE SET NULL,
    CONSTRAINT `fk_session_ticket` FOREIGN KEY (`lottery_ticket_id`) REFERENCES `lottery_tickets` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_session_draw`   FOREIGN KEY (`last_draw_id`)      REFERENCES `lottery_draws` (`id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Key/value application settings (event name, event date ...)
-- ---------------------------------------------------------------------
CREATE TABLE `settings` (
    `setting_key`   VARCHAR(50)  NOT NULL,
    `setting_value` VARCHAR(500) NULL,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Audit log of important operations
-- ---------------------------------------------------------------------
CREATE TABLE `audit_logs` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NULL,
    `action`     VARCHAR(50)  NOT NULL,
    `details`    TEXT         NULL,
    `ip_address` VARCHAR(45)  NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_action_time` (`action`, `created_at`),
    KEY `idx_audit_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Seed data
-- =====================================================================

-- Default admin: admin / Admin@123  (bcrypt hash)
INSERT INTO `users` (`username`, `password`) VALUES
('admin', '$2y$10$303ff7bzLnefqXDW00Ars.AjACVe5/E03R3A7aExAnzBTmDBr5UUO');

-- 50 prize slots (rename them from Admin > Prizes before the event)
INSERT INTO `prizes` (`prize_number`, `prize_name`) VALUES
(1,'Grand Prize'),(2,'Second Prize'),(3,'Third Prize'),(4,'Prize No. 4'),(5,'Prize No. 5'),
(6,'Prize No. 6'),(7,'Prize No. 7'),(8,'Prize No. 8'),(9,'Prize No. 9'),(10,'Prize No. 10'),
(11,'Prize No. 11'),(12,'Prize No. 12'),(13,'Prize No. 13'),(14,'Prize No. 14'),(15,'Prize No. 15'),
(16,'Prize No. 16'),(17,'Prize No. 17'),(18,'Prize No. 18'),(19,'Prize No. 19'),(20,'Prize No. 20'),
(21,'Prize No. 21'),(22,'Prize No. 22'),(23,'Prize No. 23'),(24,'Prize No. 24'),(25,'Prize No. 25'),
(26,'Prize No. 26'),(27,'Prize No. 27'),(28,'Prize No. 28'),(29,'Prize No. 29'),(30,'Prize No. 30'),
(31,'Prize No. 31'),(32,'Prize No. 32'),(33,'Prize No. 33'),(34,'Prize No. 34'),(35,'Prize No. 35'),
(36,'Prize No. 36'),(37,'Prize No. 37'),(38,'Prize No. 38'),(39,'Prize No. 39'),(40,'Prize No. 40'),
(41,'Gift Hamper'),(42,'Gift Hamper'),(43,'Gift Hamper'),(44,'Gift Hamper'),(45,'Gift Hamper'),
(46,'Gift Voucher'),(47,'Gift Voucher'),(48,'Gift Voucher'),(49,'Gift Hamper'),(50,'Consolation Prize');

INSERT INTO `draw_session` (`id`, `state`, `version`) VALUES (1, 'IDLE', 1);

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('event_name', 'Diwali Lottery Draw'),
('event_date', CURDATE()),
('event_venue', '');

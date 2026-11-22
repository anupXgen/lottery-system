-- =====================================================================
-- Housie / Tambola module - ADDITIVE migration
--
-- Creates only new tables prefixed `housie_`. It does not alter, drop or
-- write to any existing lottery table, and it is safe to run more than once
-- (CREATE TABLE IF NOT EXISTS / INSERT IGNORE).
--
-- Run against the existing lottery database, e.g.:
--   sudo mysql diwali_lottery < database/migrations/add_housie_module.sql
-- (Take a backup first: mysqldump diwali_lottery > backup.sql)
-- =====================================================================

-- One row per Housie game. Several games can exist; one is "active" (housie_game_settings).
CREATE TABLE IF NOT EXISTS `housie_games` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`            VARCHAR(150) NOT NULL,
    `game_date`       DATE NULL,
    `status`          ENUM('SETUP','RUNNING','PAUSED','ENDED') NOT NULL DEFAULT 'SETUP',
    `number_sequence` VARCHAR(400) NULL COMMENT 'Server-side shuffled 1-90 (Fisher-Yates, random_int). Never sent to browsers.',
    `calls_count`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `current_number`  TINYINT UNSIGNED NULL,
    `version`         INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Bumped on every change; lets displays poll cheaply',
    `created_by`      INT UNSIGNED NULL,
    `created_at`      DATETIME NOT NULL,
    `started_at`      DATETIME NULL,
    `last_called_at`  DATETIME NULL,
    `ended_at`        DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_housie_games_status` (`status`),
    CONSTRAINT `chk_housie_games_calls` CHECK (`calls_count` <= 90)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Printed tickets. grid = 27 cells (3 rows x 9 columns, row-major), 0 = blank cell.
CREATE TABLE IF NOT EXISTS `housie_tickets` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_id`           INT UNSIGNED NOT NULL,
    `ticket_no`         INT UNSIGNED NOT NULL COMMENT 'Ticket ID shown on the ticket, unique within the game',
    `participant_name`  VARCHAR(100) NULL,
    `participant_phone` VARCHAR(30) NULL,
    `grid`              VARCHAR(100) NOT NULL,
    `numbers_key`       VARCHAR(60) NOT NULL COMMENT 'The 15 numbers sorted - keeps every ticket combination unique',
    `created_at`        DATETIME NOT NULL,
    `updated_at`        DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_housie_ticket_no` (`game_id`, `ticket_no`),
    UNIQUE KEY `uq_housie_ticket_numbers` (`game_id`, `numbers_key`),
    KEY `idx_housie_ticket_participant` (`game_id`, `participant_name`),
    CONSTRAINT `fk_housie_tickets_game` FOREIGN KEY (`game_id`) REFERENCES `housie_games` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prize configuration per game and winning category.
CREATE TABLE IF NOT EXISTS `housie_prizes` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_id`         INT UNSIGNED NOT NULL,
    `category`        ENUM('EARLY_FIVE','TOP_LINE','MIDDLE_LINE','BOTTOM_LINE','FOUR_CORNERS',
                           'FULL_HOUSE','SECOND_FULL_HOUSE','THIRD_FULL_HOUSE') NOT NULL,
    `prize_name`      VARCHAR(150) NOT NULL,
    `description`     VARCHAR(255) NULL,
    `winners_allowed` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `sort_order`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `enabled`         TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at`      DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_housie_prize_category` (`game_id`, `category`),
    CONSTRAINT `fk_housie_prizes_game` FOREIGN KEY (`game_id`) REFERENCES `housie_games` (`id`),
    CONSTRAINT `chk_housie_prizes_winners` CHECK (`winners_allowed` BETWEEN 1 AND 50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every number called, in order. The UNIQUE keys make a repeated number or sequence slot impossible.
CREATE TABLE IF NOT EXISTS `housie_draw_numbers` (
    `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_id`   INT UNSIGNED NOT NULL,
    `seq`       TINYINT UNSIGNED NOT NULL COMMENT '1 = first number called',
    `number`    TINYINT UNSIGNED NOT NULL,
    `called_at` DATETIME NOT NULL,
    `called_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_housie_draw_number` (`game_id`, `number`),
    UNIQUE KEY `uq_housie_draw_seq` (`game_id`, `seq`),
    CONSTRAINT `fk_housie_draw_game` FOREIGN KEY (`game_id`) REFERENCES `housie_games` (`id`),
    CONSTRAINT `chk_housie_draw_number` CHECK (`number` BETWEEN 1 AND 90),
    CONSTRAINT `chk_housie_draw_seq` CHECK (`seq` BETWEEN 1 AND 90)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Claims: confirmed winners and rejected claims (the claim audit trail).
CREATE TABLE IF NOT EXISTS `housie_claims` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_id`           INT UNSIGNED NOT NULL,
    `prize_id`          INT UNSIGNED NOT NULL,
    `ticket_id`         INT UNSIGNED NULL COMMENT 'NULL when the entered Ticket ID does not exist in this game',
    `ticket_ref`        VARCHAR(30) NOT NULL COMMENT 'Ticket ID as entered by the admin',
    `participant_name`  VARCHAR(100) NULL COMMENT 'Winner name typed in for manual (physical ticket) entries',
    `category`          VARCHAR(30) NOT NULL,
    `status`            ENUM('CONFIRMED','REJECTED') NOT NULL,
    `winner_rank`       TINYINT UNSIGNED NULL COMMENT '1st, 2nd... winner of this prize (confirmed only)',
    `reason`            VARCHAR(255) NULL,
    `calls_at_claim`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_number`       TINYINT UNSIGNED NULL,
    `decided_by`        INT UNSIGNED NULL,
    `created_at`        DATETIME NOT NULL,
    `confirmed_ticket`  INT UNSIGNED GENERATED ALWAYS AS (IF(`status` = 'CONFIRMED', `ticket_id`, NULL)) STORED,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_housie_claim_confirmed` (`prize_id`, `confirmed_ticket`),
    UNIQUE KEY `uq_housie_claim_rank` (`prize_id`, `winner_rank`),
    KEY `idx_housie_claims_game` (`game_id`, `status`),
    CONSTRAINT `fk_housie_claims_game` FOREIGN KEY (`game_id`) REFERENCES `housie_games` (`id`),
    CONSTRAINT `fk_housie_claims_prize` FOREIGN KEY (`prize_id`) REFERENCES `housie_prizes` (`id`),
    CONSTRAINT `fk_housie_claims_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `housie_tickets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module settings (active game, display title).
CREATE TABLE IF NOT EXISTS `housie_game_settings` (
    `setting_key`   VARCHAR(64) NOT NULL,
    `setting_value` TEXT NULL,
    `updated_at`    DATETIME NULL,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `housie_game_settings` (`setting_key`, `setting_value`) VALUES
    ('active_game_id', ''),
    ('display_title', 'DIWALI HOUSIE');

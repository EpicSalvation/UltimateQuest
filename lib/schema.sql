-- The Ultimate Quest — MySQL schema.
-- Loaded by migrate.php; can also be imported via phpMyAdmin.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE IF NOT EXISTS teams (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(64)  NOT NULL,
  display_name VARCHAR(128) NOT NULL,
  pin_hash     VARCHAR(255) NOT NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_team_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title           VARCHAR(255) NOT NULL,
  description     TEXT NULL,
  points          INT UNSIGNED NOT NULL DEFAULT 0,
  -- Points deducted at tally time when the task is NOT approved. Always
  -- stored as a positive number; the scoring queries subtract it.
  penalty         INT UNSIGNED NOT NULL DEFAULT 0,
  photos_required TINYINT UNSIGNED NOT NULL DEFAULT 0,
  videos_required TINYINT UNSIGNED NOT NULL DEFAULT 0,
  -- "Priority" flag in the UI (kept as `mandatory` for upgrade compatibility).
  mandatory       TINYINT(1) NOT NULL DEFAULT 0,
  sort_order      INT NOT NULL DEFAULT 0,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS submissions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id      INT UNSIGNED NOT NULL,
  task_id      INT UNSIGNED NOT NULL,
  status       ENUM('pending','approved','rejected') NOT NULL,
  note         TEXT NULL,
  submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at  TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_team_task (team_id, task_id),
  KEY idx_pending_queue (status, submitted_at),
  CONSTRAINT fk_sub_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS submission_files (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  submission_id INT UNSIGNED NOT NULL,
  filename      VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(100) NOT NULL,
  byte_size     BIGINT UNSIGNED NOT NULL,
  has_thumb     TINYINT(1) NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sub (submission_id),
  CONSTRAINT fk_file_sub FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) NOT NULL,
  v TEXT NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS past_games (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  label        VARCHAR(32)  NOT NULL,
  ended_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archive_path VARCHAR(255) NOT NULL,
  summary_json TEXT         NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  bucket       VARCHAR(80)  NOT NULL,
  attempts     INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until TIMESTAMP    NULL,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

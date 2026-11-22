USE `mamcomwmat`;
-- ==========================================================================
-- Mathieu Moreau — Portfolio · MySQL / MariaDB schema (OVH compatible)
-- Import it from phpMyAdmin (OVH Manager > Web Cloud > Hosting > Databases)
-- ==========================================================================
SET NAMES utf8mb4;

-- Every page load (one row per visit). IPs are NEVER stored in clear:
-- visitor_hash = SHA-256(IP + User-Agent + day + secret salt) → anonymous, rotates daily.
CREATE TABLE IF NOT EXISTS visits (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visitor_hash  CHAR(64)      NOT NULL,
  session_id    VARCHAR(64)   NOT NULL,
  path          VARCHAR(255)  NOT NULL DEFAULT '/',
  lang          CHAR(2)       NOT NULL DEFAULT 'fr',
  referrer      VARCHAR(255)  NULL,
  referrer_host VARCHAR(120)  NULL,
  campaign      VARCHAR(60)   NULL,
  device        ENUM('mobile','tablet','desktop') NOT NULL DEFAULT 'desktop',
  browser       VARCHAR(30)   NULL,
  os            VARCHAR(30)   NULL,
  country       CHAR(2)       NULL,
  theme         VARCHAR(10)   NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (created_at),
  INDEX idx_campaign (campaign),
  INDEX idx_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Interactions: CV downloads, sections reached, quiz, palette, time on page…
CREATE TABLE IF NOT EXISTS events (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visitor_hash  CHAR(64)      NOT NULL,
  session_id    VARCHAR(64)   NOT NULL,
  name          VARCHAR(40)   NOT NULL,
  value         VARCHAR(120)  NULL,
  campaign      VARCHAR(60)   NULL,
  lang          CHAR(2)       NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_name (name),
  INDEX idx_created (created_at),
  INDEX idx_campaign (campaign)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contact form messages
CREATE TABLE IF NOT EXISTS messages (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120)  NOT NULL,
  email         VARCHAR(190)  NOT NULL,
  company       VARCHAR(120)  NULL,
  intent        ENUM('job','freelance','private','other') NOT NULL DEFAULT 'other',
  message       TEXT          NOT NULL,
  lang          CHAR(2)       NOT NULL DEFAULT 'fr',
  campaign      VARCHAR(60)   NULL,
  visitor_hash  CHAR(64)      NULL,
  is_read       TINYINT(1)    NOT NULL DEFAULT 0,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Personalised recruiter links: https://mam-com.com/?c=acme-rh
CREATE TABLE IF NOT EXISTS campaigns (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug          VARCHAR(60)   NOT NULL UNIQUE,
  company       VARCHAR(120)  NOT NULL,
  contact_name  VARCHAR(120)  NULL,
  lang          CHAR(2)       NULL,
  note          VARCHAR(255)  NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Simple rate limiting (anti-spam)
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket        VARCHAR(100)  NOT NULL PRIMARY KEY,
  hits          INT UNSIGNED  NOT NULL DEFAULT 0,
  window_start  DATETIME      NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional housekeeping (CNIL recommends ≤ 13 months for audience stats):
-- DELETE FROM visits WHERE created_at < NOW() - INTERVAL 13 MONTH;
-- DELETE FROM events WHERE created_at < NOW() - INTERVAL 13 MONTH;

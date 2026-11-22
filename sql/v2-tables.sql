-- ==========================================================================
-- mam-com.com/v2 — new tables (import ONCE in phpMyAdmin, in the SAME database as the main site)
-- The v2 also uses the existing tables: visits, events, messages, campaigns, rate_limits.
-- Nothing existing is modified: only 3 new tables, all prefixed with v2_.
-- ==========================================================================
USE `mamcomwmat`;   -- same database as the main site (schema-ovh.sql)
SET NAMES utf8mb4;

-- Questions asked to the "Ask me" AI assistant (what recruiters want to know)
CREATE TABLE IF NOT EXISTS v2_questions (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visitor_hash  CHAR(64)      NOT NULL,
  session_id    VARCHAR(64)   NULL,
  lang          CHAR(2)       NULL,
  question      TEXT          NOT NULL,
  answer        TEXT          NOT NULL,
  source        VARCHAR(20)   NOT NULL,          -- 'ai' or 'offline'
  campaign      VARCHAR(60)   NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CV downloads where the visitor left a name / company (every download is also an event 'cv_download')
CREATE TABLE IF NOT EXISTS v2_cv_requests (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visitor_hash  CHAR(64)      NOT NULL,
  cv_lang       CHAR(2)       NOT NULL,
  name          VARCHAR(120)  NULL,
  company       VARCHAR(120)  NULL,
  campaign      VARCHAR(60)   NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extra options of a recruiter link for the v2 (the link itself stays in `campaigns`)
CREATE TABLE IF NOT EXISTS v2_campaign_extras (
  slug          VARCHAR(60)   NOT NULL PRIMARY KEY,   -- = campaigns.slug
  skills        VARCHAR(400)  NULL,                    -- skill keys to highlight, comma separated
  message       VARCHAR(1000) NULL,                    -- personal message shown in the greeting
  notified_at   DATETIME      NULL,                    -- last "link opened" e-mail (anti-spam)
  updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

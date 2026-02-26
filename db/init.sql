-- ------------------------------------------------------------
-- ERP Database Initialisation
-- ------------------------------------------------------------
-- Rules (see AGENTS.md §7):
--   - Character set: utf8mb4 / utf8mb4_unicode_ci
--   - All timestamps stored in UTC
--   - Monetary values stored as integers (cents / eurocents)
--   - Deletions are soft (deleted_at timestamp, not DELETE)
--   - Schema changes after initial setup belong in db/migrations/
--   - No DROP statements in this file
-- ------------------------------------------------------------

SET NAMES utf8mb4;
SET time_zone = '+00:00';

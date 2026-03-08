-- Migration 002: add users table for session-based authentication
-- Run once against both dev and prod databases.
--
-- Roles (least → most privileged):
--   guest        — unauthenticated placeholder; not stored in DB
--   musician     — read-only access to own gig details
--   owner        — full ERP access (inquiry, gig management, invoicing)
--   admin        — owner + user management
--   developer    — admin + schema / config access
--
-- Bootstrap: after applying this migration, insert the first owner account:
--   INSERT INTO users (username, password_hash, role)
--   VALUES ('your-username', PASSWORD_HASH_HERE, 'owner');
-- Generate a hash with:
--   php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    username      VARCHAR(64)   NOT NULL,
    password_hash VARCHAR(255)  NOT NULL,
    role          ENUM(
                      'developer',
                      'admin',
                      'owner',
                      'musician',
                      'guest'
                  ) NOT NULL DEFAULT 'musician',
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at    DATETIME               DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

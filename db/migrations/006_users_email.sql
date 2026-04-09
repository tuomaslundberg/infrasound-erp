-- Migration 006: add email column to users
-- Needed before musician seeding — the musicians.sql seed populates placeholder
-- addresses so user records are identifiable without a working login.
--
-- email is nullable: existing partner/owner accounts need not have one set here
-- (they log in by username); musician accounts get a placeholder.
--
-- Apply against both dev and prod before running db/seeds/musicians.sql.

ALTER TABLE users
    ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER username;

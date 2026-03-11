-- Migration 004: gig_personnel
-- Assigns band members (users) to a specific gig with their role and fee.
-- confirmed_at records when the musician acknowledged the assignment.
-- fee_cents is the musician's individual cut for this gig (eurocents).
--
-- Apply after migration 003_gig_pricing_inputs.sql.

CREATE TABLE IF NOT EXISTS gig_personnel (
    id           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    gig_id       INT UNSIGNED   NOT NULL,
    user_id      INT UNSIGNED   NOT NULL,
    -- Role the musician plays at this specific gig
    role         ENUM(
                     'vocalist',
                     'guitarist',
                     'bassist',
                     'drummer',
                     'keyboardist',
                     'other'
                 ) NOT NULL DEFAULT 'other',
    fee_cents    INT            NOT NULL DEFAULT 0,
    confirmed_at DATETIME                DEFAULT NULL,
    created_at   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gig_user (gig_id, user_id),
    CONSTRAINT fk_gp_gig  FOREIGN KEY (gig_id)  REFERENCES gigs  (id),
    CONSTRAINT fk_gp_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

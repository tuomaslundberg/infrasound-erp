-- Migration 012: add default_car to users
-- Encodes which band car a person defaults to.
-- Used by TravelCalculator to determine driver vs passenger assignment
-- independently of the gig role they happen to be assigned.
--
-- Values: 1 = Car 1 (Caddy, Tuomas driving), 2 = Car 2 (own car, Mortti/Maxwell driving)
-- Default is 1 because most musicians ride in Car 1.

ALTER TABLE users
  ADD COLUMN default_car TINYINT(1) UNSIGNED NOT NULL DEFAULT 1
    COMMENT '1=Car 1 (Caddy), 2=Car 2. Used by TravelCalculator; independent of gig role.';

-- Seed correct values for known musicians.
-- Car 2: Mortti (driver) and Lauri (Helsinki pickup, rides with Car 2 driver).
UPDATE users SET default_car = 2
WHERE username IN ('mortti.markkanen', 'lauri.lehtinen')
  AND deleted_at IS NULL;

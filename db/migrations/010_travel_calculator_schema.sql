-- Migration 010: travel calculator schema
-- Adds home address + geocoordinates to users, transport_mode for car allocation,
-- venue lat/lng for OSRM waypoint routing, ferry fields for island venues, and
-- transport_override on gig_personnel for per-gig exceptions to the default mode.
--
-- Apply after migration 009_venue_placeholder_nulls.sql.

-- ------------------------------------------------------------
-- users: home address, geocoordinates, default transport mode
-- ------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN home_address   VARCHAR(255)                                          DEFAULT NULL
        AFTER email,
    ADD COLUMN home_lat       DECIMAL(9,6)                                          DEFAULT NULL
        AFTER home_address,
    ADD COLUMN home_lng       DECIMAL(9,6)                                          DEFAULT NULL
        AFTER home_lat,
    ADD COLUMN transport_mode ENUM('car_owner', 'passenger', 'public_transport')
                              NOT NULL DEFAULT 'passenger'
        AFTER home_lng;

-- transport_mode semantics:
--   car_owner        — has own vehicle; role determines Car 1 driver vs Car 2 driver/pickup
--   passenger        — always rides with someone else (Car 1 by default)
--   public_transport — self-sufficient by train/bus (reserved; unused in v1)

-- ------------------------------------------------------------
-- venues: geocoordinates + ferry flag
-- ------------------------------------------------------------
ALTER TABLE venues
    ADD COLUMN lat                      DECIMAL(9,6)  DEFAULT NULL
        AFTER distance_from_turku_km,
    ADD COLUMN lng                      DECIMAL(9,6)  DEFAULT NULL
        AFTER lat,
    ADD COLUMN requires_ferry           TINYINT(1)    NOT NULL DEFAULT 0
        AFTER notes,
    ADD COLUMN ferry_cost_estimate_cents INT           DEFAULT NULL
        AFTER requires_ferry;

-- ferry_cost_estimate_cents: one vehicle, one-way crossing.
-- TravelCalculator bills: ferry_cost_estimate_cents × 2 (ways) × 2 (vehicles) per gig.

-- ------------------------------------------------------------
-- gig_personnel: per-gig transport override
-- ------------------------------------------------------------
ALTER TABLE gig_personnel
    ADD COLUMN transport_override ENUM('car_owner', 'passenger', 'local') DEFAULT NULL
        AFTER fee_cents;

-- transport_override semantics (NULL = use users.transport_mode default):
--   car_owner  — drives own car this gig (not billed in Car 1 or Car 2; warn)
--   passenger  — rides Car 2 even if users.transport_mode = car_owner (Valtteri typical case)
--   local      — already at venue area; skip out-of-town pickup waypoint (Lauri staying in Turku)

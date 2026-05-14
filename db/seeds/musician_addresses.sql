-- ------------------------------------------------------------
-- Musician home address seed
-- Sets home_address and transport_mode for the core roster.
-- lat/lng are populated separately by: make geocode-musicians
--
-- Uses UPDATE (not INSERT IGNORE) so it fires even when users
-- already exist with elevated roles (e.g. tuomas.lundberg as owner).
--
-- Prerequisites:
--   migration 010_travel_calculator_schema.sql (home_address, transport_mode columns)
-- ------------------------------------------------------------

SET NAMES utf8mb4;

-- Car 1 driver (keyboards — always Tuomas)
UPDATE users SET
    home_address   = 'Vilhonkatu 9, 20810 Turku',
    transport_mode = 'car_owner'
WHERE username = 'tuomas.lundberg';

-- Car 1 passengers (Turku-based; no own car in use)
UPDATE users SET
    home_address   = 'Stålarminkatu 1, 20810 Turku',
    transport_mode = 'passenger'
WHERE username = 'toni.puttonen';

UPDATE users SET
    home_address   = 'Kirkkotie 2, 20540 Turku',
    transport_mode = 'passenger'
WHERE username = 'joni.virtanen';

UPDATE users SET
    home_address   = 'Puutarhakatu 18, 20100 Turku',
    transport_mode = 'passenger'
WHERE username = 'alina.kangas';

-- Car 2 pickup (guitar — Lauri, always picked up from Helsinki)
UPDATE users SET
    home_address   = 'Adolf Lindforsintie 3, 00400 Helsinki',
    transport_mode = 'passenger'
WHERE username = 'lauri.lehtinen';

-- Car 2 drivers (bass — Mortti or Maxwell; always own car, always Car 2)
UPDATE users SET
    home_address   = 'Tilkankatu 6, 00300 Helsinki',
    transport_mode = 'car_owner',
    default_car    = 2
WHERE username = 'mortti.markkanen';

UPDATE users SET
    home_address   = 'Arabianranta, 00550 Helsinki',
    transport_mode = 'car_owner',
    default_car    = 2
WHERE username = 'maxwell.mbare';

-- Car 2 passenger (sound engineering — Valtteri; rides Car 2 by default)
-- When he drives himself to a gig, set gig_personnel.transport_override = 'local'.
UPDATE users SET
    home_address   = 'Kaarina, Varsinais-Suomi',
    transport_mode = 'passenger',
    default_car    = 2
WHERE username = 'valtteri.alanen';

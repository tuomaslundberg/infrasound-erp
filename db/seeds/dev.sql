-- ------------------------------------------------------------
-- Dev seed data — fictional fixture rows (obfuscated)
-- ------------------------------------------------------------
-- Names and addresses are fictitious. Distances and prices are
-- realistic and match the pricing formula in PriceCalculator.
-- Run against the dev DB only (fi_infrasound_dev).
-- DO NOT run against the production database.
--
-- Usage:
--   make seed
-- ------------------------------------------------------------

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Wipe existing data so the script is idempotent (safe to re-run)
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE song_requests;
TRUNCATE TABLE gigs;
TRUNCATE TABLE customer_contacts;
TRUNCATE TABLE venues;
TRUNCATE TABLE contacts;
TRUNCATE TABLE customers;
SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- customers
-- ------------------------------------------------------------
INSERT INTO customers (id, name, type, notes) VALUES
  (1, 'Teemu Testinen',   'person', 'Wedding 2026-05-30, Helsinki'),
  (2, 'Malla Mallikas',   'person', 'Wedding 2026-06-06, Sauvo'),
  (3, 'Kaisa Koekäyttäjä','person', 'Wedding 2026-06-13, Turku'),
  (4, 'Pertti Prototyyppi','person', 'Wedding 2026-06-27, Laitila'),
  (5, 'Ulla Esimerkkinen', 'person', 'Wedding 2026-07-11, Raisio');

-- ------------------------------------------------------------
-- contacts  (customer IS the contact for all seed gigs)
-- ------------------------------------------------------------
INSERT INTO contacts (id, first_name, last_name, email, phone) VALUES
  (1, 'Teemu',  'Testinen',    'teemu@example.com',  NULL),
  (2, 'Malla',  'Mallikas',    NULL,                  NULL),
  (3, 'Kaisa',  'Koekäyttäjä', NULL,                  NULL),
  (4, 'Pertti', 'Prototyyppi', NULL,                  NULL),
  (5, 'Ulla',   'Esimerkkinen',NULL,                  NULL);

-- ------------------------------------------------------------
-- customer_contacts
-- ------------------------------------------------------------
INSERT INTO customer_contacts (customer_id, contact_id, is_primary) VALUES
  (1, 1, 1),
  (2, 2, 1),
  (3, 3, 1),
  (4, 4, 1),
  (5, 5, 1);

-- ------------------------------------------------------------
-- venues  (realistic locations, fictional booking names)
-- ------------------------------------------------------------
INSERT INTO venues (id, name, address_line, city, postal_code, distance_from_turku_km) VALUES
  (1, 'Juhlatila Satama',        'Esimerkkikatu 1',    'Helsinki', '00140', 170.0),
  (2, 'Kartano Testikulma',      'Testikuja 25',       'Sauvo',    '21570',  40.5),
  (3, 'Tehtaansali Demo',        'Rantakatu 64b',      'Turku',    '20810',   2.4),
  (4, 'Prototyyppitila OY',      'Koetie 1',           'Laitila',  '23800',  60.2),
  (5, 'Esimerkki-Vintti',        'Mallitie 3',         'Raisio',   '21200',   8.1);

-- ------------------------------------------------------------
-- gigs  (prices match PriceCalculator output for given distances)
-- ------------------------------------------------------------
INSERT INTO gigs (
  id, customer_id, contact_id, venue_id, gig_date, status,
  channel, customer_type, order_description,
  quoted_price_cents, car1_distance_km, car2_distance_km, other_travel_costs_cents
) VALUES
  -- 170km from Turku, car1=371km, other travel 22.54€ (post-hoc correction preserved)
  (1, 1, 1, 1, '2026-05-30', 'quoted', 'mail', 'wedding',
   '3 × 45 min + ennakkoroudaus',
   228900, 371.0, 0.0, 2254),

  -- 40.5km from Turku, car1=110km
  (2, 2, 2, 2, '2026-06-06', 'quoted', 'mail', 'wedding',
   NULL,
   207571, 110.0, 0.0, 3780),

  -- 2.4km from Turku (home city), car1=52.6km
  (3, 3, 3, 3, '2026-06-13', 'quoted', 'mail', 'wedding',
   NULL,
   186380, 52.6, 0.0, 3780),

  -- 60.2km from Turku, car1=147.5km
  (4, 4, 4, 4, '2026-06-27', 'quoted', 'mail', 'wedding',
   NULL,
   202165, 147.5, 0.0, 3780),

  -- 8.1km from Turku, car1=42.3km, song request extra
  (5, 5, 5, 5, '2026-07-11', 'quoted', 'mail', 'wedding',
   '3 × 45 min + 2 toivekappaletta',
   186031, 42.3, 0.0, 0);

-- ------------------------------------------------------------
-- song_requests
-- ------------------------------------------------------------
INSERT INTO song_requests (gig_id, artist, title, is_first_dance, sort_order) VALUES
  (5, 'Bon Jovi', 'Livin'' On A Prayer', 0, 1),
  (5, 'Apulanta', 'Pahempi toistaan',    0, 2);

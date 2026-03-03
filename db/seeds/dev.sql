-- ------------------------------------------------------------
-- Dev seed data — realistic fixture rows from old-files
-- ------------------------------------------------------------
-- Source: old-files/future-gigs/*/gig-info-*.txt
-- Run against the dev DB only (fi_infrasound_dev).
-- DO NOT run against the production database.
--
-- Usage:
--   docker exec -i infrasound_db mysql -u erp_user -pchangeme fi_infrasound_dev < db/seeds/dev.sql
-- ------------------------------------------------------------

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ------------------------------------------------------------
-- customers  (all weddings → type = 'person')
-- ------------------------------------------------------------
INSERT INTO customers (id, name, type, notes) VALUES
  (1, 'Konsta Hannula',    'person', 'Wedding 2026-05-30, Helsinki'),
  (2, 'Janne Arvola',      'person', 'Wedding 2026-06-06, Sauvo'),
  (3, 'Marleena Ottelin',  'person', 'Wedding 2026-06-13, Turku'),
  (4, 'Emmi Nummela',      'person', 'Wedding 2026-06-27, Laitila'),
  (5, 'Roni Sevänen',      'person', 'Wedding 2026-07-11, Raisio');

-- ------------------------------------------------------------
-- contacts  (for these gigs the customer IS the contact)
-- ------------------------------------------------------------
INSERT INTO contacts (id, first_name, last_name, email, phone) VALUES
  (1, 'Konsta',    'Hannula',  NULL, NULL),
  (2, 'Janne',     'Arvola',   NULL, NULL),
  (3, 'Marleena',  'Ottelin',  NULL, NULL),
  (4, 'Emmi',      'Nummela',  NULL, NULL),
  (5, 'Roni',      'Sevänen',  NULL, NULL);

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
-- venues
-- distance_from_turku_km = straight-line figure from gig-info
-- ------------------------------------------------------------
INSERT INTO venues (id, name, address_line, city, postal_code, distance_from_turku_km) VALUES
  (1, 'Ravintola NJK',         'Valkosaari',                   'Helsinki', '00140', 170.0),
  (2, 'Vähäkylän Kivi-Kostila','Kollarintie 25',                'Sauvo',    '21570',  40.5),
  (3, 'Manillan tehdas',       'Itäinen Rantakatu 64b',         'Turku',    '20810',   2.4),
  (4, 'Juhlatila Fordson OY',  'Keskuskatu 1',                  'Laitila',  '23800',  60.2),
  (5, 'Juhlatila Hintsan Vintti','Hintsantie 3',                'Raisio',   '21200',   8.1);

-- ------------------------------------------------------------
-- gigs
-- quoted_price_cents from gig-info "Tarjous" field (× 100)
-- car1/car2 distances from "Arvio auton N matkan pituudesta"
-- other_travel_costs_cents from "Arvio muista matkakuluista"
-- All are 'quoted' status (quote sent, awaiting reply)
-- ------------------------------------------------------------
INSERT INTO gigs (
  id, customer_id, contact_id, venue_id, gig_date, status,
  channel, customer_type, order_description,
  quoted_price_cents, car1_distance_km, car2_distance_km, other_travel_costs_cents
) VALUES
  -- Konsta Hannula, 2026-05-30, Helsinki, 170km away
  -- Original gig-info note: other_travel_costs includes a post-hoc correction
  (1, 1, 1, 1, '2026-05-30', 'quoted', 'mail', 'wedding',
   '3 × 45 min + ennakkoroudaus',
   228900, 371.0, 0.0, 2254),

  -- Janne Arvola, 2026-06-06, Sauvo
  (2, 2, 2, 2, '2026-06-06', 'quoted', 'mail', 'wedding',
   NULL,
   207571, 110.0, 0.0, 3780),

  -- Marleena Ottelin, 2026-06-13, Turku (home city, tiny distance premium)
  (3, 3, 3, 3, '2026-06-13', 'quoted', 'mail', 'wedding',
   NULL,
   186380, 52.6, 0.0, 3780),

  -- Emmi Nummela, 2026-06-27, Laitila
  (4, 4, 4, 4, '2026-06-27', 'quoted', 'mail', 'wedding',
   NULL,
   202165, 147.5, 0.0, 3780),

  -- Roni Sevänen, 2026-07-11, Raisio
  (5, 5, 5, 5, '2026-07-11', 'quoted', 'mail', 'wedding',
   '3 × 45 min + 2 toivekappaletta',
   186031, 42.3, 0.0, 0);

-- ------------------------------------------------------------
-- song_requests  (only Sevänen had actual titles in gig-info)
-- ------------------------------------------------------------
INSERT INTO song_requests (gig_id, artist, title, is_first_dance, sort_order) VALUES
  (5, 'Bon Jovi',  'Livin'' On A Prayer', 0, 1),
  (5, 'Apulanta',  'Pahempi toistaan',    0, 2);

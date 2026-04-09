-- ------------------------------------------------------------
-- Musician roster seed
-- Inserts all band members as users with role = 'musician'.
-- Safe to run against both dev and prod; uses INSERT IGNORE so
-- existing rows (e.g. Tuomas/Toni with elevated roles and real
-- password hashes) are left untouched.
--
-- password_hash = '!' is a locked-account sentinel: no bcrypt
-- hash ever begins with '!', so these accounts can never log in
-- via the normal password flow.  This is intentional — musicians
-- access the system via a future auth flow, not a password login.
--
-- email values are placeholder addresses of the form
--   <first>.<last>@musicians.infrasound.fi
-- Update with real addresses once the musician portal is live.
--
-- Prerequisites:
--   migration 002_add_users_table.sql  (users table)
--   migration 006_users_email.sql      (email column)
--
-- Run:
--   make seed-musicians          (dev)
--   make seed-musicians-prod     (prod)   ← add Makefile targets
-- ------------------------------------------------------------

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Partners (Tuomas and Toni may already exist with owner/developer roles;
-- INSERT IGNORE will skip them if so — do NOT overwrite their accounts.)
INSERT IGNORE INTO users (username, email, password_hash, role) VALUES
    ('tuomas.lundberg',  'tuomas.lundberg@musicians.infrasound.fi',  '!', 'musician'),
    ('toni.puttonen',    'toni.puttonen@musicians.infrasound.fi',    '!', 'musician'),
    ('joni.virtanen',    'joni.virtanen@musicians.infrasound.fi',    '!', 'musician'),
    ('lauri.lehtinen',   'lauri.lehtinen@musicians.infrasound.fi',   '!', 'musician');

-- Core external musicians
INSERT IGNORE INTO users (username, email, password_hash, role) VALUES
    ('mikael.lehto',     'mikael.lehto@musicians.infrasound.fi',     '!', 'musician'),
    ('emil.lamminmaki',  'emil.lamminmaki@musicians.infrasound.fi',  '!', 'musician'),
    ('alina.kangas',     'alina.kangas@musicians.infrasound.fi',     '!', 'musician'),
    ('mortti.markkanen', 'mortti.markkanen@musicians.infrasound.fi', '!', 'musician');

-- Occasional external musicians
INSERT IGNORE INTO users (username, email, password_hash, role) VALUES
    ('samuel.johansson', 'samuel.johansson@musicians.infrasound.fi', '!', 'musician'),  -- 0 recorded gigs
    ('leevi.kahkonen',   'leevi.kahkonen@musicians.infrasound.fi',   '!', 'musician'),  -- 1 gig (stand-in sound eng)
    ('lassi.kriikkula',  'lassi.kriikkula@musicians.infrasound.fi',  '!', 'musician'),
    ('maxwell.mbare',    'maxwell.mbare@musicians.infrasound.fi',    '!', 'musician'),
    ('iris.toivonen',    'iris.toivonen@musicians.infrasound.fi',    '!', 'musician'),
    ('juho.peuraniemi',  'juho.peuraniemi@musicians.infrasound.fi',  '!', 'musician'),
    ('arttu.luonsinen',  'arttu.luonsinen@musicians.infrasound.fi',  '!', 'musician'),
    ('erkki.sippel',     'erkki.sippel@musicians.infrasound.fi',     '!', 'musician'),  -- drums or bass (2 gigs)
    ('antti.saari',      'antti.saari@musicians.infrasound.fi',      '!', 'musician'),  -- bass stand-in (1 gig)
    ('eetu.hamalainen',  'eetu.hamalainen@musicians.infrasound.fi',  '!', 'musician'),  -- keyboards stand-in (1 gig)
    ('valtteri.alanen',  'valtteri.alanen@musicians.infrasound.fi',  '!', 'musician');  -- sound engineering stand-in (2025+)

-- Migration 009: venue placeholder nulls
-- ETL created phantom venue rows where name = city (e.g. 'Salo') or name = 'EI TIEDOSSA'
-- for inquiries where the venue was not disclosed. These cause false positives in
-- the venue-familiar-quote template selection logic (e.g. any Salo inquiry would
-- match the 'Salo' phantom venue and incorrectly surface the venue-familiar template).
--
-- Fix: null out venue_id on gigs referencing these phantom rows, then soft-delete
-- the phantom venue rows themselves.
--
-- Apply after migration 008_setlists.sql.

-- Step 1: Detach gigs from phantom venues
UPDATE gigs g
JOIN venues v ON v.id = g.venue_id
SET g.venue_id = NULL
WHERE v.deleted_at IS NULL
  AND (v.name = v.city OR v.name = 'EI TIEDOSSA');

-- Step 2: Soft-delete the phantom venue rows
UPDATE venues
SET deleted_at = NOW()
WHERE deleted_at IS NULL
  AND (name = city OR name = 'EI TIEDOSSA');

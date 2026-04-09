-- Migration 007: fix gig_personnel schema for invoicing ETL compatibility
--
-- Two changes:
--
-- 1. Rename role ENUM values to instrument/function nouns instead of person
--    nouns (bass vs bassist, etc.).  Adds sound_engineering for Toni / Valtteri.
--    New set: vocals, guitar, bass, drums, keyboards, sound_engineering, other
--
--    Uses the expand → migrate → contract pattern to avoid strict-mode errors:
--    the ENUM must accept both old and new labels during the UPDATE, otherwise
--    writing e.g. 'vocals' into a column that only knows 'vocalist' is rejected.
--
-- 2. Make fee_cents nullable.
--    NULL = partner fee (not tracked in invoicing; settled separately).
--    0    = explicitly confirmed zero fee (rare).
--    > 0  = fee amount in eurocents.

-- Step 1: expand ENUM to include both old and new labels simultaneously.
ALTER TABLE gig_personnel
    MODIFY COLUMN role ENUM(
        'vocalist', 'guitarist', 'bassist', 'drummer', 'keyboardist',
        'vocals',   'guitar',    'bass',    'drums',   'keyboards',
        'sound_engineering', 'other'
    ) NOT NULL DEFAULT 'other';

-- Step 2: remap existing rows. Safe on an empty table (0 rows affected).
UPDATE gig_personnel SET role = CASE role
    WHEN 'vocalist'     THEN 'vocals'
    WHEN 'guitarist'    THEN 'guitar'
    WHEN 'bassist'      THEN 'bass'
    WHEN 'drummer'      THEN 'drums'
    WHEN 'keyboardist'  THEN 'keyboards'
    ELSE role
END;

-- Step 3: contract to new labels only, and make fee_cents nullable.
ALTER TABLE gig_personnel
    MODIFY COLUMN role ENUM(
        'vocals',
        'guitar',
        'bass',
        'drums',
        'keyboards',
        'sound_engineering',
        'other'
    ) NOT NULL DEFAULT 'other',
    MODIFY COLUMN fee_cents INT DEFAULT NULL;

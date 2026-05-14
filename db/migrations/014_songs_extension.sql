-- Migration 013: extend songs and setlists for setlist ETL
--
-- Two changes:
--
-- 1. songs — adds all columns needed for the setlist ETL and Spotify integration.
--    See cli/etl/SETLIST_ETL_SPEC.md for field semantics.
--
-- 2. setlists — adds set_type ENUM to distinguish numbered sets from lounge,
--    encore, and karaoke sets in queries (cleaner than parsing the name column).

-- 1. songs extension
ALTER TABLE songs
    -- Spotify
    ADD COLUMN spotify_track_id     VARCHAR(22)                                     DEFAULT NULL,
    -- Repertoire metadata
    ADD COLUMN genre                VARCHAR(100)                                     DEFAULT NULL,
    ADD COLUMN language             ENUM('fi', 'sv', 'en', 'other')                 DEFAULT NULL,
    ADD COLUMN release_year         SMALLINT UNSIGNED                                DEFAULT NULL,
    ADD COLUMN is_jazz              TINYINT(1)       NOT NULL DEFAULT 0,
    -- in_repertoire: 1 = main setlist repertoire; 0 = extra songs section of
    -- playlist-gig.txt (old wishes, special one-offs, outside default repertoire)
    ADD COLUMN in_repertoire        TINYINT(1)       NOT NULL DEFAULT 1,
    -- Ableton / stage configuration
    -- hd_slot: 3-char launchpad coordinate, e.g. '123' (user=1, row=2, col=3).
    -- NULL = no backing track configured.
    ADD COLUMN hd_slot              CHAR(3)                                          DEFAULT NULL,
    -- hd_status reflects hd-list.txt:
    --   'none'    = no backing track (not in hd-list.txt)
    --   'done'    = backing track configured and verified (TEHTY section)
    --   'pending' = backing track intended but not yet done or unverified (TEKEMÄTTÄ)
    ADD COLUMN hd_status            ENUM('none', 'done', 'pending') NOT NULL DEFAULT 'none',
    -- guide_tone_key: single char matching drummer's keyboard key (e.g. 'g', 'h').
    -- NULL = no guide tone needed (prefix '---' in playlist-gig.txt).
    -- See old-files/info/launch-notes-correspondence.txt for key→note mapping.
    ADD COLUMN guide_tone_key       CHAR(1)                                          DEFAULT NULL,
    -- Keys (shorthand, e.g. 'Cm', 'F#m', 'Eb', 'Db')
    -- key_our: Alina's canonical key. NULL = not yet decided (SÄVELLAJI PITÄÄ PÄÄTTÄÄ).
    -- key_orig: original recording key. NULL = same as key_our (no transposition).
    -- key_transposition_st: semitones from orig to our key. NULL = no transposition.
    ADD COLUMN key_our              VARCHAR(10)                                      DEFAULT NULL,
    ADD COLUMN key_orig             VARCHAR(10)                                      DEFAULT NULL,
    ADD COLUMN key_transposition_st TINYINT                                          DEFAULT NULL,
    -- Arrangement flags
    ADD COLUMN has_gtr2             TINYINT(1)       NOT NULL DEFAULT 0,
    ADD COLUMN karaoke_eligible     TINYINT(1)       NOT NULL DEFAULT 0,
    -- Spotify ID uniqueness: one canonical track per song
    ADD UNIQUE KEY uq_spotify_track_id (spotify_track_id);

-- 2. setlists extension
-- set_type: semantic role of this set in the gig.
--   'set'     = standard numbered set (Set 1, Set 2, …)
--   'lounge'  = lounge/background set (typically jazz; may contain anything)
--   'encore'  = encore set
--   'karaoke' = karaoke set (may draw from karaoke list or anything else)
-- set_number remains the sequential ordering key within a gig (1-based).
ALTER TABLE setlists
    ADD COLUMN set_type ENUM('set', 'lounge', 'encore', 'karaoke') NOT NULL DEFAULT 'set'
    AFTER set_number;

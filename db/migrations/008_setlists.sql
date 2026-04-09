-- Migration 008: setlists
-- Three tables that together implement the band setlist feature:
--   songs       — global repertoire library (deduped by title + artist)
--   setlists    — one named set per gig (Set 1, Set 2, …)
--   setlist_songs — ordered junction; same song may appear twice in a set
--
-- Apply after migration 007_gig_personnel_schema.sql.

CREATE TABLE IF NOT EXISTS songs (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    title       VARCHAR(255)    NOT NULL,
    artist      VARCHAR(255)    NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  DATETIME                 DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_song_title_artist (title, artist)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per set number per gig.
-- set_number: 1–4 (covers typical 2–3 set structure with headroom).
-- name: optional custom label (e.g. "Viihdesetit"); NULL = display as "Set N".
-- No soft-delete: empty sets are removed entirely on last song deletion.
CREATE TABLE IF NOT EXISTS setlists (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    gig_id      INT UNSIGNED     NOT NULL,
    set_number  TINYINT UNSIGNED NOT NULL,
    name        VARCHAR(100)              DEFAULT NULL,
    created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_setlist_gig_set (gig_id, set_number),
    CONSTRAINT fk_sl_gig FOREIGN KEY (gig_id) REFERENCES gigs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ordered song slots within a set.
-- sort_order: ascending; gaps allowed (swap-based reorder preserves values).
-- notes: optional per-slot annotation (key, transition cue, arrangement note).
-- No unique key on (setlist_id, song_id): same song may repeat in a set.
CREATE TABLE IF NOT EXISTS setlist_songs (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    setlist_id  INT UNSIGNED     NOT NULL,
    song_id     INT UNSIGNED     NOT NULL,
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    notes       VARCHAR(255)              DEFAULT NULL,
    created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_ss_setlist FOREIGN KEY (setlist_id) REFERENCES setlists    (id),
    CONSTRAINT fk_ss_song    FOREIGN KEY (song_id)    REFERENCES songs        (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

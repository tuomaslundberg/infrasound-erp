-- Migration 002: store granular pricing inputs on gigs table
-- Run once against both dev and prod databases.
--
-- Persists the tier flags and additional-service quantities that
-- PriceCalculator uses to compute base_price_cents, so that the
-- edit form can pre-populate them from the saved row instead of
-- resetting them to zero.
--
-- Column semantics:
--   pricing_tier1            — on-season Saturday flag (TINYINT boolean)
--   pricing_tier2            — high-demand date flag (TINYINT boolean)
--   qty_ennakkoroudaus        — number of ennakkoroudaus sessions ordered
--   qty_song_requests_extra   — number of extra song requests ordered
--   qty_extra_performances    — number of extra performances ordered
--   qty_background_music_h    — hours of background music ordered
--   qty_live_album            — number of live album copies ordered
--   discount_cents            — gross discount in eurocents

ALTER TABLE gigs
    ADD COLUMN pricing_tier1             TINYINT(1)       NOT NULL DEFAULT 0,
    ADD COLUMN pricing_tier2             TINYINT(1)       NOT NULL DEFAULT 0,
    ADD COLUMN qty_ennakkoroudaus        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN qty_song_requests_extra   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN qty_extra_performances    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN qty_background_music_h    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN qty_live_album            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN discount_cents            INT              NOT NULL DEFAULT 0;

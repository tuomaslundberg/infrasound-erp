# Dev Log

Newest entry first. One entry per work session; entries written by Claude at session close.
Format: date · who · what was done · suggested next steps.

---

## 2026-05-13 — Invoicing ETL + Spotify fixes

**Branch:** `feat/setlist-etl`

### Done
- Fixed `enrich_spotify.py` for two Spotify API breaking changes (late 2024):
  switched from `SpotifyClientCredentials` to `SpotifyOAuth` (playlist endpoints now
  require user auth even for public playlists); fixed `item` vs `track` key rename in
  playlist item response. Result: 399/432 songs resolved (92%), 105 karaoke_eligible.
- Created `db/seeds/spotify_manual.sql` — template for manually filling the 33 unresolved
  songs; track ID lookup instructions in file header.
- Implemented `cli/etl/extract_invoicing.py`: 64/64 invoicing rows matched, 0 unmatched.
  Populates `quoted_price_cents` + `gig_personnel` (388 rows across 64 gigs).
  All partner exceptions, Valtteri Alanen substitutions, MUUT column, and fee-in-KULUT
  substitutes handled via hardcoded tables per spec.
- Added `etl-invoicing` / `import-legacy-invoicing[-prod]` Makefile targets.
- Loaded both Spotify enrichment and invoicing data into dev DB.
- Updated CHANGELOG.md.
- Created `dev-log.md` (this file) + added pointer in CLAUDE.md.

### Next steps
1. **Fill spotify_manual.sql** — manually look up Spotify track IDs for the 33 unresolved
   songs (`db/seeds/spotify_manual.sql`); load with `make shell-db-dev`.
2. **Feature A: Tilauslomake → confirmed** — add `$status` param to `GigCreator::create()`,
   pass `'confirmed'` from `handleTilauslomake()`, persist `order_description`. Branch:
   `feat/webflow-confirmation`. Fully specced in plan file.
3. **Prod deployment** — apply migrations 013/014 to prod, load songs/setlists/Spotify/
   invoicing seeds there. Prerequisites: `make seed-musicians-prod` first.

---

## 2026-05-12 — Setlist / song / Spotify ETL sprint

**Branch:** `feat/setlist-etl`

### Done
- Fixed language-assignment bug in `cli/etl/extract_songs.py`: two-pass pre-scan counts
  blank-line-separated groups per genre section, maps 1→[fi], 2→[fi,en], 3→[fi,sv,en].
  Tested and loaded: 432 songs (390 gig + 42 jazz) into dev DB.
- Implemented `cli/etl/extract_setlists.py`: three-era setlist format support (2021-2022
  internal, early-2023 numeric codes, 2023+ tabbed). Song matching: exact → prefix/suffix
  → fuzzy (0.80) → jazz title-only for lounge sets. Result: 63/63 gigs, 208 sets,
  2252 setlist_songs, 63 unmatched logged to `db/seeds/legacy_setlists_unmatched.txt`.
- Implemented `cli/etl/enrich_spotify.py`: Phase 1 playlist seeding (4 known playlists,
  threshold 0.85, karaoke playlist sets `karaoke_eligible=1`), Phase 2 Search API fallback.
  Script is written but **not yet run** — requires `pip install spotipy`.
- Added all Makefile targets: `etl-songs`, `import-legacy-songs[-prod]`, `etl-setlists`,
  `import-legacy-setlists[-prod]`, `etl-spotify`, `import-legacy-spotify[-prod]`.
- Updated CHANGELOG.md.

### Next steps
1. **Run Spotify enrichment** (immediate, ~5 min):
   `pip install spotipy && make etl-spotify && make import-legacy-spotify`
2. **Invoicing ETL** — `gig-invoicing.xlsx` → `quoted_price_cents` updates + `gig_personnel`
   rows. Spec: `cli/etl/INVOICING_ETL_SPEC.md`. Note: `gig_expenses_total_cents` is deferred.
3. **Feature A: Tilauslomake → confirmed** — add `$status` param to `GigCreator::create()`,
   pass `'confirmed'` from `handleTilauslomake()`, persist `order_description` from form fields.
   Branch: `feat/webflow-confirmation`. Fully specced in plan file.

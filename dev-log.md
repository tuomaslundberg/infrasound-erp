# Dev Log

Newest entry first. One entry per work session; entries written by Claude at session close.
Format: date · who · what was done · suggested next steps.

---

## 2026-05-14 — Phase 4 + venue ETL specs; Spotify coverage completed

**Branch:** `main` / `dev` (in sync)

### Done
- Filled all 15 remaining Spotify track IDs (manual lookup by Tuomas); soft-deleted
  the 15 featured-artist duplicate rows inserted by `import_spotify_playlist.py`
  (unique constraint required nulling IDs before soft-delete); dev DB now 542/542 = 100%
- Updated `spotify_manual.sql` to serve as prod replay: nulls IDs on duplicates first,
  then assigns to original repertoire rows
- Merged `feat/spotify-playlist-import` → `main` (went to main directly; dev synced
  by fast-forward push)
- Corrected default lineup spec: Alina Kangas (vocals), not Mikael Lehto (quit 2023);
  annotated `INVOICING_ETL_SPEC.md` with explicit note
- Wrote `PHASE4_SPEC.md` — full implementation spec for Phase 4 sprint (6 features):
  geocoding map, entity normalisation in InquiryExtractor, venue schema + edit UI,
  venue fuzzy lookup, default lineup auto-fill, gig list filters
- Wrote `cli/etl/VENUES_ETL_SPEC.md` — venuu.fi crawl spec for Finnish venue corpus
  (Varsinais-Suomi / Pirkanmaa / Uusimaa); includes pre-crawl checklist, type filtering
  guidance, geocoding approach, SQL output format, Makefile targets
- Updated `CONTEXT_PROMPT.md`, `TODO.md` to reference new specs

### Next steps
1. **Test on dev** — Tuomas to verify dev environment after merge
2. **Phase 4 sprint** — `feat/phase4-polish` branched from `dev`; feed `PHASE4_SPEC.md`
   to Claude Code; do features A → F in order (B before C; rest independent)
3. **Venue corpus** — check venuu.fi robots.txt + URL structure first; then
   `cli/etl/extract_venues.py` per `VENUES_ETL_SPEC.md`
4. **Prod ETL deploy** — once dev is verified: apply migrations 013/014 to prod,
   load all legacy seeds + `spotify_manual.sql`
5. **Joni geocoding** — verify via geocoding map (Feature A); fix address if wrong

---

## 2026-05-14 — Bookkeeping schema + context documentation

**Branch:** `feat/setlist-etl` (documentation/schema only — no migrations applied yet)

### Done
- Explored `management/financial/varjokirjanpito.xlsx` in full: all sheets read (tuntikrjp,
  tilit, matkalaskut, matkaloki, paivakirja, myynnit-tuntikrjp). Fully understood partner
  credit balance model (gig earnings + hourly work − payments out).
- Explored `management/financial/keikkapalkat.xlsx`: gig earnings structure, fee split
  formula (miksaajan palkkio = 10% net, rest split among musicians), payment log on right.
- Explored `management/accounting/ostolaskut/matkalaskut/xlsx/` travel invoices (Tuomas
  monthly batches, Toni/Lauri occasional). VSYP Matkalasku format fully documented.
- Read `241231-mikael-siirtovelat.pdf`: Mikael's deferred artist fee liability model
  (accumulated 2844.76€ as of Dec 2024, paid out in irregular chunks).
- Updated `cli/etl/BOOKKEEPING_CONTEXT.md`: added §3 (partner credit mechanics),
  §4 (travel invoicing flows), resolved both previously-open questions; renumbered
  sections; updated ETL sequencing table; corrected ostolaskut directory structure.
- Written `db/migrations/015_bookkeeping_schema.sql`: full Phase 6-7 ledger schema
  (accounts, vat_rate_schedule, journal_events, journal_lines, partner_credit_events,
  plus validation views). NOT yet applied.
- Written `db/migrations/016_documents_schema.sql`: document storage system
  (`documents` table, `km_rates` config, ALTER `journal_events` for document FK).
- Added `storage/documents/` directory skeleton + gitignore rules.
- Updated `docker-compose.yml` with storage bind-mount.
- Updated `AGENTS.md`: storage directory in repo map, PHP auth gate rule in §5.
- Updated `BOOKKEEPING_CONTEXT.md`: corrected musician fee invoice type, travel
  reimbursements don't affect partner credit, five credit mechanisms, Mikael
  siirtovelat = snapshot (live total in keikkapalkat.xlsx), oy-tavarainventaario
  deductions, document migration sequencing added to ETL table.
- Updated CHANGELOG.md and CONTEXT_PROMPT.md.

### Next steps (immediate — Claude Code)
1. **Merge feat/setlist-etl → dev** — open PR; migration rename already done (013→014).
2. **Prod deployment** (after merge):
   ```
   make seed-musicians-prod
   make migrate-prod FILE=db/migrations/013_valtteri_transport_fix.sql
   make migrate-prod FILE=db/migrations/014_songs_extension.sql
   make import-legacy-songs-prod
   make import-legacy-setlists-prod
   make import-legacy-spotify-prod
   make import-legacy-invoicing-prod
   ```
3. **Fill spotify_manual.sql** — 33 songs need manual Spotify track IDs.
4. **Phase 4 feature work** — venue edit UI, lineup auto-fill, inquiry polish (all `[copilot]` in TODO.md).

### Migrations 015 + 016 — when to apply
Deferred to Phase 6-7 (bookkeeping module). Do not apply during current feature work.
See `cli/etl/BOOKKEEPING_CONTEXT.md` for full Phase 6-7 sequencing.

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

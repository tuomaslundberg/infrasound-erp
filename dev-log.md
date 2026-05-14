# Dev Log

Newest entry first. One entry per work session; entries written by Claude at session close.
Format: date · who · what was done · suggested next steps.

---

## 2026-05-14 — Sprint close: route fix, repo review, PHASE4_SPEC complete

**Branch:** `feat/setlist-analytics` (ready to PR)

### Done
- Diagnosed Maxwell Car 2 route bug: `default_car=2` was missing from `musician_addresses.sql`
  for maxwell.mbare; migration 012 only seeded Mortti + Lauri. Fixed in seed file + dev DB.
  Result: route calculation now correctly assigns Maxwell as Car 2 driver when present.
- Added Feature G (gig conversation context) to `PHASE4_SPEC.md`: migration 018
  (`gig_messages` table), raw text persistence in process_inquiry.php + webflow.php,
  collapsible display on gig detail. PHASE4_SPEC now covers 7 features A–G.
- Full repo review pass: updated `CONTEXT_PROMPT.md` (was very stale — still referenced
  feat/setlist-etl as open branch, 92% Spotify, wrong next steps), `TODO.md` (collapsed
  stale branch section, added Feature G, added setlist analytics as ✓ in Phase 5),
  `CHANGELOG.md` (Maxwell fix + Feature G entry).
- Confirmed on prod: Tilauslomake creates status=confirmed with order_description ✓;
  webhook debug logging is now safe to remove.
- Confirmed prod seeds deferred by design: full ETL transition from fresh Dropbox snapshot;
  migrations 001–014 applied on prod, no seeds yet.
- Confirmed Spotify UI not yet wired (Phase 5); correct.

### Prod deployment — when ready
Full sequence once all ETL scripts are finalized and fresh Dropbox snapshot is taken:
```
make seed-musicians-prod              # includes Maxwell Car 2 fix
make seed-musician-addresses-prod
make geocode-musicians-prod
make import-legacy-songs-prod
make import-legacy-setlists-prod
make import-legacy-spotify-prod
make import-legacy-invoicing-prod
# Then apply spotify_manual.sql to prod DB directly
```
Migrations 015 + 016 remain deferred (Phase 7).

### Next steps
1. **PR feat/setlist-analytics → dev → main** — clean 1-commit branch, ready
2. **Remove webhook debug logging** — safe to do; confirmed working on prod
3. **Phase 4 sprint** — branch `feat/phase4-polish` from `dev`; feed `PHASE4_SPEC.md`
4. **Verify setlist analytics** — run `python cli/etl/analyze_setlists.py | head -100`
   against dev DB; check --generate and --fill flags
5. **Venue corpus** — pre-crawl checklist (robots.txt, URL structure) before writing script

---

## 2026-05-14 — Setlist analytics CLI + admin page

**Branch:** `feat/setlist-analytics` (uncommitted — index.lock blocked commit from sandbox; run `git add -A && git commit` from terminal)

### Done
- Created `cli/etl/analyze_setlists.py`:
  - `SetlistAnalytics` class: four cached query methods — `play_frequency()`, `recency()`,
    `set_structure()`, `cooccurrence()`. Intentionally I/O-free so it can be embedded in
    a future PHP wrapper (subprocess or port to PDO).
  - `SetlistBuilder` class: `fill_and_order(seed_ids, target_runtime_min, set_count)` —
    primary real-world function: takes unordered customer picks, fills/trims to target
    runtime (using configurable avg song duration, default 3.5 min), orders by greedy
    co-occurrence TSP, divides into sets. `generate_fresh(n, set_count)` for from-scratch
    generation. Scoring: 0.6×frequency + 0.4×recency (stale songs given higher weight).
  - CLI modes: default Markdown report to stdout, `--json`, `--generate N`,
    `--fill ID,ID,...  --target MINUTES --sets N`, `--seed` for reproducibility.
  - Set structure uses ROW_NUMBER() window function for gap-safe consecutive-pair
    transitions (sort_order has gaps due to swap-based reorder).
- Created `src/modules/admin/setlist_analytics.php` at `/admin/setlist-analytics`:
  - Three-tab Bootstrap 5 layout: Top-40 (with year columns 2013–present),
    Recency review (in_repertoire=1, not played >2yr; red highlight >3yr, grey = never),
    Never-played.
  - Summary strip: total songs, in-repertoire count, total play slots, stale count,
    never-played count.
  - PHP queries mirror Python definitions exactly (same recency threshold, same join logic).
- Wired route in `src/index.php` and nav link in `src/templates/layout.php`.
- Updated CHANGELOG.md.

### Next steps
1. **Commit from terminal** (index.lock blocked sandbox git):
   ```
   cd ~/projects/infrasound.fi
   git add cli/etl/analyze_setlists.py src/modules/admin/setlist_analytics.php \
           src/index.php src/templates/layout.php CHANGELOG.md
   git commit -m "feat: setlist analytics CLI + admin page"
   ```
2. **Run the report against dev DB** to verify queries work:
   ```
   python cli/etl/analyze_setlists.py | head -100
   python cli/etl/analyze_setlists.py --generate 20
   ```
3. **Test --fill flow** with a few real song IDs from the DB as a smoke-test of the builder.
4. **Duration column (future)** — add `duration_ms INT DEFAULT NULL` to songs + populate
   via Spotify track metadata API; SetlistBuilder already reads `avg_song_duration_min` as
   a constructor param, so the upgrade path is: pass per-song durations once available.
5. **PR feat/setlist-analytics → dev** when satisfied.

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

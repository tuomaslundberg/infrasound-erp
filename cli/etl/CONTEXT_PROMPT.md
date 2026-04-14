# Claude Code context prompt — Invoicing ETL next session

Use this to bring a fresh Claude Code session up to speed on where the invoicing ETL work
stands. Feed this file verbatim, then continue from the "Next task" section.

---

## Project in one paragraph

Custom ERP/CRM for a Finnish micro-company (band management). Stack: PHP 8.2 + MariaDB 10.11
+ Vue 3 + Bootstrap 5 + Docker. Authoritative conventions: CLAUDE.md (project root) and
AGENTS.md. Key rules: UTC timestamps, monetary values as integers (eurocents), PDO only,
soft deletes, no hardcoded secrets.

---

## ETL pipeline — current state

Three-stage legacy data import pipeline:

| Stage | Script / file | Makefile target | Status |
|-------|---------------|-----------------|--------|
| 1. Extraction | `cli/etl/extract_gigs.py` | `make etl-gigs` | ✅ done |
| 2. Seed import | `db/seeds/legacy_gigs.sql` (generated) | `make import-legacy-gigs` | ✅ done |
| 3. Enrichment | `cli/etl/enrich_gigs.py` → `db/seeds/legacy_enrich.sql` | `make etl-enrich && make enrich-dev` | ✅ done |
| 4. Invoicing ETL | `cli/etl/extract_invoicing.py` (NOT YET WRITTEN) | tbd | ❌ pending |

Stage 4 is gated behind `cli/etl/INVOICING_ETL_SPEC.md` — read it in full before touching
the script. Source data lives at `old-files/gig-invoicing.xlsx`.

---

## What was done in the last Cowork session

### Schema migrations written (not yet applied to any DB)

- `db/migrations/006_users_email.sql` — adds nullable `email VARCHAR(255)` to `users`
- `db/migrations/007_gig_personnel_schema.sql` — two changes to `gig_personnel`:
  1. `role` ENUM renamed from person-nouns (vocalist, guitarist …) to instrument/function
     nouns: `vocals`, `guitar`, `bass`, `drums`, `keyboards`, `sound_engineering`, `other`
  2. `fee_cents` made nullable (NULL = partner fee not tracked; 0 = confirmed zero; >0 = amount)
  The migration contains a commented-out UPDATE block for remapping any pre-existing rows.

Apply both to dev first:
```
make migrate-dev FILE=db/migrations/006_users_email.sql
make migrate-dev FILE=db/migrations/007_gig_personnel_schema.sql
```
Then to prod when ready.

### Musician seed written

`db/seeds/musicians.sql` — idempotent INSERT IGNORE for all 19 roster members.
All seeded as `role = 'musician'` with `'!'` password sentinel (locked; cannot log in).
INSERT IGNORE preserves any existing partner accounts (Tuomas/Toni/Joni/Lauri) with
higher-privilege roles intact.

Apply after migrations 006 + 007:
```
make seed-musicians        # dev
make seed-musicians-prod   # prod
```

### PHP updated to new role ENUM values

- `src/modules/gigs/personnel_add.php` — `$validRoles` updated
- `src/modules/gigs/detail.php` — role `<option>` values, display rendering, and
  null-safe `fee_cents` display updated

### INVOICING_ETL_SPEC.md — all 🚩 flags resolved

The following previously-flagged items are now confirmed and updated in the spec:

1. Leevi Kähkönen 2022-07-23 (Kaisa Korpisaari): fee **120.97€ net** (in KULUT)
2. Leevi Kähkönen 2022-07-30 (Julle Storberg): fee **169.35€ net** = ROUND(210/1.24, 2) (in KULUT)
3. Marikki Rieppola 2023-07-28: **Emil Lamminmäki absent**, Erkki Sippel on bass.
   NB: Emil may appear in source data as "Jorgos Riverside" — treat as the same person;
   Emil Lamminmäki is the legal name.
4. Kaisa Heinimaa 2023-07-29: **Emil Lamminmäki absent**, Antti Saari on bass.
5. Ulosottolaitos 2024-09-12: **Mortti WAS on the gig** (fee 251.92€ in his column, col 20).
   Iris Toivonen in MUUT (fee 251.92€). Alina absent. Calendar `(MORTTI)` was correct.
6. MIKSAAJAN PALKKIO (col 8) is **role-generic**, not Toni-specific. Valtteri Alanen's
   engineering fee also appears in this column on his gigs. MIKSAUSKREDIITIT is the credit
   accumulator; on Valtteri gigs his fee is included in col 8 and deducted from the credit
   sum so Toni accrues nothing for gigs he didn't do. Valtteri's fee is NOT in KULUT or MUUT.

---

## What is NOT done yet (scope of next session)

### Still the only remaining prerequisite before the ETL script

- **KULUT decision** (spec §Prerequisites item 3): decide whether to add a
  `gig_expenses_total_cents INT` column to `gigs` before writing the ETL, or defer it
  entirely to Phase 7 (bookkeeping module). The ETL spec recommends deferral; confirm with
  Tuomas before adding schema.

### The ETL script itself: `cli/etl/extract_invoicing.py`

Produces `db/seeds/legacy_invoicing.sql` with two classes of statements:
1. `UPDATE gigs SET quoted_price_cents = <keikkapalkkio_cents>` — for all delivered gigs
   where keikkapalkkio > 0. Do NOT update the three zero-fee gigs listed in the spec.
2. `INSERT INTO gig_personnel …` — one row per musician per gig. Lineup source depends
   on whether the gig has an invoicing row:
   - **Has invoicing row (delivered gig)**: derive presence from fee columns + documented
     exceptions. Partner slots always inserted (fee_cents = NULL); external slots inserted
     where fee > 0.
   - **No invoicing row (future / ongoing quote)**: `gig-invoicing.xlsx` has no data for
     these. Derive lineup entirely from the Google Calendar event description (parenthetical
     2024 format or structured HTML 2025 format — see spec). The legacy seed includes future
     gigs from `extract_gigs.py`, so this case will arise and must be handled in the same
     pass.

Full details, column map, matching strategy, and all personnel exceptions are in
`cli/etl/INVOICING_ETL_SPEC.md`. Read it before writing a line of code.

This session did **NOT** touch gig fees or gig_personnel rows — those are entirely the
responsibility of `extract_invoicing.py`.

---

## Setlist ETL — separate work stream

A parallel ETL work stream for songs and setlists has been scoped and specced. It is
independent of the invoicing ETL and can proceed in either order.

Key files:
- `cli/etl/SETLIST_ETL_SPEC.md` — full spec (source files, format eras, schema, Spotify plan)
- `db/migrations/008_setlists.sql` — songs/setlists/setlist_songs schema (already applied)
- `db/migrations/014_songs_extension.sql` — songs metadata + setlists.set_type (NOT YET applied)

Prerequisites completed: Spotify Developer app created (Client Credentials, HTTPS redirect URI);
`SPOTIFY_CLIENT_ID` + `SPOTIFY_CLIENT_SECRET` in `.env`, `.env.dev`, and prod `.env`;
`spotipy>=2.23` added to `cli/etl/requirements.txt`.

Next steps (in order):
1. Apply migration 014 to dev: `make migrate-dev FILE=db/migrations/014_songs_extension.sql`
2. Implement `cli/etl/extract_songs.py` → `db/seeds/legacy_songs.sql`
3. Implement `cli/etl/extract_setlists.py` → `db/seeds/legacy_setlists.sql`
4. Implement `cli/etl/enrich_spotify.py` → `db/seeds/legacy_spotify.sql`

Branch: `feat/setlist-etl`. See spec for full output file plan and parsing rules.

---

## Key files to read before starting

1. `CLAUDE.md` — non-negotiable conventions
2. `AGENTS.md` — architecture reference
3. `cli/etl/INVOICING_ETL_SPEC.md` — full invoicing ETL spec (all flags now resolved)
4. `cli/etl/SETLIST_ETL_SPEC.md` — setlist ETL spec
5. `db/migrations/007_gig_personnel_schema.sql` — gig_personnel ENUM/nullable changes
6. `cli/etl/enrich_gigs.py` — reference implementation for the fuzzy match + SQL emit pattern

# Claude Code context prompt — Infrasound ERP

Use this to bring a fresh Claude Code session up to speed on current project state.
Feed verbatim, then continue from the "Next steps" section.

---

## Project in one paragraph

Custom ERP/CRM for a Finnish micro-company (band management). Stack: PHP 8.2 + MariaDB 10.11
+ Vue 3 + Bootstrap 5 + Docker. Authoritative conventions: CLAUDE.md (project root) and
AGENTS.md. Key rules: UTC timestamps, monetary values as integers (eurocents), PDO only,
soft deletes, no hardcoded secrets.

---

## ETL pipeline — current state (as of 2026-05-15)

All legacy ETL scripts are written and loaded into dev DB. See `dev-log.md` for detail.

| Script | Output | Dev status | Prod status |
|---|---|---|---|
| `extract_gigs.py` | `legacy_gigs.sql` | ✅ loaded | ✅ loaded |
| `enrich_gigs.py` | `legacy_enrich.sql` | ✅ loaded | ✅ loaded |
| `extract_songs.py` | `legacy_songs.sql` | ✅ loaded (542 songs, 100% Spotify) | ⏳ deferred |
| `extract_setlists.py` | `legacy_setlists.sql` | ✅ loaded (2253 setlist_songs) | ⏳ deferred |
| `enrich_spotify.py` | `legacy_spotify.sql` | ✅ loaded | ⏳ deferred |
| `extract_invoicing.py` | `legacy_invoicing.sql` | ✅ loaded (64 gigs, 388 personnel rows) | ⏳ deferred |
| `spotify_manual.sql` | (direct SQL) | ✅ applied (15 manual IDs + dedup) | ⏳ deferred |

**Prod seeds deferred by design**: all song/setlist/invoicing seeds will be loaded in a
single transition from a fresh Dropbox snapshot, once all ETL scripts are finalized.
Do NOT run piecemeal prod imports in the meantime. Migrations 001–014 are applied on prod.

---

## Migrations — applied vs deferred

| Migration | Applied dev | Applied prod | Notes |
|---|---|---|---|
| 001–014 | ✅ | ✅ | All active feature migrations |
| 015_bookkeeping_schema | ❌ deferred | ❌ deferred | Phase 7 — do not apply yet |
| 016_documents_schema | ❌ deferred | ❌ deferred | Phase 7 — do not apply yet |
| 017_venue_practical_fields | ❌ not written yet | ❌ | Phase 4 — write as part of feat/phase4-polish |
| 018_gig_messages | ❌ not written yet | ❌ | Phase 4 Feature G |

---

## Branch state (as of 2026-05-15)

| Branch | Status |
|---|---|
| `main` | Production — in sync with `dev` |
| `dev` | Integration — clean |

All sprint work through setlist analytics + Copilot review fixes is merged to main.

---

## Migrations written but NOT yet applied (Phase 6–7)

- `015_bookkeeping_schema.sql` — double-entry ledger (accounts, journal_events/lines,
  partner_credit_events/balances VIEW)
- `016_documents_schema.sql` — document storage (documents table, km_rates, storage/ bind-mount)

Full design context: `cli/etl/BOOKKEEPING_CONTEXT.md`

---

## Next steps (in priority order)

1. **Phase 4 sprint** — branch `feat/phase4-polish` from `dev`; feed `PHASE4_SPEC.md`;
   implement Features A–G + Car 2 trip row in Pricing card; do B before C; G is independent
2. **Venue corpus ETL** — `cli/etl/extract_venues.py` per `VENUES_ETL_SPEC.md`;
   start with pre-crawl checklist (robots.txt, URL structure, category taxonomy)
3. **Phase 5 setlist builder spec** — write `PHASE5_SPEC.md` covering reactive edit view
   + song search/suggestions (web UI, not the analytics CLI which is already shipped)
4. **Prod ETL deploy** — single transition from fresh Dropbox snapshot; sequence in `dev-log.md`
5. **Phase 6 invoicing** — outgoing invoice generation from confirmed gigs

See `ROADMAP.md` for the full 13-sprint sequence toward production readiness.

---

## Known issues

- **Joni's home address** — `Kirkkotie 2, 20540 Turku` may geocode incorrectly; verify
  via geocoding map (Phase 4 Feature A)

---

## Key files to read before starting

1. `CLAUDE.md` — non-negotiable conventions
2. `AGENTS.md` — architecture reference
3. `TODO.md` — current task list
4. `ROADMAP.md` — broad-strokes 13-sprint sequence toward prod readiness
5. `dev-log.md` — session-by-session history + last suggested next steps
6. `PHASE4_SPEC.md` — Phase 4 sprint spec (7 features + Car 2 km display)
7. `cli/etl/VENUES_ETL_SPEC.md` — venuu.fi venue corpus crawl spec
8. `cli/etl/BOOKKEEPING_CONTEXT.md` — bookkeeping/invoicing design (Phase 6–7 reference)
9. `cli/etl/tappio_format_notes.md` — Tappio `.tlk` format spec
10. `cli/etl/nda_format_spec.md` — Nordea TITO `.nda` format spec

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

## ETL pipeline — current state (as of 2026-05-14)

All legacy ETL scripts are written and loaded into dev DB. See `dev-log.md` for detail.

| Script | Output | Dev status | Prod status |
|---|---|---|---|
| `extract_gigs.py` | `legacy_gigs.sql` | ✅ loaded | ✅ loaded |
| `enrich_gigs.py` | `legacy_enrich.sql` | ✅ loaded | ✅ loaded |
| `extract_songs.py` | `legacy_songs.sql` | ✅ loaded (432 songs) | ❌ pending |
| `extract_setlists.py` | `legacy_setlists.sql` | ✅ loaded (2252 setlist_songs) | ❌ pending |
| `enrich_spotify.py` | `legacy_spotify.sql` | ✅ loaded (399/432 = 92%) | ❌ pending |
| `extract_invoicing.py` | `legacy_invoicing.sql` | ✅ loaded (64 gigs, 388 personnel rows) | ❌ pending |

### Manual follow-up items
- `db/seeds/spotify_manual.sql` — 33 unresolved songs need Spotify track IDs added manually
- `db/seeds/legacy_setlists_unmatched.txt` — 63 setlist entries unmatched; review needed

---

## Branch state

Current open branch: `feat/setlist-etl` — contains all ETL scripts above.
**Not yet merged to dev.** Pending: PR → dev, then apply migration 014 to prod, then load
all seeds to prod.

Prod deployment sequence (once branch is merged):
```
make seed-musicians-prod
make migrate-prod FILE=db/migrations/013_valtteri_transport_fix.sql  # if not already applied
make migrate-prod FILE=db/migrations/014_songs_extension.sql
make import-legacy-songs-prod
make import-legacy-setlists-prod
make import-legacy-spotify-prod
make import-legacy-invoicing-prod
```

---

## Migrations written but NOT yet applied (Phase 6–7)

These exist in `db/migrations/` but are deferred to the bookkeeping module phase.
Do not apply during normal feature work.

- `015_bookkeeping_schema.sql` — double-entry ledger (`accounts`, `vat_rate_schedule`,
  `journal_events`/`journal_lines`), `partner_credit_events`/balances VIEW
- `016_documents_schema.sql` — document storage (`documents` table with tiered extraction
  status, `km_rates` seeded 2022–2026), ALTER `journal_events` for `document_id` FK;
  also adds `storage/` bind-mount (already in docker-compose.yml)

Full design context: `cli/etl/BOOKKEEPING_CONTEXT.md`

---

## Next steps (in priority order)

1. **Merge feat/setlist-etl → dev** — open PR; ensure migration 014 rename is correct
2. **Prod deployment** — sequence above
3. **Fill spotify_manual.sql** — 33 songs need manual Spotify track ID lookup
4. **Phase 4 feature work** — see TODO.md for scoped items (venue edit UI, lineup auto-fill,
   inquiry extractor polish); all marked `[copilot]`

---

## Key files to read before starting

1. `CLAUDE.md` — non-negotiable conventions
2. `AGENTS.md` — architecture reference
3. `TODO.md` — current task list
4. `dev-log.md` — session-by-session history + last suggested next steps
5. `cli/etl/INVOICING_ETL_SPEC.md` — invoicing ETL spec (for reference; script is done)
6. `cli/etl/SETLIST_ETL_SPEC.md` — setlist ETL spec (for reference; scripts are done)
7. `cli/etl/BOOKKEEPING_CONTEXT.md` — bookkeeping/invoicing filesystem map, data formats,
   VAT flow, partner credit mechanics, document storage design (Phase 6–7 reference)
8. `cli/etl/tappio_format_notes.md` — Tappio `.tlk` format spec
9. `cli/etl/nda_format_spec.md` — Nordea TITO `.nda` format spec

# TODO.md — Infrasound ERP
*Collaborative task list for ERP development. Version-controlled; canonical upstream for ERP tasks.*
*Tuomas owns architecture and prod decisions. Toni contributes via PRs to `dev`.*
*Format: `- [ ]` open · `- [x]` done · `[copilot]` good AI agent candidate · `[AWAIT]` external dependency*

---

## Immediate / operational

- [ ] **Prod: seed + geocode musicians** — apply `db/seeds/musician_addresses.sql` via
      phpMyAdmin, then run `/admin/geocode-musicians`; required before travel calculation
      is accurate on prod
- [ ] **Joni's home coordinates** — `Kirkkotie 2, 20540 Turku` geocodes to wrong location
      (~3.8 km from trailer but actual route is longer); verify correct street + municipality,
      update `home_address` in users and re-geocode via `/admin/geocode-musicians`
- [ ] **Remove webhook debug logging** — `error_log('Webflow webhook payload: ...')` in
      `src/modules/webhook/webflow.php`; keep until one prod Tilauslomake submission confirms
      `order_description` is populated, then remove
- [x] **Disable Webflow email notifications** ✓

---

## feat/spotify-playlist-import — pending push + merge

- [x] **Rename migration** — renamed to `014_songs_extension.sql` ✓
- [x] **Merge feat/setlist-etl → dev** ✓
- [x] **ETL scripts** — songs, setlists, Spotify enrichment, invoicing all done + loaded in dev ✓
- [x] **Spotify coverage** — 542/542 songs (100%) via playlists + manual IDs ✓
- [ ] **Push + PR feat/spotify-playlist-import → dev** — 2 commits local only (SSH key needed)
- [ ] **Prod ETL deployment** — after dev→main: apply migrations 013/014, load all seeds;
      sequence in `cli/etl/CONTEXT_PROMPT.md`

---

## Phase 4 — Venue + inquiry polish

*Fully specced in `PHASE4_SPEC.md`. Branch `feat/phase4-polish` from `dev`.*

- [ ] **Feature A: Geocoding verification map** — Leaflet map at `/admin/geocode-musicians`
      showing all musician home pins; required to verify + fix Joni's coordinates
- [ ] **Feature B: Entity extraction normalisation** — update `InquiryExtractor` system prompt
      to return all Finnish text fields in nominative (perusmuoto); handles venue names,
      customer names, city names; replaces the broken suffix-stripping approach
- [ ] **Feature C: Venue schema + edit UI** — migration 017 (4 boolean fields), `/admin/venues`
      list, venue edit form, link from gig detail
- [ ] **Feature D: Venue fuzzy lookup** — `similar_text()` ≥ 80% match in `GigCreator`
      before INSERT; prevents duplicate venues
- [ ] **Feature E: Default lineup auto-fill** — "Fill default lineup" button on confirmed
      gigs with no personnel; inserts 6 standard musicians with null fees
- [ ] **Feature F: Gig list filters** — date range (event date from/to) + channel dropdown
- [ ] **Venue corpus ETL** — `cli/etl/extract_venues.py` crawl of venuu.fi for
      Varsinais-Suomi / Pirkanmaa / Uusimaa; specced in `cli/etl/VENUES_ETL_SPEC.md`;
      do pre-crawl checklist first (robots.txt + URL structure)
- [ ] **`customer_type` correction pass** — manual data pass on prod after ETL deploy;
      full list of known non-wedding gigs in `TODO.md.legacy` §Easy issues

---

## Phase 5 — Setlists

- [ ] **Setlist builder polish** — reactive edit view (no full-page reload on reorder);
      song search from global repertoire + suggestions in add-song flow  `[copilot]`

---

## Phase 6 — Invoicing

- [x] **Schema: `km_rates`** — done in `db/migrations/016_documents_schema.sql`;
      seeded with Verohallinto rates 2022–2026 (base/trailer/passenger categories)
- [ ] **Schema: `outgoing_invoices`, `incoming_invoices`** — outgoing ties to `gig_id`;
      fields: number, issue date, due date, status, amount in eurocents  `[copilot]`
- [ ] **Outgoing invoice creation** — generate from confirmed gig; **prerequisite**:
      `PriceCalculator` must return itemised rows (currently returns only `gross_total`)
- [ ] **Invoice list / status tracking** — filter by status; mark as paid  `[copilot]`
- [ ] **Incoming invoice / expense log** — expenses against a gig or overhead  `[copilot]`

---

## Phase 7 — Accounting

*Schema foundation written (migrations 015 + 016) — not yet applied. See
`cli/etl/BOOKKEEPING_CONTEXT.md` for full design.*

- [ ] **Apply migrations 015 + 016** — prerequisite for all Phase 7 work
- [ ] **Document migration** — copy management/ files → `storage/documents/`; index in DB
- [ ] **Tappio `.tlk` import** — historical ledger events 2021–2025
- [ ] **`.nda` bank statement import** — auto-classify + manual review queue
- [ ] **Partner credit seed** — from `old-files/internal-bookkeeping.xlsx` + `old-files/gig-invoicing.xlsx`
- [ ] **Inbound invoice extraction pipeline** — pdftotext → LLM → LLM vision tiers;
      human review queue; `extraction_status` field gates journal event authority
- [ ] **Basic ledger view** — income vs expenses per period, grouped by gig
- [ ] **Export to CSV** — for accountant handoff  `[copilot]`

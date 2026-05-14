# TODO.md — Infrasound ERP
*Collaborative task list for ERP development. Version-controlled; canonical upstream for ERP tasks.*
*Tuomas owns architecture and prod decisions. Toni contributes via PRs to `dev`.*
*Format: `- [ ]` open · `- [x]` done · `[copilot]` good AI agent candidate · `[AWAIT]` external dependency*

---

## Immediate / operational

- [ ] **Prod: seed + geocode musicians** — apply `db/seeds/musician_addresses.sql` (includes
      Maxwell Car 2 fix), then run `/admin/geocode-musicians`; do this as part of the
      full prod ETL transition from fresh Dropbox snapshot (see dev-log.md)
- [ ] **Joni's home coordinates** — verify correct address via geocoding map (Phase 4
      Feature A); `Kirkkotie 2, 20540 Turku` may geocode ~3.8 km off
- [ ] **Remove webhook debug logging** — `error_log('Webflow webhook payload: ...')` in
      `src/modules/webhook/webflow.php`; prod Tilauslomake confirmed working ✓ — safe to remove
- [x] **Disable Webflow email notifications** ✓

---

## In progress / pending merge

- [ ] **PR feat/setlist-analytics → dev** — setlist analytics CLI + admin page + Maxwell
      Car 2 fix (`default_car=2` was missing for maxwell.mbare, caused route calculation
      to dump Helsinki passengers into Car 1)

---

## Phase 4 — Venue + inquiry polish

*Fully specced in `PHASE4_SPEC.md`. Branch `feat/phase4-polish` from `dev`.*

- [ ] **Feature A: Geocoding verification map** — Leaflet map at `/admin/geocode-musicians`
      showing all musician home pins; required to verify + fix Joni's coordinates
- [ ] **Feature B: Entity extraction normalisation** — update `InquiryExtractor` system prompt
      to return all Finnish text fields in nominative (perusmuoto); handles venue names,
      customer names, city names
- [ ] **Feature C: Venue schema + edit UI** — migration 017 (4 boolean fields + source column),
      `/admin/venues` list, venue edit form, link from gig detail
- [ ] **Feature D: Venue fuzzy lookup** — `similar_text()` ≥ 80% match in `GigCreator`
      before INSERT; prevents duplicate venues
- [ ] **Feature E: Default lineup auto-fill** — "Fill default lineup" button on confirmed
      gigs with no personnel; inserts 6 standard musicians (Tuomas/Toni/Joni/Lauri/Alina/Mortti)
      with null fees
- [ ] **Feature F: Gig list filters** — date range (event date from/to) + channel dropdown
- [ ] **Feature G: Gig conversation context** — migration 018 (`gig_messages` table);
      save raw inquiry text and Webflow payload; display collapsible on gig detail
- [ ] **Venue corpus ETL** — `cli/etl/extract_venues.py` crawl of venuu.fi for
      Varsinais-Suomi / Pirkanmaa / Uusimaa; specced in `cli/etl/VENUES_ETL_SPEC.md`;
      do pre-crawl checklist first (robots.txt + URL structure)
- [ ] **`customer_type` correction pass** — manual data pass on prod after ETL deploy;
      full list of known non-wedding gigs in `TODO.md.legacy` §Easy issues

---

## Phase 5 — Setlists

- [x] **Setlist analytics** — `cli/etl/analyze_setlists.py` (play frequency, recency,
      co-occurrence, SetlistBuilder) + `/admin/setlist-analytics` page ✓ (needs further
      polish and verification against dev DB)
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

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

- [ ] **Venue fuzzy lookup in `process_inquiry.php`** — before INSERT, fuzzy-match incoming
      venue name+city against existing rows; only create a new row if no match above
      threshold; prevents duplicate venues and broken "has band played here before"
      template-selection  `[copilot]`
- [ ] **Venue edit UI** — CRUD form for `name`, `address_line`, `city`,
      `distance_from_turku_km`, `notes`; accessible from gig detail and `/admin/venues`
      list; needed to correct ETL-seeded placeholder rows without SQL  `[copilot]`
- [ ] **Venue practical fields** — add `has_stage`, `haze_allowed`, `outside_gig`,
      `use_house_PA` to venues schema + edit form; surface on gig detail  `[copilot]`
- [ ] **Default lineup auto-fill** — "Fill default lineup" button on gig detail when
      `status=confirmed` and no personnel assigned; inserts the 6 default musicians with
      null fees; owner adjusts  `[copilot]`
- [ ] **Inquiry extractor polish** — (a) default `customer_name` to contact name when AI
      leaves it empty; (b) strip Finnish case suffixes from venue name before geocoding
      (e.g. "Hintsan Vintille" → "Hintsan Vinti" caused geocoding failure)  `[copilot]`
- [ ] **Additional gig filters** — filter by time range (event date + inquiry date) and
      channel enum  `[copilot]`
- [ ] **`customer_type` correction pass** — imported gigs default to `wedding`; manual pass
      after prod rebuild; full list of known non-wedding gigs in `TODO.md.legacy` §Easy issues

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

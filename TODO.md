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
- [ ] **Disable Webflow email notifications** — in Webflow Designer for both Email Form and
      Tilauslomake; prevents duplicate notifications once the ERP webhook is the primary path

---

## feat/setlist-etl — pending merge

- [ ] **Rename migration** — branch has `013_*.sql`; `013_valtteri_transport_fix.sql` is
      already on `dev`; rename setlist-etl migration to `014_*.sql` before merging
- [ ] **Merge feat/setlist-etl → dev** — standard PR; apply migration 014 to dev + prod

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

- [ ] **Schema: `km_rates`** — `(year INT PK, rate_cents_per_km INT NOT NULL)`; Finnish
      Verohallinto km-reimbursement rate by year; never hardcode in business logic;
      seed with historical rates  `[copilot]`
- [ ] **Schema: `outgoing_invoices`, `incoming_invoices`** — outgoing ties to `gig_id`;
      fields: number, issue date, due date, status, amount in eurocents  `[copilot]`
- [ ] **Outgoing invoice creation** — generate from confirmed gig; **prerequisite**:
      `PriceCalculator` must return itemised rows (currently returns only `gross_total`)
- [ ] **Invoice list / status tracking** — filter by status; mark as paid  `[copilot]`
- [ ] **Incoming invoice / expense log** — expenses against a gig or overhead  `[copilot]`

---

## Phase 7 — Accounting

- [ ] **Basic ledger view** — income vs expenses per period, grouped by gig
- [ ] **Export to CSV** — for accountant handoff  `[copilot]`

# TODO

Project task list. Phases reflect real operational priority: the ERP must be
usable for actual inquiry processing before anything else is built.

Items marked `[copilot]` are good candidates for GitHub Copilot Coding Agent
(self-contained, bounded scope, clear acceptance criteria).

---

## Phase 0 — Dev infrastructure

Unblocks all local development and testing.

- [x] **Dev environment config** — add `.env.dev` pointing to `fi_infrasound_dev`,
      add Makefile `make dev` / `make up` targets so you can switch between
      dev seed data and prod data without touching the codebase or branch
- [x] **Seed dev DB** — run `db/seeds/dev.sql` against `fi_infrasound_dev`
      (5 gigs from 2026 season, venues, customers, contacts, song requests)
- [x] **AGENTS.md update** — add `db/seeds/` to repo tree in §4

---

## Phase 1 — Inquiry processing in ERP  ← highest priority

Goal: replace the current CLI + copy-paste workflow with a web UI that writes
to the DB and outputs the filled quote email.

- [x] **PHP router** — single-entry `src/index.php` dispatcher; route table maps
      URL patterns to controller files; supports both authenticated and public
      (unauthenticated) routes from day one so the door stays open for a future
      public inquiry form
- [x] **Shared layout** — Bootstrap 5 shell (header, nav, footer); no Vue yet,
      plain PHP templates
- [x] **Inquiry form** — web form mirroring `cli/inquiry-template.yaml` fields;
      on submit: upsert customer + contact + venue + gig rows; redirect to
      inquiry detail
- [x] **Inquiry list page** — paginated table of gigs (date, customer, status,
      quoted price); links to detail
- [x] **Inquiry detail / edit page** — read/edit all gig fields; delete = soft
      delete (`deleted_at`)
- [x] **Quote calculation on save** — call PriceCalculator logic (ported from
      `cli/lib/PriceCalculator.php`) on every inquiry save; store result in
      `gigs.base_price_cents`; allow override via `quoted_price_cents`
- [x] **Quote email preview** — render the filled Finnish template (port
      TemplateRenderer) and display it in-browser; include copy-to-clipboard
      button for ProtonMail paste workflow

---

## Phase 2 — Real data migration

Goal: switch from dev seed data to actual production data so the ERP replaces
the old Excel/text workflows.

- [x] **Import extracted data** — once Cowork extraction is ready, write a
      migration script (`cli/import_legacy.php` or SQL) to load existing
      customers, venues, and gigs into the DB; preserve original quoted prices
- [x] **Validate migrated data** — spot-check 5–10 rows against source files;
      confirm foreign key integrity
- [x] **Switch to prod env** — point `.env` at `fi_infrasound`; run against
      real data; confirm inquiry workflow end-to-end

At this point the ERP should be usable in place of old workflows.

---

## Phase 3 — Auth and automation

- [x] **Auth skeleton** — session-based login; `users` table with role ENUM:
      `developer` / `admin` / `owner` / `musician` / `guest`; login/logout flow;
      route guard middleware checks minimum required role per route
- [x] **Automate price calc trigger** — price recalculation fires automatically
      on any change to distance, tier flags, channel, or extras fields (no
      manual recalc button needed)
- [x] **Scope agent service** — spec drafted in `AGENTS_AGENT_SERVICE.md`

### Agent service implementation (Phase 3 continuation)

- [x] **`InquiryExtractor.php`** — PHP class that calls Anthropic API
      (`claude-sonnet-4-6`) with structured tool use to extract gig fields from
      raw inquiry text; returns typed array of extracted fields
- [x] **Geocoding helper** — geocodes a venue address via Nominatim → OSRM to
      get driving distance (km) from Turku; fallback to null on failure
- [x] **`GET/POST /agent/process-inquiry`** — web form: textarea for raw inquiry
      text; on POST, calls `InquiryExtractor` + geocoder, upserts
      customer/contact/venue, runs PriceCalculator, inserts gig with status
      `inquiry`, redirects to gig detail
- [x] **Nav link** — "New inquiry (AI)" in navigation for owner+ role
- [ ] **Data-driven mileage baseline** *(blocked on ETL enrichment)* — once
      historical mileage data is extracted from old quote files, build a
      statistical baseline model; rules of thumb to encode once data confirms them:
      car1 = 2 × driving distance (round trip from Turku); if venue near Turku,
      compensate 2 train/bus tickets Helsinki–venue–Helsinki instead; if venue
      in Helsinki, compensate actual incidentals (bus/short car trip); if venue
      is remote with no viable public transport at gig end time, assume 2 vehicles.
      Musician home locations (gig_personnel) can refine further.  *(high priority)*
- [ ] **Quote template auto-selection** — default to `quote.txt`; switch to
      `venue-familiar-quote.txt` if venue has ≥1 delivered gig in DB; surface
      "already booked" message if a confirmed gig exists on the inquiry date  `[copilot]`

---

## Phase 4 — Gig management

- [x] **Gig state machine** — UI controls for status transitions:
      `inquiry → quoted → confirmed → delivered` and `→ cancelled / declined`;
      guard invalid transitions (`config/gig_states.php`,
      `src/modules/gigs/transition.php`, detail page updated)
- [x] **Schema: `gig_personnel`** — assign band members to a gig;
      fields: `gig_id`, `user_id`, `role` (e.g. `vocalist`, `guitarist`),
      `fee_cents`, `confirmed_at` (`db/migrations/004_gig_personnel.sql`)
- [x] **Personnel assignment UI** — assign/remove musicians from a gig;
      show current lineup with role + fee on gig detail page  `[copilot]`
- [x] **Musician read-only gig view** — musicians see their upcoming gigs:
      date, venue, order description, stage contact; requires `musician` role  `[copilot]`
- [ ] **Musician availability** *(future enhancement)* — availability
      reporting flows (tentative interest, sign-up, remove); deferred until
      core ERP is stable; depends on `gig_personnel` table

---

## Phase 5 — Setlists

- [ ] **Schema: `setlists`, `songs`, `setlist_songs`** — a setlist belongs to
      a gig; songs are a global library; `setlist_songs` is ordered junction
      `[copilot]`
- [ ] **Setlist builder UI** — drag-and-drop or ordered list; add/remove songs;
      attach setlist to gig

---

## Phase 6 — Invoicing

- [ ] **Schema: `outgoing_invoices`, `incoming_invoices`** — outgoing ties to a
      `gig_id`; fields include invoice number, issue date, due date, status
      (draft/sent/paid/overdue), amount in eurocents  `[copilot]`
- [ ] **Outgoing invoice creation** — generate invoice from confirmed gig;
      populate amount from `quoted_price_cents`; produce printable view.
      Prerequisite: `PriceCalculator` must return itemised rows (net per line,
      VAT rate, gross per line, total net, total VAT, total gross) so that
      legally compliant invoice rows can be generated — this is currently not
      the case; the calculator only returns `gross_total`.
- [ ] **Invoice list / status tracking** — list with status filter; mark as
      paid  `[copilot]`
- [ ] **Incoming invoice / expense log** — log expenses (PA hire, travel, etc.)
      against a gig or as general overhead  `[copilot]`

---

## Phase 7 — Accounting

- [ ] **Basic ledger view** — income vs expenses per period; grouped by gig
- [ ] **Export to CSV** — for accountant handoff  `[copilot]`

---

## Easy issues bucket  _(PO fills)_

Small business logic tweaks and copy fixes. Add items here as they come up;
good `[copilot]` candidates when clearly specified.

- [x] **Edit mail templates to handle Markdown links correctly** — currently e.g. "Spotify-linkki https://link.to.spotify"; should be "[Spotify-linkki](https://link.to.spotify)"  `[copilot]`
- [x] **Move necessary template/other needed files from old-files to a smarter directory structure** — E.g., `assets` or straight-to-db in case of smallish text files  `[copilot]`
- [x] **Retain complete price calculation logic in gig entities** — This needs a slight schema change (a few new INT columns on `gigs`) `[copilot]`
- [x] **Refactor dynamic pricing flags to radio** — These are either-or in the sense that Tier 2 can't be activated without Tier 1; therefore we should have EITHER Tier 1 OR (Tier 1 AND Tier 2) `[copilot]`
- [x] **Obfuscate dev customer records** — Currently, `db/seeds/dev.sql` contains real customer data extracted from old data stores. This (along with other dumps containing real data) needs to either be obfuscated (name changes will suffice) or deleted from VCS `[copilot]`
- [x] **Add notes field to gig view** — Freeform text area to add soft data in (e.g. old statuses such as "Asiakas päätynyt toiseen bändiin" or special requests like "Toivottu myös esiintymistä vihkitilaisuudessa"). `[copilot]`
- [x] **Bug: gig invoicing data not correctly merged with gig table data** — Multiple duplicate records in the gig table that pertain to the same gig, one of which is fetched from `gigs-YYYY.xlsx` and the other from `gig-invoicing.xlsx` (stating "no matching gigs-YYYY record"). Proposed first step for fix: search cli/etl/extract_gigs.py for logic errors in the merge step.
- [ ] **Merge quote/customer folder history data** — Combine data found in quote text files to DB (requires some specification; mainly locating the text files)
- [ ] **Gig detail: show full pricing inputs** — Pricing card on detail page currently shows only quoted price, distance, car 1, other travel; consider also displaying the tier flags and musician count so the owner can verify the calculation inputs without opening the edit form  `[copilot]`
- [ ] **Gig list: filter / sort / search / pagination** — currently shows all gigs flat; add status filter, date sort, customer search, and pagination for operability at scale  `[copilot]`
- [ ] _(add items here)_

---

## Keep door open  _(future, not currently blocking)_

- **Public inquiry form** (saturday.band integration) — replace the current
  freeform contact form with a structured inquiry form that writes to the ERP;
  requires the router to support unauthenticated public routes (planned from
  Phase 1)
- **Acceptance-flow automation** — customer replies accepting the offer → owner
  pastes reply into the agent → agent transitions gig `quoted → confirmed` and
  records any new details; same paste-to-agent UX as inquiry processing
- **Agent form supplementary fields** — if/when the flow becomes more automated,
  add manual override inputs (channel, email, phone) to the AI inquiry page for
  data not present in the raw text; low value while manual review is always required
- **ProtonMail inbox integration** — attach the saturday@infrasound.fi inbox
  to the ERP for inquiry triage; blocked by email classification complexity
  (inbox has mixed use cases)
- **CLI → DB bridge** — for transitional period, a CLI flag
  `--write-to-db` on `process_inquiry.php` to persist a YAML inquiry directly;
  may not be needed if Phase 1 web form lands quickly

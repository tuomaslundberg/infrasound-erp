# TODO

Project task list. Phases reflect real operational priority: the ERP must be
usable for actual inquiry processing before anything else is built.

Items marked `[copilot]` are good candidates for GitHub Copilot Coding Agent
(self-contained, bounded scope, clear acceptance criteria).

---

## Phase 0 — Dev infrastructure

Unblocks all local development and testing.

- [ ] **Dev environment config** — add `.env.dev` pointing to `fi_infrasound_dev`,
      add Makefile `make dev` / `make up` targets so you can switch between
      dev seed data and prod data without touching the codebase or branch
- [ ] **Seed dev DB** — run `db/seeds/dev.sql` against `fi_infrasound_dev`
      (5 gigs from 2026 season, venues, customers, contacts, song requests)
- [ ] **AGENTS.md update** — add `db/seeds/` to repo tree in §4

---

## Phase 1 — Inquiry processing in ERP  ← highest priority

Goal: replace the current CLI + copy-paste workflow with a web UI that writes
to the DB and outputs the filled quote email.

- [ ] **PHP router** — single-entry `src/index.php` dispatcher; route table maps
      URL patterns to controller files; supports both authenticated and public
      (unauthenticated) routes from day one so the door stays open for a future
      public inquiry form
- [ ] **Shared layout** — Bootstrap 5 shell (header, nav, footer); no Vue yet,
      plain PHP templates
- [ ] **Inquiry form** — web form mirroring `cli/inquiry-template.yaml` fields;
      on submit: upsert customer + contact + venue + gig rows; redirect to
      inquiry detail
- [ ] **Inquiry list page** — paginated table of gigs (date, customer, status,
      quoted price); links to detail
- [ ] **Inquiry detail / edit page** — read/edit all gig fields; delete = soft
      delete (`deleted_at`)
- [ ] **Quote calculation on save** — call PriceCalculator logic (ported from
      `cli/lib/PriceCalculator.php`) on every inquiry save; store result in
      `gigs.base_price_cents`; allow override via `quoted_price_cents`
- [ ] **Quote email preview** — render the filled Finnish template (port
      TemplateRenderer) and display it in-browser; include copy-to-clipboard
      button for ProtonMail paste workflow

---

## Phase 2 — Real data migration

Goal: switch from dev seed data to actual production data so the ERP replaces
the old Excel/text workflows.

- [ ] **Import extracted data** — once Cowork extraction is ready, write a
      migration script (`cli/import_legacy.php` or SQL) to load existing
      customers, venues, and gigs into the DB; preserve original quoted prices
- [ ] **Validate migrated data** — spot-check 5–10 rows against source files;
      confirm foreign key integrity
- [ ] **Switch to prod env** — point `.env` at `fi_infrasound`; run against
      real data; confirm inquiry workflow end-to-end

At this point the ERP should be usable in place of old workflows.

---

## Phase 3 — Auth and automation

- [ ] **Auth skeleton** — session-based login; `users` table with role ENUM:
      `developer` / `admin` / `owner` / `musician` / `guest`; login/logout flow;
      route guard middleware checks minimum required role per route
- [ ] **Automate price calc trigger** — price recalculation fires automatically
      on any change to distance, tier flags, channel, or extras fields (no
      manual recalc button needed)
- [ ] **Scope agent service** — when automation complexity warrants it, draft
      `AGENTS_AGENT_SERVICE.md` and create `AGENTS_AGENT_SERVICE.md`
      (currently blocked per CLAUDE.md until module is scoped)

---

## Phase 4 — Gig management

- [ ] **Gig state machine** — UI controls for status transitions:
      `inquiry → quoted → confirmed → delivered` and `→ cancelled / declined`;
      guard invalid transitions
- [ ] **Schema: `gig_personnel`** — assign band members to a gig;
      fields: `gig_id`, `user_id`, `role` (e.g. `vocalist`, `guitarist`),
      `fee_cents`, `confirmed_at`  `[copilot]`
- [ ] **Personnel assignment UI** — assign/remove musicians from a confirmed gig
- [ ] **Musician read-only gig view** — musicians see their upcoming gigs:
      date, venue, set times, stage contact; requires musician auth role
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
      populate amount from `quoted_price_cents`; produce printable view
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

- [ ] **Edit mail templates to handle Markdown links correctly** — currently e.g. "Spotify-linkki https://link.to.spotify"; should be "[Spotify-linkki](https://link.to.spotify)"  `[copilot]`
- [ ] **Move necessary template/other needed files from old-files to a smarter directory structure** — E.g., `assets` or straight-to-db in case of smallish text files  `[copilot]`
- [ ] **Retain complete price calculation logic in gig entities** — This needs a slight scema change (a few new INT columns on `gigs`) `[copilot]`
- [ ] **Refactor dynamic pricing flags to radio** — These are either-or in the sense that Tier 2 can't be activated without Tier 1; therefore we should have EITHER Tier 1 OR (Tier 1 AND Tier 2) `[copilot]`
- [ ] **Obfuscate dev customer records** — Currently, `db/seeds/dev.sql` contains real customer data extracted from old data stores. This (along with other dumps containing real data) needs to either be obfuscated (name changes will suffice) or deleted from VCS `[copilot]`
- [ ] _(add items here)_

---

## Keep door open  _(future, not currently blocking)_

- **Public inquiry form** (saturday.band integration) — replace the current
  freeform contact form with a structured inquiry form that writes to the ERP;
  requires the router to support unauthenticated public routes (planned from
  Phase 1)
- **ProtonMail inbox integration** — attach the saturday@infrasound.fi inbox
  to the ERP for inquiry triage; blocked by email classification complexity
  (inbox has mixed use cases)
- **CLI → DB bridge** — for transitional period, a CLI flag
  `--write-to-db` on `process_inquiry.php` to persist a YAML inquiry directly;
  may not be needed if Phase 1 web form lands quickly

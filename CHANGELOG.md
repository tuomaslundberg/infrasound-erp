# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

### Added
- `src/modules/agent/process_inquiry.php` — AI-assisted inquiry form: owner pastes
  raw inquiry text; calls Anthropic API to extract structured fields; geocodes venue
  address via Nominatim + OSRM to get driving distance from Turku; creates
  customer/contact/venue/gig rows in a transaction; gig created with `status = inquiry`;
  redirects to gig detail with a review notice
- `src/modules/agent/lib/InquiryExtractor.php` — wraps Anthropic API tool_use call
  (`claude-sonnet-4-6`); returns typed array of extracted gig fields; throws
  `RuntimeException` on API or curl error
- `src/modules/agent/lib/GeocodingHelper.php` — geocodes a Finnish address via
  Nominatim (OSM) then routes Turku → venue via OSRM public API; returns driving
  distance in km or null on failure
- `src/index.php` — route `GET/POST /agent/process-inquiry` (owner role)
- `src/templates/layout.php` — "New inquiry (AI)" nav link for owner+ role
- `src/modules/gigs/detail.php` — `inquiry_created` flash notice shown after AI
  extraction, reminding owner to review extracted fields before quoting
- `AGENTS_AGENT_SERVICE.md` — module scope document for the agent service

### Added
- `src/modules/gigs/personnel_add.php` — POST handler to assign a musician to
  a gig (`gig_personnel` row); duplicate assignments rejected with a flash notice
- `src/modules/gigs/personnel_remove.php` — POST handler to remove a musician
  from a gig (hard delete of `gig_personnel` row)
- `src/index.php` — routes `POST /gigs/{id}/personnel` and
  `POST /gigs/{id}/personnel/{user_id}/remove` (both require `owner` role)
- `src/modules/gigs/detail.php` — Personnel card: current lineup table with
  Username / Role / Fee / Confirmed columns, inline add-musician form
  (user select, role select, fee in €), Remove button per row, and flash
  notices for add/remove/duplicate/error outcomes
- `src/modules/gigs/notes.php` — POST handler for `POST /gigs/{id}/notes`;
  updates only `gigs.notes` for the given gig (PDO prepared statement); rejects
  soft-deleted gigs; saves empty input as NULL; redirects back to detail page
  (PRG pattern)
- `src/index.php` — route `/gigs/{id}/notes` (POST, owner)
- `src/modules/gigs/detail.php` — notes section always visible (shows
  "No notes yet." placeholder when empty); inline edit toggle reveals a
  textarea + Save / Cancel controls without a page reload (inline `<script>`)
### Changed
- `src/modules/gigs/form.php` — replaced two independent dynamic-pricing
  checkboxes (tier1 / tier2) with a three-option radio group (`pricing_tier`:
  `none` / `tier1` / `tier1_tier2`). Tier 2 is no longer independently
  selectable. POST handler derives `$tier1`/`$tier2` booleans from the single
  radio value; GET edit derives the radio value from the existing
  `pricing_tier1`/`pricing_tier2` columns. `PriceCalculator` inputs and DB
  schema unchanged.
### Added
- `src/modules/musician/gigs.php` — read-only list of upcoming gigs for the
  logged-in musician; shows only gigs where the user has a `gig_personnel` row
  and `gig_date >= CURDATE()`, ordered by date; columns: Date, Customer
  (first name only for weddings, company name otherwise), Venue, Role, Status
- `src/modules/musician/gig_detail.php` — read-only gig card for musicians;
  shows date, venue (name/address/city), order description, stage contact
  (name + phone), song requests (artist/title/first-dance flag), own role and
  fee; pricing fields (quoted/base price, cost inputs) are intentionally absent;
  enforces that the logged-in user is assigned to the gig (404 otherwise)
- `src/index.php` — routes `GET /musician/gigs` and `GET /musician/gigs/{id}`
  with minimum role `musician`
- `src/templates/layout.php` — "Gigs" nav link restricted to `owner`+ role;
  musicians see a "My Gigs" link pointing to `/musician/gigs` instead

### Fixed
- `src/assets/mail-templates/` — converted all bare `https://` URLs in sales
  email templates to Markdown links `[text](url)`; affected files:
  `fi/direct/{companies,weddings,other}/{quote,venue-familiar-quote,thank-you}.txt`
  and
  `fi/buukkaa-bandi/{companies,weddings,other}/{order-confirmation,venue-familiar-order-confirmation}.txt`
- `docker-compose.yml` — added volume mount for `src/assets/mail-templates` at
  `/var/www/src/assets/mail-templates` so `TemplateRenderer` can locate template
  files inside the PHP container (path resolved by `dirname(__DIR__, 2)` from
  `cli/lib/`, which gives `/var/www/src/...` not `/var/www/html/...`)

### Added
- `config/gig_states.php` — gig status state machine: transition map,
  button labels/styles, badge colours, `gig_valid_transitions()` and
  `gig_can_transition()` helpers
- `src/modules/gigs/transition.php` — POST handler for status transitions;
  validates against the state machine before applying; rejects invalid
  transitions with a redirect
- `src/index.php` — route `/gigs/{id}/transition` (POST, owner)
- `src/modules/gigs/detail.php` — status badge in page header; row of
  transition action buttons for all valid next states from current status
- `db/migrations/004_gig_personnel.sql` — CREATE TABLE gig_personnel:
  assigns users to gigs with role ENUM, fee_cents, confirmed_at
- `db/schema/core.sql` — gig_personnel table added

### Changed
- `cli/lib/TemplateRenderer.php` — template root updated from `old-files/sales/`
  to `src/assets/mail-templates/` following the template migration to VCS.
  `mapChannel()` default changed from pass-through to `'direct'` so all
  non-buukkaa-bandi channels (saturday_band, venuu, mail, …) resolve to the
  `direct/` directory instead of their raw ENUM name.

### Fixed
- `src/modules/gigs/form.php` — `goto render_form` inside the POST error paths
  jumped past the `$v`/`$chk` closure definitions, causing "Undefined variable"
  warnings and a "null is not callable" fatal error on form re-render. Fixed by
  moving both closures before the POST block and capturing `$db` by reference
  (`use (&$db)`) so the GET edit block can still populate it afterwards.
- `src/modules/gigs/form.php` — channel validation still listed only
  `mail|buukkaa_bandi`; updated to match the full 12-value ENUM from migration 001.

### Fixed (previous)
- `cli/etl/extract_gigs.py` — gig-invoicing merge was failing to match records
  in two cases: (1) invoicing "KEIKKA" column contained Finnish event-type
  suffixes ("Lindqvist häät", "Yritys X pikkujoulut") absent from the tracker;
  (2) tracker ASIAKAS field contained a parenthesised contact suffix
  ("Company Oy (Maija Haapakoski)") absent from the invoicing record.
  Fixed with `_normalise_invoice_name()` (strips event suffixes) and
  `_entity_name_only()` (strips parenthesised contact before comparison).
  Added `--debug-unmatched` flag.

### Changed
- `db/migrations/3_gig_pricing_inputs.sql` — ALTER TABLE adds 8 NOT NULL pricing-input
  columns (default 0) to `gigs`: `pricing_tier1`, `pricing_tier2`, `qty_ennakkoroudaus`,
  `qty_song_requests_extra`, `qty_extra_performances`, `qty_background_music_h`,
  `qty_live_album`, `discount_cents`. Persists the granular PriceCalculator inputs
  so the edit form pre-populates them instead of resetting to zero.
- `db/schema/core.sql` — same 8 columns added to the `gigs` CREATE TABLE reference block.
- `src/modules/gigs/form.php` — SELECT query, `$db[]` mapping, INSERT, and UPDATE
  statements updated to read/write the 8 new pricing-input columns.

### Added
- `db/migrations/002_add_users_table.sql` — CREATE TABLE users; role ENUM
  (`developer`/`admin`/`owner`/`musician`/`guest`); bcrypt password_hash;
  unique username; soft delete
- `config/auth.php` — `auth_start()`, `auth_user()`, `auth_has_role()`,
  `auth_require()`; role hierarchy in ROLE_ORDER constant
- `src/modules/auth/login.php` — login form + POST handler; session_regenerate_id
  on success; `?next=` redirect param
- `src/modules/auth/logout.php` — session destroy + redirect to /login
- `db/schema/core.sql` — users table added

### Changed
- `src/index.php` — route table switches from `is_public` bool to `min_role`
  (null = public); auth_require() enforced in dispatcher; /login and /logout
  added as public routes
- `src/templates/layout.php` — navbar shows username + role badge + Sign out
  when logged in; nav links hidden on login page

### Added
- `cli/etl/extract_gigs.py` — idempotent ETL script that reads all
  `old-files/info/gigs-*.xlsx` and `old-files/info/archive/gigs-*.xlsx` files
  plus `old-files/gig-invoicing.xlsx`, normalises dates, channels, statuses,
  and customer/contact names, fuzzy-merges the two sources (gig-invoicing
  records within ±3 days and ≥ 0.75 name similarity matched to gigs-YYYY
  records; fees propagated to matched rows; unmatched rows imported as
  additional `delivered` gigs), and writes `db/seeds/legacy_gigs.sql`
  (IDs 1–9999 reserved for legacy data, AUTO_INCREMENT reset to 10000) and
  `db/seeds/legacy_gigs_dup_candidates.txt`.
  Flags: `--dry-run` (SQL to stdout), `--stats` (counts only).
- `db/migrations/001_expand_channel_enum.sql` — ALTER TABLE that expands
  `gigs.channel` from `mail | buukkaa_bandi` to a 12-value ENUM covering
  all booking platforms encountered in the legacy data.
- `db/seeds/legacy_gigs.sql` — generated output of `extract_gigs.py`;
  338 gigs, 324 customers, 332 contacts, 99 venues. Not committed to git
  (gitignored alongside old-files); regenerate with `make etl-gigs`.
- `Makefile` targets: `etl-gigs`; `migrate-dev` / `migrate` (dev/prod, `FILE=`
  parameter); `import-legacy-gigs` / `import-legacy-gigs-prod` (apply migration
  001 then load seed; full workflow documented in target comments).
- `.gitignore` — `db/seeds/legacy_gigs*.sql` and `db/seeds/legacy_gigs*.txt`
  (contain customer PII; regenerate with `make etl-gigs`).

### Changed
- `db/schema/core.sql` — `gigs.channel` ENUM updated to match migration 001;
  inline comments document the channel taxonomy and buukkaa_bandi distinction.
- `cli/etl/extract_gigs.py` — `_map_status` fallback changed from `'inquiry'`
  to `'quoted'`: legacy records were never written down before a quote was sent,
  so `inquiry` is not a valid state for any historical row. `inquiry` remains
  in the ENUM for the future automated intake pipeline.

### Added (Phase 1)
- `TODO.md` — phased task list covering dev infrastructure through accounting;
  items marked `[copilot]` are suitable for GitHub Copilot Coding Agent delegation
- `db/seeds/dev.sql` — realistic dev fixture data (5 gigs from 2026 season with
  venues, customers, contacts, and song requests); sourced from `old-files/future-gigs/`
- `docker-compose.dev.yml` — compose overlay for dev environment; overrides `env_file`
  and uses a separate `db_data_dev` volume so dev and prod data never share storage
- `Makefile` — `make up` / `make dev` / `make down[-dev]` / `make seed` / `make logs[-dev]` /
  `make shell-db[-dev]`; switches between prod (`.env`) and dev (`.env.dev`) environments
- `.env.dev` — dev database credentials (gitignored; copy and fill locally)
- `docker/apache-site.conf` + `Dockerfile` — replace default Apache vhost to enable
  `AllowOverride All` for `.htaccess` rewrite rules
- `src/.htaccess` — front controller rewrite: all non-file/dir requests → `index.php`
- `src/index.php` — URL router; route table maps patterns to controller files;
  `is_public` flag per route keeps the door open for unauthenticated public routes (Phase 3)
- `src/templates/layout.php` — Bootstrap 5 shell with navbar; controllers call
  `render_layout($title, callable $content)`
- `src/templates/error.php` — 404 / 501 error page
- `src/modules/gigs/list.php` — gig list page (DB-backed; date, customer, venue, status,
  quoted price, channel)
- `src/modules/gigs/detail.php` — gig detail page (DB-backed; pricing, venue, notes)
- `src/modules/gigs/form.php` — inquiry form placeholder (full form implemented in next commit)

### Changed
- `src/modules/gigs/form.php` — full inquiry form (replaces placeholder); GET + POST handler;
  saves customer, contact, venue, gig in one transaction; calculates base_price_cents via
  PriceCalculator (reused from cli/); supports quoted price override; PRG redirect to detail
- `docker-compose.yml` — mount `./cli:/var/www/cli` so web PHP can require PriceCalculator
- `Dockerfile` — `COPY ./cli /var/www/cli` for non-volume-mount contexts
- `src/templates/layout.php` — remove incorrect Bootstrap JS SRI hash (was blocking JS)
- `db/seeds/dev.sql` — TRUNCATE block makes `make seed` idempotent
- `Makefile` — add `reset-dev` target (destroys dev volume and restarts fresh)

### Added (feature/cli-inquiry-processor)
- `cli/process_inquiry.php` — CLI entry point; reads a YAML inquiry file, calculates
  the quote price, and outputs a filled sales email template. No Docker required.
  Usage: `php cli/process_inquiry.php inquiry.yaml [--type=quote] [--output=email|summary|both]`
- `cli/inquiry-template.yaml` — blank inquiry template replacing `gig-info-yymmdd.txt`
- `cli/lib/InquiryParser.php` — YAML reader with field validation and defaults
- `cli/lib/PriceCalculator.php` — implements the pricing formula from
  `old-files/sales/price-calculation-flow.txt` (base price, distance premium,
  dynamic pricing tiers, Finnish mileage rates, buukkaa-bandi fee, additional services)
- `cli/lib/TemplateRenderer.php` — fills `[ASIAKAS]` and price tokens in sales templates
- `composer.json` + `composer.lock` — project dependencies (symfony/yaml ^7.0)
- `old-files/sales/price-calculation-flow.txt` — pricing formula documentation
  (added by user; referenced by PriceCalculator)

### Changed (feature/cli-inquiry-processor)
- `.gitignore` — added `vendor/` and `*.phar`
- `config/db.php` — sets `SET time_zone = '+00:00'` on every PDO connection

---

### Added (feature/db-schema)
- `db/schema/core.sql` — authoritative schema reference for the inquiry/gig management MVP:
  tables `customers`, `contacts`, `customer_contacts`, `venues`, `gigs`, `song_requests`
- `db/init.sql` now creates all tables on first container start

### Changed (feature/db-schema)
- `AGENTS.md` §4 — repo tree updated with `cli/` and `db/schema/core.sql`
- `CLAUDE.md` — file layout updated to reflect `cli/` and schema file

---

### Added
- `CLAUDE.md` — Claude Code operational instructions
- `README.md` — project overview and local setup guide
- `CHANGELOG.md` — this file
- `.env.example` — credentials template
- `config/feature_flags.php` — module migration flags (all off by default)
- Directory scaffolding: `src/api/`, `src/modules/{customers,gigs,invoicing,accounting}/`,
  `src/templates/`, `src/assets/`, `db/schema/`, `db/migrations/`

### Changed
- `docker-compose.yml` — credentials moved to `.env`; MariaDB bumped 10.9 → 10.11 LTS;
  removed deprecated `version` field
- `config/db.php` — credentials now read via `getenv()`; errors logged, not echoed
- `db/init.sql` — added header with schema conventions (charset, UTC, soft deletes)
- `AGENTS.md` — corrected repo root label `/erp` → `/infrasound.fi`; noted
  `AGENTS_AGENT_SERVICE.md` is planned but not yet created
- `.gitignore` — added `.env`

# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

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

# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

### Added
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
- `src/modules/gigs/form.php` — inquiry form placeholder (full form is next Phase 1 task)

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

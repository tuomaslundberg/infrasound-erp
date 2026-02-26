# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

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

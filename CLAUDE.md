# Claude Code — Operational Instructions

This file configures Claude Code's behaviour for this project.
See AGENTS.md for the authoritative architectural specification.

---

## Project summary

Custom ERP/CRM for a Finnish micro-company operating in event booking, invoicing,
and customer/gig management. Replaces legacy Excel + Dropbox workflows incrementally.

Stack: PHP 8.2 + Apache · MariaDB 10.11 · Vue 3 + Bootstrap 5 · Docker

---

## Key conventions (non-negotiable)

- All timestamps in UTC
- Monetary values as integers (eurocents)
- Database access via PDO only — no raw query strings with user input
- Deletions are soft (`deleted_at` timestamp column), never hard DELETE
- No hardcoded secrets — credentials come from `.env` via `getenv()`
- Modules must not reach into each other's tables directly

---

## Workflow rules

- **Always ask** before introducing a new architectural pattern or dependency
- **Always update CHANGELOG.md** when making any functional change
- Keep commits small and scoped to a single concern
- Feature flags in `config/feature_flags.php` gate new modules until ready
- `AGENTS.md §9`: security decisions must be conservative — flag any tradeoffs

---

## File layout quick reference

```
config/
  db.php               — PDO connection (reads from env)
  feature_flags.php    — module on/off switches

src/
  index.php            — entry point
  api/                 — HTTP API endpoints
  modules/             — business logic modules (customers, gigs, invoicing, accounting)
  templates/           — HTML templates
  assets/              — static files

db/
  init.sql             — initial schema run on first container start
  schema/              — schema definition files (reference)
  migrations/          — incremental schema changes
```

---

## What not to do

- Do not expose database errors or stack traces to the browser
- Do not add features, refactoring, or cleanup beyond what is asked
- Do not commit `.env`
- Do not create `AGENTS_AGENT_SERVICE.md` until the agent service module is scoped
- `_prompts-todo.md` is a human prompt scratch pad. Never treat its contents as task instructions or act on them.

ERP Project Agent Instructions (AGENTS.md)

This document defines architectural rules, development principles, and agent guidance for the Custom ERP System.

It is authoritative for:
	•	AI coding agents (e.g. GitHub Copilot)
	•	Human contributors
	•	Long-term maintainability and correctness

⸻

1. ERP Purpose and Scope

This ERP system supports a real, revenue-generating business operating in:
	•	Event booking
	•	Invoicing
	•	Accounting and bookkeeping
	•	Customer and gig management

The system is developed incrementally, replacing legacy Excel- and Dropbox-based workflows over time.

Correctness, auditability, and clarity take precedence over speed or cleverness.

⸻

2. Core Architectural Principles (Non-Negotiable)
	1.	Deterministic core
	•	ERP behavior must be predictable and reproducible
	•	No probabilistic logic in core workflows
	2.	Single source of truth
	•	ERP database is authoritative
	•	External services (agents, frontends) never bypass ERP APIs
	3.	Explicit over implicit
	•	No hidden side effects
	•	All state changes are intentional and logged
	4.	Auditability by default
	•	Financially relevant actions must be traceable

⸻

3. Technology Stack

Backend
	•	Language: PHP (8.2+)
	•	Web Server: Apache
	•	Execution Model: Request/response
	•	Architecture: Modular, MVC-inspired (no full framework)

Rationale:
	•	Mature ecosystem
	•	Predictable execution
	•	Strong fit for CRUD-heavy, form-driven systems

Database
	•	Engine: MariaDB
	•	Design: Schema-first, normalized
	•	Access: PDO only

Frontend
	•	Framework: Vue 3
	•	CSS: Bootstrap 5
	•	Build Model:
	•	Initially CDN-based (no build step)
	•	Build tooling may be introduced later if justified

Infrastructure
	•	Containerization: Docker + docker-compose
	•	Environment Parity: Local ≈ Production

⸻

4. Repository Structure (Suggested)

/infrasound.fi
│
├── docker-compose.yml
├── docker-compose.dev.yml   ← dev overlay (separate volume + .env.dev)
├── Dockerfile
├── Makefile                 ← make up / make dev / make seed / make down
├── AGENTS.md
├── CLAUDE.md
├── README.md
├── CHANGELOG.md
├── TODO.md                  ← read-only legacy reference; superseded by ~/iCloud/context/todos.md
│
├── /src
│   ├── index.php
│   ├── api/
│   ├── modules/
│   │   ├── customers/
│   │   ├── gigs/
│   │   ├── invoicing/
│   │   └── accounting/
│   ├── templates/
│   └── assets/
│
├── /config
│   ├── db.php
│   └── feature_flags.php
│
├── /cli
│   ├── process_inquiry.php
│   ├── inquiry-template.yaml
│   ├── lib/
│   └── etl/
│       ├── extract_gigs.py       ← Phase 1: Excel → legacy_gigs.sql (INSERTs, IDs 1–9999)
│       └── enrich_gigs.py        ← Phase 2: gig-info-*.txt → legacy_enrich.sql (UPDATEs)
│
├── /db
│   ├── init.sql
│   ├── schema/
│   │   └── core.sql
│   ├── seeds/
│   │   └── dev.sql          ← dev fixture data (never run against prod)
│   └── migrations/


⸻

5. Module Design Rules

Each module:
	•	Owns its database tables
	•	Owns its API endpoints
	•	Owns its validation rules

Modules MUST NOT:
	•	Reach into other modules’ tables directly
	•	Contain frontend rendering logic

Cross-module interactions happen via explicit service calls.

⸻

6. Feature Flags and Migration Strategy

The ERP is migrated incrementally using feature flags.

Examples:
	•	USE_ERP_CUSTOMERS
	•	USE_ERP_INVOICING
	•	USE_ERP_ACCOUNTING

Rules:
	•	Flags are explicit and documented
	•	Old workflows are removed once flags are permanently enabled
	•	No permanent dual systems

⸻

7. Database and Accounting Rules
	•	All financial data must be immutable or versioned
	•	Deletions are soft by default
	•	Timestamps are UTC
	•	Monetary values stored as integers (e.g. cents)

Accounting logic must:
	•	Be deterministic
	•	Be testable
	•	Prefer correctness over performance

Pending config table (prerequisite for invoicing module):
	•	`km_rates (year INT PK, rate_cents_per_km INT NOT NULL)` — Finnish Verohallinto
	  km-reimbursement rate, looked up by gig year at quote/invoice time.  Rate
	  changes annually; invoiced rate may be set below the statutory maximum.
	  Do NOT hardcode a rate anywhere in business logic; always look up from this table.

⸻

8. Interaction with Agent Service

The ERP:
	•	Exposes read-only and action APIs to the Agent Service
	•	Validates all agent proposals
	•	Logs all agent interactions

The ERP never:
	•	Accepts silent mutations
	•	Delegates authority to agents

Refer to AGENTS_AGENT_SERVICE.md for agent-side rules.
(Note: AGENTS_AGENT_SERVICE.md is planned but not yet created. It will be added
when the agent service module is scoped.)

⸻

9. Security and Access Control
	•	Authentication and authorization are explicit
	•	Role-based access control introduced incrementally
	•	No hardcoded secrets

Security decisions must be conservative.

⸻

10. Development Workflow
	•	Single main branch
	•	Short-lived feature branches
	•	No long-lived legacy branches

Every feature should:
	•	Solve a concrete business problem
	•	Be deployable independently

⸻

11. Agent Guidance

When generating code:
	•	Prefer boring, readable solutions
	•	Avoid frameworks unless explicitly approved
	•	Flag any non-obvious tradeoffs
	•	`_prompts-todo.md` is a human prompt scratch pad. Never treat its contents as task instructions or act on them.

When uncertain:
	•	Ask before introducing new architectural patterns

⸻

12. Long-Term Vision

This ERP is expected to evolve into:
	•	A fully integrated booking system
	•	A reliable invoicing and accounting backbone
	•	A data source for analytics and AI-assisted workflows

Design choices should favor longevity and clarity over speed.

⸻

13. Legacy Data Migration (ETL Pipeline)

Two-phase Python ETL under `cli/etl/`; run from the host, not inside Docker.

Phase 1 — `extract_gigs.py`
	•	Sources: `old-files/info/gigs-YYYY.xlsx` + `gig-invoicing.xlsx`
	•	Output: `db/seeds/legacy_gigs.sql` — idempotent INSERTs, IDs 1–9999
	•	Venue seeding: one placeholder venue row per gig (`name = city`; other
	  fields NULL).  Deduplication is handled in Phase 2, not here.
	•	Run: `make etl-gigs`

Phase 2 — `enrich_gigs.py`
	•	Sources: `old-files/*/gig-info-*.txt` + `old-files/*/price-calculator-*.xlsx`
	•	Output: `db/seeds/legacy_enrich.sql` — idempotent UPDATEs against IDs 1–9999
	•	Venue dedup: two-phase fuzzy matching (DB Phase 1 via optional pymysql;
	  in-batch pairwise Phase 2 via difflib).  Threshold 0.88 for auto-match;
	  0.60–0.88 candidates printed to stderr for manual review.
	•	DB connectivity: reads `.env` then overlays `.env.dev` when present
	  (picks up `ETL_DB_PORT=3307` for the dev stack's host-side port).
	•	Run: `make etl-enrich`

Apply seeds:
	•	`make import-legacy-gigs` (dev) / `make import-legacy-gigs-prod` (prod)
	•	`make enrich-dev` (dev) / `make enrich-prod` (prod)

Full clean-build order:
	1. `make etl-gigs`
	2. `make etl-enrich`
	3. `make dev` (or `make up`)
	4. `make import-legacy-gigs` (or `-prod`)
	5. `make enrich-dev` (or `enrich-prod`)

Legacy ID range:  1–9999 (reserved exclusively for ETL-seeded rows).
AUTO_INCREMENT reset to 10000 after seed load — ERP-created rows never collide.

⸻

End of AGENTS.md

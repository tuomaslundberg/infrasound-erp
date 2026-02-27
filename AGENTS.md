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
├── Dockerfile
├── AGENTS.md
├── CLAUDE.md
├── README.md
├── CHANGELOG.md
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
│   └── lib/
│
├── /db
│   ├── init.sql
│   ├── schema/
│   │   └── core.sql
│   └── migrations/
│
└── README.md


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

End of AGENTS.md

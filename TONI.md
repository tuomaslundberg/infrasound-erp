# Onboarding — Toni Puttonen

Welcome to the Infrasound ERP repo. This file is for you (and any AI agent you use)
to get oriented quickly.

---

## What this is

A custom ERP/CRM for Infrasound Oy — handles gig inquiries, pricing, travel calculation,
invoicing, and band logistics. It replaces the old Excel + Dropbox + copy-paste workflow.

Live at **infrasound.fi** (Plesk, PHP 8.2 + MariaDB). Local dev via Docker.

---

## Your role in this repo

You have **owner** role in the ERP (same as Tuomas). You can:
- Review and merge PRs to `dev` on GitHub
- Open your own feature branches and PRs
- Access the live site and admin pages

Tuomas makes final calls on architecture, prod deployments, and DB schema changes.

---

## Key files to read first

| File | What it is |
|------|-----------|
| `CLAUDE.md` | Coding conventions, branch rules, what not to do |
| `AGENTS.md` | Architecture spec — authoritative for all contributors and AI agents |
| `todos.md` | Current task list — check here before starting anything |
| `CHANGELOG.md` | History of every functional change |
| `db/schema/core.sql` | Authoritative DB schema reference |

---

## Branch workflow

```
main  ←  dev  ←  feat/<topic>   (your feature branches)
                  fix/<topic>    (bug fixes)
```

- **Never commit directly to `dev` or `main`**
- Branch from `dev`, open a PR back to `dev`, Tuomas reviews and merges
- `dev → main` PRs are opened by Tuomas when a batch of features is ready to ship

```bash
git checkout dev && git pull
git checkout -b feat/your-topic
# ... make changes ...
git push -u origin feat/your-topic
gh pr create --base dev
```

---

## Local dev setup

```bash
cp .env.dev.example .env.dev   # fill in DB credentials
make dev                        # starts dev Docker stack
make migrate-dev FILE=db/migrations/NNN_name.sql
make seed                       # load dev fixtures
```

The dev DB is `fi_infrasound_dev` — completely separate from prod data.

---

## Good first tasks

Items marked `[copilot]` in `todos.md` are well-scoped and good for AI-assisted work:
- **Venue edit UI** — straightforward CRUD form, clear spec
- **Default lineup auto-fill** — small button + DB insert, well-defined
- **Setlist builder polish** — reactive UI, bounded scope
- **Additional gig filters** — SQL + PHP, no schema change needed

Avoid touching `cli/lib/PriceCalculator.php`, `cli/lib/TravelCalculator.php`, or any
migration files without discussing with Tuomas first — these have subtle business logic.

---

## If you use Claude Code or GitHub Copilot

Both `CLAUDE.md` and `AGENTS.md` are read automatically by AI agents. They contain
all the conventions. Key things agents must follow:

- PDO only — no raw query strings with user input
- Soft deletes (`deleted_at`) — never hard DELETE
- Monetary values as integers (eurocents)
- Always update `CHANGELOG.md` on functional changes
- Never commit `.env`

For Claude Code: launch from the repo root. It will pick up `CLAUDE.md` automatically.

---

## Asking questions

Ping Tuomas on WhatsApp for anything unclear. For code questions, a comment on the
relevant GitHub PR works well.

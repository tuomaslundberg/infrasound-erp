# infrasound.fi ERP

Custom ERP/CRM for a Finnish micro-company. Replaces legacy Excel and Dropbox
workflows incrementally with a web-based system covering event booking, invoicing,
and customer/gig management.

---

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) and Docker Compose

---

## Running locally

Copy the environment templates and fill in your credentials:

```bash
cp .env.example .env          # production-equivalent settings
cp .env.dev.example .env.dev  # dev stack overrides (separate DB, dev secrets)
```

Start the dev stack:

```bash
make dev
```

The application is available at http://localhost:8080.

Stop the stack:

```bash
make down
```

> **Note:** If you need to reset the database volume (e.g. after a MariaDB version
> change), run `docker-compose down -v` before `make dev`.

---

## Architecture

See [AGENTS.md](AGENTS.md) for the full architectural specification, module design
rules, and development guidelines.

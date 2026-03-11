.PHONY: up dev down down-dev reset-dev seed logs logs-dev shell-db shell-db-dev \
        migrate migrate-dev etl-gigs import-legacy-gigs import-legacy-gigs-prod

# ---------------------------------------------------------------------------
# Production environment  (uses .env → fi_infrasound)
# ---------------------------------------------------------------------------
up:
	docker compose up -d

down:
	docker compose down

logs:
	docker compose logs -f

shell-db:
	docker compose exec db mysql -u"$$(grep MYSQL_USER .env | cut -d= -f2)" \
	  -p"$$(grep MYSQL_PASSWORD .env | cut -d= -f2)" \
	  "$$(grep MYSQL_DATABASE .env | cut -d= -f2)"

# ---------------------------------------------------------------------------
# Dev environment  (uses .env.dev → fi_infrasound_dev, separate volume)
# ---------------------------------------------------------------------------
dev:
	docker compose -f docker-compose.yml -f docker-compose.dev.yml -p infrasound_dev up -d

down-dev:
	docker compose -p infrasound_dev down

# Destroy dev volume and start fresh (data is lost)
reset-dev:
	docker compose -p infrasound_dev down -v
	docker compose -f docker-compose.yml -f docker-compose.dev.yml -p infrasound_dev up -d

logs-dev:
	docker compose -p infrasound_dev logs -f

# Load dev seed fixtures into the dev DB.
# Requires the dev containers to be running (make dev).
seed:
	docker compose -p infrasound_dev exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < db/seeds/dev.sql

shell-db-dev:
	docker compose -p infrasound_dev exec db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"'

# ---------------------------------------------------------------------------
# Migrations
# ---------------------------------------------------------------------------
# Apply a single migration file to the prod DB.
# Usage: make migrate FILE=db/migrations/001_expand_channel_enum.sql
migrate:
	docker compose exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < $(FILE)

# Apply a single migration file to the dev DB.
# Usage: make migrate-dev FILE=db/migrations/001_expand_channel_enum.sql
migrate-dev:
	docker compose -p infrasound_dev exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < $(FILE)

# ---------------------------------------------------------------------------
# Legacy data ETL
# ---------------------------------------------------------------------------
# Regenerate db/seeds/legacy_gigs.sql from the source Excel files.
# Run this whenever old-files/info/gigs-*.xlsx or gig-invoicing.xlsx is updated.
# Output is gitignored (contains customer PII); commit the script, not the SQL.
etl-gigs:
	python cli/etl/extract_gigs.py $(FLAGS)

# Apply migration 001 then load the generated legacy seed into the dev DB.
# Full workflow: make etl-gigs → make dev → make import-legacy-gigs
import-legacy-gigs:
	$(MAKE) migrate-dev FILE=db/migrations/001_expand_channel_enum.sql
	docker compose -p infrasound_dev exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < db/seeds/legacy_gigs.sql

# Apply migration 001 then load the generated legacy seed into the prod DB.
# Full workflow: make etl-gigs → make up → make import-legacy-gigs-prod
import-legacy-gigs-prod:
	$(MAKE) migrate FILE=db/migrations/001_expand_channel_enum.sql
	docker compose exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < db/seeds/legacy_gigs.sql

.PHONY: up dev down down-dev seed logs logs-dev shell-db shell-db-dev

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

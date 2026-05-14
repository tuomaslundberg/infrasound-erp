.PHONY: up dev down down-dev reset-dev seed seed-musicians seed-musicians-prod \
        seed-musician-addresses seed-musician-addresses-prod geocode-musicians \
        logs logs-dev shell-db shell-db-dev \
        migrate migrate-dev etl-gigs etl-enrich etl-songs etl-setlists etl-spotify etl-invoicing import-spotify-playlist \
        import-legacy-gigs import-legacy-gigs-prod \
        import-legacy-songs import-legacy-songs-prod \
        import-legacy-setlists import-legacy-setlists-prod \
        import-legacy-spotify import-legacy-spotify-prod \
        import-legacy-invoicing import-legacy-invoicing-prod \
        enrich-dev enrich-prod

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

# Seed musician user accounts into the dev DB.
# Prerequisites: migrations 006 and 007 applied (make migrate-dev FILE=...).
seed-musicians:
	docker compose -p infrasound_dev exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < db/seeds/musicians.sql

# Seed musician user accounts into the prod DB.
seed-musicians-prod:
	docker compose exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < db/seeds/musicians.sql

# Seed musician home addresses and transport modes into the dev DB.
# Prerequisites: migration 010 applied.
seed-musician-addresses:
	docker compose -p infrasound_dev exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < db/seeds/musician_addresses.sql

# Seed musician home addresses into the prod DB.
seed-musician-addresses-prod:
	docker compose exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < db/seeds/musician_addresses.sql

# Geocode musician home addresses → populate home_lat/home_lng in users (dev DB).
# Run after seed-musician-addresses. Rate-limited to 1 req/s (Nominatim ToS).
geocode-musicians:
	docker compose -p infrasound_dev exec php php /var/www/cli/geocode_musicians.php

# Geocode musician home addresses → populate home_lat/home_lng in users (prod DB).
geocode-musicians-prod:
	docker compose exec php php /var/www/cli/geocode_musicians.php

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

# Regenerate db/seeds/legacy_enrich.sql from gig-info-*.txt files.
# Run after etl-gigs.  Output is gitignored (contains customer PII).
etl-enrich:
	python cli/etl/enrich_gigs.py $(FLAGS)

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

# ---------------------------------------------------------------------------
# Songs / setlist ETL  (migration 014 must be applied first)
# ---------------------------------------------------------------------------
# Regenerate db/seeds/legacy_songs.sql from the repertoire info files.
# Output is NOT gitignored (no PII); commit both script and SQL.
etl-songs:
	python cli/etl/extract_songs.py $(FLAGS)

# Load songs into dev DB.
# Full workflow: make etl-songs → make import-legacy-songs
import-legacy-songs:
	docker compose -p infrasound_dev exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < db/seeds/legacy_songs.sql

# Load songs into prod DB.
import-legacy-songs-prod:
	docker compose exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < db/seeds/legacy_songs.sql

# Regenerate db/seeds/legacy_setlists.sql from per-gig setlist files.
# Requires a running DB with gigs + songs already loaded.
# Output is gitignored (contains gig/song data referencing PII-linked gig IDs).
etl-setlists:
	python cli/etl/extract_setlists.py $(FLAGS)

# Load setlists into dev DB.
# Full workflow: make import-legacy-songs → make etl-setlists → make import-legacy-setlists
import-legacy-setlists:
	docker compose -p infrasound_dev exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < db/seeds/legacy_setlists.sql

# Load setlists into prod DB.
import-legacy-setlists-prod:
	docker compose exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < db/seeds/legacy_setlists.sql

# Resolve Spotify track IDs for songs table.
# Requires SPOTIFY_CLIENT_ID + SPOTIFY_CLIENT_SECRET in .env/.env.dev.
# Output is gitignored.
etl-spotify:
	python cli/etl/enrich_spotify.py $(FLAGS)

# Load Spotify enrichment into dev DB.
import-legacy-spotify:
	docker compose -p infrasound_dev exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < db/seeds/legacy_spotify.sql

# Load Spotify enrichment into prod DB.
import-legacy-spotify-prod:
	docker compose exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < db/seeds/legacy_spotify.sql

# Import songs from a Spotify playlist (upsert: update existing + insert new).
# Usage: make import-spotify-playlist PLAYLIST=<url_or_id> [FLAGS=--dry-run]
import-spotify-playlist:
	python cli/etl/import_spotify_playlist.py $(PLAYLIST) $(FLAGS)

# ---------------------------------------------------------------------------
# Invoicing ETL  (migrations 006 + 007 and musicians.sql must be applied first)
# ---------------------------------------------------------------------------
# Regenerate db/seeds/legacy_invoicing.sql from gig-invoicing.xlsx.
# Requires a running DB with gigs + users already loaded.
# Output is gitignored.
etl-invoicing:
	python cli/etl/extract_invoicing.py $(FLAGS)

# Load invoicing data (quoted_price_cents + gig_personnel) into dev DB.
import-legacy-invoicing:
	docker compose -p infrasound_dev exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < db/seeds/legacy_invoicing.sql

# Load invoicing data into prod DB.
import-legacy-invoicing-prod:
	docker compose exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < db/seeds/legacy_invoicing.sql

# ---------------------------------------------------------------------------
# Load gig-info enrichment into dev DB (run after import-legacy-gigs).
# Full workflow: make etl-enrich → make enrich-dev
enrich-dev:
	docker compose -p infrasound_dev exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < db/seeds/legacy_enrich.sql

# Load gig-info enrichment into prod DB (run after import-legacy-gigs-prod).
enrich-prod:
	docker compose exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < db/seeds/legacy_enrich.sql

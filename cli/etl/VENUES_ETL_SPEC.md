# Venues ETL spec — venuu.fi corpus seed

*Pre-seeds the `venues` table with Finnish event venues from venuu.fi, covering
Varsinais-Suomi, Pirkanmaa, and Uusimaa. A pre-seeded corpus enables the fuzzy
lookup in GigCreator (see PHASE4_SPEC.md §D) to resolve venue names without live
Nominatim calls during inquiry processing.*

---

## 1. Script

**`cli/etl/extract_venues.py`**

Output: `db/seeds/legacy_venues.sql` (NOT gitignored — venue names are not PII).
Makefile targets to add:
```makefile
etl-venues:
	python cli/etl/extract_venues.py $(FLAGS)

import-legacy-venues:
	docker compose -p infrasound_dev exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < db/seeds/legacy_venues.sql

import-legacy-venues-prod:
	docker compose exec -T db \
	  sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
	  < db/seeds/legacy_venues.sql
```

---

## 2. Pre-crawl checklist

Before writing the crawler, do these manually:

1. **Check `venuu.fi/robots.txt`** — confirm crawling is permitted. If it disallows
   scraping, evaluate alternatives (venue APIs, other sources).

2. **Inspect the regional filter URL** — open venuu.fi, filter by region
   (e.g. Varsinais-Suomi), copy the resulting URL. Determine:
   - Whether it's a query parameter (`?region=varsinais-suomi`) or a path segment
   - Whether the listing is server-rendered HTML or client-rendered JavaScript
   - If JS-rendered: check whether the same data is available via a JSON API
     (inspect network tab for XHR/fetch requests)

3. **Inspect venue category/type options** — document what categories venuu.fi
   offers and decide which to include vs. exclude (see §5).

4. **Note pagination scheme** — page size and how to advance to the next page.

---

## 3. Crawl scope

**Regions:**
- Varsinais-Suomi
- Pirkanmaa
- Uusimaa

**Target count:** 500–1 500 venues total. A larger corpus doesn't hurt correctness
(fuzzy lookup filters by city before matching) but increases geocoding time.

**Rate limiting:**
- venuu.fi requests: 1 req/s with a random ±0.3s jitter; set `User-Agent` to
  something descriptive (e.g. `InfrasoundERP-venue-import/1.0`)
- Nominatim geocoding: 1 req/s hard limit (ToS)

---

## 4. Data fields to extract per venue

| Field | Source | Notes |
|---|---|---|
| `name` | Venue listing | Required; skip row if absent |
| `city` | Venue listing | Required |
| `address_line` | Venue detail page or listing | Optional |
| `capacity_approx` | Venue listing | Optional; for filtering only, not stored |
| `venue_type` | Category/type tag | Used for type filtering (§5); not stored |
| `venuu_url` | Crawl URL | For dedup and debugging; not stored |

Do NOT extract: pricing, reviews, contact details, photos.

---

## 5. Venue type filtering

The goal is to include venues where a live cover band would plausibly perform —
event halls, restaurants with event spaces, manors, hotels with ballrooms, outdoor
festival stages — and exclude venues that are structurally unsuitable.

**Recommended exclusion criteria** (verify against actual venuu.fi categories):

Exclude if the venue type/category contains:
- "toimisto" / "office" — office spaces
- "kokoustila" — pure meeting rooms with no event capacity
- "sauna" — sauna-only venues (unless they also list event space)
- "studio" — recording/photo studios
- capacity clearly < 30 persons (if available) — too small for a full band

**Include:** juhlatila, ravintola, hotelli (with event space), kartano, huvila,
tapahtumatila, terassi (outdoor stage/terrace), kongressikeskus.

If venuu.fi categories don't map cleanly to these, err on the side of inclusion —
a false positive (venue that doesn't host live bands) in the DB is harmless; it
will just never match an inquiry and never be selected.

If the category data is unavailable or unreliable, skip type filtering entirely
and import all venues in the target regions.

---

## 6. Geocoding

After extracting a venue, geocode it via Nominatim to populate `lat`, `lng`, and
`distance_from_turku_km`:

```python
query = f"{address_line}, {city}, Finland" if address_line else f"{name}, {city}, Finland"
coords = geocode_nominatim(query)  # reuse the geocoding helper pattern from enrich_gigs.py
```

If geocoding fails (returns no result), still INSERT the venue row with `lat=NULL`,
`lng=NULL`, `distance_from_turku_km=NULL`. The inquiry pipeline handles null
coordinates gracefully (falls back to live geocoding on first gig at that venue).

`distance_from_turku_km`: compute as the straight-line distance from Turku city
centre `(60.4518, 22.2666)` using the Haversine formula if OSRM is unavailable
from the ETL context. This is a rough seed value; the travel calculator uses OSRM
routing for actual gig cost calculation.

---

## 7. Deduplication against existing venues

Before inserting, check whether a venue with the same name+city already exists:

```python
cursor.execute(
    "SELECT id FROM venues WHERE name = %s AND city = %s AND deleted_at IS NULL",
    (name, city)
)
if cursor.fetchone():
    stats['skipped'] += 1
    continue  # already in DB; do not overwrite existing data
```

Do NOT fuzzy-match here — the ETL runs once; exact dedup is sufficient. The fuzzy
match is reserved for runtime inquiry processing.

---

## 8. SQL output format

```sql
-- legacy_venues.sql — generated by extract_venues.py
-- Idempotent: INSERT IGNORE skips rows that violate the unique key on (name, city, source).
SET NAMES utf8mb4;

INSERT IGNORE INTO venues (name, city, address_line, lat, lng, distance_from_turku_km, source)
VALUES ('Hintsan Vintti', 'Turku', 'Hintantie 1', 60.4714, 22.2345, 3.4, 'venuu');
-- ... one row per venue
```

**Prerequisites — columns and unique constraint:** migration 017 must add the `source` column
(already included in PHASE4_SPEC.md C1) **and** the following unique key so that
`INSERT IGNORE` is actually idempotent:

```sql
ALTER TABLE venues
    ADD COLUMN source VARCHAR(50) DEFAULT NULL
      COMMENT 'venuu = seeded from venuu.fi; NULL = created via inquiry pipeline or manually',
    ADD UNIQUE KEY uq_venues_name_city_source (name, city, source);
```

Add both to `db/schema/core.sql`.

---

## 9. Script flags

Follow the same flag pattern as other ETL scripts:

| Flag | Behaviour |
|---|---|
| `--dry-run` | Print SQL to stdout; do not geocode or write to DB |
| `--stats` | Print counts (fetched / filtered / geocoded / inserted / skipped) without writing |
| `--region REGION` | Crawl only one region (e.g. `--region varsinais-suomi`) |
| `--no-geocode` | Skip Nominatim; insert with NULL coords (useful for testing the scraper) |

---

## 10. Implementation order

1. Do the pre-crawl checklist (§2) — URL structure determines everything else.
2. Write the scraper for one region first with `--dry-run`; inspect output.
3. Add type filtering once you know venuu.fi's actual categories.
4. Enable geocoding; test on a small batch.
5. Run the full three-region crawl.
6. Review `legacy_venues.sql` (it's not gitignored) before committing.
7. `make import-legacy-venues` on dev, verify counts.
8. Add `source` column to migration 017 (or 018) before prod import.

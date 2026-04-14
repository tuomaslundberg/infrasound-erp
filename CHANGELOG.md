# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

### Added
- `db/migrations/013_songs_extension.sql` — extends `songs` with all columns needed for
  setlist ETL and Spotify integration: `spotify_track_id`, `genre`, `language`, `release_year`,
  `is_jazz`, `in_repertoire`, `hd_slot`, `hd_status`, `guide_tone_key`, `key_our`, `key_orig`,
  `key_transposition_st`, `has_gtr2`, `karaoke_eligible`. Extends `setlists` with `set_type`
  ENUM (`set`, `lounge`, `encore`, `karaoke`) for analytics-friendly set classification.
- `cli/etl/SETLIST_ETL_SPEC.md` — comprehensive design spec for the setlist/song ETL:
  source file map, three-era setlist format documentation (2021-2022 `setlist-internal` /
  early-2023 numeric codes / 2023+ current format), `playlist-gig.txt` parsing map with
  genre/language group structure, `keys.txt` section guide with full-English→shorthand key
  normalisation table, Spotify integration plan (Client Credentials flow, `enrich_spotify.py`
  enrichment pass), analytics use-case motivation, and Ableton XML structure notes for future
  automation.

### Fixed
- **TravelCalculator: car assignment now based on person, not gig role** — the previous
  implementation used the gig role (keyboards→Car1, bass→Car2, etc.) as a proxy for which car
  someone travels in, which broke whenever roles were assigned to non-default musicians.
  Assignment is now driven solely by `users.transport_mode` and the new `users.default_car`
  column (1=Car1, 2=Car2), making it independent of the musical role in the gig.
- `travel_calculate.php` — on-demand geocoding for legacy venues: if a venue has address/city
  but no lat/lng (imported before inquiry pipeline existed), geocode via Nominatim on "Recalculate
  travel", persist coords to the venue row, then run TravelCalculator normally; no-address venues
  still show the existing warning

### Added
- `db/migrations/012_users_default_car.sql` — adds `default_car TINYINT(1)` to users;
  seeds mortti.markkanen and lauri.lehtinen as Car 2 (default_car=2)

### Changed
- `TravelCalculator::calculateFromPersonnel()` — role parameter now ignored for car assignment;
  uses transport_mode + default_car instead; `calculate()` fetches default_car from DB
- `process_inquiry.php`, `webflow.php` — synthetic default lineup now passes default_car from DB;
  `$defaultRoles` map removed as it was only needed for the old role-based logic

---

### Added
- `db/migrations/010_travel_calculator_schema.sql` — schema additions for route-based travel
  cost calculation: `users` gains `home_address`, `home_lat/lng`, `transport_mode`;
  `venues` gains `lat/lng`, `requires_ferry`, `ferry_cost_estimate_cents`;
  `gig_personnel` gains `transport_override`
- `db/seeds/musician_addresses.sql` — sets `home_address` and `transport_mode` for the 8
  core musicians; lat/lng populated separately via `make geocode-musicians`
- `cli/geocode_musicians.php` — one-time geocoding script; reads `home_address` from users,
  queries Nominatim, updates `home_lat/home_lng`; rate-limited to 1 req/s (Nominatim ToS)
- `cli/lib/RoutingHelper.php` — shared geocoding and routing primitives: `geocode()` (Nominatim)
  and `waypointRouteKm()` (OSRM multi-waypoint); extracted from GeocodingHelper and generalised
- `cli/lib/TravelCalculator.php` — computes Car 1 and Car 2 route-based km from assigned
  personnel home addresses; role-based car allocation; trailer waypoint at Opettajankatu 9;
  ferry cost support; graceful fallback on missing coords
- `src/modules/gigs/travel_calculate.php` — POST handler for "Recalculate travel" button;
  runs TravelCalculator against assigned personnel and updates gig travel fields

### Changed
- `src/modules/agent/lib/GeocodingHelper.php` — refactored to delegate to RoutingHelper;
  new `geocodeVenue()` method returns `{lat, lng, distance_km}` so venue coordinates are
  captured and stored alongside the distance-from-Turku value
- `src/modules/agent/process_inquiry.php` — at inquiry time: stores venue `lat/lng`; runs
  TravelCalculator with the default 6-person lineup (Toni/Mortti as sound/bass defaults)
  to populate `car1_distance_km` and `car2_distance_km` instead of the naive `distFromTurku×2`
- `src/modules/gigs/detail.php` — "Recalculate travel" button in Pricing card header;
  flash notices for travel_recalculated, travel_failed, travel_no_venue_coords
- `src/index.php` — route `/gigs/{id}/travel/calculate` (POST, owner)
- `db/schema/core.sql` — updated with all migration 010 columns; `gig_personnel` ENUM
  updated to match migration 007 role names
- `Makefile` — new targets: `seed-musician-addresses`, `seed-musician-addresses-prod`,
  `geocode-musicians`

---

## [Unreleased — previous]

### Added
- `db/migrations/008_setlists.sql` — three new tables: `songs` (global repertoire library,
  deduped by title+artist), `setlists` (one named set per gig), `setlist_songs` (ordered
  junction; same song may repeat in a set; swap-based `sort_order` reordering)
- `db/migrations/009_venue_placeholder_nulls.sql` — detaches gigs from ETL-created phantom
  venue rows (`name = city` or `name = 'EI TIEDOSSA'`) and soft-deletes those rows;
  prevents false-positive venue-familiar-quote template selection
- `src/modules/gigs/setlist_song_add.php` — POST handler: get-or-create song + setlist,
  append to ordered setlist_songs; case-insensitive song dedup
- `src/modules/gigs/setlist_song_remove.php` — POST handler: delete setlist_songs row;
  auto-removes parent setlist row if it becomes empty
- `src/modules/gigs/setlist_song_move.php` — POST handler: swap sort_order with adjacent
  row (up/down); no-op if already at boundary
- Setlist card on gig detail page — shows per-set song tables with ↑↓ reorder and Remove
  actions; add form in card footer (title, artist, set selector, optional notes field)

### Changed
- `src/index.php` — three new routes for setlist song add, remove, move (owner role)
- `db/schema/core.sql` — three new table definitions added (songs, setlists, setlist_songs)

---

## [Unreleased — previous]

### Added
- `db/migrations/006_users_email.sql` — adds nullable `email VARCHAR(255)` column to
  `users`; prerequisite for musician seeding and future musician portal auth.
- `db/migrations/007_gig_personnel_schema.sql` — two changes to `gig_personnel`:
  (1) renames `role` ENUM values from person-nouns (`vocalist`, `guitarist`, …) to
  instrument/function nouns (`vocals`, `guitar`, `bass`, `drums`, `keyboards`,
  `sound_engineering`, `other`), matching the invoicing ETL spec; adds
  `sound_engineering` for Toni / Valtteri Alanen slots.
  (2) makes `fee_cents` nullable: NULL = partner fee not tracked in invoicing (settled
  separately); 0 = explicitly confirmed zero; >0 = eurocent amount.
  Migration includes commented-out UPDATE block for remapping any pre-existing rows.
- `db/seeds/musicians.sql` — idempotent INSERT IGNORE seed for all 19 musicians on
  the band roster (as per `cli/etl/INVOICING_ETL_SPEC.md`); uses `'!'` as
  password_hash sentinel (locked account, cannot authenticate via password flow);
  placeholder email addresses in the `musicians.infrasound.fi` subdomain.
  Partners (Tuomas, Toni, Joni, Lauri) seeded as musicians; INSERT IGNORE preserves
  any existing higher-privilege accounts.

### Changed
- `src/modules/gigs/personnel_add.php` — `$validRoles` updated to new ENUM values.
- `src/modules/gigs/detail.php` — role `<option>` values and role display in the
  personnel table updated to new ENUM values; `str_replace('_', ' ', …)` applied for
  readable display of `sound_engineering`; `fee_cents` display now null-safe
  (renders `—` for NULL rather than dividing by zero).

### Added
- `src/assets/mail-templates/fi/direct/{weddings,companies,other}/no-date-inquiry.txt` —
  new template for inquiries that don't include a date; politely asks the customer for
  the event date before quoting
- `db/migrations/005_nullable_gig_date.sql` — allows `gig_date` to be NULL for
  inquiry-status gigs where the customer has not yet specified a date

### Changed
- `src/modules/gigs/quote.php` — company quotes now pass net price (gross ÷ 1.135)
  to `TemplateRenderer`; consumer (wedding/other) templates continue to receive gross;
  fixes mismatch with "ei sis. ALV" wording in company template
- `src/modules/gigs/quote.php` — `no-date-inquiry` added to available template types
- `cli/lib/TemplateRenderer.php` — docblock updated: price parameter is now caller's
  responsibility to pass gross or net as appropriate
- `src/modules/agent/process_inquiry.php` — customer and venue lookup now matches
  existing DB rows by name before INSERTing; eliminates duplicate entities on
  repeated agent-processed inquiries; falls back to stored venue distance if geocoding
  returns null
- `src/modules/gigs/form.php` — new-gig path now matches existing customer by name
  before INSERTing; gig date validation allows null (owner fills before quoting)
- `src/modules/gigs/list.php` — null gig_date rendered as "—" instead of blank
- `db/schema/core.sql` — `gig_date` column updated to `DATE DEFAULT NULL`

### Added
- `cli/etl/enrich_gigs.py` — UPDATE pass ETL script: parses all gig-info-*.txt and
  price-calculator-*.xlsx files under `old-files/`, matches each to an existing legacy
  gig record by (date, customer name), and emits `db/seeds/legacy_enrich.sql` with
  idempotent UPDATE statements.
  Populates:
  - `venues.{name, address_line, postal_code, city, distance_from_turku_km}`
  - `gigs.{car1_distance_km, car2_distance_km, other_travel_costs_cents,
    order_description, quoted_price_cents, base_price_cents}` (price fields only where
    currently NULL, preserving invoice data from extract_gigs.py)
  - `gigs.{pricing_tier1, pricing_tier2, qty_ennakkoroudaus, qty_song_requests_extra,
    qty_extra_performances, qty_background_music_h, qty_live_album, discount_cents}`
    (from price-calculator; always overwritten)
  - `contacts.{email, phone}` from 2021–2022 old-format files (legal entities only)
  Three gig-info format generations handled (new / intermediate / old).
  Two price-calculator format generations handled (new row-labelled / old columnar).
  Files with unparseable dates written to `db/seeds/legacy_enrich_unmatched.txt`.
  Design decisions:
  - `other_travel_costs_cents`: 37.80 € was the real default train-ticket estimate
    (Helsinki–Turku return, 1 person); imported as-is rather than skipped.
  - Intermediate "Etäisyys Lipunkantajankadulta": Lipunkantajankatu 18, Runosmäki ≈
    Turku centre; imported as `distance_from_turku_km` for analytics parity.
  - Multi-option Tarjous (Finnish set labels): "kolme" (3×45 min) taken as canonical
    default; numbered Tarjous blocks prefer HYVÄKSYTTY entry, then second option.
  - Old price-calculator: 3-set price is in rows[3] col[28]; rows[4] is Perushinta.
  - Song requests: intentionally skipped — schema not yet defined; source data stale.
  Flags: `--dry-run` (SQL to stdout), `--stats` (counts only).

### Fixed
- `cli/etl/extract_gigs.py` — `get_or_create_venue` previously deduplicated venues by
  city name, causing all gigs in the same city to share one `venue_id`. When
  `enrich_gigs.py` updated the shared venue row with detailed data from one gig's
  gig-info file, it silently overwrote the venue for all other gigs in that city (e.g.
  every Tampere gig would end up showing the last-processed Tampere venue name/address).
  Fixed by creating one venue row per gig; fuzzy deduplication is now handled by
  `enrich_gigs.py` (see below).

- `cli/etl/enrich_gigs.py` — venue fuzzy deduplication pass added after parsing:
  two-phase approach using `difflib.SequenceMatcher` (stdlib, no new dependency).
  Phase 1 (DB matching): each parsed venue with a non-placeholder name is compared
  against all existing venue rows in the DB (loaded via optional pymysql connection;
  gracefully skipped if unavailable).  City-only placeholder rows from extract_gigs.py
  are excluded from matching.  Score ≥ 0.88 → auto-match (emit `UPDATE gigs SET
  venue_id = X`; existing venue row filled with COALESCE-guarded field updates).
  Phase 2 (in-batch dedup): remaining unmatched records are clustered pairwise; score
  ≥ 0.88 → canonical elected by VenueData completeness; duplicates emit a venue_id
  copy via subquery (`UPDATE gigs SET venue_id = (SELECT g2.venue_id ... canonical)`).
  Near-misses (0.60–0.88) printed to stderr for manual review.
  Result: 40 in-batch venues auto-deduped on first run; venue-familiar template
  selection in `quote.php` will now work for any venue the band has played at before.

- `cli/etl/enrich_gigs.py` — fixed venue name not written when DB-matched to a
  city-only placeholder row (`name = city` pattern seeded by `extract_gigs.py`).
  A plain `COALESCE(name, …)` does not fire when `name` is non-NULL (i.e. already
  set to the city string), so the real venue name was silently dropped.  Changed to
  `COALESCE(NULLIF(name, city), …)` in the `matched_venue_id` branch: `NULLIF` turns
  the city-sentinel back to NULL, allowing COALESCE to write the real name.  Genuine
  venue names (name ≠ city) are unaffected.

- `cli/etl/_try_connect_db` — dev stack credentials and port (3307) are now picked up
  automatically by merging `.env.dev` on top of `.env` when present.  `ETL_DB_PORT`
  added to `.env.dev` to cover the host-side port mapping in `docker-compose.dev.yml`.

- `Makefile` targets: `etl-enrich` (regenerate `legacy_enrich.sql` from text files);
  `enrich-dev` / `enrich-prod` (load enrichment SQL into dev / prod DB).
  Full ETL workflow: `make etl-gigs && make etl-enrich` → `make dev` →
  `make import-legacy-gigs && make enrich-dev`.
- `.gitignore` — `db/seeds/legacy_enrich*.sql` and `db/seeds/legacy_enrich*.txt`
  (contain customer PII; regenerate with `make etl-enrich`).



### Added
- `src/modules/gigs/list.php` — status filter (button group, `?status=`), sortable
  column headers with ▲/▼ indicator (`?sort=&dir=`), customer name search via
  `LIKE` bound parameter (`?q=`), and pagination at 25 rows/page (`?page=N`) with
  "Showing X–Y of Z" count and prev/next links; all four controls compose correctly;
  sort column whitelisted before SQL interpolation; no raw user input in SQL
- `src/modules/gigs/detail.php` — Pricing card now shows all seven pricing inputs
  below the existing four rows: Dynamic pricing tier (None / Tier 1 / Tier 1 + 2),
  Ennakkoroudaus, Extra song requests, Extra performances, Background music, Live album,
  and Discount. Zero-value quantities render as `—`; monetary values use `X,XX €` format.
### Changed
- `src/modules/gigs/quote.php` — template auto-selection: defaults to
  `venue-familiar-quote` if the venue has ≥1 delivered gig in DB, or
  `sorry-were-booked` if a confirmed gig already exists on the inquiry date
  (takes priority over venue-familiar); explicit `?type=` param still overrides.
  An "already booked" warning banner is shown regardless of selected template;
  a dismissible info banner is shown when venue-familiar is auto-selected.

### Fixed
- `src/modules/agent/process_inquiry.php` — car1 baseline mileage corrected to
  `2 × driving distance` (round trip); was incorrectly using the one-way distance
- `src/modules/gigs/form.php` — contact email field changed from `type="email"` to
  `type="text"` so AI-extracted placeholders like `<UNKNOWN>` can be saved and
  corrected at review time; browser email validation was blocking form submission
- `src/modules/gigs/form.php` — distance label corrected from "Straight-line
  distance" to "Driving distance" (geocoder returns driving distance, not straight-line)

### Added
- `src/modules/agent/process_inquiry.php` — AI-assisted inquiry form: owner pastes
  raw inquiry text; calls Anthropic API to extract structured fields; geocodes venue
  address via Nominatim + OSRM to get driving distance from Turku; creates
  customer/contact/venue/gig rows in a transaction; gig created with `status = inquiry`;
  redirects to gig detail with a review notice
- `src/modules/agent/lib/InquiryExtractor.php` — wraps Anthropic API tool_use call
  (`claude-sonnet-4-6`); returns typed array of extracted gig fields; throws
  `RuntimeException` on API or curl error
- `src/modules/agent/lib/GeocodingHelper.php` — geocodes a Finnish address via
  Nominatim (OSM) then routes Turku → venue via OSRM public API; returns driving
  distance in km or null on failure
- `src/index.php` — route `GET/POST /agent/process-inquiry` (owner role)
- `src/templates/layout.php` — "New inquiry (AI)" nav link for owner+ role
- `src/modules/gigs/detail.php` — `inquiry_created` flash notice shown after AI
  extraction, reminding owner to review extracted fields before quoting
- `AGENTS_AGENT_SERVICE.md` — module scope document for the agent service

### Added
- `src/modules/gigs/personnel_add.php` — POST handler to assign a musician to
  a gig (`gig_personnel` row); duplicate assignments rejected with a flash notice
- `src/modules/gigs/personnel_remove.php` — POST handler to remove a musician
  from a gig (hard delete of `gig_personnel` row)
- `src/index.php` — routes `POST /gigs/{id}/personnel` and
  `POST /gigs/{id}/personnel/{user_id}/remove` (both require `owner` role)
- `src/modules/gigs/detail.php` — Personnel card: current lineup table with
  Username / Role / Fee / Confirmed columns, inline add-musician form
  (user select, role select, fee in €), Remove button per row, and flash
  notices for add/remove/duplicate/error outcomes
- `src/modules/gigs/notes.php` — POST handler for `POST /gigs/{id}/notes`;
  updates only `gigs.notes` for the given gig (PDO prepared statement); rejects
  soft-deleted gigs; saves empty input as NULL; redirects back to detail page
  (PRG pattern)
- `src/index.php` — route `/gigs/{id}/notes` (POST, owner)
- `src/modules/gigs/detail.php` — notes section always visible (shows
  "No notes yet." placeholder when empty); inline edit toggle reveals a
  textarea + Save / Cancel controls without a page reload (inline `<script>`)
### Changed
- `src/modules/gigs/form.php` — replaced two independent dynamic-pricing
  checkboxes (tier1 / tier2) with a three-option radio group (`pricing_tier`:
  `none` / `tier1` / `tier1_tier2`). Tier 2 is no longer independently
  selectable. POST handler derives `$tier1`/`$tier2` booleans from the single
  radio value; GET edit derives the radio value from the existing
  `pricing_tier1`/`pricing_tier2` columns. `PriceCalculator` inputs and DB
  schema unchanged.
### Added
- `src/modules/musician/gigs.php` — read-only list of upcoming gigs for the
  logged-in musician; shows only gigs where the user has a `gig_personnel` row
  and `gig_date >= CURDATE()`, ordered by date; columns: Date, Customer
  (first name only for weddings, company name otherwise), Venue, Role, Status
- `src/modules/musician/gig_detail.php` — read-only gig card for musicians;
  shows date, venue (name/address/city), order description, stage contact
  (name + phone), song requests (artist/title/first-dance flag), own role and
  fee; pricing fields (quoted/base price, cost inputs) are intentionally absent;
  enforces that the logged-in user is assigned to the gig (404 otherwise)
- `src/index.php` — routes `GET /musician/gigs` and `GET /musician/gigs/{id}`
  with minimum role `musician`
- `src/templates/layout.php` — "Gigs" nav link restricted to `owner`+ role;
  musicians see a "My Gigs" link pointing to `/musician/gigs` instead

### Fixed
- `src/assets/mail-templates/` — converted all bare `https://` URLs in sales
  email templates to Markdown links `[text](url)`; affected files:
  `fi/direct/{companies,weddings,other}/{quote,venue-familiar-quote,thank-you}.txt`
  and
  `fi/buukkaa-bandi/{companies,weddings,other}/{order-confirmation,venue-familiar-order-confirmation}.txt`
- `docker-compose.yml` — added volume mount for `src/assets/mail-templates` at
  `/var/www/src/assets/mail-templates` so `TemplateRenderer` can locate template
  files inside the PHP container (path resolved by `dirname(__DIR__, 2)` from
  `cli/lib/`, which gives `/var/www/src/...` not `/var/www/html/...`)

### Added
- `config/gig_states.php` — gig status state machine: transition map,
  button labels/styles, badge colours, `gig_valid_transitions()` and
  `gig_can_transition()` helpers
- `src/modules/gigs/transition.php` — POST handler for status transitions;
  validates against the state machine before applying; rejects invalid
  transitions with a redirect
- `src/index.php` — route `/gigs/{id}/transition` (POST, owner)
- `src/modules/gigs/detail.php` — status badge in page header; row of
  transition action buttons for all valid next states from current status
- `db/migrations/004_gig_personnel.sql` — CREATE TABLE gig_personnel:
  assigns users to gigs with role ENUM, fee_cents, confirmed_at
- `db/schema/core.sql` — gig_personnel table added

### Changed
- `cli/lib/TemplateRenderer.php` — template root updated from `old-files/sales/`
  to `src/assets/mail-templates/` following the template migration to VCS.
  `mapChannel()` default changed from pass-through to `'direct'` so all
  non-buukkaa-bandi channels (saturday_band, venuu, mail, …) resolve to the
  `direct/` directory instead of their raw ENUM name.

### Fixed
- `src/modules/gigs/form.php` — `goto render_form` inside the POST error paths
  jumped past the `$v`/`$chk` closure definitions, causing "Undefined variable"
  warnings and a "null is not callable" fatal error on form re-render. Fixed by
  moving both closures before the POST block and capturing `$db` by reference
  (`use (&$db)`) so the GET edit block can still populate it afterwards.
- `src/modules/gigs/form.php` — channel validation still listed only
  `mail|buukkaa_bandi`; updated to match the full 12-value ENUM from migration 001.

### Fixed (previous)
- `cli/etl/extract_gigs.py` — gig-invoicing merge was failing to match records
  in two cases: (1) invoicing "KEIKKA" column contained Finnish event-type
  suffixes ("Lindqvist häät", "Yritys X pikkujoulut") absent from the tracker;
  (2) tracker ASIAKAS field contained a parenthesised contact suffix
  ("Company Oy (Maija Haapakoski)") absent from the invoicing record.
  Fixed with `_normalise_invoice_name()` (strips event suffixes) and
  `_entity_name_only()` (strips parenthesised contact before comparison).
  Added `--debug-unmatched` flag.

### Changed
- `db/migrations/3_gig_pricing_inputs.sql` — ALTER TABLE adds 8 NOT NULL pricing-input
  columns (default 0) to `gigs`: `pricing_tier1`, `pricing_tier2`, `qty_ennakkoroudaus`,
  `qty_song_requests_extra`, `qty_extra_performances`, `qty_background_music_h`,
  `qty_live_album`, `discount_cents`. Persists the granular PriceCalculator inputs
  so the edit form pre-populates them instead of resetting to zero.
- `db/schema/core.sql` — same 8 columns added to the `gigs` CREATE TABLE reference block.
- `src/modules/gigs/form.php` — SELECT query, `$db[]` mapping, INSERT, and UPDATE
  statements updated to read/write the 8 new pricing-input columns.

### Added
- `db/migrations/002_add_users_table.sql` — CREATE TABLE users; role ENUM
  (`developer`/`admin`/`owner`/`musician`/`guest`); bcrypt password_hash;
  unique username; soft delete
- `config/auth.php` — `auth_start()`, `auth_user()`, `auth_has_role()`,
  `auth_require()`; role hierarchy in ROLE_ORDER constant
- `src/modules/auth/login.php` — login form + POST handler; session_regenerate_id
  on success; `?next=` redirect param
- `src/modules/auth/logout.php` — session destroy + redirect to /login
- `db/schema/core.sql` — users table added

### Changed
- `src/index.php` — route table switches from `is_public` bool to `min_role`
  (null = public); auth_require() enforced in dispatcher; /login and /logout
  added as public routes
- `src/templates/layout.php` — navbar shows username + role badge + Sign out
  when logged in; nav links hidden on login page

### Added
- `cli/etl/extract_gigs.py` — idempotent ETL script that reads all
  `old-files/info/gigs-*.xlsx` and `old-files/info/archive/gigs-*.xlsx` files
  plus `old-files/gig-invoicing.xlsx`, normalises dates, channels, statuses,
  and customer/contact names, fuzzy-merges the two sources (gig-invoicing
  records within ±3 days and ≥ 0.75 name similarity matched to gigs-YYYY
  records; fees propagated to matched rows; unmatched rows imported as
  additional `delivered` gigs), and writes `db/seeds/legacy_gigs.sql`
  (IDs 1–9999 reserved for legacy data, AUTO_INCREMENT reset to 10000) and
  `db/seeds/legacy_gigs_dup_candidates.txt`.
  Flags: `--dry-run` (SQL to stdout), `--stats` (counts only).
- `db/migrations/001_expand_channel_enum.sql` — ALTER TABLE that expands
  `gigs.channel` from `mail | buukkaa_bandi` to a 12-value ENUM covering
  all booking platforms encountered in the legacy data.
- `db/seeds/legacy_gigs.sql` — generated output of `extract_gigs.py`;
  338 gigs, 324 customers, 332 contacts, 99 venues. Not committed to git
  (gitignored alongside old-files); regenerate with `make etl-gigs`.
- `Makefile` targets: `etl-gigs`; `migrate-dev` / `migrate` (dev/prod, `FILE=`
  parameter); `import-legacy-gigs` / `import-legacy-gigs-prod` (apply migration
  001 then load seed; full workflow documented in target comments).
- `.gitignore` — `db/seeds/legacy_gigs*.sql` and `db/seeds/legacy_gigs*.txt`
  (contain customer PII; regenerate with `make etl-gigs`).

### Changed
- `db/schema/core.sql` — `gigs.channel` ENUM updated to match migration 001;
  inline comments document the channel taxonomy and buukkaa_bandi distinction.
- `cli/etl/extract_gigs.py` — `_map_status` fallback changed from `'inquiry'`
  to `'quoted'`: legacy records were never written down before a quote was sent,
  so `inquiry` is not a valid state for any historical row. `inquiry` remains
  in the ENUM for the future automated intake pipeline.

### Added (Phase 1)
- `TODO.md` — phased task list covering dev infrastructure through accounting;
  items marked `[copilot]` are suitable for GitHub Copilot Coding Agent delegation
- `db/seeds/dev.sql` — realistic dev fixture data (5 gigs from 2026 season with
  venues, customers, contacts, and song requests); sourced from `old-files/future-gigs/`
- `docker-compose.dev.yml` — compose overlay for dev environment; overrides `env_file`
  and uses a separate `db_data_dev` volume so dev and prod data never share storage
- `Makefile` — `make up` / `make dev` / `make down[-dev]` / `make seed` / `make logs[-dev]` /
  `make shell-db[-dev]`; switches between prod (`.env`) and dev (`.env.dev`) environments
- `.env.dev` — dev database credentials (gitignored; copy and fill locally)
- `docker/apache-site.conf` + `Dockerfile` — replace default Apache vhost to enable
  `AllowOverride All` for `.htaccess` rewrite rules
- `src/.htaccess` — front controller rewrite: all non-file/dir requests → `index.php`
- `src/index.php` — URL router; route table maps patterns to controller files;
  `is_public` flag per route keeps the door open for unauthenticated public routes (Phase 3)
- `src/templates/layout.php` — Bootstrap 5 shell with navbar; controllers call
  `render_layout($title, callable $content)`
- `src/templates/error.php` — 404 / 501 error page
- `src/modules/gigs/list.php` — gig list page (DB-backed; date, customer, venue, status,
  quoted price, channel)
- `src/modules/gigs/detail.php` — gig detail page (DB-backed; pricing, venue, notes)
- `src/modules/gigs/form.php` — inquiry form placeholder (full form implemented in next commit)

### Changed
- `src/modules/gigs/form.php` — full inquiry form (replaces placeholder); GET + POST handler;
  saves customer, contact, venue, gig in one transaction; calculates base_price_cents via
  PriceCalculator (reused from cli/); supports quoted price override; PRG redirect to detail
- `docker-compose.yml` — mount `./cli:/var/www/cli` so web PHP can require PriceCalculator
- `Dockerfile` — `COPY ./cli /var/www/cli` for non-volume-mount contexts
- `src/templates/layout.php` — remove incorrect Bootstrap JS SRI hash (was blocking JS)
- `db/seeds/dev.sql` — TRUNCATE block makes `make seed` idempotent
- `Makefile` — add `reset-dev` target (destroys dev volume and restarts fresh)

### Added (feature/cli-inquiry-processor)
- `cli/process_inquiry.php` — CLI entry point; reads a YAML inquiry file, calculates
  the quote price, and outputs a filled sales email template. No Docker required.
  Usage: `php cli/process_inquiry.php inquiry.yaml [--type=quote] [--output=email|summary|both]`
- `cli/inquiry-template.yaml` — blank inquiry template replacing `gig-info-yymmdd.txt`
- `cli/lib/InquiryParser.php` — YAML reader with field validation and defaults
- `cli/lib/PriceCalculator.php` — implements the pricing formula from
  `old-files/sales/price-calculation-flow.txt` (base price, distance premium,
  dynamic pricing tiers, Finnish mileage rates, buukkaa-bandi fee, additional services)
- `cli/lib/TemplateRenderer.php` — fills `[ASIAKAS]` and price tokens in sales templates
- `composer.json` + `composer.lock` — project dependencies (symfony/yaml ^7.0)
- `old-files/sales/price-calculation-flow.txt` — pricing formula documentation
  (added by user; referenced by PriceCalculator)

### Changed (feature/cli-inquiry-processor)
- `.gitignore` — added `vendor/` and `*.phar`
- `config/db.php` — sets `SET time_zone = '+00:00'` on every PDO connection

---

### Added (feature/db-schema)
- `db/schema/core.sql` — authoritative schema reference for the inquiry/gig management MVP:
  tables `customers`, `contacts`, `customer_contacts`, `venues`, `gigs`, `song_requests`
- `db/init.sql` now creates all tables on first container start

### Changed (feature/db-schema)
- `AGENTS.md` §4 — repo tree updated with `cli/` and `db/schema/core.sql`
- `CLAUDE.md` — file layout updated to reflect `cli/` and schema file

---

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

# Agent Service — Module Spec

This document scopes the AI-assisted inquiry processing module for Infrasound ERP.
It unblocks implementation and satisfies the CLAUDE.md gate.

---

## Purpose

An owner pastes a raw inquiry (email body, web form message, or similar text) into a
PHP web form. The LLM extracts structured gig data. The system creates the gig entity
in the database with status `inquiry`. The owner reviews the extracted data, corrects
any gaps or errors, and generates a quote email for copy-paste to ProtonMail.

---

## Design decisions

| Concern | Decision |
|---------|----------|
| V1 input | Manual paste into web form (no inbox integration in V1) |
| LLM | Anthropic API — `claude-sonnet-4-6`; tool use for reliable structured output |
| Interface | PHP web module — non-technical owners need a browser UI |
| Gig creation | Immediate on extraction: gig inserted with `status = inquiry`; owner reviews via existing detail/edit flow |
| Future inbox | Protonmail webhook → POST to same endpoint with email body; same DB path |

Saving as `inquiry` immediately is preferred over pre-filling the form because it
aligns with the future inbox-scraping path: inbox automation will create inquiry-status
gigs without human interaction, enabling filtering of unquoted gigs across both flows.

---

## Extracted fields

The LLM extracts the following from raw inquiry text.
Fields absent from the inquiry are left as `null`; the owner fills gaps in the edit view.

| Field | Type | Notes |
|-------|------|-------|
| `customer_name` | string | Legal name or full name |
| `customer_type` | enum | `wedding` / `company` / `other` |
| `gig_date` | date | ISO 8601; null if not stated |
| `venue_name` | string | |
| `venue_address` | string | Street address |
| `venue_city` | string | |
| `contact_name` | string | Person who sent the inquiry |
| `contact_phone` | string | |
| `contact_email` | string | |
| `order_description` | string | What the customer described |
| `notes` | string | Budget hints, special requests, raw extras |

---

## Geocoding

Venue address → driving distance (km) from Turku is needed for `PriceCalculator`.

**Approach for V1:**
1. Geocode `venue_address, venue_city` via Nominatim (OSM) → lat/lon
2. Route lat/lon → Turku coordinates via OSRM public API → distance in km
3. Populate `car1_distance_km` (and `car2_distance_km` if set equal by default)
4. On any geocoding failure: set distance to `null`; owner enters manually

No API key required. Finnish address coverage is adequate for current gig geography.

---

## Architecture

### New module: `src/modules/agent/`

```
src/modules/agent/
  process_inquiry.php     — GET (form) + POST (extract, create, redirect) controller
  lib/
    InquiryExtractor.php  — Anthropic API call + response parsing
    GeocodingHelper.php   — Nominatim + OSRM distance lookup
```

### Routes (add to `src/index.php`)

```php
['#^/agent/process-inquiry$#', 'modules/agent/process_inquiry.php', 'owner'],
```

### POST flow

1. Validate: raw text non-empty
2. `InquiryExtractor::extract(string $rawText): array` — calls API, returns typed array
3. `GeocodingHelper::distanceFromTurku(string $address, string $city): ?int` — km or null
4. Upsert customer (match on name), contact, venue using same logic as `gigs/form.php`
5. Run `PriceCalculator` with extracted inputs where available
6. `INSERT INTO gigs (..., status) VALUES (..., 'inquiry')`
7. Redirect to `/gigs/{id}?notice=inquiry_created`

### InquiryExtractor

- Single static `extract()` method
- Uses Anthropic `tool_use` with a tool schema mirroring the field table above
- Model: `claude-sonnet-4-6`
- API key from `getenv('ANTHROPIC_API_KEY')`
- HTTP via `curl` (no new Composer dependency)
- Returns associative array; throws `RuntimeException` on API error

---

## Config

- `ANTHROPIC_API_KEY` — already in `.env`; no new config needed
- No new DB schema for V1 (uses existing `gigs`, `customers`, `contacts`, `venues`)

---

## Distance vs mileage — important distinction

Two separate numeric concepts must not be confused:

| Concept | DB column | Purpose | How set |
|---------|-----------|---------|---------|
| **Distance from Turku** | `venues.distance_from_turku_km` | Distance premium in pricing (flat rate by km band) | Geocoded in V1; owner can correct |
| **Car 1 mileage** | `gigs.car1_distance_km` | Travel compensation (€0.81/km) — actual driven route: band members' pickups, trailer fetch, full round trip | Estimated by owner |
| **Car 2 mileage** | `gigs.car2_distance_km` | Second vehicle compensation (€0.55/km) — same concept | Estimated by owner |

Mileage estimation is substantially more complex than distance: it depends on who is
performing (gig_personnel), who has a car, where they live, whether the trailer is
needed, etc. In future this could be partially automated from gig_personnel data.

The agent service populates `distance_from_turku_km` from geocoding (Turku → venue)
and uses it as a baseline pre-fill for `car1_distance_km` only. The owner must
verify and correct mileage estimates before generating a quote.

---

## Future / Keep door open

- **Acceptance-flow automation** — customer replies accepting the offer → owner pastes
  the reply into the agent → agent records any additional info and transitions the gig
  from `quoted` → `confirmed`; same paste-to-agent UX as inquiry processing
- **ProtonMail inbox scraping** — webhook or polling → POST raw email body to
  `/agent/process-inquiry`; same extraction + creation path
- **Confidence scoring** — flag low-confidence extractions for mandatory review before save
- **Mileage estimation from gig_personnel** — once gig_personnel data is populated,
  estimate car routes from known band member locations / vehicle ownership
- **Public web form** — structured inquiry form on saturday.band writes to ERP directly
  (inquiry-status gig created, no LLM needed for that path)

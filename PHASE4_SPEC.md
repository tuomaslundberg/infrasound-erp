# Phase 4 implementation spec — Venue polish + inquiry improvements

*Feed this file to a fresh Claude Code session together with `CLAUDE.md` and `AGENTS.md`.*
*All features branch from `dev` as `feat/phase4-polish` (single branch; features are small enough to ship together).*

---

## Overview

Six features, roughly in dependency order:

| # | Feature | Files touched | Effort |
|---|---------|--------------|--------|
| A | Geocoding verification map | `geocode_musicians.php` | Small |
| B | Entity extraction — nominative normalisation | `InquiryExtractor.php` | Small |
| C | Venue schema migration + edit UI | new migration, new PHP files | Medium |
| D | Venue fuzzy lookup in GigCreator | `GigCreator.php` | Small |
| E | Default lineup auto-fill | `gigs/detail.php`, new endpoint | Small |
| F | Gig list filters | `gigs/list.php` | Small |

Do B before C (normalised venue names feed into fuzzy lookup). Otherwise features are independent.

---

## Feature A — Geocoding verification map

**Goal:** owner can visually confirm all musician home pins are correct without SQL.

**File:** `src/modules/admin/geocode_musicians.php`

The page already queries all users with `home_address IS NOT NULL` and displays them in a table. Add a Leaflet map section beneath the existing table.

### Changes

1. In the `<head>` section of the rendered page, load Leaflet from CDN:
   ```html
   <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
   <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
   ```

2. After the summary table, add:
   ```html
   <div id="geocode-map" style="height:500px; margin-top:1.5rem;"></div>
   ```

3. Inline `<script>` block at bottom of page:
   - Initialise Leaflet map centred on Turku (`[60.4518, 22.2666]`, zoom 8)
   - Use OpenStreetMap tiles: `https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png`
   - For each user with non-null `home_lat` / `home_lng`, add a marker with a popup showing `username` and `home_address`
   - Users with `home_lat IS NULL` are shown in the table with a red badge but no pin
   - PHP emits the user list as a JSON array into the script: `const musicians = <?= json_encode($allUsers) ?>;`

4. The existing POST geocoding action and results table are unchanged.

---

## Feature B — Entity extraction: nominative normalisation

**Goal:** all text fields returned by InquiryExtractor are in Finnish nominative (perusmuoto), preventing geocoding failures and DB inconsistencies caused by inflected forms.

**Background:** Finnish morphology inflects proper nouns heavily. The inquiry pipeline currently stores inflected forms verbatim — e.g. `venue_name = "Hintsan Vintillä"` instead of `"Hintsan Vintti"`, which causes geocoding to fail. The same applies to customer names ("Harri Mäkiselle" → "Harri Mäkinen"), city names, and contact names. Purely programmatic suffix stripping is unreliable due to irregular morphology; an LLM call handles edge cases naturally.

**File:** `src/modules/agent/lib/InquiryExtractor.php`

### Changes

No new file or API call needed. Update the existing system prompt / field descriptions to instruct the model to return nominative forms.

1. Add a top-level instruction to the `messages[0].content` string:
   ```
   IMPORTANT: Return all name and place fields in Finnish nominative case (perusmuoto / dictionary form),
   regardless of how they appear in the source text. Examples:
     "Hintsan Vintillä" → venue_name: "Hintsan Vintti"
     "Annalille" → contact_first_name: "Annaliina"
     "Mikko Virtaselle" → customer_name: "Mikko Virtanen"
     "Tampereella" → venue_city: "Tampere"
   If the text is already in nominative, return it as-is.
   If you cannot determine the nominative form with confidence, return the value as-is rather than guessing.
   ```

2. Update the `venue_name` field description to explicitly mention nominative:
   ```
   'description' => 'Name of the venue in Finnish nominative form (perusmuoto). ...'
   ```

3. Update `venue_city`, `customer_name`, `contact_first_name`, `contact_last_name` descriptions similarly.

4. Add a fallback in `process_inquiry.php` and `webflow.php`: if `customer_name` is empty after extraction, fall back to `contact_first_name . ' ' . contact_last_name` (already partially handled; make it explicit):
   ```php
   if (empty($customerName)) {
       $customerName = trim(($fields['contact_first_name'] ?? '') . ' ' . ($fields['contact_last_name'] ?? ''));
   }
   ```

---

## Feature C — Venue schema migration + edit UI

### C1 — Migration

**File:** `db/migrations/017_venue_practical_fields.sql`

```sql
ALTER TABLE venues
    ADD COLUMN has_stage      TINYINT(1) DEFAULT NULL COMMENT '1=yes, 0=no, NULL=unknown',
    ADD COLUMN haze_allowed   TINYINT(1) DEFAULT NULL,
    ADD COLUMN outside_gig    TINYINT(1) DEFAULT NULL,
    ADD COLUMN use_house_pa   TINYINT(1) DEFAULT NULL;
```

Also add these columns to `db/schema/core.sql` in the `venues` table block.

Apply to dev: `make migrate-dev FILE=db/migrations/017_venue_practical_fields.sql`

### C2 — Venue list page

**File:** `src/modules/admin/venues.php` (new)

Standard admin list page following the same pattern as `gigs/list.php`:
- Query: `SELECT id, name, city, distance_from_turku_km, has_stage, haze_allowed, outside_gig, use_house_pa FROM venues WHERE deleted_at IS NULL ORDER BY city, name`
- Table columns: Name, City, Distance (km), Stage, Haze, Outside, House PA, Actions (Edit)
- Boolean columns render as ✓ / ✗ / — (yes / no / unknown)
- "Edit" links to `?module=admin&action=venue_edit&id={id}`
- "New venue" button at top

Register at `src/index.php` under `admin` module: `'venue_list'` and `'venue_edit'` actions.

### C3 — Venue edit form

**File:** `src/modules/admin/venue_edit.php` (new)

GET: render form pre-populated from DB.
POST: validate + UPDATE (or INSERT for new).

Fields:
- `name` — text, required
- `address_line` — text, nullable
- `city` — text, nullable
- `distance_from_turku_km` — float, nullable
- `notes` — textarea, nullable
- `has_stage` — select: Unknown / Yes / No (NULL / 1 / 0)
- `haze_allowed` — same
- `outside_gig` — same
- `use_house_pa` — same

On save, redirect to venue list with a success flash.

Soft delete button (sets `deleted_at`) — show only if no gigs reference this venue.

### C4 — Venue link on gig detail

**File:** `src/modules/gigs/detail.php`

The venue name in the gig detail view is currently plain text. Make it a link to the venue edit page:
```html
<a href="?module=admin&action=venue_edit&id=<?= $gig['venue_id'] ?>"><?= htmlspecialchars($gig['venue_name']) ?></a>
```

Also add a read-only row beneath the venue name showing the practical fields (has_stage, haze_allowed, outside_gig, use_house_pa) if any are set — as small badges.

---

## Feature D — Venue fuzzy lookup in GigCreator

**Goal:** prevent duplicate venue rows when an inquiry names the same venue slightly differently (different inflection, typo, abbreviated name).

**File:** `src/modules/agent/lib/GigCreator.php`

Current code (around line 107) does an exact name+city lookup before INSERT. Replace with a two-stage fuzzy match:

### Stage 1 — Exact (existing)
```sql
SELECT id, distance_from_turku_km FROM venues
WHERE name = ? AND (city = ? OR city IS NULL) AND deleted_at IS NULL
```

### Stage 2 — Fuzzy (new, only if stage 1 returns nothing)
Load all venues for the same city (or all venues if city is null), then use PHP's `similar_text()` to find the best match:

```php
$candidates = /* SELECT id, name, distance_from_turku_km FROM venues WHERE city = ? AND deleted_at IS NULL */;
$bestId    = null;
$bestScore = 0;
foreach ($candidates as $row) {
    similar_text(mb_strtolower($venueName), mb_strtolower($row['name']), $pct);
    if ($pct > $bestScore) { $bestScore = $pct; $bestId = $row; }
}
if ($bestScore >= 80.0) {
    // use $bestId as the matched venue
}
```

Threshold: **80%** similarity. If no match above threshold, INSERT new venue as before.

Log a warning (`error_log`) when a fuzzy match is used, including both names and the score, so edge cases can be reviewed.

Feature B (nominative normalisation) reduces the need for this fuzzy stage but does not eliminate it — abbreviations and brand-name variations will still occur.

---

## Feature E — Default lineup auto-fill

**Goal:** one-click button on a confirmed gig detail page to insert the 6 standard musicians with null fees.

**Default lineup** (current, as of post-2023):
| Username | Role |
|---|---|
| `tuomas.lundberg` | `keyboards` |
| `toni.puttonen` | `sound_engineering` |
| `joni.virtanen` | `drums` |
| `lauri.lehtinen` | `guitar` |
| `alina.kangas` | `vocals` |
| `mortti.markkanen` | `bass` |

### New endpoint: `src/modules/gigs/personnel_fill_default.php`

Accepts POST with `gig_id`. Guards:
- Gig must exist, not deleted
- Gig status must be `confirmed`
- `gig_personnel` must have 0 rows for this gig (refuse if already populated to avoid duplicates)

Logic:
```sql
SELECT id FROM users WHERE username IN (...) AND deleted_at IS NULL
-- for each found user:
INSERT INTO gig_personnel (gig_id, user_id, role, fee_cents) VALUES (?, ?, ?, NULL)
-- ON DUPLICATE KEY IGNORE (gig_id + user_id unique constraint)
```

On success: redirect back to gig detail with `?filled=1`.
On error: redirect back with `?fill_error=1`.

### Gig detail changes (`src/modules/gigs/detail.php`)

Show the button only when `status = 'confirmed'` AND `gig_personnel` count for this gig is 0:

```html
<form method="post" action="?module=gigs&action=personnel_fill_default">
    <input type="hidden" name="gig_id" value="<?= $gig['id'] ?>">
    <button class="btn btn-outline-secondary btn-sm">Fill default lineup</button>
</form>
```

Show a dismissible alert when `?filled=1` is in the URL.

---

## Feature F — Gig list filters

**File:** `src/modules/gigs/list.php`

Add the following filter inputs to the existing filter bar (alongside the existing status and search inputs):

### New inputs

| Input | GET param | Type | SQL condition |
|---|---|---|---|
| Event date from | `date_from` | date | `g.gig_date >= :date_from` |
| Event date to | `date_to` | date | `g.gig_date <= :date_to` |
| Channel | `channel` | select | `g.channel = :channel` |

Channel ENUM values (from schema): `website`, `email`, `phone`, `referral`, `saturday_band`, `other` — plus "All" as the default empty option.

All three inputs are optional; omit the WHERE clause fragment if the value is empty.

Preserve all active filter values in pagination links (append to the `?page=N` URL).

Validation: `date_from` / `date_to` must match `YYYY-MM-DD` format or be ignored. Channel must be in the allowed ENUM list or be ignored.

---

## Verification checklist

- [ ] Map at `/admin/geocode-musicians` shows all geocoded musicians as labelled pins
- [ ] Submit a test inquiry with inflected venue name (e.g. "Hintsan Vintillä") → stored as "Hintsan Vintti"
- [ ] `customer_name` falls back to contact name when AI returns null
- [ ] Venue list at `/admin/venues` shows all venues
- [ ] Venue edit form saves all fields including the four boolean fields
- [ ] Venue name in gig detail links to edit form
- [ ] Submitting two inquiries for the same venue (one slightly different name) → same venue_id on both gigs
- [ ] "Fill default lineup" button visible on a confirmed gig with no personnel; inserts 6 rows
- [ ] Button absent on inquiry-status gigs and on gigs that already have personnel
- [ ] Gig list date-range and channel filters narrow results correctly; filter state survives pagination

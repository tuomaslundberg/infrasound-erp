# GitHub Issues — Draft

Copy each section below as a separate GitHub Issue.
Title is the H2 heading; body is everything under it.

---

## fix: ETL merge bug — duplicate gigs from gig-invoicing.xlsx

### Context

`cli/etl/extract_gigs.py` merges two sources:
- `old-files/info/gigs-YYYY.xlsx` — the main booking tracker (rich metadata)
- `old-files/gig-invoicing.xlsx` — the invoicing ledger (date + customer + fee)

The intended behaviour is: if an invoicing record matches a tracker record
(same gig, within ±3 days, name similarity ≥ 0.75), propagate the fee to the
tracker row and discard the invoicing row. Unmatched invoicing records are
imported as additional `delivered` gigs.

The reported bug is that multiple duplicate records appear in the DB for the
same real-world gig — one sourced from `gigs-YYYY.xlsx` and one from
`gig-invoicing.xlsx` (with `notes` containing "no matching gigs-YYYY record"),
suggesting the fuzzy-merge is silently failing to match records that should match.

### Suggested investigation

1. Open `cli/etl/extract_gigs.py` and read `_is_gig_match()` (the matching
   predicate) and the merge loop in `main()` (section 2 of the script).
2. Check whether the issue is in `_normalise_name()` (strip/lowercase/unicode
   normalisation) or in the ±3-day date window.
3. Add a `--debug-unmatched` flag that prints each unmatched invoicing record
   alongside the closest tracker candidate (name + date) and its similarity
   score. This makes the root cause visible without touching production data.
4. Adjust the matching logic as needed and verify with `--dry-run --stats`.
5. After the fix, regenerate `db/seeds/legacy_gigs.sql` with `make etl-gigs`
   and re-import with `make import-legacy-gigs-prod`.

### Constraints

- Python only; no new dependencies beyond `openpyxl` and stdlib.
- `_is_gig_match()` signature must remain stable (used only internally).
- Do not lower the similarity threshold below 0.70 without a clear rationale
  in the PR description (risk of false positives merging different customers).
- Update CHANGELOG.md under `## [Unreleased]`.

### Acceptance criteria

- [ ] `--debug-unmatched` flag prints unmatched pairs with similarity scores
- [ ] Re-running `make etl-gigs` produces a materially smaller unmatched count
- [ ] No new false-positive merges (different customers collapsed into one)
- [ ] CHANGELOG.md updated

---

## feat: refactor dynamic pricing tier flags to radio group

### Context

The inquiry form (`src/modules/gigs/form.php`) currently has two independent
checkboxes for dynamic pricing:
- Tier 1 — on-season Saturday (May–Sep): +50 € net
- Tier 2 — high-demand date: +75 € net

Tier 2 cannot logically apply without Tier 1 (it is an additional premium on
top). The correct states are therefore:
- Neither tier (off-season or low-demand)
- Tier 1 only (on-season Saturday)
- Tier 1 + Tier 2 (on-season Saturday, high-demand)

This should be a radio group with three options, not two checkboxes.

### Task

1. In `src/modules/gigs/form.php`, replace the two checkboxes with a radio
   group that maps to the same three underlying states. The radio names can
   be `pricing_tier` with values `none`, `tier1`, `tier1_tier2`.
2. On POST, derive `$tier1` and `$tier2` from the single radio value:
   - `none` → tier1=false, tier2=false
   - `tier1` → tier1=true, tier2=false
   - `tier1_tier2` → tier1=true, tier2=true
3. On GET edit, derive the radio value from `$row['pricing_tier1']` and
   `$row['pricing_tier2']`.
4. No schema changes needed (`pricing_tier1` / `pricing_tier2` columns
   remain; only the UI input changes).
5. Update CHANGELOG.md.

### Constraints

- `PriceCalculator::calculate()` in `cli/lib/PriceCalculator.php` must not
  be modified — it already accepts two separate booleans.
- No changes to `cli/process_inquiry.php` or `cli/inquiry-template.yaml`.
- Follow the Bootstrap 5 form-check pattern already used in the file.

### Acceptance criteria

- [ ] Three radio options render correctly on new and edit form
- [ ] Tier 2 is not selectable without Tier 1 (enforced by UI, not just
      server-side)
- [ ] Existing gigs with tier1=true, tier2=false pre-select the "Tier 1"
      option on edit
- [ ] PriceCalculator output unchanged for all three states
- [ ] CHANGELOG.md updated

---

## feat: add persistent notes field to gig detail view

### Context

The `gigs` table already has a `notes TEXT` column and the inquiry form
already writes to it. However, the gig detail page
(`src/modules/gigs/detail.php`) only shows notes if they are non-empty and
does not provide an inline way to add or update them without opening the full
edit form.

### Task

Add a simple notes widget to the gig detail page:

1. Always show the notes section on the detail page (even when empty, with a
   placeholder "No notes yet").
2. Add an inline edit toggle (a small "Edit" link next to the section header)
   that reveals a `<textarea>` and a Save button via Bootstrap `d-none` toggle.
3. The Save button posts to a new route `POST /gigs/{id}/notes` which updates
   only `gigs.notes` for the given ID (single-field PDO UPDATE).
4. Add `POST /gigs/(\d+)/notes` to the route table in `src/index.php`
   (min_role: `owner`).
5. On success, redirect back to `/gigs/{id}` (PRG pattern).
6. Update CHANGELOG.md.

### Constraints

- PDO only; no raw query strings with user input.
- Soft-delete guard: reject update if `deleted_at IS NOT NULL`.
- No new JS files; use inline `<script>` for the toggle if needed.

### Acceptance criteria

- [ ] Notes section visible on all gig detail pages
- [ ] Inline edit toggle shows/hides textarea without page reload
- [ ] Saving updates only `notes`; all other gig fields unchanged
- [ ] Empty notes saved as NULL (not empty string)
- [ ] CHANGELOG.md updated

---

## feat: move sales email templates out of old-files into src/assets/

### Context

Sales email templates live at `old-files/sales/fi/{channel}/{customer_type}/*.txt`.
`old-files/` is gitignored (contains PII from legacy data), which means the
templates are not version-controlled even though they contain no PII and are
needed at runtime by both `cli/lib/TemplateRenderer.php` and
`src/modules/gigs/quote.php`.

### Task

1. Create the directory `src/assets/mail-templates/` and copy the template
   tree there, preserving the `{lang}/{channel}/{customer_type}/{type}.txt`
   path structure:
   ```
   src/assets/mail-templates/fi/mail/weddings/quote.txt
   src/assets/mail-templates/fi/mail/weddings/venue-familiar-quote.txt
   ... (all existing .txt files under old-files/sales/fi/)
   ```
2. Update `cli/lib/TemplateRenderer.php`: change `$this->salesRoot` to point
   to the new location. The constructor currently resolves:
   ```php
   dirname(__DIR__, 2) . '/old-files/sales'
   ```
   Change to:
   ```php
   dirname(__DIR__, 2) . '/src/assets/mail-templates'
   ```
3. Verify that `php cli/process_inquiry.php` still finds templates and that
   the web quote preview (`/gigs/{id}/quote`) still works.
4. Update CHANGELOG.md.

### Constraints

- Do not modify template file contents (Finnish copy is owned by the PO).
- Do not remove the old path from `old-files/` — just add the new canonical
  location; the old path can be cleaned up separately once confirmed working.
- `TemplateRenderer` constructor path change is the only code change needed.

### Acceptance criteria

- [ ] All template files committed under `src/assets/mail-templates/`
- [ ] `TemplateRenderer` resolves templates from new path
- [ ] CLI smoke test passes: `php cli/process_inquiry.php cli/inquiry-template.yaml --output=email`
- [ ] Web quote preview works for at least one gig
- [ ] CHANGELOG.md updated

---

## feat: fix Markdown links in sales email templates

**Depends on:** "move sales email templates out of old-files into src/assets/"
(templates must be in VCS before editing them)

### Context

Some sales email templates contain bare URLs, e.g.:

```
Spotifysta löydät meidät täältä: https://open.spotify.com/artist/...
```

The intended format for Markdown-aware clients is:

```
[Spotify](https://open.spotify.com/artist/...)
```

### Task

1. Review all `.txt` files under `src/assets/mail-templates/` (after the
   template migration above).
2. For each bare URL that has a natural link text in the surrounding sentence,
   rewrite as a Markdown link `[text](url)`.
3. For bare URLs with no natural anchor text, wrap as `[url](url)`.
4. Do not change any other copy.
5. Update CHANGELOG.md.

### Constraints

- Finnish copy must remain grammatically correct after the change.
- No code changes required — templates are plain text files.

### Acceptance criteria

- [ ] No bare `https://` URLs remain in templates (unless intentional)
- [ ] CHANGELOG.md updated

---

## feat: import quote/customer folder history into DB

### Context

`old-files/future-gigs/` and `old-files/future-gigs/quotes/` contain filled
`gig-info-*.txt` files for confirmed/recent gigs with richer data than the
Excel tracker: contact email/phone, order description, set count, and song
requests.

This data is not currently in the DB — the ETL script only reads the Excel
files.

### Task

This issue requires specification before implementation. The PO should:
1. Identify which fields in the `.txt` files are worth importing (at minimum:
   contact email, phone, order description, song requests).
2. Confirm which gig-info files map to records already in the DB (by date +
   customer name) vs. represent new records.
3. Decide whether to extend `cli/etl/extract_gigs.py` with a new source
   loader, or write a separate `cli/etl/extract_gig_folders.py`.

Once specified, the implementation follows the same pattern as the existing
ETL: parse → normalise → match to existing DB rows → emit idempotent SQL.

### Acceptance criteria

- [ ] Spec comment added to this issue before implementation begins
- [ ] Contact email/phone populated for gigs that have them in the .txt files
- [ ] Song requests imported into `song_requests` table
- [ ] Re-running is idempotent (no duplicate song_requests rows)
- [ ] CHANGELOG.md updated

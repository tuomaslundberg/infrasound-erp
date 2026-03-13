# GitHub Issues — Draft

Copy each section below as a separate GitHub Issue.
Title is the H2 heading; body is everything under it.
Struck-through sections are already merged and can be skipped.

---

## feat: gig list filter, sort, search, and pagination

### Context

`src/modules/gigs/list.php` currently fetches all non-deleted gigs in a single
`SELECT … ORDER BY gig_date ASC` query and renders them in a flat table.
With a growing number of gigs (legacy data already imported), the list needs
filtering, sorting, and search to be usable day-to-day.

### Task

Extend `list.php` to support the following, all via GET query parameters
(no JS required — plain form submit or anchor links):

1. **Status filter** — a row of buttons or a `<select>` above the table for:
   `all` (default) / `inquiry` / `quoted` / `confirmed` / `delivered` /
   `cancelled` / `declined`. Appends `?status=…` to the URL.

2. **Sort** — clicking a column header toggles `ASC`/`DESC` on that column.
   Supported sort columns: `gig_date` (default ASC), `customer_name`,
   `quoted_price_cents`. Use `?sort=column&dir=asc|desc` query params.
   Render a small arrow indicator (▲/▼) next to the active sort column header.

3. **Customer search** — a text input above the table that filters on
   `customers.name LIKE ?` (case-insensitive). Appends `?q=…` to the URL.
   Trim and sanitise with PDO bound parameter; never interpolate into SQL.

4. **Pagination** — show 25 rows per page. Use `?page=N` (1-indexed).
   Show a simple prev/next link row below the table; include total count
   ("Showing 26–50 of 143").

All four controls must coexist: a filtered + searched + sorted result set
is paginated correctly. The SQL query must use a `COUNT(*)` subquery or
second query for the total, and `LIMIT`/`OFFSET` for the page slice.

All query parameters must be read via `$_GET` and validated/cast before use;
sort column must be whitelisted against an allowed list before interpolating
into the query.

### Acceptance criteria

- [ ] Status filter works; `all` shows all statuses
- [ ] Sort by date, customer name, and price; direction toggles on re-click
- [ ] Customer search filters correctly; SQL injection not possible
- [ ] Pagination shows 25 rows per page with correct total count
- [ ] All four controls compose correctly (filter + search + sort + page)
- [ ] No raw user input interpolated into SQL
- [ ] CHANGELOG.md updated

---

## feat: show full pricing inputs on gig detail page

### Context

The **Pricing** card on `src/modules/gigs/detail.php` currently shows only
four fields: Quoted price, Distance (Turku), Car 1 trip, Other travel.

The `gigs` table has eight pricing-input columns (added in migration 003):
`pricing_tier1`, `pricing_tier2`, `qty_ennakkoroudaus`, `qty_song_requests_extra`,
`qty_extra_performances`, `qty_background_music_h`, `qty_live_album`,
`discount_cents`. These are not rendered anywhere on the detail page, forcing
the owner to open the edit form to verify what inputs produced a given price.

### Task

Extend the Pricing card in `detail.php` to also display the pricing inputs.
The SELECT in `detail.php` already uses `g.*` so all columns are available.

Add a second `<dl>` block (or extend the existing one) below the current four
rows:

| Label | Value |
|---|---|
| Dynamic pricing | "None" / "Tier 1 (+50 € net)" / "Tier 1 + 2 (+125 € net)" |
| Ennakkoroudaus | qty × 200 € (or `—` if 0) |
| Extra song requests | qty × 100 € (or `—` if 0) |
| Extra performances | qty × 100 € (or `—` if 0) |
| Background music | qty h × 300 € (or `—` if 0) |
| Live album | qty × 300 € (or `—` if 0) |
| Discount | amount in € (or `—` if 0) |

Zero-value quantities should render as `—`, not `0`, to reduce visual noise.
Pricing tier derives from `pricing_tier1`/`pricing_tier2` boolean columns.

### Acceptance criteria

- [ ] All seven pricing inputs visible on the detail page without opening edit
- [ ] Pricing tier shown as a human-readable label
- [ ] Zero quantities render as `—`
- [ ] Monetary values formatted as `X,XX €` (consistent with existing fields)
- [ ] CHANGELOG.md updated

---

## ~~feat: personnel assignment UI on gig detail page~~ ✅ merged

---

## ~~feat: musician read-only gig view~~ ✅ merged

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

# GitHub Issues — Draft

Copy each section below as a separate GitHub Issue.
Title is the H2 heading; body is everything under it.

---

## feat: personnel assignment UI on gig detail page

### Context

`db/migrations/004_gig_personnel.sql` added the `gig_personnel` table
(`gig_id`, `user_id`, `role` ENUM, `fee_cents`, `confirmed_at`).
The `users` table already exists with a `role` ENUM that includes `musician`.

The gig detail page (`src/modules/gigs/detail.php`) currently shows no
personnel information. Musicians need to be assignable to gigs from this page.

### Task

Add a **Personnel** card to the gig detail page:

1. **Current lineup** — query `gig_personnel JOIN users` and display a table
   with columns: Username, Role, Fee (€), Confirmed. If empty, show a
   placeholder row.
2. **Add musician form** — a small inline form with:
   - `<select>` of active users (non-deleted, role `musician` or higher)
   - `<select>` of roles matching the ENUM: `vocalist`, `guitarist`, `bassist`,
     `drummer`, `keyboardist`, `other`
   - Fee field (number input, euros; stored as eurocents)
   - Submit → POST to `/gigs/{id}/personnel` (new route)
3. **Remove** — a Remove button per row → POST to
   `/gigs/{id}/personnel/{user_id}/remove`
4. New routes (both `owner` minimum role):
   - `POST /gigs/(\d+)/personnel` → `modules/gigs/personnel_add.php`
   - `POST /gigs/(\d+)/personnel/(\d+)/remove` → `modules/gigs/personnel_remove.php`

Fee input is in euros (decimal); multiply × 100 before storing.
All DB writes via PDO prepared statements.

### Acceptance criteria

- [ ] Personnel card visible on gig detail page
- [ ] Add form inserts a `gig_personnel` row; duplicate (same user) rejected
      gracefully (flash notice, no 500)
- [ ] Remove deletes the row (hard delete is acceptable — no soft-delete
      requirement for personnel assignments)
- [ ] Fee stored as eurocents, displayed as `X,XX €`
- [ ] No raw query strings with user input
- [ ] CHANGELOG.md updated

---

## feat: musician read-only gig view

### Context

The `users` table has a `musician` role. Musicians should be able to log in
and see a read-only list of their upcoming gigs without having access to
pricing, customer contact details, or management controls.

### Task

1. **Musician gig list** (`GET /musician/gigs`) — shows only gigs where the
   logged-in user has a row in `gig_personnel` and `gig_date >= CURDATE()`,
   ordered by `gig_date ASC`. Columns: Date, Customer (first name only for
   weddings / company name for companies), Venue name + city, Role, Status.
2. **Musician gig detail** (`GET /musician/gigs/{id}`) — read-only card with:
   - Date, venue name + address + city
   - Order description (set count / duration)
   - Stage contact name + phone (from `contacts` joined via `gigs.contact_id`)
   - Song requests (artist + title, first-dance flag)
   - Own role and fee for this gig
   - **Not shown**: quoted/base price, other personnel fees, customer email,
     channel, pricing inputs
3. Add routes to `src/index.php` with minimum role `musician`:
   - `GET /musician/gigs`
   - `GET /musician/gigs/(\d+)`
4. Add a nav item visible only to users with role `musician` (hide the main
   gig management nav from musicians).

### Acceptance criteria

- [ ] `musician`-role user can log in and reach `/musician/gigs`
- [ ] List shows only gigs the musician is assigned to, upcoming only
- [ ] Detail page shows venue, contact, song requests, own role/fee
- [ ] Pricing fields (quoted price, base price, cost inputs) are **not** rendered
- [ ] `owner`/`admin` visiting `/musician/gigs` also works (they pass the
      `musician` role check); no need to restrict upwards
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

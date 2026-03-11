# GitHub Issues — Draft

Copy each section below as a separate GitHub Issue.
Title is the H2 heading; body is everything under it.

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

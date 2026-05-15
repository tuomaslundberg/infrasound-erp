# Infrasound ERP — Broad-strokes roadmap

*High-level sprint sequence toward full production readiness.*
*Granular tasks are in `TODO.md`; this document tracks ordering and dependencies.*
*Last updated: 2026-05-15*

---

## Sprint sequence

### 1. Phase 4 polish implementation
**Status:** ready — fully specced in `PHASE4_SPEC.md`

Features A–G + Car 2 trip display in gig Pricing card (added to spec).
Branch: `feat/phase4-polish` from `dev`.
Do B before C (nominative normalisation feeds into fuzzy venue lookup); G is independent.

---

### 2. Phase 5 setlist builder spec
**Status:** needs spec

Scope is the **web-based setlist builder UI**, not the analytics CLI (already shipped in
`cli/etl/analyze_setlists.py`). Per current TODO.md:
- Reactive edit view — reorder songs without full-page reload
- Song search from global repertoire + suggestions in the add-song flow

Write a `PHASE5_SPEC.md` covering these two features before implementation.

---

### 3. Phase 5 setlist builder implementation
**Status:** follows spec from sprint 2

---

### 4. Comprehensive Dropbox review
**Status:** open-ended — no spec yet

Goal: confirm that all data worth migrating has been identified and either loaded or specced
for ETL. Check `old-files/` and the live Dropbox workspace directory for any files not yet
examined (invoicing, contracts, correspondence, etc.).

Output: either "nothing new found" or a short ETL spec for newly discovered sources.
Done before the venue corpus ETL so that any new data sources can be included in a single
coherent ETL pass rather than discovered too late.

---

### 5. Venue corpus ETL (venuu.fi)
**Status:** fully specced — `cli/etl/VENUES_ETL_SPEC.md`

This is the only remaining specced-but-unimplemented ETL. All other ETLs (songs, setlists,
Spotify, invoicing) are already loaded in dev. Start with the pre-crawl checklist in the spec
(robots.txt, URL structure, category taxonomy) before writing the crawler.

If the Dropbox review (sprint 4) surfaces additional data sources, spec and implement those
alongside or after this sprint before moving to Phase 6.

---

### 6. Bookkeeping and invoicing features (Phase 6–7)
**Status:** schema written (`015_bookkeeping_schema.sql`, `016_documents_schema.sql`) —
not yet applied to dev or prod

Phase 6: outgoing invoice generation, invoice list + status tracking, incoming invoice log.
Phase 7: Tappio `.tlk` import, `.nda` bank statement import, partner credit seed, ledger view, CSV export.

**Migration note:** migrations 015+016 go to prod as part of their feature release (with the
feature branch). They are NOT part of the legacy data ETL run in sprint 11.

Phase 7 ETL scripts (Tappio, .nda parser) need to be built and debugged against real data.
Context: `cli/etl/BOOKKEEPING_CONTEXT.md`.

---

### 7. Quote mail exchanges + pre-ERP inquiry backlog import
**Status:** partially scoped — needs Tuomas review pass before ETL spec

**Index:** `tarjoukset.txt` in Dropbox workspace/saturday/ — curated list of historical
quote/inquiry records. Tuomas must review and annotate before ETL runs.

**Content sources (in order of completeness):**
- `tarjoukset.txt` index → parse + fuzzy-match against existing gigs
- buukka-bandi.fi band page → cURL to extract booking form submissions
- ProtonMail (saturday@infrasound.fi) → manual export or integration for thread bodies
- iOS Messages → edge cases; handle manually

**Approach:** parse index → fuzzy-match against DB gigs by date+customer → LLM triage
(ignore / historical record / active inquiry) → human review queue → bulk insert.
Dropbox + buukka-bandi resolves ~99% of cases.

---

### 8. Bug fix sweep
**Status:** ongoing

After features are in place, sweep for accumulated bugs before prod deployment. Prioritise
anything that affects price calculation, route calculation, or gig state transitions.

---

### 9. Deep review: security, adherence to principles, UI
**Status:** pre-prod gate

Multi-aspect review pass:
- Security: OWASP top 10, input validation, auth guards, no secrets in code
- Principles: soft-delete everywhere, eurocents everywhere, PDO only, UTC timestamps
- UI: visual consistency, mobile usability, error states
- AGENTS.md compliance

---

### 10. New Dropbox snapshot → old-files/
**Status:** pre-prod gate

Take a fresh snapshot of the live Dropbox and replace `old-files/`. This snapshot seeds
the prod ETL run. Do not run prod ETL from a stale snapshot.

---

### 11. Production-grade ETL run to prod DB
**Status:** final data migration

Full sequence (songs, setlists, Spotify, invoicing legacy data + venue corpus). Sequence
documented in `dev-log.md` most recent sprint-close entry. Update that sequence as ETL
scripts are finalized during sprints 4–7.

**Not included here:** schema migrations (applied with their feature branches).

Prerequisite: prod musician geocoding pass (`/admin/geocode-musicians`).

---

### 12. System verification, testing, fixing
**Status:** post-prod

End-to-end smoke tests: inquiry submission → gig creation → quote email → status transition
→ invoice generation. Fix anything that breaks.

---

### 13. Feature backlog planning + future vision
**Status:** post-stable

Go through the "Keep door open" items in `TODO.md`, re-evaluate priority given actual usage,
and plan the next development cycle.

---

## Key spec documents

| Doc | Sprint |
|-----|--------|
| `PHASE4_SPEC.md` | 1 |
| `PHASE5_SPEC.md` | 2 (to be written) |
| `cli/etl/VENUES_ETL_SPEC.md` | 4 |
| `cli/etl/BOOKKEEPING_CONTEXT.md` | 6 |

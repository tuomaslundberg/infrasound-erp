# Bookkeeping & Invoicing Context — ERP Reference

*Written after hands-on exploration of `management/` (Dropbox) and the Tappio `.tlk` format.
Covers: file system structure, document conventions, data flows, and ETL implications for
the future invoicing/bookkeeping module.*

§1 maps the Dropbox `management/` directory structure for reference. **ETL scripts must
use `old-files/` paths** (a curated snapshot of the Dropbox files, some renamed for
clarity). The Dropbox paths below are for human orientation only.

### Dropbox → old-files name mapping (key files)

| Dropbox path | old-files path | Notes |
|---|---|---|
| `financial/varjokirjanpito.xlsx` | `old-files/internal-bookkeeping.xlsx` | Partner credit + hours |
| `financial/keikkapalkat.xlsx` | `old-files/gig-invoicing.xlsx` | Already used by extract_invoicing.py |
| `accounting/ostolaskut/matkalaskut/xlsx/` | *(no current old-files equivalent)* | Copy at prod import time |

**Prod import protocol**: when the actual production DB import begins, do a fresh manual
copy from Dropbox → old-files (clearing and re-importing), preserving the renamed
paths above. This ensures all ETL scripts remain path-consistent and never touch live
Dropbox data directly.

All Dropbox paths below are relative to
`/Users/primeo/Library/CloudStorage/Dropbox/management/`
unless otherwise noted.

---

## 1. Directory structure

```
management/
├── accounting/
│   ├── alv/                    # Quarterly VAT spreadsheets
│   │   └── {yy}q{q}/           # e.g. 26q1/
│   │       ├── infrasound-oy-alv-laskelma-{yy}q{q}.xlsx  (source)
│   │       ├── infrasound-oy-alv-laskelma-{yy}q{q}.pdf   (export)
│   │       └── Arvonlisaveroilmoitus_*.pdf                (Verovirasto receipt)
│   ├── matkalaskukuitit/       # Travel expense receipts (fuel, parking, etc.)
│   │   └── {yy}/               # Fuel receipts etc.; used for travel invoice backing
│   ├── muut/                   # Misc accounting docs (year-end declarations, etc.)
│   ├── myyntilaskut/           # Outgoing sales invoices
│   │   ├── {yyyy}/             # PDF copies, named {number}-{yymmdd}-{slug}.pdf
│   │   │   └── xlsx/           # XLSX source files (same naming)
│   │   └── tulevat/            # Draft invoices for future gigs; xxx- prefix, xlsx only
│   ├── ostolaskut/             # Purchase invoices (PDF)
│   │   ├── {yyyy}/             # Regular vendor/subscription invoices, by year
│   │   └── matkalaskut/        # Partner travel invoices (own subfolder, not by year)
│   │       ├── {number}-{yymmdd}-{person-slug}-signed.pdf   (signed PDF copy)
│   │       └── xlsx/           # XLSX source files (same base name, no "-signed")
│   ├── tappio/                 # Tappio fiscal year files
│   │   ├── Rainland_2019_2020.tlk
│   │   ├── Infrasound_2021.tlk
│   │   └── infrasound-oy-{yyyy}.tlk   # 2022–2026 confirmed
│   ├── tilinpaatos/            # Annual accounts, by two-digit year
│   │   └── {yy}/               # Seven standard Tappio exports + tax decision PDFs
│   └── tiliotteet/             # Bank statements, by year
│       └── {yyyy}/
│           ├── {yymm}-infrasound-oy-tiliote.pdf
│           └── nda/            # Machine-readable Nordea TITO format
│               └── {yymm}-infrasound-oy-tiliote.nda
├── financial/                  # Live operational spreadsheets (not official bookkeeping)
│   ├── keikkapalkat.xlsx       # Live gig payments tracker (successor to old-files/gig-invoicing.xlsx)
│   ├── varjokirjanpito.xlsx    # Shadow bookkeeping (see §3)
│   ├── mikael-siirtovelat.xlsx # Mikael's running deferred payment balance
│   ├── maksetut-vuokrat-orikedonkatu-22-b-{yy}.xlsx  # Paid rents to Tribo per year
│   ├── oy-tavarainventaario.xlsx  # Company equipment inventory
│   ├── poikkeus-keikkapalkat.xlsx # Exception gig payments
│   ├── vuokralaskelma.xlsx     # Rent calculation sheet
│   ├── {yyyy}-kululaskelma.xlsx   # Annual expense summary
│   └── archive/                # Older financial worksheets
├── templates/
│   ├── alv-laskelma-pohja.xlsx    # VAT spreadsheet master template
│   ├── matkalaskut/               # Three travel invoice templates
│   │   ├── matkalasku-template-keikka-ajot.xlsx        # Gig driving
│   │   ├── matkalasku-template-majoitus-ja-julkiset.xlsx  # Accommodation + public transport
│   │   └── matkalasku-template-muut-ajot.xlsx          # Other driving
│   ├── sopimukset/                # Legal contract templates (rental, inventory, loan)
│   └── muut/                      # Board meeting minutes, POA templates
├── print-buffer/               # Staging zone: print-ready outbound documents
│   └── uudet/                  # Inbound invoices not yet correctly named/filed
└── scan-buffer/                # Staging zone: inbound paper scans before filing
```

---

## 2. Invoice conventions

### Naming format

```
{invoice_number}-{yymmdd}-{customer-slug}.{pdf|xlsx}
```

- `invoice_number`: globally sequential integer across all years (currently at 262 as of May 2026)
- `yymmdd`: date of invoice issuance (payment-based: recorded when money moves)
- `customer-slug`: kebab-case customer or counterparty name
- Dual format: XLSX is the editable master; PDF is the export for sending/printing

### Invoice number sequencing

The number is a single global sequence — not reset per year. The ERP must maintain and advance
this counter. Draft future invoices use `xxx` as the placeholder prefix (filed in `tulevat/`)
and are renamed when finalised.

### Invoice types in `myyntilaskut/`

Three distinct document types coexist in the same folder structure:

1. **Client gig invoices** — charged to event organisers/wedding clients for gig services.
   Revenue account 3000 (keikkapalvelut, 14 % VAT) or 3050 (travel recharge, 14 % VAT).

2. **Musician/artist fee invoices** — purchase invoices from musicians billed back to
   Infrasound for their gig fees (typically via Suomen Keikkalasku or their own company).
   These arrive as inbound invoices filed under `ostolaskut/`. Account 4410 on the cost
   side (VAT-exempt). Outgoing invoices in this category do NOT exist — Infrasound pays
   musicians directly from internal credit tracking.

3. **Miscellaneous sales invoices** — sporadic, non-gig revenue: sale of old inventory,
   one-off PA/sound system rentals, mixing gigs, etc. Account codes vary by type.

4. **Rehearsal room rental invoices** — to sub-tenants of Orikedonkatu 22 B.
   Account 3760 (vuokratuotot, 25.5 % VAT).

### Draft / future invoices (`tulevat/`)

XLSX-only files named `xxx-{yymmdd}-{slug}.xlsx`. These correspond to upcoming confirmed gigs.
When the gig is delivered and payment is expected, the invoice is finalised (number assigned,
PDF exported) and moved to the year folder. The ERP's `gigs.quoted_price_cents` feeds this step.

---

## 3. Partner credit balance mechanics

### 3.1 Overview

The four partners (Tuomas, Toni, Joni, Lauri) accumulate credits from three sources and
are paid out irregularly. The running balances are tracked in `financial/varjokirjanpito.xlsx`.

**Five mechanisms that affect the tilit sheet credit balance:**

1. **Hourly earnings** (primary) — hours × rate from `tuntikrjp` sheets. This is the
   primary mechanism that determines actual outpayment capacity; the goal is to be able
   to pay these out. Could be a running total in the final ERP rather than batch entries.

2. **Gig earnings** — currently ~50% of each partner's calculated gig split; the
   remainder stays inside the firm. Sourced from `keikkapalkat.xlsx`.

3. **Purchases credited to partners** — when a partner purchases company equipment that
   is so specific to them that the internal policy is to deduct the net (ex-VAT) amount
   from their credit. Source: `oy-tavarainventaario.xlsx` rows (see §3.5). The purchase
   is still VAT-deductible for Infrasound Oy; only the internal allocation changes.

4. **Other earnings** — none logged in the live document currently.

5. **Ad-hoc adjustments** — none logged in the live document currently.

**What does NOT affect partner credit**: travel reimbursements (matkalaskut payments)
are pure reimbursements for actual expenses incurred — not compensation. They pass
through the books as cost items (km/päiväraha/etc.) without touching the credit balance.

**Current balances** (as of latest update in `tilit` sheet):

| Partner | Credit balance |
|---------|---------------|
| Tuomas  | +619.83 €     |
| Toni    | −1 069.07 €   |
| Joni    | −736.82 €     |
| Lauri   | −810.89 €     |

Negative balances mean the partner has already received more cash than they've earned
to date under the current tracking model (over-advances or unlogged earnings). These
are informal internal figures, not legal debt.

### 3.2 `old-files/internal-bookkeeping.xlsx` sheet structure

This file is the old-files snapshot of `management/financial/varjokirjanpito.xlsx`
(renamed for clarity). ETL scripts must reference the `old-files/` path.

| Sheet | Content |
|-------|---------|
| `tuntikrjp 23/24/25/26` | Hourly work log: work ID, date, start/end, duration, per-partner presence markers, category, notes |
| `tilit` | Partner credit account: batched gig wages, hourly wages by period, individual payments made, running credit balances |
| `matkalaskut` | Per-trip mileage tracker: ID, date, km, amount, paid flag — four columns (Tuomas/Toni gig/non-gig) |
| `matkaloki` | Raw driving log: odometer start/end, km, route, description — cross-referenced by ID with `matkalaskut` |
| `myynnit-tuntikrjp` | Sales time tracking: Tuomas's sales calls logged with resulting gig customer + outcome statistics |

Work categories (tuntikrjp): `hallinto`, `taloushallinto`, `live`/`live-tekniikka`,
`ylläpito`, `markkinointi`, `myynti`, `tuotanto/studiotyö`, `treeniksen huolto`.

### 3.3 Gig earnings model (`financial/keikkapalkat.xlsx`)

Single sheet, 102 rows × 87 columns. Column layout mirrors `old-files/gig-invoicing.xlsx`
with every-other-column spacer pattern. Right-side columns track per-person payment history.

**Fee split formula:**
```
miksaajan_palkkio  = 0.10 × palkkio_alv       # 10 % of net to sound engineer
soittajien_palkkio = palkkio_alv − miksaajan_palkkio − kulut
```

Each partner's individual share is calculated from `soittajien_palkkio` divided by the
number of active musicians on that gig (determined by non-zero fee columns).

**Running credit totals** (row 1 summary):
```
MIKSAUSKREDIITIT YHTEENSÄ  8 121.87 €   (Toni's sound engineering earnings)
MIKAEL KREDIITIT            2 436.76 €
```
Partners' gig credit totals are in `tilit` sheet, not row 1 of keikkapalkat.

### 3.4 Mikael's deferred payment balance

Mikael is not a partner but accumulates gig earnings tracked in the same way as partners.

**Live running total**: `MIKAEL KREDIITIT` row in `financial/keikkapalkat.xlsx` — 2 436.76 €
as of latest update. This is the authoritative current balance.

**`financial/mikael-siirtovelat.xlsx`** is a historical snapshot, not a live tracker. It
was used when the siirtovelka was first moved to the books (as a year-end Tappio accrued
liability entry). It shows the accumulated unpaid balance with individual payment history,
but its running total reflects a past point in time.

**Year-end Tappio entry**: the siirtovelka event debits account 4410 (artist fees, VAT-exempt)
and credits a liability account. Deductions include Orikedonkatu room rent historically
taken from his balance.

### 3.5 Equipment inventory deductions (`financial/oy-tavarainventaario.xlsx`)

Company equipment purchases are logged in the inventory file. When a purchase is so
specific to one partner (by location, personal need, or expressed preference) that it
would create an imbalance between partners, the internal policy is to deduct the **net
(ex-VAT) amount** from that partner's credit balance.

The purchase remains fully VAT-deductible for Infrasound Oy — only the internal credit
allocation changes. This acts as "insurance" against imbalanced purchasing: any partner
can freely buy equipment needed for their work, knowing it will be reflected in their
credit. This mechanism maps to credit type 3 ("Purchases credited to partners") in §3.1.

---

## 4. Travel invoicing flows

### 4.1 Overview

Partners submit travel expense invoices to Infrasound Oy for business travel (gig driving,
equipment hauling, other company errands). The company reimburses them, and these invoices
appear as purchase invoices in `accounting/ostolaskut/matkalaskut/`.

**Who submits**: Primarily Tuomas (monthly batch), occasionally Toni and Lauri.

### 4.2 Invoice format (VSYP Matkalasku template)

Standard Finnish tax authority travel expense form. Fields:
- Person name, IBAN (reimbursement destination)
- Invoice number (global series — shares the same sequence as sales invoices)
- Reference number (`{invoice_number}{single_check_digit}`) — used in bank viitenumero
- Vehicle (registration, make, model)
- Trip purpose + route (route detail in `matkaloki` / Google Maps timeline)
- Trip dates/times and total duration

**Mileage reimbursement:**
- Base rate: 0.55 €/km (2026), 0.59 €/km (2025)
- Supplements: trailer +10 snt/km; each additional passenger +4 snt/km
- Source: Verohallinto annual decision on travel cost reimbursement limits

**Daily allowances (kotimaan päiväraha, if applicable):**
- Kokopäiväraha (>10 h): 44 €
- Osapäiväraha (>6 h): 20 €
- Yömatkaraha: 13 €
- Ateriakorvaus: 11 €

**Other expense categories** (with receipts):
- Majoituskulut (accommodation)
- Taksit, bussit, lennot, junat (public transport — receipts attached)
- Paikoitusmaksut (parking)
- Edustus (representation)
- Muut kulut (other)

**Payment barcode**: virtual reference barcode encoded with IBAN + amount + reference + due date.

### 4.3 Three template types

| Template | When used |
|----------|-----------|
| `matkalasku-template-keikka-ajot.xlsx` | Gig-day driving only |
| `matkalasku-template-muut-ajot.xlsx` | Non-gig driving (equipment runs, admin errands) |
| `matkalasku-template-majoitus-ja-julkiset.xlsx` | Any trip with accommodation or public transport |

Tuomas typically submits monthly invoices covering all driving that month (both gig and
non-gig trips combined into one invoice). The matkaloki tracks each individual trip;
the invoice totals across the period.

### 4.4 Bookkeeping treatment

On the Tappio side, travel invoice reimbursements are purchase entries:
- Mileage: account 7680 (kilometrikorvaukset, no VAT deductible)
- Public transport, accommodation: account 7630 (matkakulut, VAT-deductible at receipt rate)
- Daily allowances: account 7650 (päivärahat, no VAT)

Travel reimbursements are pure cost pass-throughs for the company and do NOT affect
partner credit balances. They are not compensation for work — the partner incurred a
real expense on the company's behalf and is being made whole.

**ETL implication**: travel invoices are purchase invoices from Infrasound's perspective.
Their total amounts appear as inbound NDA bank transactions. The mileage detail lives only
in the XLSX source files and `matkaloki`. Phase 7 ETL will parse the XLSX sources to
reconstruct individual trip-level data; for now, the PDF totals are sufficient for ledger
entries.

---

## 5. Bank statement format (`.nda` / Nordea TITO)

Fixed-width text records, one logical record per line group:

| Record type | Content |
|---|---|
| `T00` | File header: account number, statement period, opening balance |
| `T10` | Transaction: date (`YYMMDD`), amount in eurocents (signed, + = credit, - = debit), counterparty name |
| `T11` | Continuation: reference number, message text |
| `T113` | Continuation: counterparty IBAN, BIC |

Amount sign convention (from the account holder's perspective):
- `+` = money in (credit to bank account)
- `−` = money out (debit from bank account)

One `.nda` file per calendar month. Confirmed present from 2022 onwards.

**ETL opportunity**: the `.nda` files are machine-readable and cover every bank transaction.
They can be auto-parsed to seed ledger events, cross-referenced against invoices by description
pattern matching and amount. This would partially automate the current manual VAT spreadsheet
workflow.

---

## 6. Tappio `.tlk` format — summary

Full details in `cli/etl/tappio_format_notes.md` (generated by Windows Claude session,
2026-05-12). Key points for ERP planning:

- S-expression format, ISO-8859-1 encoding, single continuous line (no newlines)
- Money: eurocents as signed integers; positive = debit, negative = credit
- No invoice number field — counterparty is in the free-text description only
- Payment-based bookkeeping (maksuperusteinen): events recorded at payment date
- VAT rate is implicit from account codes, not a stored field per event
- A single event may mix multiple VAT rates (e.g. 14 % keikka + 25.5 % travel)

**Rate schedule** (must be parameterised by fiscal year):

| Rate | Accounts | Applies from |
|---|---|---|
| 14 % | 3000, 3040, 3050, 3060 → 2938 | through 31 Dec 2025 |
| 13.5 % | same accounts | 1 Jan 2026 onwards |
| 25.5 % | 3010, 3020, 3030, 3760 → 2938 / expense accts → 17631 | current |
| 0 % | 4410 (artist fees), 3990, 3990–3950 | always |

Tappio will be used as legal archive only going forward. Day-to-day entry moves to ERP.
Annual export cycle: ERP → Tappio (once per year, before `tilinpäätös`).

---

## 7. Quarterly VAT process (current manual flow)

Trigger: quarterly deadline (45 days after period end; e.g., Q1 = by ~12 May).

1. Download bank statements (PDF + `.nda`) → `print-buffer/`, copy to `accounting/tiliotteet/{yyyy}/`
2. Scan paper purchase invoices → `scan-buffer/` → `print-buffer/` → `accounting/ostolaskut/{yyyy}/`
3. Download e-invoices (DNA, If Vahinkovakuutus from Nordea netbank) + web invoices
   (Tribo, Google Ads, Nordea, Webflow, Splice, HP Instant Ink, Aatos, Distrokid, Ring, Dropbox)
   → `print-buffer/` → `accounting/ostolaskut/{yyyy}/`
4. Copy VAT template → `accounting/alv/{yy}q{q}/infrasound-oy-alv-laskelma-{yy}q{q}.xlsx`
5. Edit period headers in spreadsheet (e.g. "OSTOT 1.1.–31.3.2026")
6. Log sales events with VAT to **Myynnit** tab
7. Log rent payments to `financial/maksetut-vuokrat-orikedonkatu-22-b-{yy}.xlsx`;
   deduct from Mikael's balance in `financial/keikkapalkat.xlsx`
8. Log purchase events with VAT to **Ostot** tab
9. Export spreadsheet to PDF → `print-buffer/` + `accounting/alv/{yy}q{q}/...pdf`
10. File manually to Verovirasto OmaVero based on the spreadsheet aggregate totals
11. Print everything in `print-buffer/`, verify all saved elsewhere, clear buffer
12. File physical documents into binders

**Automation opportunity**: steps 1–3 are file management (scriptable). Steps 6 and 8
(log events) are the expensive manual step — addressable once the ERP has a ledger module
and `.nda` auto-import. Step 10 (Verovirasto filing) requires Vero API or manual OmaVero entry.

---

## 8. Key operational spreadsheets

| File | Role | ERP module |
|---|---|---|
| `financial/keikkapalkat.xlsx` | Live gig payments tracker; source for invoicing ETL (updated version of `old-files/gig-invoicing.xlsx`) | Gig management / invoicing |
| `financial/varjokirjanpito.xlsx` | Partner credit balances (hours, travel, gig earnings, historical adjustments) — not a ledger | Partner credit module |
| `financial/mikael-siirtovelat.xlsx` | Mikael's running deferred debt (old gig earnings minus Orikedonkatu rent paid) | Partner credit balance |
| `financial/maksetut-vuokrat-orikedonkatu-22-b-{yy}.xlsx` | Paid rents to Tribo, per year | Rent/expense tracking |
| `accounting/alv/{yy}q{q}/*.xlsx` | Quarterly VAT worksheets | VAT reporting |
| `templates/alv-laskelma-pohja.xlsx` | Master VAT template | VAT reporting |

---

## 9. Open questions / future scope (Phase 6–7)

### Inbound invoice extraction pipeline

Specced in `db/migrations/016_documents_schema.sql` (ETL notes section). Summary:

- **Legacy documents** (migrated from management/): no extraction needed. Their financial
  data already lives in Tappio / spreadsheets. File copy + filename-based index only;
  `extraction_status = 'none'`.

- **New inbound invoices** (ongoing, post-migration): tiered extraction pipeline:
  1. Text extraction (pdftotext/pdfminer) — handles ~80% of cases (machine-generated PDFs)
  2. LLM structured extraction (Claude with `extract_invoice_fields` tool) — for clean text
     that needs interpretation
  3. LLM vision path — for scanned/handwritten/photo receipts
  — All auto-extracted documents land in a human review queue; `extraction_status = 'verified'`
  only after manual confirmation. Journal events sourced from unverified documents are
  flagged as provisional.

- **ERP-generated documents** (sales invoices, etc.): written directly by the invoicing
  module; `extraction_status = 'none'` (no extraction needed).

### Open design decision: extraction pipeline execution model

Two options for triggering the tiered extraction pipeline when a new document is uploaded:

- **Synchronous** — extraction runs during the upload request; simpler, no infrastructure,
  but blocks the UI on slow LLM calls and makes the upload endpoint fragile.
- **Queue-based** — upload stores the file and enqueues a job; extraction runs async;
  document lands in a "pending extraction" state until complete. More robust but requires
  a job runner (cron + DB queue table is sufficient at this scale; no Redis/RabbitMQ needed).

Given the volume (tens of invoices per month, not thousands), a simple DB-backed queue
with a cron-triggered PHP worker is likely sufficient. Decision deferred to Phase 6-7
implementation.

### Remaining deferred items

1. **Vero and Tulorekisteri APIs** — OmaVero machine-to-machine API for VAT filing;
   Tulorekisteri for payroll-adjacent reporting. Both needed for full automation.

2. **Print/scan intake** — `print-buffer/` and `scan-buffer/` as watch folders feeding
   the extraction pipeline. Automatable once the pipeline exists.

3. **Sales invoice generation** — ERP should generate invoice PDFs from structured data
   (gig, price, customer) rather than from XLSX templates.

---

## 10. ETL sequencing implications

| ETL step | Source | Status |
|---|---|---|
| Gig prices + personnel lineups | `financial/keikkapalkat.xlsx` | ✅ done (`extract_invoicing.py`) |
| Document migration (file copy + index) | all `accounting/` subdirs | ❌ future (Phase 6–7, `extract_documents.py`) |
| Partner credit balances (initial seed) | `old-files/internal-bookkeeping.xlsx` (tilit) + `old-files/gig-invoicing.xlsx` | ❌ future (Phase 6–7) |
| Historical ledger events (2021–2025) | `accounting/tappio/*.tlk` | ❌ future (Phase 6–7) |
| 2026 ledger events (current year) | Source docs only; `.tlk` filled at year-end | ❌ future (Phase 6–7) |
| Bank transaction import | `accounting/tiliotteet/*/nda/*.nda` | ❌ future (Phase 6–7) |

The schema for all of the above is now written (migrations 015 + 016). The document
migration (`extract_documents.py`) should run first — its output document IDs are
referenced by subsequent ledger ETL steps.

### Document migration sequencing

The document ETL is a prerequisite for the bookkeeping ETL, not the other way around.
Recommended order for Phase 6–7:

1. `make migrate-dev FILE=db/migrations/015_bookkeeping_schema.sql`
2. `make migrate-dev FILE=db/migrations/016_documents_schema.sql`
3. `extract_documents.py` — copies management/ files → storage/documents/, INSERTs index rows
4. `.tlk` import (`extract_tappio.py`) — links journal events to document IDs
5. `.nda` import (`extract_nda.py`) — links bank transactions to bank statement documents
6. Partner credit seed — reads `old-files/internal-bookkeeping.xlsx` + `old-files/gig-invoicing.xlsx`

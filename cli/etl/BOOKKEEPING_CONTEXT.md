# Bookkeeping & Invoicing Context — ERP Reference

*Written after hands-on exploration of `management/` (Dropbox) and the Tappio `.tlk` format.
Covers: file system structure, document conventions, data flows, and ETL implications for
the future invoicing/bookkeeping module.*

All paths below are relative to
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
│   ├── ostolaskut/             # Purchase invoices (PDF), by year
│   │   └── {yyyy}/
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

2. **Musician fee invoices** — issued to band members for their accumulated gig earnings.
   Appear as quarterly or year-end batches (all dated Dec 31 for the annual reconciliation).
   These are internal compensation documents; the musician's response (their invoice back to
   Infrasound, typically via Suomen Keikkalasku or their own company) becomes an inbound
   purchase invoice filed under `ostolaskut/`. Account 4410 on the cost side (VAT-exempt).

3. **Rehearsal room rental invoices** — to sub-tenants of Orikedonkatu 22 B.
   Account 3760 (vuokratuotot, 25.5 % VAT).

### Draft / future invoices (`tulevat/`)

XLSX-only files named `xxx-{yymmdd}-{slug}.xlsx`. These correspond to upcoming confirmed gigs.
When the gig is delivered and payment is expected, the invoice is finalised (number assigned,
PDF exported) and moved to the year folder. The ERP's `gigs.quoted_price_cents` feeds this step.

---

## 3. Partner accounting (`financial/varjokirjanpito.xlsx`)

Despite the name ("shadow bookkeeping"), this file is **not** a parallel accounting ledger.
It tracks internal partner economics:

- **Hourly bookkeeping** — hours worked by each partner, used to calculate compensation
- **Travel logs** — partner travel for business purposes
- **Partner credit balances** — derived from `keikkapalkat.xlsx` (gig earnings), hourly
  bookkeeping data, and historical ad-hoc decisions (e.g. Mikael's Orikedonkatu rent
  deducted from his old gig earnings balance)

There is no real-time parallel to Tappio's double-entry ledger. The current fiscal year's
accounting events exist only in source documents (bank statements, paper/scanned invoices)
until Tappio is filled at year-end. The 2026 `.tlk` file exists with only the opening
balance entry at time of writing.

**ETL implication**: `varjokirjanpito.xlsx` is the source for partner credit balance data,
not for ledger events. For the bookkeeping ETL, the sources are the `.tlk` files (historical
years) and the raw source documents for the current year.

---

## 4. Bank statement format (`.nda` / Nordea TITO)

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

## 5. Tappio `.tlk` format — summary

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

## 6. Quarterly VAT process (current manual flow)

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

## 7. Key operational spreadsheets

| File | Role | ERP module |
|---|---|---|
| `financial/keikkapalkat.xlsx` | Live gig payments tracker; source for invoicing ETL (updated version of `old-files/gig-invoicing.xlsx`) | Gig management / invoicing |
| `financial/varjokirjanpito.xlsx` | Partner credit balances (hours, travel, gig earnings, historical adjustments) — not a ledger | Partner credit module |
| `financial/mikael-siirtovelat.xlsx` | Mikael's running deferred debt (old gig earnings minus Orikedonkatu rent paid) | Partner credit balance |
| `financial/maksetut-vuokrat-orikedonkatu-22-b-{yy}.xlsx` | Paid rents to Tribo, per year | Rent/expense tracking |
| `accounting/alv/{yy}q{q}/*.xlsx` | Quarterly VAT worksheets | VAT reporting |
| `templates/alv-laskelma-pohja.xlsx` | Master VAT template | VAT reporting |

---

## 8. Areas yet to explore / open questions

The following were flagged as in-scope but not yet explored in detail:

1. **Partner credit balance mechanics** — how gig earnings are tracked per partner,
   how Mikael's rent deduction interacts with his credit balance, how SVOP distributions
   connect to this. `mikael-siirtovelat.xlsx` and `keikkapalkat.xlsx` are the sources.

2. **Travel invoicing flows** — three separate templates exist (gig driving, accommodation
   + public transport, other driving). Understand input fields, calculation rules, and
   when each template is used. Receipts are in `matkalaskukuitit/` and cross-reference
   with `accounting/ostolaskut/`.

3. **Vero and Tulorekisteri APIs** — OmaVero has a machine-to-machine API for VAT filing.
   Tulorekisteri (incomes register) is relevant for any payroll-adjacent reporting.
   Both would be needed for full bookkeeping automation.

4. **Inbound PDF invoice extraction** — purchase invoices arrive as PDFs (scanned paper,
   e-invoices, web downloads). Format varies wildly. LLM-based extraction is the likely
   approach; accuracy and auditability are the key concerns.

5. **Print/scan automation** — `print-buffer/` and `scan-buffer/` as staging zones suggest
   a workflow that could be automated end-to-end (watch folder → parse → file → log).

6. **Internal invoice generation** — sales invoices (client gig invoices, musician fee
   invoices, rental invoices) are currently generated from XLSX templates. The ERP should
   generate these from structured data and export PDF directly.

---

## 9. ETL sequencing implications

| ETL step | Source | Status |
|---|---|---|
| Gig prices + personnel lineups | `financial/keikkapalkat.xlsx` | ❌ pending (invoicing ETL, unblocked) |
| Historical ledger events (2021–2025) | `accounting/tappio/*.tlk` | ❌ future (Phase 6–7) |
| 2026 ledger events (current year) | Source docs only (bank stmts + invoices); `.tlk` filled at year-end | ❌ future (Phase 6–7) |
| Bank transaction import | `accounting/tiliotteet/*/nda/*.nda` | ❌ future (Phase 6–7) |
| Invoice catalogue | `accounting/myyntilaskut/` | ❌ future (Phase 6–7) |

The `.tlk` and `.nda` formats are both well-understood and parseable. The main schema
design work remaining before Phase 6–7 ETL can begin is defining the ERP ledger tables
(`accounts`, `journal_events`, `journal_lines`, `vat_rate_schedule`).

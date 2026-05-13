# Tappio `.tlk` File Format — ETL Reference Notes

**Source file:** `tappio-example.tlk` (fiscal year 2025, Infrasound Oy, 3015709-2)  
**Tappio version:** 0.22  
**Analysed:** 2026-05-12 (read-only)

---

## 1. File Properties

| Property | Value |
|---|---|
| Extension | `.tlk` |
| Encoding | ISO-8859-1 (Latin-1) — Finnish characters use 8-bit code points |
| Line endings | **None** — entire file is a single continuous line (no `\n`, `\r`) |
| Format | **S-expression** (Lisp-like parenthesised syntax) |
| Size (example) | ~69 kB, 451 journal events, one fiscal year |

When reading in PHP/Python you must specify the source encoding explicitly before any string processing. Converting to UTF-8 first is recommended:

```bash
iconv -f latin1 -t utf8 file.tlk > file_utf8.tlk
```

---

## 2. Top-level Structure

```
(identity "Tappio" version "versio 0.22"
  finances
    (fiscal-year "COMPANY NAME (Y-TUNNUS)"
      (date YYYY M D)          ; fiscal year start
      (date YYYY M D)          ; fiscal year end
      (account-map ...)        ; chart of accounts tree
      (                        ; journal — flat list of events
        (event ...)
        (event ...)
        ...
      )
    )
)
```

There is no separate invoice register, accounts-receivable ledger, or balance-sheet section. Everything is encoded as journal events against the chart of accounts.

---

## 3. Account Map (`account-map`)

The chart of accounts is a **recursive tree** of `account` nodes:

```
(account NUMBER "NAME" (CHILDREN...) [VAT-ANNOTATION])
```

- **`NUMBER = -1`** — heading/grouping node only; no postings can be made against it
- **`NUMBER ≥ 0`** — real account; postings use this integer as the account key
- **`CHILDREN`** — nested `(account ...)` list; empty `()` for leaf accounts
- **`VAT-ANNOTATION`** — optional `(vat TYPE RATE)` tag (see §6)

### Account Number Ranges (Finnish chart of accounts convention)

| Range | Section | Type |
|---|---|---|
| 1000–1999 | Vastaavaa (Assets) | Balance sheet — debit normal |
| 2000–2999 | Vastattavaa (Liabilities + Equity) | Balance sheet — credit normal |
| 3000–3999 | Myynti / Tuotot (Revenue) | P&L — credit normal |
| 4000–4999 | Materiaalit ja palvelut (COGS) | P&L — debit normal |
| 5000–6999 | Henkilöstökulut (Personnel costs) | P&L — debit normal |
| 7000–8999 | Liiketoiminnan muut kulut (Operating expenses) | P&L — debit normal |
| 9000–9999 | Rahoitustuotot/-kulut, verot (Finance + Tax) | P&L — mixed |

### Key Accounts for ETL

#### Assets (1xxx)
| Code | Name | Notes |
|---|---|---|
| 1179 | Muut ajoneuvot | Vehicles |
| 1201 | Kalusto ja muu irtain | Equipment & fixtures |
| 1700 | Myyntisaamiset | Accounts receivable |
| 1763 / 17631 | Ostojen alv-saamiset (domestic) | **Input VAT receivable — domestic** |
| 17632 | Ostojen alv-saamiset EU-tavaraostot | **Input VAT — EU goods (reverse charge)** |
| 17633 | Ostojen alv-saamiset EU-palveluostot | **Input VAT — EU services (reverse charge)** |
| 1767 | Maksetut vuokravakuudet | Rent deposits paid |
| 1900 | Käteisvarat | Cash |
| 1910 | FI48 1544 3000 1331 03 | **Main bank account (Nordea)** |

#### Liabilities (2xxx)
| Code | Name | Notes |
|---|---|---|
| 2000 | Osakepääoma | Share capital |
| 2070 | SVOP | Unrestricted equity reserve (SVOP) |
| 2200 | Edellisten tilikausien voitto | Retained earnings |
| 2870 | Ostovelat | Accounts payable |
| 2938 | Arvonlisävero myynneistä | **Output VAT collected** |
| 2939 / 29394 | Alv EU-tavaraostoista | **Output VAT — EU goods reverse charge** |
| 29395 | Alv EU-palveluostoista | **Output VAT — EU services reverse charge** |
| 2977 | Arvonlisäverovelat (siirtovelat) | **Net VAT payable (filed to Verovirasto)** |
| 2979 | Muut siirtovelat | Accrued liabilities (misc) |

#### Revenue (3xxx)
| Code | Name | VAT Rate |
|---|---|---|
| 3000 | Myynti, keikkapalvelut | **14 %** |
| 3010 | Myynti, studiopalvelut | **25,5 %** |
| 3020 | Myynti, laitevuokrat | **25,5 %** |
| 3030 | Muu myynti | **25,5 %** |
| 3040 | Muu myynti | **14 %** |
| 3050 | Myynti, matka- ja paikoituskulut | **14 %** |
| 3060 | Myynti, välityspalkkiot | **14 %** |
| 3760 | Vuokratuotot toimitiloista | **25,5 %** (rehearsal room rent) |
| 3990 | Muut liiketoiminnan muut tuotot | 0 % (penalty income etc.) |

#### COGS / External Services (4xxx)
| Code | Name | VAT |
|---|---|---|
| 4000 | Ostot 25,5 % | 25,5 % input VAT |
| 4030 | Ostot 0 % | 0 % |
| 4410 | Keikkatuotannon palvelut | **0 % (VAT-exempt artists/gig services)** |
| 4420 | Musiikintuottamisen palvelut | — |

---

## 4. Journal Events

```
(event ID (date YYYY M D) "DESCRIPTION"
  (
    (ACCOUNT_CODE (money CENTS))
    (ACCOUNT_CODE (money CENTS))
    ...
  )
)
```

### Fields

| Field | Type | Notes |
|---|---|---|
| `ID` | integer | Sequential, 0-based. Event 0 is always the opening balance (`Avaava tase`). |
| `date` | `(YYYY M D)` | Calendar date. Month and day are **not** zero-padded. |
| `DESCRIPTION` | string | Free-text. No structured invoice number or counterparty ID field. Customer/supplier names are embedded in the description string. |
| `ACCOUNT_CODE` | integer | References a real (non-`-1`) account in the account-map. |
| `money CENTS` | integer | **Eurocents**. Positive = debit, negative = credit (standard double-entry sign convention). |

There is **no invoice number field** in the event structure. Any reference (e.g., `"Suomen Keikkalasku / Eetu Hämäläinen"`) is baked into the description string and must be parsed heuristically.

Multiple lines to the same account within one event are permitted and do occur (e.g., VAT settlements that clear several sub-periods of account 2938 in one event).

---

## 5. Double-entry Sign Convention

| Side | Sign | Typical accounts |
|---|---|---|
| Debit | **positive** | Assets (+), Expenses (+) |
| Credit | **negative** | Liabilities (−), Equity (−), Revenue (−) |

Every event should balance to zero (sum of all `(money ...)` lines = 0). The opening balance (event 0) is an exception — it establishes carried-forward balances.

---

## 6. VAT Encoding

### 6a. Account-level annotation

Some accounts carry an optional `(vat TYPE RATE)` tag in the account-map:

```
(account 4410 "Keikkatuotannon palvelut" () (vat purchase 0))
(account 3760 "Vuokratuotot toimitiloista" () (vat sales 0))
```

- `TYPE` is `sales` or `purchase`
- `RATE = 0` in all observed instances

This annotation is a **Tappio UI hint only** — VAT amounts are never auto-populated by the software. Every VAT figure is entered manually by reading the source invoice. The annotation has no effect on the stored data and can be ignored by the ETL.

### 6b. VAT rate per transaction — implicit encoding

The VAT rate is **not stored as a structured field** on each event. The accounts used indicate the VAT *mechanism* (domestic, EU reverse charge, exempt), and account names embed the applicable rate as of the time the chart of accounts was set up:

1. **Account name suffix** — e.g., `"Myynti, keikkapalvelut 14%"`, `"Myynti, studiopalvelut 25,5%"`. Finnish decimal comma (`25,5` not `25.5`).
2. **VAT accounts present** — the identity of VAT accounts (2938, 17631, 29394, 29395…) indicates the mechanism.

**Do not attempt to back-calculate VAT rates from `|VAT_amount / net_amount|`.** This is unreliable for two reasons:
- A single event can mix multiple VAT rates (e.g., one line at 14 %, another at 25,5 %), with all output VAT summed into a single debit on account 2938. There is no per-rate breakdown in the event.
- Finnish VAT rates change over time. For example, the "food and culture" rate moved from 14 % to 13,5 % at the start of 2026, and some items previously taxed at 10 % were reclassified to 13,5 %. Account names reflect the rate at chart-of-accounts setup time and may lag a legislative change until the chart is updated.

The reliable ETL strategy is: use the **account code** to determine VAT treatment (exempt / domestic / EU reverse charge), and use the **account name** or a separately maintained rate schedule (keyed on account code + date range) to determine the applicable percentage for reporting purposes.

### 6c. VAT Account Roles by Transaction Type

#### Domestic sale with output VAT
```
(event 187 (date 2025 6 23) "Tuomas Gråsten"
  ((1910 (money 203686))        ; Bank debit (gross received)
   (3000 (money -157480))       ; Revenue credit — keikka net (÷1.14)
   (2938 (money -25014))        ; Output VAT credit (2938)
   (3050 (money -21192))))      ; Revenue credit — travel net (÷1.14)
```
VAT check: (157480 + 21192) × 0.14 = 25014 ✓

#### Domestic purchase with input VAT
```
(event 3 (date 2025 1 2) "Vuokra Orikedonkatu 22 B"
  ((1910 (money -39494))        ; Bank credit (gross paid)
   (7230 (money 31469))         ; Expense debit — net (÷1.255)
   (17631 (money 8025))))       ; Input VAT debit (17631)
```
VAT check: 31469 × 0.255 = 8025 ✓

#### EU service purchase (reverse charge, e.g., Google Ads)
```
(event 11 (date 2025 1 2) "Google Ads"
  ((8090 (money 2812))          ; Expense debit — net
   (1910 (money -2812))         ; Bank credit (no VAT in the invoice)
   (17633 (money 717))          ; Input VAT receivable debit — EU services
   (29395 (money -717))))       ; Output VAT payable credit — EU services
```
The self-assessed reverse-charge VAT is symmetrically posted: 17633 (debit) and 29395 (credit) net to zero for cash flow but create reporting entries.

#### EU goods purchase (reverse charge, e.g., Thomann)
Same pattern but uses accounts **17632** (debit) and **29394** (credit).

#### VAT-exempt purchase (e.g., gig production services)
```
(event 203 (date 2025 7 1) "Suomen Keikkalasku / Eetu Hämäläinen"
  ((4410 (money 30000))         ; Expense debit — gross = net (no VAT)
   (1910 (money -30000))))      ; Bank credit
```
No VAT accounts appear. Account 4410 carries `(vat purchase 0)`.

#### Quarterly VAT settlement (filing the ALV return)
```
(event 448 (date 2025 12 31) "Arvonlisäverolaskelma 10-12/2025 Q4"
  ((2938 (money 26352))         ; Clear output VAT collected
   (2938 (money 51962))         ; (may be split across sub-periods)
   (29394 (money 15177))        ; Clear EU goods reverse charge payable
   (29395 (money 6388))         ; Clear EU services reverse charge payable
   (17632 (money -15177))       ; Clear EU goods input VAT receivable
   (17633 (money -6388))        ; Clear EU services input VAT receivable
   (17631 (money -48981))       ; Clear domestic input VAT receivable
   (2977 (money -29333))))      ; Net VAT payable to Verovirasto (credit = liability)
```
The net VAT balance posted to **2977** is then paid in a subsequent event debiting 2977 and crediting 1910 (bank).

### 6d. VAT Rate Summary (rates as of FY 2025)

| Rate | Accounts (sales side) | Accounts (purchase side) |
|---|---|---|
| **14 %** | 3000, 3040, 3050, 3060 → 2938 | — |
| **25,5 %** | 3010, 3020, 3030, 3760 → 2938 | expense accts → 17631 |
| **25,5 % (EU svc)** | — | expense accts → 17633 + 29395 |
| **25,5 % (EU goods)** | — | expense accts → 17632 + 29394 |
| **0 %** | 3990, 3900–3950 | 4410 (artist fees) |

> **Rate history note:** The 14 % rate dropped to 13,5 % from 1 Jan 2026. ETL code that maps account codes to rates must be parameterised by fiscal year, not hard-coded.

---

## 7. Typical Sales Transaction — End-to-End

Infrasound Oy uses **payment-based (maksuperusteinen) bookkeeping**, which is permitted for small companies under Finnish accounting law. Events are recorded at the moment of actual payment, not invoice issuance. There is no separate accounts-receivable module or invoice register in Tappio.

A typical gig payment arriving in the bank account:

```
(event N (date YYYY M D) "CLIENT NAME / gig reference"
  ((1910 (money +GROSS))        ; 1. Bank debit (gross received)
   (3000 (money -NET_KEIKKA))   ; 2. Revenue credit — keikka net
   (3050 (money -NET_TRAVEL))   ; 3. Revenue credit — travel recharge
   (2938 (money -VAT_OUT))))    ; 4. Output VAT credit (all lines combined)
```

Where `GROSS = NET_KEIKKA + NET_TRAVEL + VAT_OUT`.

**Account 1700 (Myyntisaamiset)** appears only in exceptional cases where formal debt collection was required (e.g., a payment reminder / *maksumuistutus* sent to a non-paying client). These are not normal operating entries.

**Cross-year accruals** are avoided as much as possible. Known exceptions:
- Account 2979 (Muut siirtovelat) carries a persistent multi-period rent debt (Mikael Lehto) that spans fiscal years.
- Tax balances (ennakkoverot, alv-velat) are routinely transferred at year-end as required by law.

---

## 8. ETL Import Considerations

### Parsing approach
The `.tlk` format is a subset of S-expressions. A recursive-descent parser or a simple tokeniser (split on `(`, `)`, whitespace, quoted strings) is sufficient. No standard Lisp reader is required.

### Monetary values
- All `(money N)` values are **eurocents as signed integers** — store directly in `INT` / `BIGINT` MariaDB columns.
- No decimal points appear anywhere in the money fields.
- Negative = credit side per double-entry convention.

### Account codes
- Account codes are plain integers. Code **-1** is a heading-only node and must not be imported as a real account.
- Sub-account codes up to 5 digits (e.g., `17631`, `20701`, `29394`) are confirmed. 6-digit codes are theoretically possible in the format but are not expected in Infrasound's chart of accounts in the near term.
- **Architecture note:** Going forward, Tappio will serve as the legal archive and *tilinpäätös* (annual accounts) reporting tool only. Day-to-day ledger entry will move to the ERP. The `.tlk` file will be exported once per fiscal year for archival, making the ETL an annual one-way import (Tappio → ERP).

### Dates
- Event dates use `(date YYYY M D)` with unpadded month/day integers.
- Fiscal year boundaries are given at the top of the file.

### No invoice number field
- The description string is the only counterparty/invoice reference.
- Pattern matching on the description can extract: client name, document type (vuokra, matkalasku, kululasku, keikka), and sometimes a counterparty entity.

### VAT treatment in ETL
- Identify the **mechanism** from the accounts present: domestic (2938 / 17631), EU services reverse charge (17633 / 29395), EU goods reverse charge (17632 / 29394), or exempt (no VAT accounts).
- Do **not** attempt to derive the rate by dividing `VAT_amount / net_amount`. A single event may mix rates, and because Finnish VAT rates have changed across fiscal years, the ratio is ambiguous without knowing the exact legislative period.
- For reporting that requires a rate, maintain a separate lookup table keyed on `(account_code, fiscal_year)` → `vat_rate_percent`. This table must be updated when rates change (e.g., 14 % → 13,5 % from 2026).

### Events to skip / handle specially
| Event type | Identifier | Action |
|---|---|---|
| Opening balance | `event 0`, description "Avaava tase" | Import as opening balance entries, not as transactions |
| VAT settlement | Description contains "Arvonlisäveroilmoitus" or "Arvonlisäverolaskelma" | Mark as VAT period close; may want to exclude from P&L import |
| Depreciation | Description "poistot" | Depreciation journal entry |
| Tax accrual | Description "Verojaksotus" | Period-end tax accrual |
| Accrual reversals | Accounts 2977, 2979 with period descriptions | Accrual entries for deferred items |

### Encoding
Always read the file as **ISO-8859-1** (Latin-1). The file contains Finnish characters (ä, ö, å) and the Finnish currency symbol `€` may appear in descriptions. Transcode to UTF-8 before inserting into MariaDB (which should use `utf8mb4` collation).

---

## 9. Open Questions / Future Concerns

1. **`(vat TYPE RATE)` semantics** — The `0` value likely indicates "no auto-VAT" (confirmed: all VAT is entered manually). The annotation is safe to ignore in ETL. No test edit required.

2. **Multi-currency** — Account 1905 (Valuuttakassat) exists in the chart of accounts but no FX events appear in the 2025 file. Multi-currency bookkeeping is not planned; if it arises it is a future development item, not an ETL concern for the current phase.

3. **VAT rate schedule maintenance** — As Finnish VAT rates change legislatively, the `(account_code, fiscal_year) → rate` lookup table in the ERP must be updated manually. The Tappio file itself will not reflect rate changes until the chart of accounts is edited; account names may temporarily lag the legal rate.

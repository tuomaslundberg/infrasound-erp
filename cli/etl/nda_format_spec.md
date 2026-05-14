# Nordea TITO (.nda) Format Specification

*Derived from hands-on analysis of `2512-infrasound-oy-tiliote.nda` cross-referenced against
the matching PDF statement and the December 2025 Tappio ledger events. All examples are from
that file unless noted.*

---

## 1. File properties

| Property | Value |
|---|---|
| Extension | `.nda` |
| Encoding | ISO-8859-1 (Latin-1) — transcode to UTF-8 before processing |
| Line endings | `\r\n` (CRLF) — one record per line |
| Standard | Finnish TITO (Tiliote tiedostona) — Nordea dialect |
| Naming | `{yymm}-infrasound-oy-tiliote.nda` |
| Coverage | One calendar month per file |

---

## 2. Record types overview

| Prefix | Name | Role |
|---|---|---|
| `T00` | Header | File and account metadata, opening balance |
| `T10` | Transaction | One per bank transaction (the primary data record) |
| `T11` | Continuation | Additional fields for the preceding T10 (message, reference, IBAN) |
| `T40` | Daily balance | Running balance after all transactions for the day |
| `T50` | Daily totals | Debit/credit counts and amounts per transaction type for the day |

At month end, after the last day's T50, there are period/month/year summary T50 lines
distinguished by their variant code (T502, T503, T504 respectively).

---

## 3. T00 — Header record

```
T00322100154430001331030122512012512312512312128    1176491K     251231+000000000000680077000191EURMaksuliiketili                000000000000000000INFRASOUND OY                      Nordea Bank Oyj, Y-tunnus 2858394-9     1544 NBC FI Customers
```

| Field | Example | Notes |
|---|---|---|
| Record type | `T00` | Fixed |
| Sort code | `32210` | Nordea branch code |
| Account number | `0154430001331030` | Without dashes |
| Statement number | `12` | Month index (December = 12) |
| Period start | `2512` (YYMM) | Parsed as the 1st of that month |
| Period start day | `01` | |
| Period end | `2512` | |
| Period end day | `31` | |
| Date generated | `2512` | |
| Seq / statement no | `31` | |
| Previous balance indicator | `2128` | (internal Nordea use) |
| Opening balance | `+000000000000680077` | Eurocents, signed; 6800.77 € |
| Transaction count | `000191` | Total T10 records in file |
| Currency | `EUR` | |
| Account type name | `Maksuliiketili` | |
| Account holder | `INFRASOUND OY` | |
| Bank name | `Nordea Bank Oyj, Y-tunnus 2858394-9` | |
| Branch | `1544 NBC FI Customers` | |

---

## 4. T10 — Transaction record (primary)

```
T101880000012512012588781I59152512012512012512011705Viitemaksu                         +000000000000002700 AJIMI JOONATAN TOIVIAINEN           J               00000000000000002011
```

Approximate field layout (fixed-width; positions are approximate):

| Field | Example | Notes |
|---|---|---|
| Record type | `T10` | |
| Statement no | `188` | Matches T00 |
| Sequence no | `000001` | Sequential, 1-based within the month |
| Archive reference | `2512012588781I5915` | **Arkistointitunnus** — unique transaction ID; appears in PDF |
| Booking date | `251201` | YYMMDD; date the transaction was posted |
| Payment date | `251201` | YYMMDD; usually = booking date |
| Value date | `251201` | YYMMDD; date for interest calculation |
| Transaction type code | `1705` | See §5 |
| Transaction type name | `Viitemaksu` | Padded to fixed width |
| Amount (signed) | `+000000000000002700` | Eurocents; `+` = credit (money in), `-` = debit (money out) |
| A/J indicator | `A` | `A` = automatic (machine-processed), `J` = manually entered |
| Counterparty name | `JIMI JOONATAN TOIVIAINEN` | 35 chars, padded |
| Domestic/foreign | `J` | `J` = domestic, `A` = foreign |
| (padding) | | |
| Reference or IBAN fragment | `00000000002011` | For reference payments: the reference number |

---

## 5. Transaction type codes (Tapahtumalaji)

| Code | Finnish name | Meaning |
|---|---|---|
| `705` / `710` | Viitemaksu | Incoming reference payment (credit transfer in) |
| `720` | Itsepalvelu / Verkkolasku | Outgoing transfer initiated by account holder or inbound e-invoice |
| `721` | Korttiosto | Card purchase (debit) |
| `730` | Palvelumaksu | Nordea bank service fee |
| (Pano) | Pano | Credit/deposit; sometimes used for SVOP returns and other non-standard credits |

The distinction between 705 and 710 corresponds to the two "Viitemaksu" subtypes; both carry
a structured reference number. 720 is used for self-service transfers (both `Itsepalvelu` and
`Verkkolasku`) — the description in T11 distinguishes them.

---

## 6. T11 — Continuation records

Up to three T11 sub-types follow each T10, providing detail that does not fit in the T10:

### T1104300 — Message / description text
```
T1104300vuokra, Orikedonkatu 22 B
```
Free text from the payment, up to ~35 chars per record. May repeat for multi-line messages.
For card purchases: city name appears here. For e-invoices: invoice number and date.

### T1107806 — Reference number / additional fields
```
T110780600000000000000001805
```
Contains the structured reference number (viitenumero) for reference payments, or additional
metadata. For transfers: may contain an internal message code.

### T1132311 — Counterparty IBAN and BIC
```
T1132311                                   FI2220572004007674                 NDEAFIHHXXX
```
Counterparty IBAN (padded) and BIC code. Present when the counterparty account is known.
For card purchases this record is absent or contains only the merchant terminal reference.

---

## 7. T40 — Daily balance record

```
T40050251201+000000000000822623+000000000000000000
```

| Field | Example | Notes |
|---|---|---|
| Record type | `T40` | |
| (subtype) | `050` | |
| Date | `251201` | YYMMDD |
| Closing balance | `+000000000000822623` | Eurocents; balance at end of day |
| (reserved) | `+000000000000000000` | Always zero in observed data |

---

## 8. T50 — Daily / period totals

```
T50067125120100000011+00000000000254025000000021-000000000000111479
```

| Field | Example | Notes |
|---|---|---|
| Record type | `T50` | |
| Variant | `067` | Daily (`T500`), period (`T502`), month (`T503`), year (`T504`) |
| Date | `251201` | YYMMDD |
| Credit count | `00000011` | Number of credit transactions for the day |
| Credit amount | `+000000000000254025` | Total credits in eurocents |
| Debit count | `000000021` | Number of debit transactions |
| Debit amount | `-000000000000111479` | Total debits in eurocents |

Month-end summary lines (`T502`/`T503`/`T504`) carry the same structure but accumulate
across the full period, month, or year respectively.

---

## 9. Three-source cross-reference: December 2025

The following traces selected transactions across all three formats to document how .nda
maps to Tappio and what enrichment happens at the ledger entry step.

### 9a. Incoming reference payment (room rental)

**NDA (T10):**
```
T10 seq=005  archive=2512012588781I5915  date=251201  type=1705 Viitemaksu
  +000000000000002700  JIMI JOONATAN TOIVIAINEN  ref=2011
```

**PDF:** Tap.nro 5 | JIMI JOONATAN TOIVIAINEN | 705 Viitemaksu | Viite 2011 | 27,00+

**Tappio (event 393):**
```
(event 393 (date 2025 12 1) "Jimi Toiviainen vuokra"
  ((1910 (money 2700))
   (3760 (money -2151))
   (2938 (money -549))))
```

**Mapping notes:**
- Gross 2700 → net 2151 + VAT 549 (25.5% room rental; 2151 × 1.255 = 2699.5 ≈ 2700 ✓)
- Description enriched from "JIMI JOONATAN TOIVIAINEN" to "Jimi Toiviainen vuokra"
- Reference number 2011 is the Infrasound-assigned viite for this tenant; not stored in Tappio

### 9b. Outgoing card purchase — entertainment, no deductible VAT

**NDA (T10):**
```
T10 seq=007  archive=251201258875KG1504  date=251201  type=0721 Korttiosto
  -000000000000002630  VIKING LINE, VIKING GRACE
T11: MARIEHAMN
T11: card 4920210015834405  ref 251129094656
```

**PDF:** Tap.nro 7 | VIKING LINE, VIKING GRACE | 721 Korttiosto | 26,30−

**Tappio (event 395):**
```
(event 395 (date 2025 12 1) "Viking Grace pikkujouluristeily"
  ((7010 (money 2630))
   (1910 (money -2630))))
```

**Mapping notes:**
- No VAT claimed: ferry purchases (Viking Line) are VAT-non-deductible for non-transport companies
- Multiple Viking Line charges from the same event (a company Christmas cruise) are booked as
  separate Tappio events, one per card transaction, all under account 7010
- Description contextualised: "VIKING LINE, VIKING GRACE" → "Viking Grace pikkujouluristeily"

### 9c. Outgoing e-invoice — domestic purchase with input VAT

**NDA (T10):**
```
T10 seq=033  archive=251202258878073242  date=251202  type=0720 Itsepalvelu
  -000000000000039494  Tribo Invest Oy
T11: vuokra, Orikedonkatu 22 B
T11: ref 00000000000000000000
T11: FI5057169020050600  OKOYFIHHXXX
```

**PDF:** Tap.nro 33 | Tribo Invest Oy | 720 Itsepalvelu | vuokra, Orikedonkatu 22 B | 394,94−

**Tappio (event 421):**
```
(event 421 (date 2025 12 2) "Vuokra Orikedonkatu 22 B"
  ((1910 (money -39494))
   (7230 (money 31469))
   (17631 (money 8025))))
```

**Mapping notes:**
- 25.5% domestic purchase; 31469 × 1.255 = 39493.6 ≈ 39494 ✓
- T11 message `vuokra, Orikedonkatu 22 B` directly usable as categorisation signal

### 9d. Outgoing card purchase — EU service (reverse charge, no bank VAT movement)

**NDA (T10):**
```
T10 seq=036  archive=251202258875E96122  date=251202  type=0721 Korttiosto
  -000000000000008383  GOOGLE *ADS3273673256
T11: EUR 83,83 cc_google.com
T11: card 4920210015834405  ref 251201104162
```

**PDF:** Tap.nro 36 | GOOGLE *ADS3273673256 | 721 Korttiosto | 83,83−

**Tappio (event 424):**
```
(event 424 (date 2025 12 2) "Google Ads"
  ((8090 (money 8383))
   (1910 (money -8383))
   (17633 (money 2138))
   (29395 (money -2138))))
```

**Mapping notes:**
- EU services reverse charge: the two VAT lines (17633 debit, 29395 credit) net to zero cash
  — they do not appear in the bank statement at all; they are pure accounting entries added
  at ledger time
- The .nda only shows the net payment amount (8383); the VAT entries require knowledge of the
  account type (EU service) and applicable rate (25.5%)
- 8383 × 0.255 = 2137.7 ≈ 2138 ✓

### 9e. Outgoing gig artist fee — VAT-exempt (4410)

**NDA (T10):**
```
T10 seq=047  archive=251218258871070178  date=251218  type=0720 Verkkolasku
  -000000000000030313  Nukketeatteritaiteilijayhdistys Aur
T11: N:20251204201111617717 / 18.12.2025
T11: ref 00000000000000020019
T11: FI7057169020025602  OKOYFIHH
```

**PDF:** Tap.nro 47 | Nukketeatteritaiteilijayhdistys Aur | 720 Verkkolasku | 303,13−

**Tappio (event 435):**
```
(event 435 (date 2025 12 18)
  "Nukketeatteritaiteilijayhdistys Aura of Puppets ry / Valtteri Alanen"
  ((4410 (money 30313))
   (1910 (money -30313))))
```

**Mapping notes:**
- Artist/gig production service (4410), VAT-exempt: gross = net, no VAT lines
- Tappio description adds the individual name (Valtteri Alanen) not present anywhere in the
  bank statement — this comes from the underlying invoice, which must be cross-referenced manually
- Invoice reference `N:20251204201111617717` is the e-invoice identifier, available in T11

### 9f. Incoming gig payment — mixed revenue lines

**NDA (T10):**
```
T10 seq=031  archive=GSCTC3482135211002  date=251201  type=0710 Viitemaksu
  +000000000000227025  UTU OY  ref=2354
T11: 00000081/145
T11: BANK017074532  SCT4DKHGMECFE1R
```

**PDF:** Tap.nro 31 | UTU OY | 710 Viitemaksu | Viite 2354 | 2.270,25+

**Tappio (event 419):**
```
(event 419 (date 2025 12 1) "UTU Oy"
  ((1910 (money 227025))
   (2938 (money -27880))
   (3000 (money -164100))
   (3050 (money -35045))))
```

**Mapping notes:**
- Gross 227025 = net keikka 164100 + net travel 35045 + VAT 27880
- VAT check: (164100 + 35045) × 0.14 = 27880.3 ≈ 27880 ✓ (all lines at 14%)
- The reference number 2354 corresponds to Infrasound's internal invoice reference

### 9g. Year-end events with no bank transaction

Three Tappio events from December 2025 have **no corresponding .nda record**:

| Event | Description | Why no bank transaction |
|---|---|---|
| 446 | Mikael Lehto vuokra 2510-2512 | Accrual: deferred rent from account 2979 (no cash) |
| 447 | Thomann täsmäytys suoriteperusteiseksi | Accrual adjustment: goods-in-transit (2870/17632/29394) |
| 448 | Arvonlisäverolaskelma 10-12/2025 Q4 | VAT period close: clears VAT accounts to 2977 |
| 449 | Pitkävaikutteisten menojen poistot | Depreciation (1201/1179/6870) |
| 450 | Verojaksotus | Tax accrual (1761/9940) |

These are purely accounting entries that must be constructed from the invoice record, VAT
calculation, or year-end procedure — not from bank data.

---

## 10. ETL implications

### What the .nda provides
- Every bank transaction with: date, amount (eurocents), counterparty name, transaction type code
- Archive reference (Arkistointitunnus) — stable unique ID usable as idempotency key
- For reference payments: structured reference number (viite)
- For e-invoices: invoice reference in T11 message field
- For card purchases: merchant name, city, card last digits, merchant terminal reference
- Daily balance checks (T40) for reconciliation

### What must be added at ledger entry time (not in .nda)
- Account code assignment (1910 vs. 3000 vs. 4410 etc.) — requires categorisation
- VAT split: net amount and VAT amount — requires knowing account type and applicable rate
- EU reverse charge entries (17633/29395 etc.) — zero cash impact, invisible to bank
- Year-end accruals, depreciation, VAT settlement — no bank trace at all
- Full counterparty name and context (e.g. "Valtteri Alanen" behind Nukketeatteriyhdistys)

### Auto-classifiable transaction types from .nda alone
The following patterns are reliably classifiable without manual review:

| Pattern | Signal | Ledger treatment |
|---|---|---|
| Code 710/705 + reference = known tenant viite | Tenant name from name match | 1910 / 3760 / 2938 (25.5%) |
| Counterparty = `Tribo Invest Oy` | Known vendor | 1910 / 7230 / 17631 (25.5%) |
| Counterparty = `GOOGLE *ADS*` | Known EU service | 1910 / 8090 / 17633 / 29395 (reverse charge) |
| Counterparty = `Suomen Keikkalasku*` or `Odeal Oy` or `Stagent Oy` | Artist billing service | 1910 / 4410 (exempt) |
| Counterparty = `NORDEA` + code 730 | Bank fees | 1910 / 8560 / 17631 (deductible fraction) |
| Code 720 Verkkolasku + known DNA/If IBAN | Known recurring e-invoice | Category-specific |

Unknown card purchases (code 721) and one-off transfers require human review or LLM-assisted
categorisation from the counterparty name + city + amount pattern.

### Reconciliation approach
1. Parse all T10 records → produce a candidate ledger entry per transaction
2. Match T40 daily balances against running sum to detect parsing errors
3. For each auto-classified entry, emit a draft ledger event
4. Queue unclassified entries for human review
5. Human confirms/corrects account assignments
6. Year-end: manually add accrual, depreciation, and VAT settlement events

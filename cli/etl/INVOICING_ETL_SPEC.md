# Invoicing ETL — Design Specification

*Written after a combined analysis of `gig-invoicing.xlsx` and the Saturday Google Calendar.*
*This spec gates the implementation of `extract_invoicing.py` and the related DB seed.*

---

## Source files

- `old-files/gig-invoicing.xlsx` — primary source (64 gigs, 2020-02-29 → 2025-12-05)
- Saturday Google Calendar — supplementary; personnel exceptions recorded from 2024 onwards

---

## Column map (Sheet1, row 2 = header, data from row 3)

| Col (0-idx) | Header              | Meaning                                      |
|-------------|---------------------|----------------------------------------------|
| 0           | KEIKKAPÄIVÄ         | Gig date                                     |
| 2           | KEIKKA              | Customer name                                |
| 4           | KEIKKAPALKKIO       | Gross invoice amount (VAT-inclusive)         |
| 6           | PALKKIO - ALV       | Net invoice amount (ex-VAT, ~÷1.1)           |
| 8           | MIKSAAJAN PALKKIO   | Sound engineer fee (Toni OR Valtteri — column is role-generic, not person-specific) |
| 10          | KULUT               | Total gig expenses (see note below)          |
| 12          | SOITTAJIEN PALKKIO  | Total musician fees                          |
| 14          | MIKAELIN PALKKIO    | Mikael Lehto fee (0 if not on gig)           |
| 16          | EMILIN PALKKIO      | Emil Lamminmäki fee                          |
| 18          | ALINAN PALKKIO      | Alina Kangas fee                             |
| 20          | MORTIN PALKKIO      | Mortti Markkanen fee                         |
| 22          | SAMUELIN PALKKIO    | Samuel Johansson fee (always 0 in data)      |
| 24          | LEEVIN PALKKIO      | Leevi Kähkönen fee                           |
| 26          | MUUT ULKOPUOLISET   | Other external musicians fee (see MUUT map)  |

Right-side columns (28+): per-musician credit tracking (KREDIITIT YHTEENSÄ = total earned − paid out).

---

## KULUT — what it contains

Not clean travel expenses. Formula evolved across three eras:

- **Pre-2023**: Arbitrary sum of line items (train tickets, equipment rental, external musician fees
  paid "from the top", etc.). No consistent structure.
- **2023**: `=220+X` where 220 was a fixed standard travel deduction applied to every gig,
  and X is additional realised expenses (e.g., extra transport, venue-specific costs).
- **From 2025**: `=(1-0.28)*<raw_costs>+0.28*PALKKIO-ALV` — cost allocation formula
  incorporating a percentage of net revenue. `<raw_costs>` is the sum of expense components.

**ETL decision**: Do NOT map KULUT to `other_travel_costs_cents`. It is accounting data
belonging to Phase 7 (bookkeeping module). The total is still useful as a reference figure;
consider a `gig_expenses_total_cents` field on gigs, or defer entirely to Phase 7.

---

## Price data — ETL decision

`KEIKKAPALKKIO` is the authoritative gross invoice amount. For delivered gigs, this equals
`quoted_price_cents` (the customer agreed to the price and was invoiced that amount; the
±50€ tolerance in the contract means minor discrepancies are absorbed).

**Update rule**: `UPDATE gigs SET quoted_price_cents = <keikkapalkkio_cents>`
for matched delivered gigs where keikkapalkkio > 0.

Two gigs have keikkapalkkio = 0 and should NOT be updated:
- 2024-08-31 RAPUILMIÖ (internal event, expenses-only)
- 2025-09-20 RAPUILMIÖ (same)
- 2025-05-17 Rami Lehtinen (internal/charity gig)

`PALKKIO - ALV` (net amount) belongs in future `outgoing_invoices.net_amount_cents`.
Do not write it anywhere yet.

---

## Lineup — default and exceptions

### Personnel roster with roles

| Name                | Role              | DB user needed |
|---------------------|-------------------|----------------|
| Tuomas Lundberg     | keyboards         | yes (partner)  |
| Toni Puttonen       | sound_engineering | yes (partner)  |
| Joni Virtanen       | drums             | yes (partner)  |
| Lauri Lehtinen      | guitar            | yes (partner)  |
| Mikael Lehto        | vocals            | yes            |
| Emil Lamminmäki     | bass              | yes            |
| Alina Kangas        | vocals            | yes            |
| Mortti Markkanen    | bass              | yes            |
| Samuel Johansson    | bass              | yes (0 gigs)   |
| Leevi Kähkönen      | bass              | yes (1 gig)    |
| Lassi Kriikkula     | bass              | yes            |
| Maxwell Mbare       | bass              | yes            |
| Iris Toivonen       | vocals            | yes            |
| Juho Peuraniemi     | keyboards         | yes            |
| Arttu Luonsinen     | bass              | yes            |
| Erkki Sippel        | drums / bass      | yes (2 gigs)   |
| Antti Saari         | bass              | yes (1 gig)    |
| Eetu Hämäläinen     | keyboards         | yes (1 gig)    |
| Valtteri Alanen     | sound_engineering | yes            |

All should be seeded as `users` with `role = musician` (no login password required).
Toni, Tuomas, Joni, and Lauri are partners; create them with appropriate roles if not yet present.

### Default lineup evolution

The "default" external slot has rotated over time. Use the fee columns as the authoritative
presence signal (fee > 0 = present), not a hardcoded default list.

Partner defaults (Tuomas, Toni, Joni, Lauri) are assumed present on every gig UNLESS
explicitly listed as an exception below.

### Documented partner exceptions

```
2022-07-15  Kankaisten Cecilia Oy   TRIO GIG: only Lauri (guitar), Tuomas (keyboards),
                                    Mikael (vocals). Joni, Toni, Alina, Mortti absent.

2022-07-16  Jukka Taavitsainen      Joni absent; Erkki Sippel on drums (fee in KULUT ~342€).
                                    Lauri, Tuomas, Emil, Mikael present.

2022-07-23  Kaisa Korpisaari        Toni absent; Leevi Kähkönen on sound engineering.
                                    Fee confirmed: 120.97€ net (in KULUT; col 24 = 0 since
                                    Leevi was engineer, not filling a musician slot).

2022-07-30  Julle Storberg          Toni absent; Leevi Kähkönen on sound engineering.
                                    Fee confirmed: 169.35€ net = ROUND(210/1.24, 2) (in KULUT).

2023-07-28  Marikki Rieppola        Emil Lamminmäki absent; Erkki Sippel on bass (fee ~320€
                                    in KULUT). NB: Emil may appear as "Jorgos Riverside" in
                                    source data; treat as same person — Emil Lamminmäki is legal.

2023-07-29  Kaisa Heinimaa          Emil Lamminmäki absent; Antti Saari on bass
                                    (fee ~329.45€ in KULUT).

2025-06-14  Ada Aadeli              Tuomas absent; Eetu Hämäläinen on keyboards
                                    (fee ~300€ + travel, likely the 377.91€ KULUT row).
                                    Toni absent; Valtteri Alanen on sound engineering.
                                    Mortti absent (or supplemented); Maxwell Mbare on bass.
                                    Joni, Lauri, Alina present.

2025-07-12  Artturi Karttunen       Tuomas absent; Juho Peuraniemi on keyboards (300€ + expenses).
                                    Toni at 75% of normal engineering fee.
                                    Joni and Lauri each at 75% of normal musician share.
                                    Alina and Mortti split the remainder.
                                    (Excruciating detail in gig-invoicing.xlsx right-side columns.)
```

### MUUT column breakdown — identified musicians

All confirmed via the MUUT KREDIITIT MAKSETTU log and Google Calendar:

```
2024-08-03  Rakennuspalvelu Rale Oy   Lassi Kriikkula (bass)         fee 309.04€
2024-08-24  Niko Mattila              Maxwell Mbare (bass)           fee 217.80€
2024-09-12  Ulosottolaitos            Iris Toivonen (vocals)         fee 251.92€ (MUUT col)
                                    Mortti Markkanen also present: fee 251.92€ in col 20.
                                    Alina absent. Calendar (MORTTI) was correct.
2024-09-21  Werner Lindell            Maxwell Mbare (bass)           fee 335.12€ (incl. 50€ travel)
2024-10-12  Sini Räisänen             Maxwell Mbare (bass)           fee 368.64€ (incl. ~102€ travel)
2025-06-14  Ada Aadeli                Maxwell Mbare (bass)           fee 211.11€ (separate from Eetu's KBD fee)
2025-07-12  Artturi Karttunen         Juho Peuraniemi (keyboards)    fee 300€ + expenses (VAT 14%)
2025-07-26  Anssi Soinio              Maxwell Mbare (bass)           fee 215.20€
2025-08-16  Janna Saarela             Arttu Luonsinen (bass)         fee 205.83€
2025-08-23  Niiko Niemi               Maxwell Mbare (bass)           fee 187.32€ (incl. ~222€ travel)
2025-11-14  UTU Oy                    Maxwell Mbare (bass)           fee 206.77€ (incl. ~82€ travel)
```

### Valtteri Alanen (sound engineering, 2025+)

Acts as stand-in sound engineer when Toni is absent. Calendar confirms the following gigs:
```
2025-06-14  Ada Aadeli
2025-06-28  Martta Lehtonen
2025-07-05  Meidän Ranthuone Oy
2025-07-26  Anssi Soinio
2025-09-13  Sampsa Myllymäki
2025-11-14  UTU Oy
```
Fee tracking: Valtteri's fee appears in **MIKSAAJAN PALKKIO** (col 8) on his gigs — the
column is role-generic, not Toni-specific. The MIKSAUSKREDIITIT credit sum is calculated
from MIKSAAJAN PALKKIO; on Valtteri gigs his fee is included in col 8 and simultaneously
deducted from the credit sum, so Toni accrues zero credit for gigs he didn't do.
Valtteri's fee is NOT embedded in KULUT or MUUT.

On all Valtteri gigs: Toni absent → insert gig_personnel row for Valtteri
(sound_engineering), omit Toni's row.

---

## Lineup source authority by gig status

| Gig status | Primary lineup source | Notes |
|---|---|---|
| Delivered (has invoicing row) | `gig-invoicing.xlsx` fee columns | Calendar used only to resolve exceptions / stand-ins |
| Future / ongoing quote (no invoicing row) | Google Calendar | Only source; invoicing data does not exist yet |

This distinction matters because `extract_gigs.py` seeds *all* gigs — delivered, confirmed,
and quoted — but `gig-invoicing.xlsx` only covers delivered gigs. Future gigs imported via
the legacy seed will have no invoicing row, so their `gig_personnel` rows must come entirely
from the calendar. The ETL script should handle both cases in the same pass: if a gig has
an invoicing match, use fee columns; if not, fall back to the calendar event description.

---

## Google Calendar — parsing strategy

From 2024: parenthetical in event SUMMARY indicates non-default external musician:
- `(MORTTI)` — Mortti Markkanen on gig (flagged when non-obvious)
- `(LASSI)` — Lassi Kriikkula (bass)
- `(MAKKE)` — Maxwell Mbare (bass)

From 2025: structured HTML in event DESCRIPTION:
```html
<ul>
  <li>asiakas: [customer name]</li>
  <li>kiipparisti: [name]</li>   ← keyboard stand-in
  <li>basisti: [name]</li>       ← bass stand-in / external
  <li>miksaaja: [name]</li>      ← sound engineering stand-in
</ul>
```
Parse with `html.parser` or regex; `li` roles map to gig_personnel roles:
- `kiipparisti` → `keyboards`
- `basisti` → `bass`
- `miksaaja` → `sound_engineering`

---

## Matching strategy

Match invoicing rows to DB gigs by (date, customer_name) fuzzy match,
same approach as `enrich_gigs.py`. Customer names in the invoicing file
are a subset of the extract_gigs.py output; match quality should be high.

Special cases:
- "Fast Enterprises, LLC" — date in file is "11.12.2021" (Finnish DD.MM.YYYY); correct
  gig date is **2021-12-11**. Parse as DD.MM.YYYY, not ISO.
- RAPUILMIÖ gigs: two entries (2024-08-31, 2025-09-20). keikkapalkkio=0; do not
  update quoted_price_cents. Still extract lineup.

---

## Prerequisites before writing the ETL script

1. ✅ **User seeding**: `db/seeds/musicians.sql` written. All 19 roster members seeded
   as `users` with `role = 'musician'`, placeholder email, `'!'` password sentinel.
   Apply with `make seed-musicians[-prod]` after migrations 006 and 007.

2. ✅ **`gig_personnel` schema**: Migrations 006 + 007 applied:
   - `users.email` column added (migration 006)
   - `fee_cents` is now nullable (migration 007)
   - `role` ENUM updated to: `vocals`, `guitar`, `bass`, `drums`, `keyboards`,
     `sound_engineering`, `other` (migration 007)

3. **KULUT decision**: Decide whether to add `gig_expenses_total_cents INT` to gigs
   before writing the ETL, or defer entirely to Phase 7.

4. ✅ **All 🚩 flags resolved** (see updated exceptions above).

---

## Output files (when implemented)

- `db/seeds/legacy_invoicing.sql` — UPDATE quoted_price_cents + INSERT gig_personnel
- `db/seeds/legacy_invoicing_unmatched.txt` — rows that couldn't be matched to a gig

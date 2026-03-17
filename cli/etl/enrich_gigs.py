#!/usr/bin/env python3
"""
cli/etl/enrich_gigs.py

UPDATE pass for legacy data: parses every gig-info-*.txt and
price-calculator-*.xlsx file under old-files/ and emits idempotent SQL
UPDATE statements that enrich the records created by extract_gigs.py.

Fields populated
----------------
  venues       : name, address_line, postal_code, city, distance_from_turku_km
  gigs         : car1_distance_km, car2_distance_km, other_travel_costs_cents,
                 order_description, quoted_price_cents (only where NULL),
                 base_price_cents (only where NULL),
                 pricing_tier1, pricing_tier2,
                 qty_ennakkoroudaus, qty_song_requests_extra,
                 qty_extra_performances, qty_background_music_h,
                 qty_live_album, discount_cents
  contacts     : email, phone  (old-format gig-info only — legal entities)

Data sources and priority
-------------------------
  1. gig-invoicing.xlsx (via extract_gigs.py)  — highest: actual invoiced amounts
  2. price-calculator-*.xlsx                   — fine-grained pricing breakdown
  3. gig-info-*.txt                            — venue address, distances, quote

  quoted_price_cents / base_price_cents are only written where currently NULL
  so invoice data from extract_gigs.py is never overwritten.

  price-calculator is authoritative for pricing_tier1/2 and qty_* columns;
  gig-info Tarjous is the fallback for quoted_price_cents only.

Format generations (gig-info)
------------------------------
  new          — "Etäisyys Turun keskustasta" present (mid-2024 onwards)
  intermediate — "Etäisyys Lipunkantajankadulta" or "etäisyys treenikseltä"
                 The Lipunkantajankatu 18 origin is in Runosmäki, Turku —
                 functionally the same reference as "Turku centre" for analytics.
                 Imported as distance_from_turku_km.
  old          — neither; flat key:value with Finnish labels (2021-2022)

Format generations (price-calculator)
--------------------------------------
  new  — row-labelled (col[0] = label, col[2] = input value);
         detected by row[1][1] being None (vs. 'Soiva aika' in old)
  old  — columnar single data-row; col headers in row[1];
         detected by row[1][1] == 'Soiva aika'

Design decisions
----------------
  • other_travel_costs_cents : 37.80 € was the default train-ticket estimate
    (Helsinki–Turku return, 1 person); imported as-is since it was the best
    available estimate at the time.  Not adapted for new ERP pricing.
  • Tarjous (multi-option)   : "kolme 45 min settiä" (3×45) taken as the
    canonical default.  "Tarjous N:" numbered blocks: entry marked HYVÄKSYTTY
    preferred; otherwise the second option; finally first.
  • Song requests             : intentionally skipped — schema not yet defined;
    gig-info files do not contain up-to-date setlist data anyway.
  • intermediate distance     : Lipunkantajankadulta ≈ Turku centre; imported
    as distance_from_turku_km for analytics value.
  • old-format km_estimate    : total multi-stop trip km, not per-car one-way —
    not imported into car1/car2_distance_km to avoid false precision.

Matching strategy
-----------------
  SQL uses WHERE on (gig_date, customers.name) subquery so it survives re-runs
  of extract_gigs.py with different ID assignments.
  Only rows with id BETWEEN 1 AND 9999 (the legacy range) are affected.

Outputs
-------
  db/seeds/legacy_enrich.sql          — UPDATE statements
  db/seeds/legacy_enrich_unmatched.txt — files that couldn't be matched

Usage
-----
  python cli/etl/enrich_gigs.py
  python cli/etl/enrich_gigs.py --dry-run
  python cli/etl/enrich_gigs.py --stats

Prerequisites
-------------
  Run extract_gigs.py and import legacy_gigs.sql first.
"""

from __future__ import annotations

import argparse
import glob
import re
import sys
import unicodedata
from dataclasses import dataclass, field
from datetime import date, datetime
from pathlib import Path
from typing import Optional

# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------
REPO_ROOT = Path(__file__).resolve().parent.parent.parent

GIGS_INFO_GLOBS = [
    str(REPO_ROOT / "old-files/future-gigs/**/gig-info-*.txt"),
    str(REPO_ROOT / "old-files/past-gigs/**/gig-info-*.txt"),
    str(REPO_ROOT / "old-files/info/archive/**/gig-info-*.txt"),
]
PRICE_CALC_GLOBS = [
    str(REPO_ROOT / "old-files/future-gigs/**/price-calculator-*.xlsx"),
    str(REPO_ROOT / "old-files/past-gigs/**/price-calculator-*.xlsx"),
    str(REPO_ROOT / "old-files/info/archive/**/price-calculator-*.xlsx"),
]

OUTPUT_SQL = REPO_ROOT / "db/seeds/legacy_enrich.sql"
UNMATCHED  = REPO_ROOT / "db/seeds/legacy_enrich_unmatched.txt"


# ---------------------------------------------------------------------------
# Data model
# ---------------------------------------------------------------------------

@dataclass
class VenueData:
    name:                   Optional[str]   = None
    address_line:           Optional[str]   = None
    postal_code:            Optional[str]   = None
    city:                   Optional[str]   = None
    distance_from_turku_km: Optional[float] = None


@dataclass
class GigData:
    file_path:              str             = ""
    gig_date:               Optional[date]  = None
    customer_name:          Optional[str]   = None
    venue:                  VenueData       = field(default_factory=VenueData)
    # gig columns
    car1_distance_km:       Optional[float] = None
    car2_distance_km:       Optional[float] = None
    other_travel_costs_cents: Optional[int] = None
    order_description:      Optional[str]   = None
    quoted_price_cents:     Optional[int]   = None   # IF NULL only
    base_price_cents:       Optional[int]   = None   # IF NULL only
    # pricing inputs (from price-calculator)
    pricing_tier1:          Optional[bool]  = None
    pricing_tier2:          Optional[bool]  = None
    qty_ennakkoroudaus:     Optional[int]   = None
    qty_song_requests_extra:Optional[int]   = None
    qty_extra_performances: Optional[int]   = None
    qty_background_music_h: Optional[int]   = None
    qty_live_album:         Optional[int]   = None
    discount_cents:         Optional[int]   = None
    # old-format contact details
    contact_email:          Optional[str]   = None
    contact_phone:          Optional[str]   = None
    # venue dedup (populated by _match_venues)
    matched_venue_id:       Optional[int]              = None  # DB-matched venue id
    canonical_gig_key:      Optional[tuple[date, str]] = None  # (date, name) of in-batch canonical
    # metadata
    format_gen:             str             = "unknown"
    sources:                list[str]       = field(default_factory=list)
    parse_warnings:         list[str]       = field(default_factory=list)


# ---------------------------------------------------------------------------
# Generic helpers
# ---------------------------------------------------------------------------

def _normalise_name(name: str) -> str:
    n = unicodedata.normalize("NFKD", name).encode("ascii", "ignore").decode()
    n = n.lower()
    n = re.sub(r"[^\w\s]", "", n)
    return " ".join(n.split())


def _slug_to_name(slug: str) -> str:
    return " ".join(p.capitalize() for p in slug.split("-"))


def _parse_yymmdd(s: str) -> Optional[date]:
    m = re.match(r"^(\d{2})(\d{2})(\d{2})$", s)
    if not m:
        return None
    try:
        return date(2000 + int(m.group(1)), int(m.group(2)), int(m.group(3)))
    except ValueError:
        return None


def _parse_eur_amount(s: str) -> Optional[float]:
    """Parse Finnish EUR string '2 021,65 €' → 2021.65."""
    s = re.sub(r"[€\s]", "", str(s).strip())
    s = re.sub(r"\.", "", s)      # remove period (thousands sep)
    s = s.replace(",", ".")
    try:
        v = float(s)
        return v if v >= 0 else None
    except ValueError:
        return None


def _parse_km(s: str) -> Optional[float]:
    """Parse '60,2 km' or '60.2' → 60.2."""
    m = re.search(r"([\d]+[,.][\d]+|[\d]+)\s*km", str(s), re.IGNORECASE)
    if not m:
        # Try bare number
        m = re.match(r"^\s*([\d]+[,.][\d]+|[\d]+)\s*$", str(s))
    if m:
        try:
            return float(m.group(1).replace(",", "."))
        except ValueError:
            pass
    return None


# ---------------------------------------------------------------------------
# Venue parsing
# ---------------------------------------------------------------------------

_VENUE_PLACEHOLDER = re.compile(
    r"paikan nimi|katuosoite|postitoimipaikka|xxxx|^\?\?\?", re.IGNORECASE
)


def _parse_sijainti(raw: str) -> VenueData:
    """Parse a 'Sijainti:' or 'sijainti:' field value into VenueData."""
    v = VenueData()
    if not raw or raw.strip() in ("", "???", "-"):
        return v

    # Strip old-format trailing distance annotation
    raw = re.split(r",?\s*etäisyys\s+", raw, flags=re.IGNORECASE)[0].strip().rstrip(",")

    parts = [p.strip() for p in raw.split(",") if p.strip()]
    if not parts:
        return v

    postal_idx = None
    for i, p in enumerate(parts):
        m = re.match(r"^(\d{5})\s+(.+)$", p)
        if m:
            postal_idx = i
            v.postal_code = m.group(1)
            v.city = m.group(2).strip()
            break

    if postal_idx is not None:
        preceding = parts[:postal_idx]
        if len(preceding) == 1:
            if re.search(r"\d", preceding[0]):
                v.address_line = preceding[0]
            else:
                v.name = preceding[0]
        elif len(preceding) > 1:
            v.name = preceding[0]
            v.address_line = ", ".join(preceding[1:])
    else:
        if len(parts) == 1:
            if not re.search(r"\d", parts[0]) and len(parts[0].split()) <= 3:
                v.city = parts[0]
            else:
                v.name = parts[0]
        else:
            v.name = parts[0]
            last = parts[-1]
            if not re.search(r"\d", last) and len(last.split()) <= 3:
                v.city = last
                if len(parts) > 2:
                    v.address_line = ", ".join(parts[1:-1])

    # Discard placeholder tokens
    for attr in ("name", "address_line", "city", "postal_code"):
        val = getattr(v, attr)
        if val and _VENUE_PLACEHOLDER.search(val):
            setattr(v, attr, None)
        if attr == "postal_code" and val and re.search(r"X", val, re.IGNORECASE):
            v.postal_code = None

    return v


# ---------------------------------------------------------------------------
# Tarjous (price) parsing
# ---------------------------------------------------------------------------

def _parse_tarjous_from_content(content: str, gd: GigData) -> None:
    """Detect and parse Tarjous from gig-info content."""
    # Single-value: "Tarjous: 2 021,65 €"
    m = re.search(r"^Tarjous\s*:\s*(.+)$", content, re.MULTILINE)
    if m:
        val = m.group(1).strip()
        if val:
            eur = _parse_eur_amount(val)
            if eur and eur > 0:
                gd.quoted_price_cents = round(eur * 100)
            return

    # Multi-option block — capture everything from "Tarjous" to the next section
    # heading; blank lines between the label and the options are allowed.
    m = re.search(
        r"^(Tarjous\b.*?)(?=\n(?:Toivekappaleet|Muuta tietoa|Aikataulu)\b|\Z)",
        content, re.MULTILINE | re.DOTALL | re.IGNORECASE
    )
    if m:
        block = m.group(1)

        # Finnish text multi-set lines: prefer "kolme" (3×45 min)
        if re.search(r"kaksi|kolme|nelj[äa]", block, re.IGNORECASE):
            cents = _pick_kolme_tarjous(block)
            if cents:
                gd.quoted_price_cents = cents
                return

        # Numbered "Tarjous N:" — prefer HYVÄKSYTTY entry
        if re.search(r"Tarjous\s+\d+\s*:", block):
            cents = _pick_numbered_tarjous(block)
            if cents:
                gd.quoted_price_cents = cents


def _pick_kolme_tarjous(block: str) -> Optional[int]:
    """From a Finnish multi-set Tarjous block, return the 3-set (kolme) price."""
    for line in block.splitlines():
        if re.search(r"\bkolme\b", line, re.IGNORECASE):
            m = re.search(r"([\d\s]+,\d{2})\s*€", line)
            if m:
                eur = _parse_eur_amount(m.group(1))
                if eur and eur > 0:
                    return round(eur * 100)
    # Fallback: take any first EUR amount found
    for line in block.splitlines():
        m = re.search(r"([\d\s]+,\d{2})\s*€", line)
        if m:
            eur = _parse_eur_amount(m.group(1))
            if eur and eur > 0:
                return round(eur * 100)
    return None


def _pick_numbered_tarjous(block: str) -> Optional[int]:
    """From a 'Tarjous N:' block, prefer the HYVÄKSYTTY entry, else second option."""
    entries: list[tuple[int, int]] = []   # (tarjous_n, cents)
    hyväksytty_cents: Optional[int] = None

    for line in block.splitlines():
        m = re.match(r"^Tarjous\s+(\d+)\s*:\s*(.+)$", line)
        if not m:
            continue
        n = int(m.group(1))
        rest = m.group(2)
        eur_m = re.search(r"([\d\s]+,\d{2})\s*€", rest)
        if eur_m:
            eur = _parse_eur_amount(eur_m.group(1))
            if eur and eur > 0:
                cents = round(eur * 100)
                entries.append((n, cents))
                if re.search(r"hyväksytty", rest, re.IGNORECASE):
                    hyväksytty_cents = cents

    if hyväksytty_cents is not None:
        return hyväksytty_cents
    if len(entries) >= 2:
        return entries[1][1]   # second option (index 1)
    if entries:
        return entries[0][1]
    return None


# ---------------------------------------------------------------------------
# gig-info parsers
# ---------------------------------------------------------------------------

def _parse_new_format(content: str, gd: GigData) -> None:
    gd.format_gen = "new"
    kv: dict[str, str] = {}
    for line in content.splitlines():
        m = re.match(r"^([^:]+):\s*(.*)", line)
        if m:
            kv[m.group(1).strip()] = m.group(2).strip()

    if not gd.customer_name:
        gd.customer_name = kv.get("Asiakas", "").strip() or None

    sijainti = kv.get("Sijainti", "")
    if sijainti:
        gd.venue = _parse_sijainti(sijainti)

    # distance_from_turku_km
    dist_raw = re.sub(r"\(.*\)", "", kv.get("Etäisyys Turun keskustasta", ""))
    if dist_raw.strip():
        km = _parse_km(dist_raw.strip() + (" km" if "km" not in dist_raw else ""))
        if km is not None:
            gd.venue.distance_from_turku_km = km

    # car distances (new format only)
    for attr, key in (("car1_distance_km", "Arvio auton 1 matkan pituudesta"),
                      ("car2_distance_km", "Arvio auton 2 matkan pituudesta")):
        raw = re.sub(r"\(.*\)", "", kv.get(key, ""))
        if raw.strip():
            km = _parse_km(raw.strip() + (" km" if "km" not in raw else ""))
            if km is not None:
                setattr(gd, attr, km)

    # other_travel_costs — import as-is (37.80 was a real train-ticket estimate)
    travel_raw = kv.get("Arvio muista matkakuluista", "")
    if travel_raw:
        eur = _parse_eur_amount(travel_raw)
        if eur is not None:
            gd.other_travel_costs_cents = round(eur * 100)

    # order description
    tilaus = kv.get("Tilaus", "").strip()
    if tilaus and not re.match(r"^X\s*x\s*XX\s*min$", tilaus, re.IGNORECASE):
        gd.order_description = tilaus

    _parse_tarjous_from_content(content, gd)


def _parse_intermediate_format(content: str, gd: GigData) -> None:
    """Intermediate format: Etäisyys Lipunkantajankadulta / treenikseltä.

    The origin address (Lipunkantajankatu 18, Runosmäki) is functionally the
    same reference as 'Turku centre' for analytics — imported as
    distance_from_turku_km.  km_estimate (total trip) is NOT imported into
    car1/car2_distance_km to avoid false precision (it includes pickup stops).
    """
    gd.format_gen = "intermediate"
    kv: dict[str, str] = {}
    for line in content.splitlines():
        m = re.match(r"^([^:]+):\s*(.*)", line)
        if m:
            kv[m.group(1).strip()] = m.group(2).strip()

    if not gd.customer_name:
        gd.customer_name = kv.get("Asiakas", "").strip() or None

    sijainti = kv.get("Sijainti", "")
    if sijainti:
        gd.venue = _parse_sijainti(sijainti)

    # Lipunkantajankatu ≈ Turku centre
    for key in ("Etäisyys Lipunkantajankadulta", "Etäisyys treenikseltä"):
        raw = kv.get(key, "")
        if raw:
            km = _parse_km(raw + (" km" if "km" not in raw else ""))
            if km is not None:
                gd.venue.distance_from_turku_km = km
            break

    tilaus = kv.get("Tilaus", "").strip()
    if tilaus and not re.match(r"^X\s*x\s*XX\s*min$", tilaus, re.IGNORECASE):
        gd.order_description = tilaus

    _parse_tarjous_from_content(content, gd)


def _parse_old_format(content: str, gd: GigData, dir_slug: str) -> None:
    """Old 2021-2022 format: flat key:value lines."""
    gd.format_gen = "old"
    kv: dict[str, str] = {}
    for line in content.splitlines():
        m = re.match(r"^([^:]+):\s*(.*)", line)
        if m:
            kv[m.group(1).strip().lower()] = m.group(2).strip()

    if not gd.customer_name:
        parts = dir_slug.split("-", 1)
        if len(parts) == 2:
            gd.customer_name = _slug_to_name(parts[1])

    sijainti = kv.get("sijainti", "")
    if sijainti:
        gd.venue = _parse_sijainti(sijainti)

    # Order + price from "keikka: N x 45 min, X XXX,XX €"
    keikka = kv.get("keikka", "")
    if keikka:
        m = re.match(r"^(\d+\s*x\s*\d+\s*min)", keikka, re.IGNORECASE)
        if m:
            gd.order_description = m.group(1).strip()
        m2 = re.search(r"([\d\s]+,\d{2})\s*€", keikka)
        if m2:
            eur = _parse_eur_amount(m2.group(1))
            if eur and eur > 0:
                gd.quoted_price_cents = round(eur * 100)

    # Contact details (legal entities / companies only)
    yht = kv.get("yhteyshenkilö", "")
    if yht:
        em = re.search(r"[\w.\-+]+@[\w.\-]+\.\w+", yht)
        if em:
            gd.contact_email = em.group(0)
        ph = re.search(r"puh\.\s*([\+\d\s\(\)\-]+)", yht)
        if ph:
            gd.contact_phone = ph.group(1).strip()


def parse_gig_info_file(path: Path) -> GigData:
    gd = GigData(file_path=str(path))
    try:
        content = path.read_text(encoding="utf-8")
    except Exception as e:
        gd.parse_warnings.append(f"read error: {e}")
        return gd

    m = re.search(r"gig-info-(\d{6})", path.name)
    if m:
        gd.gig_date = _parse_yymmdd(m.group(1))
    if gd.gig_date is None:
        gd.parse_warnings.append("could not parse date from filename")

    dir_slug = path.parent.name
    gd.sources.append(path.name)

    if "Etäisyys Turun keskustasta" in content:
        _parse_new_format(content, gd)
    elif ("Etäisyys Lipunkantajankadulta" in content
          or "Etäisyys treenikseltä" in content):
        _parse_intermediate_format(content, gd)
    else:
        _parse_old_format(content, gd, dir_slug)

    return gd


# ---------------------------------------------------------------------------
# Price calculator parsers
# ---------------------------------------------------------------------------

def _load_price_calculator(path: Path, gd: GigData) -> None:
    """Parse a price-calculator-*.xlsx and merge data into gd (in-place)."""
    try:
        import openpyxl
    except ImportError:
        gd.parse_warnings.append("openpyxl not installed — skipping price calculator")
        return

    try:
        wb = openpyxl.load_workbook(str(path), data_only=True)
    except Exception as e:
        gd.parse_warnings.append(f"price-calculator read error: {e}")
        return

    ws = wb.active
    rows = list(ws.iter_rows(values_only=True))
    if len(rows) < 2:
        return

    # Detect format by row[1][1]:
    #   old format → row[1][1] == 'Soiva aika'  (columnar single data row)
    #   new format → row[1][1] is None           (row-labelled)
    header_row = rows[1] if len(rows) > 1 else ()
    if len(header_row) > 1 and header_row[1] == "Soiva aika":
        _parse_pc_old_format(rows, gd)
    else:
        _parse_pc_new_format(rows, gd)

    gd.sources.append(path.name)


def _parse_pc_new_format(rows: list, gd: GigData) -> None:
    """New price-calculator format: row-labelled layout.

    Relevant rows (col[0] = label, col[2] = input, col[6] = net):
      'Pvm'         → date (ignored; matched by file date already)
      'Etäisyys'    → distance_from_turku_km
      'Korotus 1'   → pricing_tier1
      'Korotus 2'   → pricing_tier2
      'Km-arvio 1'  → car1_distance_km
      'Km-arvio 2'  → car2_distance_km
      'Muut matkakulut' (no col[0] label) → other_travel_costs (col[6] = net)
      'Yhteensä'    → quoted_price_cents (col[8] = brutto)
      'Perushinta' data row col[6] → base_price_cents (net)
    Additional services (col[4] = qty or None-when-zero, col[6] = net value):
      '1 Ennakkoroudaus', '2 Ylimääräiset toivekappaleet', ...
    """
    # Build label → row dict for easy lookup
    label_rows: dict[str, tuple] = {}
    for row in rows:
        label = str(row[0]).strip() if row[0] is not None else ""
        if label and label not in label_rows:
            label_rows[label] = row

    def _col2(row: tuple) -> Optional[float]:
        v = row[2] if len(row) > 2 else None
        if v is None or v == "" or (isinstance(v, str) and not v.strip()):
            return None
        try:
            return float(str(v).replace(",", "."))
        except (ValueError, TypeError):
            return None

    def _col6(row: tuple) -> Optional[float]:
        v = row[6] if len(row) > 6 else None
        if v is None:
            return None
        try:
            return float(v)
        except (ValueError, TypeError):
            return None

    # distance_from_turku_km (don't overwrite if already set from gig-info)
    if gd.venue.distance_from_turku_km is None:
        if "Etäisyys" in label_rows:
            km = _col2(label_rows["Etäisyys"])
            if km is not None:
                gd.venue.distance_from_turku_km = km

    # car distances (only set if not already set from gig-info)
    if gd.car1_distance_km is None and "Km-arvio 1" in label_rows:
        km = _col2(label_rows["Km-arvio 1"])
        if km is not None:
            gd.car1_distance_km = km
    if gd.car2_distance_km is None and "Km-arvio 2" in label_rows:
        km = _col2(label_rows["Km-arvio 2"])
        if km is not None:
            gd.car2_distance_km = km

    # pricing tiers
    if "Korotus 1" in label_rows:
        v = label_rows["Korotus 1"][2] if len(label_rows["Korotus 1"]) > 2 else None
        gd.pricing_tier1 = (v is not None and str(v).strip() not in ("", "None"))
    if "Korotus 2" in label_rows:
        v = label_rows["Korotus 2"][2] if len(label_rows["Korotus 2"]) > 2 else None
        gd.pricing_tier2 = (v is not None and str(v).strip() not in ("", "None"))

    # other_travel_costs: the "Muut matkakulut" row has no col[0] label —
    # scan all rows for col[3] == 'Muut matkakulut'
    if gd.other_travel_costs_cents is None:
        for row in rows:
            if len(row) > 3 and row[3] == "Muut matkakulut":
                net = _col6(row)
                if net is not None:
                    gd.other_travel_costs_cents = round(net * 100)
                break

    # quoted_price_cents: Yhteensä col[8] (brutto)
    if gd.quoted_price_cents is None and "Yhteensä" in label_rows:
        r = label_rows["Yhteensä"]
        brutto = r[8] if len(r) > 8 else None
        if brutto is not None:
            try:
                gd.quoted_price_cents = round(float(brutto) * 100)
            except (ValueError, TypeError):
                pass

    # base_price_cents: 'Perushinta' row (col[6] = net base price)
    # In new format this appears as 'Pvm' row with 'Perushinta' in col[3]
    if gd.base_price_cents is None:
        for row in rows:
            if len(row) > 3 and row[3] == "Perushinta":
                net = _col6(row)
                if net is not None and net > 0:
                    gd.base_price_cents = round(net * 100)
                break

    # Additional service quantities (from Lisäpalvelut section)
    _QTY_MAP = {
        "1 Ennakkoroudaus":              "qty_ennakkoroudaus",
        "2 Ylimääräiset toivekappaleet": "qty_song_requests_extra",
        "3 Ylimääräiset ohjelmanumerot": "qty_extra_performances",
        "4 Taustamusiikki":              "qty_background_music_h",
        "5 Live-albumi":                 "qty_live_album",
    }
    for label, attr in _QTY_MAP.items():
        if label in label_rows:
            row = label_rows[label]
            qty = row[4] if len(row) > 4 else None
            if qty is not None and isinstance(qty, (int, float)) and qty > 0:
                setattr(gd, attr, int(qty))
            elif qty is None:
                # qty=None means 0 in new-format cells (not entered)
                pass

    # Discount
    if "Alennus" in label_rows:
        net = _col6(label_rows["Alennus"])
        if net is not None and net > 0:
            gd.discount_cents = round(net * 100)


def _parse_pc_old_format(rows: list, gd: GigData) -> None:
    """Old price-calculator format: columnar, single data row.

    Row layout:
      rows[0]: wide header ('Hinnanmuodostus', ...)
      rows[1]: column labels ('Pvm', 'Soiva aika', 'Etäisyys', 'Km-arvio',
                               'Korotus 1', 'Korotus 2', 'buukkaa-bandi.fi', ...)
      rows[2]: data values  (date, playing_time, distance, km_total, tier1, tier2, ...)
      rows[3]: 3-set price  (col[28])  ← "kolme" default
      rows[4]: Perushinta / 4-set price (col[28])
    Old format columns:
      [0]=Pvm  [2]=Etäisyys  [3]=Km-arvio  [4]=Korotus1  [5]=Korotus2
      [26]=total brutto  [28]="copy to gig-info" / option prices
    """
    if len(rows) < 3:
        return
    data = rows[2]
    if not data or len(data) < 5:
        return

    # distance_from_turku_km (Lipunkantajankatu ≈ Turku centre)
    if gd.venue.distance_from_turku_km is None and len(data) > 2:
        v = data[2]
        if v is not None:
            try:
                gd.venue.distance_from_turku_km = float(v)
            except (ValueError, TypeError):
                pass

    # pricing tiers
    if len(data) > 4:
        gd.pricing_tier1 = (data[4] is not None and str(data[4]).strip() not in ("", "None"))
    if len(data) > 5:
        gd.pricing_tier2 = (data[5] is not None and str(data[5]).strip() not in ("", "None"))

    # quoted_price_cents: 3-set option (row index 3, col 28)
    if gd.quoted_price_cents is None and len(rows) >= 4:
        three_set_row = rows[3]
        if three_set_row and len(three_set_row) > 28 and three_set_row[28] is not None:
            try:
                gd.quoted_price_cents = round(float(three_set_row[28]) * 100)
            except (ValueError, TypeError):
                pass

    # base_price_cents: search for 'Perushinta' label in col[24] of later rows
    if gd.base_price_cents is None:
        for row in rows[5:]:
            if row and len(row) > 28 and row[24] == "Perushinta":
                try:
                    base_net = row[28]  # the net base price
                    if base_net is not None:
                        gd.base_price_cents = round(float(base_net) * 100)
                except (ValueError, TypeError):
                    pass
                break
            # Also check for a bare number row following 'Perushinta'
            if row and len(row) > 24 and isinstance(row[24], (int, float)):
                try:
                    gd.base_price_cents = round(float(row[24]) * 100)
                except (ValueError, TypeError):
                    pass
                break


# ---------------------------------------------------------------------------
# DB connectivity (optional — gracefully skipped if pymysql unavailable)
# ---------------------------------------------------------------------------

def _load_dot_env(path: Optional[Path] = None) -> dict[str, str]:
    if path is None:
        path = REPO_ROOT / ".env"
    env: dict[str, str] = {}
    if not path.exists():
        return env
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, _, v = line.partition("=")
        env[k.strip()] = v.strip().strip('"').strip("'")
    return env


def _try_connect_db():
    """Attempt a MariaDB connection via pymysql; return connection or None.

    Merges .env.dev on top of .env when present so that dev-stack credentials
    and port mappings (ETL_DB_PORT=3307 for the host-side dev port) are picked
    up automatically when running against `make dev`.
    """
    try:
        import pymysql  # type: ignore
    except ImportError:
        return None
    env = _load_dot_env()
    dev_env_path = REPO_ROOT / ".env.dev"
    if dev_env_path.exists():
        env.update(_load_dot_env(dev_env_path))
    # ETL_DB_HOST overrides DB_HOST — needed when the script runs on the host
    # while the DB runs inside Docker (DB_HOST='db' is only resolvable inside
    # the Docker network; ETL_DB_HOST='127.0.0.1' reaches the mapped port).
    # ETL_DB_PORT overrides DB_PORT — dev stack maps port 3307 on the host.
    host     = env.get("ETL_DB_HOST") or env.get("DB_HOST", "127.0.0.1")
    port     = int(env.get("ETL_DB_PORT") or env.get("DB_PORT", "3306"))
    user     = env.get("ETL_DB_USER")     or env.get("MYSQL_USER")
    password = env.get("ETL_DB_PASSWORD") or env.get("MYSQL_PASSWORD")
    dbname   = env.get("MYSQL_DATABASE")
    if not all([user, password, dbname]):
        return None
    try:
        return pymysql.connect(
            host=host, port=port, user=user, password=password,
            database=dbname, charset="utf8mb4", connect_timeout=5,
        )
    except Exception as e:
        print(f"  DB connect failed: {e}", file=sys.stderr)
        return None


def _load_db_venues(conn) -> list[dict]:
    """Load all non-deleted venue rows from DB."""
    with conn.cursor() as cur:
        cur.execute(
            "SELECT id, name, address_line, city FROM venues WHERE deleted_at IS NULL"
        )
        return [
            {"id": r[0], "name": r[1], "address_line": r[2], "city": r[3]}
            for r in cur.fetchall()
        ]


# ---------------------------------------------------------------------------
# Venue deduplication
# ---------------------------------------------------------------------------

def _normalise_for_match(s: str) -> str:
    """Normalise a string for venue fuzzy comparison (ASCII, lowercase, no punct)."""
    s = unicodedata.normalize("NFKD", s).encode("ascii", "ignore").decode()
    s = s.lower()
    s = re.sub(r"[^\w\s]", " ", s)
    return " ".join(s.split())


def _venue_match_key(name: Optional[str], city: Optional[str]) -> str:
    parts = [p for p in [name, city] if p]
    return _normalise_for_match(" ".join(parts))


def _venue_completeness(v: VenueData) -> int:
    return sum(
        1 for f in [v.name, v.address_line, v.postal_code, v.city, v.distance_from_turku_km]
        if f is not None
    )


def _match_venues(
    records: list[GigData],
    db_venues: list[dict],
    auto_threshold:      float = 0.88,
    candidate_threshold: float = 0.60,
) -> None:
    """Two-phase venue deduplication; modifies records in-place.

    Phase 1 — DB matching:
      Each parsed venue (with a non-placeholder name) is compared against existing
      DB venue rows.  Rows where name == city (city-only placeholders seeded by
      extract_gigs.py) are excluded from matching.
      - score >= auto_threshold  → set gd.matched_venue_id; gig's venue_id reassigned
      - candidate_threshold <= score < auto_threshold → printed for manual review

    Phase 2 — in-batch dedup:
      Remaining unmatched records are clustered pairwise by normalised (name+city).
      Within each cluster the most complete VenueData wins as canonical; others get
      canonical_gig_key set so their venue_id is copied from the canonical.

    SQL consequences (handled in _venue_update_sql / _venue_id_reassign_sql):
      matched_venue_id set  → UPDATE gigs SET venue_id = X
                              UPDATE venues SET field = COALESCE(field, val) WHERE id = X
      canonical_gig_key set → UPDATE gigs SET venue_id = (subquery for canonical)
                              (no venue field update — canonical's record covers it)
      neither               → UPDATE venues SET field = val  (current behaviour)
    """
    from difflib import SequenceMatcher  # stdlib, no new dep

    def _sim(a: str, b: str) -> float:
        return SequenceMatcher(None, a, b).ratio()

    candidates_log: list[str] = []

    # ── Phase 1: DB matching ─────────────────────────────────────────────────
    # Exclude city-only placeholder rows (name == city, no real venue name).
    db_keyed: list[tuple[str, dict]] = []
    for v in db_venues:
        name = (v.get("name") or "").strip()
        city = (v.get("city") or "").strip()
        if name and name.lower() != city.lower():
            db_keyed.append((_venue_match_key(name, city), v))

    for gd in records:
        if not gd.venue.name:
            continue
        query = _venue_match_key(gd.venue.name, gd.venue.city)
        if not query:
            continue

        best_score  = 0.0
        best_venue: Optional[dict] = None
        near_misses: list[tuple[float, dict]] = []

        for db_key, v in db_keyed:
            score = _sim(query, db_key)
            if score >= auto_threshold:
                if score > best_score:
                    best_score = score
                    best_venue = v
            elif score >= candidate_threshold:
                near_misses.append((score, v))

        if best_venue is not None:
            gd.matched_venue_id = best_venue["id"]
        elif near_misses:
            near_misses.sort(key=lambda x: -x[0])
            s, v = near_misses[0]
            candidates_log.append(
                f"  DB-CANDIDATE  {s:.2f}"
                f"  [{gd.customer_name} {gd.gig_date} | {gd.venue.name}]"
                f"  →  [id={v['id']} {v.get('name')} / {v.get('city')}]"
            )

    # ── Phase 2: in-batch dedup ──────────────────────────────────────────────
    unmatched = [gd for gd in records
                 if gd.matched_venue_id is None and gd.canonical_gig_key is None
                 and gd.venue.name]

    assigned: set[int] = set()   # id(gd) objects already assigned to a cluster

    for i, gd in enumerate(unmatched):
        if id(gd) in assigned:
            continue
        key_i = _venue_match_key(gd.venue.name, gd.venue.city)
        cluster = [gd]

        for j in range(i + 1, len(unmatched)):
            other = unmatched[j]
            if id(other) in assigned:
                continue
            key_j = _venue_match_key(other.venue.name, other.venue.city)
            score = _sim(key_i, key_j)
            if score >= auto_threshold:
                cluster.append(other)
                assigned.add(id(other))
            elif score >= candidate_threshold:
                candidates_log.append(
                    f"  BATCH-CANDIDATE  {score:.2f}"
                    f"  [{gd.customer_name} {gd.gig_date} | {gd.venue.name}]"
                    f"  ~  [{other.customer_name} {other.gig_date} | {other.venue.name}]"
                )

        if len(cluster) > 1:
            cluster.sort(key=lambda x: _venue_completeness(x.venue), reverse=True)
            canonical = cluster[0]
            for dup in cluster[1:]:
                dup.canonical_gig_key = (canonical.gig_date, canonical.customer_name)

    # ── Summary ───────────────────────────────────────────────────────────────
    n_db    = sum(1 for gd in records if gd.matched_venue_id is not None)
    n_batch = sum(1 for gd in records if gd.canonical_gig_key is not None)
    print(f"  Venue dedup: {n_db} DB-matched · {n_batch} in-batch deduped", file=sys.stderr)
    if candidates_log:
        print(f"  Venue candidates for manual review ({len(candidates_log)}):", file=sys.stderr)
        for line in candidates_log:
            print(line, file=sys.stderr)


# ---------------------------------------------------------------------------
# SQL helpers
# ---------------------------------------------------------------------------

def _sq(val) -> str:
    if val is None:
        return "NULL"
    return "'" + str(val).replace("\\", "\\\\").replace("'", "\\'") + "'"


def _sf(val: Optional[float]) -> str:
    return str(val) if val is not None else "NULL"


def _si(val: Optional[int]) -> str:
    return str(val) if val is not None else "NULL"


def _gig_match_where(gig_date: date, customer_name: str) -> str:
    return (
        f"g.gig_date = {_sq(gig_date.isoformat())} "
        f"AND c.name = {_sq(customer_name)} "
        f"AND g.id BETWEEN 1 AND 9999"
    )


def _venue_id_reassign_sql(gd: GigData) -> Optional[str]:
    """Emit a venue_id reassignment UPDATE for DB-matched or in-batch duplicate gigs."""
    where = _gig_match_where(gd.gig_date, gd.customer_name)
    if gd.matched_venue_id is not None:
        return (
            f"UPDATE gigs g\n"
            f"  JOIN customers c ON g.customer_id = c.id\n"
            f"SET g.venue_id = {gd.matched_venue_id}\n"
            f"WHERE {where};"
        )
    if gd.canonical_gig_key is not None:
        canon_date, canon_cust = gd.canonical_gig_key
        return (
            f"UPDATE gigs g\n"
            f"  JOIN customers c ON g.customer_id = c.id\n"
            f"  JOIN (\n"
            f"    SELECT g2.venue_id FROM gigs g2\n"
            f"    JOIN customers c2 ON g2.customer_id = c2.id\n"
            f"    WHERE g2.gig_date = {_sq(canon_date.isoformat())}\n"
            f"      AND c2.name = {_sq(canon_cust)}\n"
            f"      AND g2.id BETWEEN 1 AND 9999\n"
            f"  ) canonical\n"
            f"SET g.venue_id = canonical.venue_id\n"
            f"WHERE {where};"
        )
    return None


def _venue_update_sql(gd: GigData) -> Optional[str]:
    """Emit a venue fields UPDATE.

    - canonical_gig_key set (in-batch non-canonical): skip — canonical gig covers it.
    - matched_venue_id set (DB match): COALESCE-guard each field to avoid overwriting
      real data that may already exist on the matched row.
    - neither: standard UPDATE via gig JOIN (the 1:1 placeholder row from extract_gigs).
    """
    if gd.canonical_gig_key is not None:
        return None  # non-canonical duplicate; canonical's record handles venue fields

    v = gd.venue
    sets: list[str] = []

    if gd.matched_venue_id is not None:
        # Fill only NULL fields on the existing venue row.
        # For `name` we use NULLIF(name, city) before COALESCE: extract_gigs.py seeds
        # placeholder rows with name = city (e.g. name = 'Turku'), which is non-NULL
        # and would suppress a plain COALESCE.  NULLIF turns that sentinel back to NULL
        # so the real venue name is written in.  Genuine names (name != city) survive.
        if v.name:
            sets.append(f"name = COALESCE(NULLIF(name, city), {_sq(v.name)})")
        if v.address_line:
            sets.append(f"address_line = COALESCE(address_line, {_sq(v.address_line)})")
        if v.postal_code:
            sets.append(f"postal_code = COALESCE(postal_code, {_sq(v.postal_code)})")
        if v.city:
            sets.append(f"city = COALESCE(city, {_sq(v.city)})")
        if v.distance_from_turku_km is not None:
            sets.append(
                f"distance_from_turku_km = COALESCE(distance_from_turku_km, "
                f"{_sf(v.distance_from_turku_km)})"
            )
        if not sets:
            return None
        return (
            f"UPDATE venues\n"
            f"SET {', '.join(sets)}\n"
            f"WHERE id = {gd.matched_venue_id};"
        )

    # No match: update the 1:1 placeholder row via gig JOIN
    if v.name:
        sets.append(f"v.name = {_sq(v.name)}")
    if v.address_line:
        sets.append(f"v.address_line = {_sq(v.address_line)}")
    if v.postal_code:
        sets.append(f"v.postal_code = {_sq(v.postal_code)}")
    if v.city:
        sets.append(f"v.city = {_sq(v.city)}")
    if v.distance_from_turku_km is not None:
        sets.append(f"v.distance_from_turku_km = {_sf(v.distance_from_turku_km)}")
    if not sets:
        return None
    where = _gig_match_where(gd.gig_date, gd.customer_name)
    return (
        f"UPDATE venues v\n"
        f"  JOIN gigs g ON g.venue_id = v.id\n"
        f"  JOIN customers c ON g.customer_id = c.id\n"
        f"SET {', '.join(sets)}\n"
        f"WHERE {where};"
    )


def _gig_update_sql(gd: GigData) -> Optional[str]:
    sets: list[str] = []

    # Direct overwrite fields
    if gd.car1_distance_km is not None:
        sets.append(f"g.car1_distance_km = {_sf(gd.car1_distance_km)}")
    if gd.car2_distance_km is not None:
        sets.append(f"g.car2_distance_km = {_sf(gd.car2_distance_km)}")
    if gd.other_travel_costs_cents is not None:
        sets.append(f"g.other_travel_costs_cents = {_si(gd.other_travel_costs_cents)}")
    if gd.order_description:
        sets.append(f"g.order_description = {_sq(gd.order_description)}")
    if gd.pricing_tier1 is not None:
        sets.append(f"g.pricing_tier1 = {1 if gd.pricing_tier1 else 0}")
    if gd.pricing_tier2 is not None:
        sets.append(f"g.pricing_tier2 = {1 if gd.pricing_tier2 else 0}")
    for attr, col in (
        ("qty_ennakkoroudaus",      "qty_ennakkoroudaus"),
        ("qty_song_requests_extra", "qty_song_requests_extra"),
        ("qty_extra_performances",  "qty_extra_performances"),
        ("qty_background_music_h",  "qty_background_music_h"),
        ("qty_live_album",          "qty_live_album"),
        ("discount_cents",          "discount_cents"),
    ):
        val = getattr(gd, attr)
        if val is not None:
            sets.append(f"g.{col} = {_si(val)}")

    # NULL-guarded fields (don't overwrite existing invoice data)
    if gd.quoted_price_cents is not None:
        sets.append(
            f"g.quoted_price_cents = IF(g.quoted_price_cents IS NULL, "
            f"{_si(gd.quoted_price_cents)}, g.quoted_price_cents)"
        )
    if gd.base_price_cents is not None:
        sets.append(
            f"g.base_price_cents = IF(g.base_price_cents IS NULL OR g.base_price_cents = 0, "
            f"{_si(gd.base_price_cents)}, g.base_price_cents)"
        )

    if not sets:
        return None
    where = _gig_match_where(gd.gig_date, gd.customer_name)
    return (
        f"UPDATE gigs g\n"
        f"  JOIN customers c ON g.customer_id = c.id\n"
        f"SET {', '.join(sets)}\n"
        f"WHERE {where};"
    )


def _contact_update_sql(gd: GigData) -> Optional[str]:
    sets: list[str] = []
    if gd.contact_email:
        sets.append(f"ct.email = {_sq(gd.contact_email)}")
    if gd.contact_phone:
        sets.append(f"ct.phone = {_sq(gd.contact_phone)}")
    if not sets:
        return None
    where = _gig_match_where(gd.gig_date, gd.customer_name)
    return (
        f"UPDATE contacts ct\n"
        f"  JOIN customer_contacts cc ON cc.contact_id = ct.id\n"
        f"  JOIN customers c ON cc.customer_id = c.id\n"
        f"  JOIN gigs g ON g.customer_id = c.id\n"
        f"SET {', '.join(sets)}\n"
        f"WHERE {where}\n"
        f"  AND ct.id BETWEEN 1 AND 9999;"
    )


# ---------------------------------------------------------------------------
# File collection helpers
# ---------------------------------------------------------------------------

def _collect_unique(globs: list[str]) -> list[Path]:
    seen: set[Path] = set()
    result: list[Path] = []
    for pattern in globs:
        for f in sorted(glob.glob(pattern, recursive=True)):
            p = Path(f).resolve()
            if p not in seen:
                seen.add(p)
                result.append(Path(f))
    return result


def _is_template_file(path: Path) -> bool:
    """Skip known template/scaffold files."""
    name = path.stem.lower()
    return (
        "yymmdd" in name
        or "saturday" in name
        or re.search(r"[xX]{4,}", path.name) is not None
    )


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> int:
    parser = argparse.ArgumentParser(
        description="Enrich legacy gig records from gig-info and price-calculator files."
    )
    parser.add_argument("--dry-run", action="store_true",
                        help="Print SQL to stdout instead of writing files.")
    parser.add_argument("--stats", action="store_true",
                        help="Print counts only; write no files.")
    args = parser.parse_args()

    # ── 1. Collect files ──────────────────────────────────────────────────────
    info_files = [f for f in _collect_unique(GIGS_INFO_GLOBS)
                  if not _is_template_file(f)]
    pc_files   = [f for f in _collect_unique(PRICE_CALC_GLOBS)
                  if not _is_template_file(f)]

    print(f"  Found {len(info_files)} gig-info files, {len(pc_files)} price-calculator files",
          file=sys.stderr)

    # ── 2. Parse gig-info files ───────────────────────────────────────────────
    # Key: (date_iso, customer_name_normalised) → GigData
    gig_map: dict[tuple[str, str], GigData] = {}
    unmatched: list[GigData] = []
    fmt_counts: dict[str, int] = {}

    for fp in info_files:
        gd = parse_gig_info_file(fp)
        fmt_counts[gd.format_gen] = fmt_counts.get(gd.format_gen, 0) + 1

        if gd.gig_date is None or not gd.customer_name:
            unmatched.append(gd)
            continue

        key = (gd.gig_date.isoformat(), _normalise_name(gd.customer_name))
        gig_map[key] = gd

    print(
        f"  gig-info: {len(gig_map)} matchable"
        f"  [{fmt_counts.get('new',0)} new · "
        f"{fmt_counts.get('intermediate',0)} intermediate · "
        f"{fmt_counts.get('old',0)} old]"
        f"  {len(unmatched)} unmatched",
        file=sys.stderr
    )

    # ── 3. Parse price-calculator files and merge into gig_map ───────────────
    pc_matched = 0
    pc_unmatched: list[Path] = []

    for fp in pc_files:
        # Date from filename
        m = re.search(r"price-calculator-(\d{6})", fp.name)
        if not m:
            pc_unmatched.append(fp)
            continue
        gig_date = _parse_yymmdd(m.group(1))
        if gig_date is None:
            pc_unmatched.append(fp)
            continue

        # Customer name: prefer sibling gig-info Asiakas: field, else dir slug
        customer_name: Optional[str] = None
        sibling_infos = list(fp.parent.glob("gig-info-*.txt"))
        if sibling_infos:
            try:
                sibling_content = sibling_infos[0].read_text(encoding="utf-8")
                m2 = re.search(r"^Asiakas\s*:\s*(.+)$", sibling_content, re.MULTILINE)
                if m2:
                    customer_name = m2.group(1).strip()
            except Exception:
                pass
        if not customer_name:
            slug_parts = fp.parent.name.split("-", 1)
            if len(slug_parts) == 2:
                customer_name = _slug_to_name(slug_parts[1])

        if not customer_name:
            pc_unmatched.append(fp)
            continue

        key = (gig_date.isoformat(), _normalise_name(customer_name))
        if key in gig_map:
            gd = gig_map[key]
        else:
            # Price-calculator with no matching gig-info — create a stub
            gd = GigData(file_path=str(fp), gig_date=gig_date,
                         customer_name=customer_name)
            gig_map[key] = gd

        _load_price_calculator(fp, gd)
        pc_matched += 1

    print(f"  price-calculator: {pc_matched} merged, {len(pc_unmatched)} unmatched",
          file=sys.stderr)

    # ── 3b. Venue matching / dedup pass ───────────────────────────────────────
    records = list(gig_map.values())
    db_conn = _try_connect_db()
    db_venues: list[dict] = []
    if db_conn:
        try:
            db_venues = _load_db_venues(db_conn)
            print(f"  DB: {len(db_venues)} venue rows loaded for matching", file=sys.stderr)
        except Exception as e:
            print(f"  DB venue load failed: {e}", file=sys.stderr)
        finally:
            db_conn.close()
    else:
        print("  DB: no connection — venue matching skipped (install pymysql and ensure .env is set)",
              file=sys.stderr)
    _match_venues(records, db_venues)

    # ── 4. Count enrichment types ─────────────────────────────────────────────
    n_venue_fields = sum(1 for r in records if _venue_update_sql(r) is not None)
    n_venue_id     = sum(1 for r in records if _venue_id_reassign_sql(r) is not None)
    n_gig          = sum(1 for r in records if _gig_update_sql(r) is not None)
    n_contacts     = sum(1 for r in records if _contact_update_sql(r) is not None)
    n_with_tiers   = sum(1 for r in records if r.pricing_tier1 is not None)
    print(
        f"  Updates: {n_venue_fields} venue-fields · {n_venue_id} venue-id · "
        f"{n_gig} gig · {n_contacts} contact  "
        f"({n_with_tiers} records with pricing tier data)",
        file=sys.stderr
    )

    if args.stats:
        return 0

    # ── 5. Build SQL ──────────────────────────────────────────────────────────
    now_ts = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")
    sql_blocks: list[str] = []
    sql_blocks.append(f"""\
-- ===========================================================================
-- LEGACY GIGS ENRICHMENT
-- Generated by cli/etl/enrich_gigs.py  on  {now_ts} UTC
-- Sources: gig-info-*.txt ({len(info_files)} files) + price-calculator-*.xlsx ({len(pc_files)} files)
--
-- Prerequisites: load db/seeds/legacy_gigs.sql first.
-- Safe to re-run: all UPDATE statements are idempotent.
-- quoted/base_price_cents only written where currently NULL.
-- ===========================================================================
""")

    for gd in sorted(records, key=lambda r: (r.gig_date or date.min, r.customer_name or "")):
        stmts: list[str] = []
        vid = _venue_id_reassign_sql(gd)
        if vid:
            stmts.append(vid)
        v = _venue_update_sql(gd)
        if v:
            stmts.append(v)
        g = _gig_update_sql(gd)
        if g:
            stmts.append(g)
        c = _contact_update_sql(gd)
        if c:
            stmts.append(c)

        if stmts:
            rel = ", ".join(gd.sources) if gd.sources else Path(gd.file_path).name
            sql_blocks.append(
                f"-- {rel}\n" + "\n".join(stmts) + "\n"
            )

    sql = "\n".join(sql_blocks)

    # ── 6. Write output ───────────────────────────────────────────────────────
    if args.dry_run:
        print(sql)
    else:
        OUTPUT_SQL.parent.mkdir(parents=True, exist_ok=True)
        OUTPUT_SQL.write_text(sql, encoding="utf-8")
        print(f"  Wrote {OUTPUT_SQL.relative_to(REPO_ROOT)}", file=sys.stderr)

    # ── 7. Unmatched report ───────────────────────────────────────────────────
    report_lines = [
        "# Unmatched / unenriched files",
        f"# Generated: {now_ts} UTC",
        "",
        "## gig-info files (missing date or customer name)",
    ]
    for gd in unmatched:
        rel = str(Path(gd.file_path).relative_to(REPO_ROOT))
        w = "; ".join(gd.parse_warnings) if gd.parse_warnings else "no warnings"
        report_lines.append(
            f"  {rel}  "
            f"[fmt={gd.format_gen}  date={gd.gig_date}  "
            f"customer={gd.customer_name!r}  {w}]"
        )

    report_lines += ["", "## price-calculator files (no date/customer match)"]
    for fp in pc_unmatched:
        rel = str(fp.relative_to(REPO_ROOT))
        report_lines.append(f"  {rel}")

    report_text = "\n".join(report_lines)

    if args.dry_run:
        print("\n--- Unmatched ---", file=sys.stderr)
        print(report_text, file=sys.stderr)
    else:
        UNMATCHED.write_text(report_text, encoding="utf-8")
        n_total = len(unmatched) + len(pc_unmatched)
        print(f"  Wrote {UNMATCHED.relative_to(REPO_ROOT)} ({n_total} items)",
              file=sys.stderr)

    return 0


if __name__ == "__main__":
    sys.exit(main())

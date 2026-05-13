#!/usr/bin/env python3
"""
cli/etl/extract_setlists.py

Parse per-gig setlist files into setlists + setlist_songs INSERT statements.

Sources
-------
  old-files/past-gigs/YY/YYMMDD-customer/
    setlist-gig-YYMMDD.txt       Primary (2023+).  Three format eras:
                                   Era 2: bare numeric codes, no tabs
                                   Era 3: prefix + tab + content (current)
    setlist-internal-YYMMDD.txt  Fallback for 2021-2022 only.  Era 1 format.

  When both exist in the same directory (2023 gigs), setlist-gig wins.

Output
------
  db/seeds/legacy_setlists.sql
  db/seeds/legacy_setlists_unmatched.txt

Prerequisites
-------------
  Running dev DB (make dev) with:
    - gigs table populated (import-legacy-gigs)
    - songs table populated (etl-songs → import-legacy-songs)
  pymysql package installed.

Usage
-----
  python cli/etl/extract_setlists.py
  python cli/etl/extract_setlists.py --dry-run
  python cli/etl/extract_setlists.py --stats
"""

from __future__ import annotations

import argparse
import difflib
import re
import sys
import unicodedata
from dataclasses import dataclass, field
from datetime import date
from pathlib import Path
from typing import Optional

# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------
REPO_ROOT   = Path(__file__).resolve().parent.parent.parent
PAST_GIGS   = REPO_ROOT / "old-files" / "past-gigs"
OUTPUT_SQL  = REPO_ROOT / "db" / "seeds" / "legacy_setlists.sql"
UNMATCHED   = REPO_ROOT / "db" / "seeds" / "legacy_setlists_unmatched.txt"

# ---------------------------------------------------------------------------
# Normalisation helpers (same logic as extract_songs.py for cross-file match)
# ---------------------------------------------------------------------------

def _norm(s: str) -> str:
    s = unicodedata.normalize("NFKD", s).encode("ascii", "ignore").decode()
    s = s.lower()
    s = re.sub(r"[^\w\s]", " ", s)
    return " ".join(s.split())

def _song_key(artist: str, title: str) -> str:
    return _norm(artist + " " + title)

# ---------------------------------------------------------------------------
# SQL helpers
# ---------------------------------------------------------------------------

def _sq(val: Optional[str]) -> str:
    if val is None:
        return "NULL"
    return "'" + str(val).replace("\\", "\\\\").replace("'", "\\'") + "'"

def _si(val: Optional[int]) -> str:
    return str(val) if val is not None else "NULL"

# ---------------------------------------------------------------------------
# Data model
# ---------------------------------------------------------------------------

@dataclass
class SongEntry:
    """One song slot within a set as parsed from the file."""
    artist: Optional[str]   # None for jazz title-only instrumentals
    title:  str
    notes:  Optional[str]   # annotation or singer label

@dataclass
class SetEntry:
    """One set within a gig setlist."""
    set_number: int          # sequential within gig (1-based, regardless of type)
    set_type:   str          # 'set' | 'lounge' | 'encore' | 'karaoke'
    name:       Optional[str]  # NULL for regular sets (UI shows "Set N")
    songs:      list[SongEntry] = field(default_factory=list)

# ---------------------------------------------------------------------------
# Date helpers
# ---------------------------------------------------------------------------

def _parse_yymmdd(s: str) -> Optional[date]:
    m = re.match(r"^(\d{2})(\d{2})(\d{2})$", s)
    if not m:
        return None
    try:
        return date(2000 + int(m.group(1)), int(m.group(2)), int(m.group(3)))
    except ValueError:
        return None

# ---------------------------------------------------------------------------
# File discovery
# ---------------------------------------------------------------------------

def find_setlist_files() -> list[tuple[date, Path]]:
    """Return (event_date, file_path) sorted by date.

    Prefers setlist-gig-*.txt over setlist-internal-*.txt when both exist.
    """
    results: list[tuple[date, Path]] = []
    for gig_dir in sorted(PAST_GIGS.glob("*/*")):
        if not gig_dir.is_dir():
            continue
        # Extract YYMMDD from directory name (e.g. 240831-rapuilmio → 240831)
        m = re.match(r"^(\d{6})", gig_dir.name)
        if not m:
            continue
        d = _parse_yymmdd(m.group(1))
        if d is None:
            continue
        # Prefer setlist-gig over setlist-internal
        gig_files    = sorted(gig_dir.glob("setlist-gig-*.txt"))
        intern_files = sorted(gig_dir.glob("setlist-internal-*.txt"))
        chosen = gig_files[0] if gig_files else (intern_files[0] if intern_files else None)
        if chosen:
            results.append((d, chosen))
    return results

# ---------------------------------------------------------------------------
# Set header parsing
# ---------------------------------------------------------------------------

# Era 1 (setlist-internal): plain text headers with optional stats in parens.
_ERA1_SETTI_RE  = re.compile(r"^SETTI\s+(\d+)\b", re.IGNORECASE)
_ERA1_ENCORE_RE = re.compile(r"^ENCORE\b", re.IGNORECASE)
_ERA1_LOUNGE_RE = re.compile(r"^(LOUNGE|JAZZ|VIIHDE)\b", re.IGNORECASE)
_ERA1_KARAOKE_RE= re.compile(r"^KARAOKE-SETTI\b", re.IGNORECASE)

# Era 2/3 (setlist-gig): dashed separators.
_ERA23_HDR_RE   = re.compile(r"^-{5,}\s+(.+?)\s+-{5,}$")
_SETTI_NUM_RE   = re.compile(r"^SETTI\s+(\d+)\b", re.IGNORECASE)
_KARAOKE_RE     = re.compile(r"^KARAOKE", re.IGNORECASE)
_ENCORE_RE      = re.compile(r"^ENCORE\b", re.IGNORECASE)
_LOUNGE_RE      = re.compile(r"^(LOUNGE|JAZZ|VIIHDE)\b", re.IGNORECASE)


def _parse_set_header_era1(line: str, seq: int) -> Optional[SetEntry]:
    """Try to parse an Era 1 set header line.  Returns SetEntry with empty songs list."""
    s = line.strip()
    if _ERA1_SETTI_RE.match(s):
        n = int(_ERA1_SETTI_RE.match(s).group(1))
        return SetEntry(set_number=seq, set_type="set", name=None)
    if _ERA1_ENCORE_RE.match(s):
        return SetEntry(set_number=seq, set_type="encore", name="Encore")
    if _ERA1_LOUNGE_RE.match(s):
        return SetEntry(set_number=seq, set_type="lounge", name=s.split("(")[0].strip())
    if _ERA1_KARAOKE_RE.match(s):
        return SetEntry(set_number=seq, set_type="karaoke", name="Karaoke")
    return None


def _parse_set_header_era23(line: str, seq: int) -> Optional[SetEntry]:
    """Try to parse an Era 2/3 dashed header line."""
    m = _ERA23_HDR_RE.match(line.strip())
    if not m:
        return None
    label = m.group(1).strip()
    if _SETTI_NUM_RE.match(label):
        return SetEntry(set_number=seq, set_type="set", name=None)
    if _KARAOKE_RE.match(label):
        return SetEntry(set_number=seq, set_type="karaoke",
                        name=label.split("(")[0].strip())
    if _ENCORE_RE.match(label):
        return SetEntry(set_number=seq, set_type="encore", name="Encore")
    if _LOUNGE_RE.match(label):
        return SetEntry(set_number=seq, set_type="lounge",
                        name=label.split("(")[0].strip())
    # Unknown label — skip
    return None

# ---------------------------------------------------------------------------
# Song line parsing helpers
# ---------------------------------------------------------------------------

_KEY_SHORTHAND_RE = re.compile(r"^[A-Ga-g][b#]?m?$")
_ANNOTATION_RE    = re.compile(r"\s*\(([^)]+)\)\s*$")

# Matches "YYMMDD Customer" Era-1 sub-section headers (skip these)
_ERA1_SUBSECTION_RE = re.compile(r"^\d{6}\b")


def _strip_annotation(text: str) -> tuple[str, Optional[str]]:
    """Strip trailing (ANNOTATION) from text.  Returns (clean_text, annotation_or_None)."""
    m = _ANNOTATION_RE.search(text)
    if m:
        return text[:m.start()].strip(), m.group(1).strip()
    return text.strip(), None


def _strip_key_and_gtr2(text: str) -> str:
    """Remove trailing key shorthand and GTR2 marker from song content."""
    text = re.sub(r"!!!+\s*$", "", text)  # ad-hoc transposition marker
    text = re.sub(r"\s+GTR2\s*$", "", text)
    m = re.search(r"\s{3,}(\S+)\s*$", text)
    if m and _KEY_SHORTHAND_RE.match(m.group(1)):
        text = text[:m.start()]
    return text.strip()


def _split_artist_title(text: str) -> tuple[Optional[str], Optional[str]]:
    for sep in (" – ", " - "):  # en-dash then hyphen
        idx = text.find(sep)
        if idx > 0:
            return text[:idx].strip(), text[idx + len(sep):].strip()
    return None, None


def _extract_singer(text: str) -> tuple[Optional[str], str]:
    """If text has 'Singer: Artist – Title', split off singer.

    Returns (singer_or_None, remaining_text).
    """
    colon = text.find(": ")
    endash = text.find(" – ")
    if colon > 0 and (endash < 0 or colon < endash):
        return text[:colon].strip(), text[colon + 2:].strip()
    return None, text


_LEADING_ANNOTATION_RE = re.compile(r"^\([^)]+\)\s*")


def _parse_song_content(text: str) -> Optional[SongEntry]:
    """Parse 'Artist – Song Name' (with optional singer prefix, key, GTR2, annotation)."""
    if not text.strip():
        return None
    text = _strip_key_and_gtr2(text)
    text, annotation = _strip_annotation(text)
    singer, text = _extract_singer(text)
    # Strip leading conditional notes like "(JOS NÄYTTÄÄ SILTÄ) Artist – Title"
    text = _LEADING_ANNOTATION_RE.sub("", text).strip()
    artist, title = _split_artist_title(text)
    if not artist or not title:
        return None
    notes = singer or annotation
    return SongEntry(artist=artist, title=title, notes=notes)


# ---------------------------------------------------------------------------
# Era 1 parser
# ---------------------------------------------------------------------------

_ERA1_TEMPLATE_MARKER = re.compile(r"^Artist\s+[-–]\s+Song Name$")
_ERA1_NUMBERING_RE    = re.compile(r"^\d+\s+")
_ERA1_SKIP_SECTIONS   = {"TYOMAA", "VARABIISIT", "SETTIBIISIT"}


def _parse_era1(path: Path) -> list[SetEntry]:
    lines = path.read_text(encoding="utf-8").splitlines()
    sets: list[SetEntry] = []
    current: Optional[SetEntry] = None
    seq = 0
    skip_section = False

    for line in lines:
        s = line.strip()
        if not s:
            continue
        if s.startswith("("):
            continue  # stats/preamble line
        if s.startswith("- "):
            continue  # bullet note (key info, etc.)
        if _ERA1_TEMPLATE_MARKER.match(s):
            continue  # template placeholder
        if _ERA1_SUBSECTION_RE.match(s) and " " in s:
            continue  # YYMMDD sub-header within RUNKOSETIN ULKOPUOLISET
        # Check for skip-section labels (TYOMAA etc.)
        if s.upper() in {x.upper() for x in _ERA1_SKIP_SECTIONS} or re.match(
            r"^(" + "|".join(_ERA1_SKIP_SECTIONS) + r")\b", s, re.IGNORECASE
        ):
            skip_section = True
            current = None
            continue
        # Try set header
        seq_try = seq + 1
        new_set = _parse_set_header_era1(s, seq_try)
        if new_set is not None:
            skip_section = False
            seq = seq_try
            current = new_set
            sets.append(current)
            continue
        if skip_section or current is None:
            continue
        # Strip optional leading number prefix (e.g. "1 Olavi Virta – ...")
        song_text = _ERA1_NUMBERING_RE.sub("", s, count=1)
        entry = _parse_song_content(song_text)
        if entry:
            current.songs.append(entry)

    return sets


# ---------------------------------------------------------------------------
# Era 2 / Era 3 combined parser
# ---------------------------------------------------------------------------

_ERA2_PREFIX_RE   = re.compile(r"^(---|\d{3}|XXX|[a-zA-Z])\s+(.+)$")
_ETSI_RE          = re.compile(r"^ETSI\s+", re.IGNORECASE)
_ALT_SONG_RE      = re.compile(r"\s//\s")   # "Song A // Song B" = alternative; skip


def _parse_song_line_era23(line: str, is_era3: bool) -> Optional[SongEntry]:
    """Parse one song line for Era 2 or Era 3 files."""
    s = line.rstrip()
    if not s:
        return None
    if _ETSI_RE.match(s):
        return None  # placeholder, song not yet prepared
    if _ALT_SONG_RE.search(s):
        return None  # "Song A // Song B" = alternative options; not a committed song

    if is_era3 and "\t" in s:
        # Era 3: [prefix]\t[content]
        parts = s.split("\t", 1)
        content = parts[1] if len(parts) > 1 else ""
        return _parse_song_content(content)
    elif not is_era3:
        # Era 2: [NNN|---|XXX|letter] [content]  or bare [content]
        m = _ERA2_PREFIX_RE.match(s)
        if m:
            return _parse_song_content(m.group(2))
        # No recognised prefix — try bare artist–title
        return _parse_song_content(s)
    else:
        # Era 3 file but no tab: could be a title-only jazz line in a lounge set
        # or a bare song without prefix (rare edge case)
        if " – " in s or " - " in s:
            return _parse_song_content(s)
        # Return as title-only jazz entry if it looks like a song name (not a header/note)
        if s and not s.startswith("-") and not re.match(r"^[A-Z]{5,}$", s):
            return SongEntry(artist=None, title=s, notes=None)
        return None


def _parse_era23(path: Path) -> list[SetEntry]:
    text = path.read_text(encoding="utf-8")
    lines = text.splitlines()
    is_era3 = any("\t" in ln for ln in lines)  # at least one tabbed song line

    sets: list[SetEntry] = []
    current: Optional[SetEntry] = None
    seq = 0

    for line in lines:
        s = line.rstrip()
        if not s:
            continue
        # Try Era 2/3 dashed header
        new_set = _parse_set_header_era23(s, seq + 1)
        if new_set is not None:
            seq += 1
            current = new_set
            sets.append(current)
            continue
        if current is None:
            continue
        # Song line (tab or space separated)
        entry = _parse_song_line_era23(s, is_era3)
        if entry:
            current.songs.append(entry)

    return sets


# ---------------------------------------------------------------------------
# Top-level setlist file parser
# ---------------------------------------------------------------------------

def parse_setlist_file(path: Path) -> list[SetEntry]:
    """Auto-detect era and return parsed SetEntry list."""
    if "setlist-internal-" in path.name:
        return _parse_era1(path)
    return _parse_era23(path)

# ---------------------------------------------------------------------------
# DB connection (mirrors enrich_gigs.py)
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
    try:
        import pymysql  # type: ignore
    except ImportError:
        return None
    env = _load_dot_env()
    dev_env = REPO_ROOT / ".env.dev"
    if dev_env.exists():
        env.update(_load_dot_env(dev_env))
    host     = env.get("ETL_DB_HOST") or env.get("DB_HOST", "127.0.0.1")
    port     = int(env.get("ETL_DB_PORT") or env.get("DB_PORT", "3306"))
    user     = env.get("ETL_DB_USER")     or env.get("MYSQL_USER")
    password = env.get("ETL_DB_PASSWORD") or env.get("MYSQL_PASSWORD")
    dbname   = env.get("MYSQL_DATABASE")
    if not all([user, password, dbname]):
        return None
    try:
        import pymysql  # type: ignore
        return pymysql.connect(
            host=host, port=port, user=user, password=password,
            database=dbname, charset="utf8mb4", connect_timeout=5,
        )
    except Exception as e:
        print(f"  DB connect failed: {e}", file=sys.stderr)
        return None

# ---------------------------------------------------------------------------
# DB lookups
# ---------------------------------------------------------------------------

def load_songs(conn) -> tuple[dict[str, int], dict[str, int], list[tuple[str, int]]]:
    """
    Returns:
      exact_map  — {_song_key(artist, title) → song_id}
      jazz_map   — {_norm(title) → song_id}  for is_jazz=1
      all_songs  — [(norm_key, song_id)] for fuzzy fallback
    """
    exact_map: dict[str, int] = {}
    jazz_map:  dict[str, int] = {}
    all_songs: list[tuple[str, int]] = []

    with conn.cursor() as cur:
        cur.execute(
            "SELECT id, artist, title, is_jazz "
            "FROM songs WHERE id BETWEEN 1 AND 9999 AND deleted_at IS NULL"
        )
        for (sid, artist, title, is_jazz) in cur.fetchall():
            k = _song_key(artist, title)
            exact_map[k] = sid
            all_songs.append((k, sid))
            if is_jazz:
                nt = _norm(title)
                jazz_map[nt] = sid

    return exact_map, jazz_map, all_songs


def load_gigs(conn) -> dict[str, int]:
    """Returns {YYYY-MM-DD → gig_id}."""
    gig_map: dict[str, int] = {}
    with conn.cursor() as cur:
        cur.execute(
            "SELECT id, gig_date FROM gigs "
            "WHERE deleted_at IS NULL AND id BETWEEN 1 AND 9999"
        )
        for (gid, gig_date) in cur.fetchall():
            if gig_date:
                gig_map[str(gig_date)] = gid
    return gig_map

# ---------------------------------------------------------------------------
# Song matching
# ---------------------------------------------------------------------------

_FUZZY_THRESHOLD = 0.80


def _prefix_match(k: str, all_songs: list[tuple[str, int]]) -> Optional[int]:
    """Return song_id when k is a leading prefix of a DB song key (or vice versa).

    Handles title truncations like "Good Riddance" → "Good Riddance Time of Your Life".
    Only matches when the shorter key is at least 10 chars to avoid spurious hits.
    """
    if len(k) < 10:
        return None
    for (nk, sid) in all_songs:
        if nk.startswith(k) or k.startswith(nk):
            if len(nk) >= 10:
                return sid
    return None


def match_song(
    entry: SongEntry,
    exact_map: dict[str, int],
    jazz_map:  dict[str, int],
    all_songs: list[tuple[str, int]],
) -> Optional[int]:
    """Return song_id for a parsed SongEntry, or None if unresolved."""
    if entry.artist:
        k = _song_key(entry.artist, entry.title)
        # 1. Exact match
        if k in exact_map:
            return exact_map[k]
        # 2. Prefix/suffix match (handles truncated titles)
        sid = _prefix_match(k, all_songs)
        if sid is not None:
            return sid
        # 3. Fuzzy match
        keys = [s[0] for s in all_songs]
        close = difflib.get_close_matches(k, keys, n=1, cutoff=_FUZZY_THRESHOLD)
        if close:
            return exact_map.get(close[0]) or next(
                (sid for nk, sid in all_songs if nk == close[0]), None
            )
        return None
    else:
        # Jazz title-only
        nt = _norm(entry.title)
        if nt in jazz_map:
            return jazz_map[nt]
        # Fuzzy title-only against jazz songs
        jazz_keys = list(jazz_map.keys())
        close = difflib.get_close_matches(nt, jazz_keys, n=1, cutoff=_FUZZY_THRESHOLD)
        if close:
            return jazz_map[close[0]]
        return None

# ---------------------------------------------------------------------------
# SQL generation
# ---------------------------------------------------------------------------

def _generate_gig_sql(
    gig_id:    int,
    gig_date:  str,
    sets:      list[SetEntry],
    exact_map: dict[str, int],
    jazz_map:  dict[str, int],
    all_songs: list[tuple[str, int]],
    unmatched: list[str],
    stats:     dict,
) -> list[str]:
    """Generate SQL lines for one gig.  Mutates unmatched and stats."""
    lines: list[str] = []
    lines.append(f"\n-- Gig {gig_id}  ({gig_date})")
    lines.append(f"DELETE FROM setlist_songs WHERE setlist_id IN "
                 f"(SELECT id FROM setlists WHERE gig_id = {gig_id});")
    lines.append(f"DELETE FROM setlists WHERE gig_id = {gig_id};")

    sl_var_idx = 0

    for s in sets:
        if not s.songs:
            continue
        sl_var_idx += 1
        var = f"@sl{gig_id}_{sl_var_idx}"

        lines.append(
            f"INSERT INTO setlists (gig_id, set_number, set_type, name) VALUES "
            f"({gig_id}, {s.set_number}, {_sq(s.set_type)}, {_sq(s.name)});"
        )
        lines.append(f"SET {var} = LAST_INSERT_ID();")

        slot_lines: list[str] = []
        for order, song in enumerate(s.songs, start=1):
            song_id = match_song(song, exact_map, jazz_map, all_songs)
            if song_id is None:
                label = (
                    f"{song.artist} – {song.title}"
                    if song.artist
                    else song.title
                )
                unmatched.append(f"{gig_date}\t{label}")
                stats["unmatched"] += 1
                continue
            stats["songs_inserted"] += 1
            slot_lines.append(
                f"  ({var}, {song_id}, {order}, {_sq(song.notes)})"
            )

        if slot_lines:
            lines.append(
                "INSERT INTO setlist_songs (setlist_id, song_id, sort_order, notes) VALUES"
            )
            lines.append(",\n".join(slot_lines) + ";")
        stats["sets_inserted"] += 1

    return lines

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> int:
    parser = argparse.ArgumentParser(
        description="Extract per-gig setlists into legacy_setlists.sql."
    )
    parser.add_argument("--dry-run", action="store_true",
                        help="Print SQL to stdout instead of writing file.")
    parser.add_argument("--stats", action="store_true",
                        help="Print match counts only; write no files.")
    args = parser.parse_args()

    # 1. Find all setlist files
    files = find_setlist_files()
    print(f"  Found {len(files)} setlist files", file=sys.stderr)

    # 2. Parse all setlist files
    all_parsed: list[tuple[date, Path, list[SetEntry]]] = []
    for (d, path) in files:
        try:
            sets = parse_setlist_file(path)
        except Exception as e:
            print(f"  PARSE ERROR {path.name}: {e}", file=sys.stderr)
            sets = []
        all_parsed.append((d, path, sets))

    if args.stats:
        total_sets  = sum(len(s) for _, _, s in all_parsed)
        total_songs = sum(sum(len(x.songs) for x in s) for _, _, s in all_parsed)
        print(f"  Sets parsed: {total_sets}", file=sys.stderr)
        print(f"  Songs parsed: {total_songs}", file=sys.stderr)
        # Still attempt DB connect to report match rate
        conn = _try_connect_db()
        if conn:
            exact_map, jazz_map, all_songs = load_songs(conn)
            gig_map = load_gigs(conn)
            print(f"  Songs in DB: {len(exact_map)}", file=sys.stderr)
            print(f"  Gigs in DB: {len(gig_map)}", file=sys.stderr)
            matched_gigs = sum(
                1 for (d, _, _) in all_parsed if str(d) in gig_map
            )
            print(f"  Gig date matches: {matched_gigs}/{len(all_parsed)}", file=sys.stderr)
            conn.close()
        return 0

    # 3. Connect to DB
    conn = _try_connect_db()
    if conn is None:
        print("  ERROR: Cannot connect to DB. "
              "Run 'make dev' and import legacy gigs + songs first.", file=sys.stderr)
        return 1

    print("  Loading songs from DB...", file=sys.stderr)
    exact_map, jazz_map, all_songs = load_songs(conn)
    print(f"    {len(exact_map)} songs loaded", file=sys.stderr)

    print("  Loading gigs from DB...", file=sys.stderr)
    gig_map = load_gigs(conn)
    print(f"    {len(gig_map)} gigs loaded", file=sys.stderr)
    conn.close()

    # 4. Generate SQL
    now_ts = __import__("datetime").datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")
    header = [
        "-- ===========================================================================",
        "-- LEGACY SETLISTS SEED",
        f"-- Generated by cli/etl/extract_setlists.py  on  {now_ts} UTC",
        "--",
        "-- Sources: old-files/past-gigs/*/setlist-gig-*.txt",
        "--          old-files/past-gigs/*/setlist-internal-*.txt",
        "--",
        "-- Prerequisites: migration 014_songs_extension.sql + legacy_songs.sql must",
        "-- be applied, and the gigs table must be populated.",
        "-- ===========================================================================",
        "",
        "SET NAMES utf8mb4;",
        "SET foreign_key_checks = 0;",
        "",
    ]

    sql_lines: list[str] = list(header)
    unmatched: list[str] = []
    stats = {"sets_inserted": 0, "songs_inserted": 0, "unmatched": 0,
             "gigs_found": 0, "gigs_skipped": 0}

    for (d, path, sets) in all_parsed:
        date_str = str(d)
        gig_id = gig_map.get(date_str)
        if gig_id is None:
            stats["gigs_skipped"] += 1
            continue
        stats["gigs_found"] += 1
        gig_sql = _generate_gig_sql(
            gig_id, date_str, sets, exact_map, jazz_map, all_songs, unmatched, stats
        )
        sql_lines.extend(gig_sql)

    sql_lines.append("")
    sql_lines.append("SET foreign_key_checks = 1;")
    sql_lines.append("")

    print(
        f"  Gigs matched: {stats['gigs_found']}, skipped (no DB gig): {stats['gigs_skipped']}",
        file=sys.stderr,
    )
    print(
        f"  Sets: {stats['sets_inserted']}, Songs: {stats['songs_inserted']}, "
        f"Unmatched: {stats['unmatched']}",
        file=sys.stderr,
    )

    sql = "\n".join(sql_lines)

    if args.dry_run:
        print(sql)
    else:
        OUTPUT_SQL.parent.mkdir(parents=True, exist_ok=True)
        OUTPUT_SQL.write_text(sql, encoding="utf-8")
        print(f"  Wrote {OUTPUT_SQL.relative_to(REPO_ROOT)}", file=sys.stderr)

    if unmatched:
        if not args.dry_run:
            UNMATCHED.write_text("\n".join(unmatched) + "\n", encoding="utf-8")
            print(f"  Unmatched songs: {UNMATCHED.relative_to(REPO_ROOT)}", file=sys.stderr)
        else:
            print("\n-- UNMATCHED SONGS:", file=sys.stderr)
            for u in unmatched:
                print(f"  {u}", file=sys.stderr)

    return 0


if __name__ == "__main__":
    sys.exit(main())

#!/usr/bin/env python3
"""
cli/etl/extract_songs.py

Parse the global repertoire files into INSERT statements for the songs table.

Sources
-------
  old-files/info/playlist-gig.txt       Primary: genre, language, hd_slot, guide_tone_key,
                                         key_our (shorthand), has_gtr2, in_repertoire
  old-files/info/playlist-jazz.txt      Jazz songs (is_jazz=1)
  old-files/info/keys.txt               Supplementary: key_our, key_orig, key_transposition_st
  old-files/info/hd-list.txt            HD status: 'done' (TEHTY) | 'pending' (TEKEMÄTTÄ)
  old-files/info/playlist-w-extra-info.txt  release_year

Output
------
  db/seeds/legacy_songs.sql

The output is safe to re-run: starts with DELETE of the legacy range (id 1–9999),
then INSERT with explicit sequential IDs.

Usage
-----
  python cli/etl/extract_songs.py
  python cli/etl/extract_songs.py --dry-run
  python cli/etl/extract_songs.py --stats

Prerequisites
-------------
  Migration 014_songs_extension.sql must be applied first.
"""

from __future__ import annotations

import argparse
import re
import sys
import unicodedata
from dataclasses import dataclass, field
from datetime import datetime
from pathlib import Path
from typing import Optional

# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------
REPO_ROOT = Path(__file__).resolve().parent.parent.parent
INFO_DIR  = REPO_ROOT / "old-files" / "info"

PLAYLIST_GIG  = INFO_DIR / "playlist-gig.txt"
PLAYLIST_JAZZ = INFO_DIR / "playlist-jazz.txt"
KEYS_TXT      = INFO_DIR / "keys.txt"
HD_LIST       = INFO_DIR / "hd-list.txt"
EXTRA_INFO    = INFO_DIR / "playlist-w-extra-info.txt"

OUTPUT_SQL = REPO_ROOT / "db" / "seeds" / "legacy_songs.sql"

# ---------------------------------------------------------------------------
# Key normalisation: full English → shorthand
# ---------------------------------------------------------------------------
KEY_MAP: dict[str, str] = {
    "C major": "C",       "C minor": "Cm",
    "C-sharp major": "C#", "C-sharp minor": "C#m",
    "D-flat major": "Db",  "D-flat minor": "Dbm",
    "D major": "D",        "D minor": "Dm",
    "E-flat major": "Eb",  "E-flat minor": "Ebm",
    "E major": "E",        "E minor": "Em",
    "F major": "F",        "F minor": "Fm",
    "F-sharp major": "F#", "F-sharp minor": "F#m",
    "G-flat major": "Gb",  "G-flat minor": "Gbm",
    "G major": "G",        "G minor": "Gm",
    "G-sharp major": "G#", "G-sharp minor": "G#m",
    "A-flat major": "Ab",  "A-flat minor": "Abm",
    "A major": "A",        "A minor": "Am",
    "B-flat major": "Bb",  "B-flat minor": "Bbm",
    "B major": "B",        "B minor": "Bm",
    # Variants found in source (non-standard spelling)
    "the Trammps": None,   # not a key; artist name match guard
}

_KEY_FULL_RE = re.compile(
    r"^([A-G]-(?:sharp|flat)\s+(?:major|minor)|[A-G]\s+(?:major|minor))$"
)

def normalise_key(full_english: str) -> Optional[str]:
    """Convert 'E-flat minor' → 'Ebm'. Returns None if unrecognised."""
    k = full_english.strip()
    return KEY_MAP.get(k)

# ---------------------------------------------------------------------------
# Data model
# ---------------------------------------------------------------------------
@dataclass
class SongRecord:
    artist:               str
    title:                str
    genre:                Optional[str]  = None
    language:             Optional[str]  = None   # 'fi' | 'sv' | 'en' | 'other' | None
    release_year:         Optional[int]  = None
    is_jazz:              bool           = False
    in_repertoire:        bool           = True
    hd_slot:              Optional[str]  = None   # 3-char launchpad coord
    hd_status:            str            = "none" # 'none' | 'done' | 'pending'
    guide_tone_key:       Optional[str]  = None   # single char
    key_our:              Optional[str]  = None   # shorthand
    key_orig:             Optional[str]  = None   # shorthand; None = same as key_our
    key_transposition_st: Optional[int]  = None   # semitones from orig to our
    has_gtr2:             bool           = False
    karaoke_eligible:     bool           = False

def _norm_key(s: str) -> str:
    """Normalise a song identity key for cross-file matching."""
    s = unicodedata.normalize("NFKD", s).encode("ascii", "ignore").decode()
    s = s.lower()
    s = re.sub(r"[^\w\s]", " ", s)
    return " ".join(s.split())

def _song_key(artist: str, title: str) -> str:
    return _norm_key(artist + " " + title)

# ---------------------------------------------------------------------------
# playlist-gig.txt parsing
# ---------------------------------------------------------------------------

# Genre headers are lines without a tab that look like section labels.
_GENRE_HEADER_RE = re.compile(
    r"^[A-ZÄÖÅ][A-ZÄÖÅa-zäöå0-9 /&\-]+$"
)
_EXTRA_SECTION = "Ohjelmiston ulkopuoliset"
_KEY_SHORTHAND_RE = re.compile(r"^[A-Ga-g][b#]?m?$")

# Language order by number of song groups per genre.
# 2-group genres skip Swedish (fi → en); 3-group genres use fi → sv → en.
_LANG_BY_GROUPS: dict[int, list[str]] = {
    1: ["fi"],
    2: ["fi", "en"],
    3: ["fi", "sv", "en"],
}

def _count_genre_groups(lines: list[str]) -> dict[str, int]:
    """Pre-scan: return {genre_name: num_song_groups} for language assignment."""
    result: dict[str, int] = {}
    current: Optional[str] = None
    blanks = 0
    saw_song = False
    for line in lines:
        s = line.rstrip()
        if not s:
            if saw_song:
                blanks += 1
                saw_song = False
            continue
        if "\t" in s:
            saw_song = True
            continue
        if s == _EXTRA_SECTION or _GENRE_HEADER_RE.match(s):
            if current is not None:
                result[current] = blanks
            current = s
            blanks = 0
            saw_song = False
    if current is not None:
        result[current] = blanks
    return result

def _parse_prefix(prefix: str) -> tuple[Optional[str], Optional[str]]:
    """Return (hd_slot, guide_tone_key) from a playlist-gig prefix token."""
    p = prefix.strip()
    if p in ("---", "XXX", ""):
        return None, None
    if re.match(r"^\d{3}$", p):
        return p, None
    if re.match(r"^[a-zA-Z]$", p):
        return None, p
    return None, None

def _parse_song_rest(text: str) -> tuple[str, Optional[str], bool]:
    """
    Parse 'Artist – Title     KEY   GTR2' (tab already removed).
    Returns (artist_title, key_shorthand, has_gtr2).
    """
    text = text.rstrip()
    # Strip !!! (ad-hoc transposition marker)
    text = re.sub(r"!!!+\s*$", "", text)
    has_gtr2 = False
    if text.endswith("GTR2"):
        has_gtr2 = True
        text = re.sub(r"\s+GTR2\s*$", "", text)
    key: Optional[str] = None
    # Key follows 3+ spaces at end of line
    m = re.search(r"\s{3,}(\S+)\s*$", text)
    if m:
        tok = m.group(1)
        if _KEY_SHORTHAND_RE.match(tok):
            key = tok
            text = text[:m.start()].strip()
    return text.strip(), key, has_gtr2

def _split_artist_title(text: str) -> tuple[Optional[str], Optional[str]]:
    """Split 'Artist – Title' on en-dash or hyphen separator."""
    for sep in (" – ", " - "):  # en-dash then hyphen
        idx = text.find(sep)
        if idx > 0:
            return text[:idx].strip(), text[idx + len(sep):].strip()
    return None, None

def parse_playlist_gig(path: Path) -> list[SongRecord]:
    lines = path.read_text(encoding="utf-8").splitlines()
    songs: list[SongRecord] = []

    genre_groups = _count_genre_groups(lines)

    current_genre: Optional[str] = None
    lang_index  = 0   # advances on each blank-after-song
    in_repertoire = True
    saw_song      = False  # True after first song in current language block

    for line in lines:
        stripped = line.rstrip()

        if not stripped:
            # Blank line: if we've seen a song, mark language boundary
            if saw_song:
                lang_index += 1
                saw_song   = False
            continue

        if "\t" in stripped:
            # Song line: [prefix]\t[rest]
            parts = stripped.split("\t", 1)
            prefix_str = parts[0]
            rest = parts[1] if len(parts) > 1 else ""
            artist_title, key_our, has_gtr2 = _parse_song_rest(rest)
            artist, title = _split_artist_title(artist_title)
            if not artist or not title:
                continue
            hd_slot, guide_tone_key = _parse_prefix(prefix_str)
            num_groups = genre_groups.get(current_genre or "", 2)
            langs = _LANG_BY_GROUPS.get(num_groups, ["fi", "en"])
            language = langs[min(lang_index, len(langs) - 1)]
            songs.append(SongRecord(
                artist        = artist,
                title         = title,
                genre         = current_genre,
                language      = language,
                in_repertoire = in_repertoire,
                hd_slot       = hd_slot,
                guide_tone_key= guide_tone_key,
                key_our       = key_our,
                has_gtr2      = has_gtr2,
            ))
            saw_song = True
        else:
            # Potential genre header
            if stripped == _EXTRA_SECTION:
                in_repertoire = False
                current_genre = stripped
                lang_index = 0
                saw_song   = False
            elif _GENRE_HEADER_RE.match(stripped):
                current_genre = stripped
                lang_index = 0
                saw_song   = False

    return songs

# ---------------------------------------------------------------------------
# playlist-jazz.txt parsing
# ---------------------------------------------------------------------------

def parse_playlist_jazz(path: Path) -> list[SongRecord]:
    lines = path.read_text(encoding="utf-8").splitlines()
    songs: list[SongRecord] = []
    current_subgenre: Optional[str] = None

    # Sub-genre headers: "Jazz standard (N)", "Jazz original (N)", "Fusion (N)"
    _SUBGENRE_RE = re.compile(r"^(Jazz standard|Jazz original|Fusion)\s*\(")
    _SECTION_RE  = re.compile(r"^(INSTRUMENTAL|VOCAL|TOTAL)\b")

    for line in lines:
        stripped = line.rstrip()
        if not stripped:
            continue
        if _SECTION_RE.match(stripped):
            continue
        if _SUBGENRE_RE.match(stripped):
            current_subgenre = re.match(r"^([^\(]+)", stripped).group(1).strip()
            continue
        # Song line: Artist – Title (no prefix, no key)
        artist, title = _split_artist_title(stripped)
        if artist and title:
            songs.append(SongRecord(
                artist    = artist,
                title     = title,
                genre     = current_subgenre or "Jazz",
                language  = "en",  # jazz standards are English-language
                is_jazz   = True,
                in_repertoire = True,
            ))

    return songs

# ---------------------------------------------------------------------------
# keys.txt parsing
# ---------------------------------------------------------------------------

@dataclass
class KeyData:
    key_our:              Optional[str] = None
    key_orig:             Optional[str] = None
    key_transposition_st: Optional[int] = None

_SECTION_HEADERS = {
    "VANHA RUNKOSETTI",
    "RUNKOSETIN ULKOPUOLISET",
    "RUNKOSETTIIN TULEVAT, EI-TOIVEBIISIT",
    "EI TULE UUTEEN RUNKOSETTIIN (= MIKAELIN KANSSA VEDETTYIHIN EI TARVITSE SÄVELLAJIA)",
    "UUDET (OHJEMISTOSSA)",
    "UUDET (OHJELMISTON ULKOPUOLELLA)",
}

def parse_keys_txt(path: Path) -> dict[str, KeyData]:
    """Returns {normalised_artist_title → KeyData}."""
    lines = path.read_text(encoding="utf-8").splitlines()
    result: dict[str, KeyData] = {}

    i = 0
    while i < len(lines):
        line = lines[i].rstrip()

        # Skip blank lines, section headers, and sub-section headers (YYMMDD …)
        if not line:
            i += 1
            continue
        if line in _SECTION_HEADERS:
            i += 1
            continue
        # Sub-section header: starts with 6 digits then space
        if re.match(r"^\d{6}\s", line):
            i += 1
            continue
        # Bullet point
        if line.startswith("- "):
            i += 1
            continue

        # Try to parse as "Artist – Title"
        artist, title = _split_artist_title(line)
        if not artist or not title:
            i += 1
            continue

        norm = _song_key(artist, title)
        kd = KeyData()

        # Consume following bullet lines
        i += 1
        while i < len(lines):
            bline = lines[i].rstrip()
            if not bline:
                i += 1
                break
            if not bline.startswith("- "):
                break  # next song starts

            content = bline[2:].strip()

            # Transposition: "+4 / -8 ST"
            if re.match(r"^[+-]\d+\s*/\s*[+-]\d+\s+ST$", content):
                m = re.match(r"^\+(\d+)", content)
                if m:
                    kd.key_transposition_st = int(m.group(1))
                i += 1
                continue

            # HUOM! note
            if content.startswith("HUOM!"):
                note = content[5:].strip()
                if "SÄVELLAJIA EI OLE PÄÄTETTY" in note or "RIIPPUU TOISESTA" in note:
                    kd.key_our = None   # explicitly undecided for Alina
                i += 1
                continue

            # SÄVELLAJI PITÄÄ PÄÄTTÄÄ (undecided key)
            if "SÄVELLAJI PITÄÄ PÄÄTTÄÄ" in content:
                m = re.search(r"\(orig\.\s+([^)]+)\)", content)
                if m:
                    kd.key_orig = normalise_key(m.group(1).strip())
                kd.key_our = None
                i += 1
                continue

            # "orig. KEY" (without our-key prefix — used in EI TULE section)
            m_orig_only = re.match(r"^orig\.\s+(.+)$", content)
            if m_orig_only:
                kd.key_orig = normalise_key(m_orig_only.group(1).strip())
                kd.key_our  = None
                i += 1
                continue

            # Normal key line: "G minor (orig. E minor)" or "G minor (orig.)"
            m_key = re.match(r"^([^(]+?)(?:\s+\(orig\.(?:\s+([^)]+))?\))?$", content)
            if m_key:
                our_full = m_key.group(1).strip()
                orig_raw = m_key.group(2)
                our = normalise_key(our_full)
                if our is not None:
                    kd.key_our = our
                    if orig_raw:
                        orig = normalise_key(orig_raw.strip())
                        kd.key_orig = orig  # None if same as our
                    else:
                        kd.key_orig = None  # "(orig.)" alone → same as our
                elif "SÄVELLAJI PITÄÄ PÄÄTTÄÄ" in our_full:
                    kd.key_our = None
            i += 1

        result[norm] = kd

    return result

# ---------------------------------------------------------------------------
# hd-list.txt parsing
# ---------------------------------------------------------------------------

def parse_hd_list(path: Path) -> dict[str, str]:
    """Returns {normalised_artist_title → 'done' | 'pending'}."""
    lines = path.read_text(encoding="utf-8").splitlines()
    result: dict[str, str] = {}
    current_status: Optional[str] = None

    for line in lines:
        stripped = line.rstrip()
        if not stripped:
            continue
        if stripped == "TEHTY":
            current_status = "done"
            continue
        if stripped == "TEKEMÄTTÄ":
            current_status = "pending"
            continue
        if current_status is None:
            continue
        # Strip parenthetical notes from TEKEMÄTTÄ entries
        song_name = re.sub(r"\s*\([^)]+\)\s*$", "", stripped).strip()
        artist, title = _split_artist_title(song_name)
        if artist and title:
            result[_song_key(artist, title)] = current_status

    return result

# ---------------------------------------------------------------------------
# playlist-w-extra-info.txt parsing
# ---------------------------------------------------------------------------

def parse_extra_info(path: Path) -> dict[str, int]:
    """Returns {normalised_artist_title → release_year}."""
    lines = path.read_text(encoding="utf-8").splitlines()
    result: dict[str, int] = {}
    _YEAR_RE = re.compile(r"^(.+?)\s+\((\d{4})\)\s*$")

    for line in lines:
        stripped = line.rstrip()
        m = _YEAR_RE.match(stripped)
        if not m:
            continue
        song_part = m.group(1).strip()
        year      = int(m.group(2))
        artist, title = _split_artist_title(song_part)
        if artist and title:
            result[_song_key(artist, title)] = year

    return result

# ---------------------------------------------------------------------------
# SQL helpers
# ---------------------------------------------------------------------------

def _sq(val: Optional[str]) -> str:
    if val is None:
        return "NULL"
    return "'" + str(val).replace("\\", "\\\\").replace("'", "\\'") + "'"

def _si(val) -> str:
    return str(val) if val is not None else "NULL"

def _sb(val: bool) -> str:
    return "1" if val else "0"

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> int:
    parser = argparse.ArgumentParser(
        description="Extract songs from repertoire files into legacy_songs.sql."
    )
    parser.add_argument("--dry-run", action="store_true",
                        help="Print SQL to stdout instead of writing file.")
    parser.add_argument("--stats", action="store_true",
                        help="Print counts only; write no files.")
    args = parser.parse_args()

    # 1. Parse primary sources
    print("  Parsing playlist-gig.txt...", file=sys.stderr)
    gig_songs  = parse_playlist_gig(PLAYLIST_GIG)
    print(f"    {len(gig_songs)} songs", file=sys.stderr)

    print("  Parsing playlist-jazz.txt...", file=sys.stderr)
    jazz_songs = parse_playlist_jazz(PLAYLIST_JAZZ)
    print(f"    {len(jazz_songs)} songs", file=sys.stderr)

    # 2. Parse supplementary sources
    print("  Parsing keys.txt...", file=sys.stderr)
    keys_map = parse_keys_txt(KEYS_TXT)
    print(f"    {len(keys_map)} entries", file=sys.stderr)

    print("  Parsing hd-list.txt...", file=sys.stderr)
    hd_map = parse_hd_list(HD_LIST)
    print(f"    {len(hd_map)} entries", file=sys.stderr)

    print("  Parsing playlist-w-extra-info.txt...", file=sys.stderr)
    year_map = parse_extra_info(EXTRA_INFO)
    print(f"    {len(year_map)} entries", file=sys.stderr)

    # 3. Merge
    all_songs = gig_songs + jazz_songs
    matched_keys = 0
    matched_hd   = 0
    matched_year = 0

    for song in all_songs:
        k = _song_key(song.artist, song.title)

        kd = keys_map.get(k)
        if kd:
            matched_keys += 1
            # keys.txt is authoritative for key data; override if present
            if kd.key_our is not None or song.key_our is None:
                song.key_our = kd.key_our
            if kd.key_orig is not None:
                song.key_orig = kd.key_orig
            if kd.key_transposition_st is not None:
                song.key_transposition_st = kd.key_transposition_st

        hd = hd_map.get(k)
        if hd:
            matched_hd += 1
            song.hd_status = hd

        yr = year_map.get(k)
        if yr:
            matched_year += 1
            song.release_year = yr

    print(
        f"  Merged: {matched_keys} key entries · {matched_hd} HD entries · "
        f"{matched_year} year entries",
        file=sys.stderr
    )
    print(f"  Total songs: {len(all_songs)}", file=sys.stderr)

    if args.stats:
        return 0

    # 4. Build SQL
    now_ts = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")
    lines: list[str] = [
        "-- ===========================================================================",
        "-- LEGACY SONGS SEED",
        f"-- Generated by cli/etl/extract_songs.py  on  {now_ts} UTC",
        "--",
        "-- Sources: playlist-gig.txt · playlist-jazz.txt · keys.txt",
        "--          hd-list.txt · playlist-w-extra-info.txt",
        "--",
        "-- Prerequisites: migration 014_songs_extension.sql must be applied.",
        "-- Safe to re-run: DELETE clears id range 1–9999 before reinserting.",
        "-- ===========================================================================",
        "",
        "SET NAMES utf8mb4;",
        "SET foreign_key_checks = 0;",
        "",
        "-- Clear ETL-seeded songs (preserves any manually-added songs with id >= 10000)",
        "DELETE FROM setlist_songs WHERE song_id IN (SELECT id FROM songs WHERE id BETWEEN 1 AND 9999);",
        "DELETE FROM songs WHERE id BETWEEN 1 AND 9999;",
        "",
    ]

    col_names = (
        "id, artist, title, genre, language, release_year, is_jazz, in_repertoire, "
        "hd_slot, hd_status, guide_tone_key, key_our, key_orig, key_transposition_st, "
        "has_gtr2, karaoke_eligible, created_at, updated_at"
    )
    lines.append(f"INSERT INTO songs ({col_names}) VALUES")

    value_rows: list[str] = []
    for idx, song in enumerate(all_songs, start=1):
        value_rows.append(
            f"({idx}, {_sq(song.artist)}, {_sq(song.title)}, "
            f"{_sq(song.genre)}, {_sq(song.language)}, {_si(song.release_year)}, "
            f"{_sb(song.is_jazz)}, {_sb(song.in_repertoire)}, "
            f"{_sq(song.hd_slot)}, {_sq(song.hd_status)}, {_sq(song.guide_tone_key)}, "
            f"{_sq(song.key_our)}, {_sq(song.key_orig)}, {_si(song.key_transposition_st)}, "
            f"{_sb(song.has_gtr2)}, {_sb(song.karaoke_eligible)}, "
            f"UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )

    lines.append(",\n".join(value_rows) + ";")
    lines.append("")
    lines.append("SET foreign_key_checks = 1;")
    lines.append("")

    sql = "\n".join(lines)

    if args.dry_run:
        print(sql)
    else:
        OUTPUT_SQL.parent.mkdir(parents=True, exist_ok=True)
        OUTPUT_SQL.write_text(sql, encoding="utf-8")
        print(f"  Wrote {OUTPUT_SQL.relative_to(REPO_ROOT)}", file=sys.stderr)

    return 0


if __name__ == "__main__":
    sys.exit(main())

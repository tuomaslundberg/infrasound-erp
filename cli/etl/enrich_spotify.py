#!/usr/bin/env python3
"""
cli/etl/enrich_spotify.py

Resolve Spotify track IDs for songs in the songs table.

Strategy
--------
Phase 1 — Playlist seeding (high confidence):
  Fetch the four known Saturday playlists, fuzzy-match each track against
  unresolved songs rows (threshold ≥ 0.85).  The karaoke playlist also sets
  karaoke_eligible = 1 on matched rows.

Phase 2 — Search-based resolution (fallback):
  For songs still unresolved after Phase 1, query the Spotify Search API.
  Threshold ≥ 0.85 → auto-assign; below → log for manual review.

Known playlists
---------------
  Saturday repertoire   7macuFdR2Oipb4tzfcgb8B
  Saturday suggestions  4POTR14VsbrqC85DtAA37M
  Saturday live karaoke 3Q3mAtmIcrQjLFVsoAzHER  (also sets karaoke_eligible)
  Saturday Jazz         5aY2nEjdBSf9PVw0QoZEgk

Output
------
  db/seeds/legacy_spotify.sql          — UPDATE statements
  db/seeds/spotify_unmatched.txt       — songs below threshold (manual review)

Prerequisites
-------------
  SPOTIFY_CLIENT_ID and SPOTIFY_CLIENT_SECRET in .env / .env.dev.
  pip install spotipy

Usage
-----
  python cli/etl/enrich_spotify.py
  python cli/etl/enrich_spotify.py --dry-run
  python cli/etl/enrich_spotify.py --stats
  python cli/etl/enrich_spotify.py --phase1-only   # skip search API
"""

from __future__ import annotations

import argparse
import difflib
import re
import sys
import time
import unicodedata
from pathlib import Path
from typing import Optional

REPO_ROOT  = Path(__file__).resolve().parent.parent.parent
OUTPUT_SQL = REPO_ROOT / "db" / "seeds" / "legacy_spotify.sql"
UNMATCHED  = REPO_ROOT / "db" / "seeds" / "spotify_unmatched.txt"

KNOWN_PLAYLISTS = [
    ("7macuFdR2Oipb4tzfcgb8B", False, "Saturday repertoire"),
    ("4POTR14VsbrqC85DtAA37M", False, "Saturday suggestions"),
    ("3Q3mAtmIcrQjLFVsoAzHER", True,  "Saturday live karaoke"),
    ("5aY2nEjdBSf9PVw0QoZEgk", False, "Saturday Jazz"),
]

FUZZY_THRESHOLD = 0.85

# ---------------------------------------------------------------------------
# Normalisation (same as extract_songs / extract_setlists)
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

# ---------------------------------------------------------------------------
# .env loader + DB connection (mirrors enrich_gigs.py)
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


def _get_env() -> dict[str, str]:
    env = _load_dot_env()
    dev = REPO_ROOT / ".env.dev"
    if dev.exists():
        env.update(_load_dot_env(dev))
    return env


def _try_connect_db(env: dict[str, str]):
    try:
        import pymysql  # type: ignore
    except ImportError:
        return None
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

# ---------------------------------------------------------------------------
# DB: load unresolved songs
# ---------------------------------------------------------------------------

def load_unresolved_songs(conn) -> list[dict]:
    """Load songs without spotify_track_id from the ETL range."""
    with conn.cursor() as cur:
        cur.execute(
            "SELECT id, artist, title, is_jazz "
            "FROM songs "
            "WHERE spotify_track_id IS NULL "
            "  AND id BETWEEN 1 AND 9999 "
            "  AND deleted_at IS NULL "
            "ORDER BY id"
        )
        return [
            {"id": r[0], "artist": r[1], "title": r[2], "is_jazz": bool(r[3])}
            for r in cur.fetchall()
        ]

# ---------------------------------------------------------------------------
# Spotify client
# ---------------------------------------------------------------------------

def _get_spotify(env: dict[str, str]):
    try:
        import spotipy  # type: ignore
        from spotipy.oauth2 import SpotifyOAuth  # type: ignore
    except ImportError:
        print("  ERROR: spotipy not installed.  Run: pip install spotipy", file=sys.stderr)
        return None
    client_id     = env.get("SPOTIFY_CLIENT_ID", "")
    client_secret = env.get("SPOTIFY_CLIENT_SECRET", "")
    redirect_uri  = env.get("SPOTIFY_REDIRECT_URI", "http://127.0.0.1:9090")
    if not client_id or not client_secret:
        print("  ERROR: SPOTIFY_CLIENT_ID / SPOTIFY_CLIENT_SECRET not set in .env", file=sys.stderr)
        return None
    # Spotify requires user auth (OAuth) for playlist endpoints as of late 2024,
    # even for public playlists.  Token is cached in .spotify_cache (gitignored).
    try:
        auth = SpotifyOAuth(
            client_id=client_id,
            client_secret=client_secret,
            redirect_uri=redirect_uri,
            scope="playlist-read-private playlist-read-collaborative",
            cache_path=str(REPO_ROOT / ".spotify_cache"),
            open_browser=True,
        )
        return spotipy.Spotify(auth_manager=auth)
    except Exception as e:
        print(f"  ERROR: Spotify auth failed: {e}", file=sys.stderr)
        return None

# ---------------------------------------------------------------------------
# Playlist fetching
# ---------------------------------------------------------------------------

def fetch_playlist_tracks(sp, playlist_id: str) -> list[dict]:
    """Return all tracks in a playlist as list of {track_id, artist, title}."""
    tracks = []
    offset = 0
    while True:
        result = sp.playlist_tracks(playlist_id, limit=100, offset=offset)
        items = result.get("items", [])
        for item in items:
            t = item.get("item") or item.get("track")  # Spotify renamed "track" → "item" in 2024
            if not t or not t.get("id"):
                continue
            artists = ", ".join(a["name"] for a in t.get("artists", []))
            tracks.append({"track_id": t["id"], "artist": artists, "title": t["name"]})
        if result.get("next") is None:
            break
        offset += len(items)
    return tracks

# ---------------------------------------------------------------------------
# Matching
# ---------------------------------------------------------------------------

def _best_match(
    track_key: str,
    songs: list[dict],
    song_keys: list[str],
) -> Optional[dict]:
    """Return the best-matching song dict for a Spotify track, or None."""
    close = difflib.get_close_matches(track_key, song_keys, n=1, cutoff=FUZZY_THRESHOLD)
    if not close:
        return None
    idx = song_keys.index(close[0])
    return songs[idx]


def phase1_seed_from_playlists(
    sp,
    songs: list[dict],
    results: dict[int, dict],  # song_id → {track_id, karaoke_eligible}
    unmatched_tracks: list[str],
) -> None:
    """Populate results from the four known playlists."""
    song_keys = [_song_key(s["artist"], s["title"]) for s in songs]

    for (playlist_id, is_karaoke, label) in KNOWN_PLAYLISTS:
        print(f"  Fetching '{label}' ({playlist_id})...", file=sys.stderr)
        try:
            tracks = fetch_playlist_tracks(sp, playlist_id)
        except Exception as e:
            print(f"    WARN: failed to fetch playlist: {e}", file=sys.stderr)
            continue
        print(f"    {len(tracks)} tracks", file=sys.stderr)

        matched = 0
        for t in tracks:
            tk = _song_key(t["artist"], t["title"])
            song = _best_match(tk, songs, song_keys)
            if song is None:
                unmatched_tracks.append(f"{label}\t{t['artist']} – {t['title']}")
                continue
            sid = song["id"]
            if sid not in results:
                results[sid] = {"track_id": t["track_id"], "karaoke_eligible": False}
            if is_karaoke:
                results[sid]["karaoke_eligible"] = True
            matched += 1

        print(f"    {matched}/{len(tracks)} matched", file=sys.stderr)


def phase2_search_api(
    sp,
    songs: list[dict],
    results: dict[int, dict],
    unmatched_songs: list[str],
) -> None:
    """Resolve remaining unmatched songs via Spotify Search API."""
    remaining = [s for s in songs if s["id"] not in results]
    print(f"  Phase 2: {len(remaining)} songs to search...", file=sys.stderr)

    for song in remaining:
        query = f"artist:{song['artist']} track:{song['title']}"
        try:
            resp = sp.search(q=query, type="track", limit=5)
            items = resp.get("tracks", {}).get("items", [])
        except Exception as e:
            print(f"    WARN: search failed for {song['artist']} – {song['title']}: {e}",
                  file=sys.stderr)
            unmatched_songs.append(f"SEARCH_FAIL\t{song['artist']} – {song['title']}")
            continue

        if not items:
            # Fallback: plain text search without field qualifiers
            try:
                resp = sp.search(q=f"{song['artist']} {song['title']}", type="track", limit=5)
                items = resp.get("tracks", {}).get("items", [])
            except Exception:
                items = []

        best = None
        best_score = 0.0
        target_key = _song_key(song["artist"], song["title"])

        for item in items:
            candidate_artist = ", ".join(a["name"] for a in item.get("artists", []))
            candidate_key = _song_key(candidate_artist, item.get("name", ""))
            score = difflib.SequenceMatcher(None, target_key, candidate_key).ratio()
            if score > best_score:
                best_score = score
                best = (item["id"], score)

        if best and best[1] >= FUZZY_THRESHOLD:
            results[song["id"]] = {"track_id": best[0], "karaoke_eligible": False}
        else:
            label = f"{song['artist']} – {song['title']}"
            score_str = f"{best[1]:.2f}" if best else "no_result"
            unmatched_songs.append(f"score={score_str}\t{label}")

        # Spotify rate limit: ~180 req/min with Client Credentials; 0.35s headroom
        time.sleep(0.35)

    resolved = len(remaining) - len([s for s in remaining if s["id"] not in results])
    print(f"  Phase 2 resolved: {resolved}/{len(remaining)}", file=sys.stderr)

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> int:
    parser = argparse.ArgumentParser(
        description="Resolve Spotify track IDs for songs table."
    )
    parser.add_argument("--dry-run", action="store_true",
                        help="Print SQL to stdout instead of writing file.")
    parser.add_argument("--stats", action="store_true",
                        help="Report match counts only; write no files.")
    parser.add_argument("--phase1-only", action="store_true",
                        help="Skip Phase 2 search API (playlist seeding only).")
    args = parser.parse_args()

    env = _get_env()

    # Connect to DB
    conn = _try_connect_db(env)
    if conn is None:
        print("  ERROR: Cannot connect to DB.", file=sys.stderr)
        return 1

    songs = load_unresolved_songs(conn)
    conn.close()
    print(f"  Songs to resolve: {len(songs)}", file=sys.stderr)

    if args.stats:
        print(f"  (Run without --stats to perform Spotify resolution)", file=sys.stderr)
        return 0

    # Connect to Spotify
    sp = _get_spotify(env)
    if sp is None:
        return 1

    results: dict[int, dict] = {}
    unmatched_tracks: list[str] = []
    unmatched_songs:  list[str] = []

    # Phase 1
    phase1_seed_from_playlists(sp, songs, results, unmatched_tracks)
    print(f"  Phase 1 total resolved: {len(results)}", file=sys.stderr)

    if not args.phase1_only:
        phase2_search_api(sp, songs, results, unmatched_songs)

    print(f"  Total resolved: {len(results)}/{len(songs)}", file=sys.stderr)
    print(f"  Unmatched playlist tracks: {len(unmatched_tracks)}", file=sys.stderr)
    print(f"  Unmatched songs: {len(unmatched_songs)}", file=sys.stderr)

    # Build SQL
    now_ts = __import__("datetime").datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")
    sql_lines = [
        "-- ===========================================================================",
        "-- SPOTIFY ENRICHMENT SEED",
        f"-- Generated by cli/etl/enrich_spotify.py  on  {now_ts} UTC",
        "--",
        "-- Updates spotify_track_id and karaoke_eligible on the songs table.",
        "-- Safe to re-run: idempotent UPDATE statements.",
        "-- ===========================================================================",
        "",
        "SET NAMES utf8mb4;",
        "",
    ]

    for song_id, data in sorted(results.items()):
        karaoke = "1" if data["karaoke_eligible"] else "0"
        sql_lines.append(
            f"UPDATE songs SET spotify_track_id = {_sq(data['track_id'])}, "
            f"karaoke_eligible = {karaoke} "
            f"WHERE id = {song_id};"
        )

    sql_lines.append("")
    sql = "\n".join(sql_lines)

    if args.dry_run:
        print(sql)
    else:
        OUTPUT_SQL.parent.mkdir(parents=True, exist_ok=True)
        OUTPUT_SQL.write_text(sql, encoding="utf-8")
        print(f"  Wrote {OUTPUT_SQL.relative_to(REPO_ROOT)}", file=sys.stderr)

    # Write unmatched files
    all_unmatched = unmatched_tracks + unmatched_songs
    if all_unmatched and not args.dry_run:
        UNMATCHED.write_text("\n".join(all_unmatched) + "\n", encoding="utf-8")
        print(f"  Unmatched: {UNMATCHED.relative_to(REPO_ROOT)}", file=sys.stderr)

    return 0


if __name__ == "__main__":
    sys.exit(main())

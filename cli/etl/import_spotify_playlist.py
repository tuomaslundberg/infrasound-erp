#!/usr/bin/env python3
"""
cli/etl/import_spotify_playlist.py

Import songs from a Spotify playlist into the songs table.

For each playlist track:
  1. If spotify_track_id already in songs → skip.
  2. Try to match an existing song by artist+title (exact → prefix → fuzzy ≥ 0.80).
     If matched and spotify_track_id is NULL → UPDATE it.
  3. If no match → INSERT a new song row (artist, title, spotify_track_id;
     key/HD fields NULL, in_repertoire = 0).

This makes the songs table Spotify-first: any track the band plays or is
proposed by a customer can be represented, independent of playlist-gig.txt.
Running on new customer playlists is the primary use-case.

Usage
-----
  python cli/etl/import_spotify_playlist.py <playlist_url_or_id>
  python cli/etl/import_spotify_playlist.py <url> --dry-run
  python cli/etl/import_spotify_playlist.py <url> --in-repertoire
  python cli/etl/import_spotify_playlist.py <url> --stats

Examples
--------
  # Import Saturday suggestions (fills gaps + adds new tracks)
  python cli/etl/import_spotify_playlist.py 4POTR14VsbrqC85DtAA37M

  # Import a customer's wedding playlist proposal (dry-run first)
  python cli/etl/import_spotify_playlist.py https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M --dry-run

Prerequisites
-------------
  pip install spotipy
  SPOTIFY_CLIENT_ID, SPOTIFY_CLIENT_SECRET, SPOTIFY_REDIRECT_URI in .env/.env.dev
  Token cached in .spotify_cache after first OAuth login (see enrich_spotify.py)
"""

from __future__ import annotations

import argparse
import difflib
import re
import sys
import unicodedata
from pathlib import Path
from typing import Optional

REPO_ROOT = Path(__file__).resolve().parent.parent.parent

FUZZY_THRESHOLD  = 0.80
PREFIX_MIN_CHARS = 10

# ---------------------------------------------------------------------------
# Normalisation
# ---------------------------------------------------------------------------

def _norm(s: str) -> str:
    s = unicodedata.normalize("NFKD", s).encode("ascii", "ignore").decode()
    s = s.lower()
    s = re.sub(r"[^\w\s]", " ", s)
    return " ".join(s.split())

def _song_key(artist: str, title: str) -> str:
    return _norm(artist + " " + title)

# ---------------------------------------------------------------------------
# Matching: exact → prefix → fuzzy
# ---------------------------------------------------------------------------

def _match_song(
    track_key: str,
    songs: list[dict],
    song_keys: list[str],
) -> Optional[dict]:
    # 1. Exact
    if track_key in song_keys:
        return songs[song_keys.index(track_key)]

    # 2. Prefix/suffix (handles remaster/subtitle suffixes)
    if len(track_key) >= PREFIX_MIN_CHARS:
        for i, sk in enumerate(song_keys):
            if len(sk) >= PREFIX_MIN_CHARS:
                if track_key.startswith(sk) or sk.startswith(track_key):
                    return songs[i]

    # 3. Fuzzy
    close = difflib.get_close_matches(track_key, song_keys, n=1, cutoff=FUZZY_THRESHOLD)
    if close:
        return songs[song_keys.index(close[0])]

    return None

# ---------------------------------------------------------------------------
# .env / DB helpers
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

def _connect_db(env: dict[str, str]):
    try:
        import pymysql  # type: ignore
    except ImportError:
        print("  ERROR: pymysql not installed.", file=sys.stderr)
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
# Spotify client (reuses cached OAuth token from enrich_spotify.py)
# ---------------------------------------------------------------------------

def _get_spotify(env: dict[str, str]):
    try:
        import spotipy  # type: ignore
        from spotipy.oauth2 import SpotifyOAuth  # type: ignore
    except ImportError:
        print("  ERROR: pip install spotipy", file=sys.stderr)
        return None
    client_id     = env.get("SPOTIFY_CLIENT_ID", "")
    client_secret = env.get("SPOTIFY_CLIENT_SECRET", "")
    redirect_uri  = env.get("SPOTIFY_REDIRECT_URI", "http://127.0.0.1:9090")
    if not client_id or not client_secret:
        print("  ERROR: SPOTIFY_CLIENT_ID / SPOTIFY_CLIENT_SECRET not set.", file=sys.stderr)
        return None
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

def _extract_playlist_id(arg: str) -> str:
    """Accept a full URL or bare playlist ID."""
    m = re.search(r"playlist/([A-Za-z0-9]+)", arg)
    return m.group(1) if m else arg

def fetch_playlist_tracks(sp, playlist_id: str) -> list[dict]:
    tracks = []
    offset = 0
    while True:
        result = sp.playlist_tracks(playlist_id, limit=100, offset=offset)
        items = result.get("items", [])
        for item in items:
            t = item.get("item") or item.get("track")
            if not t or not t.get("id"):
                continue
            artists = ", ".join(a["name"] for a in t.get("artists", []))
            tracks.append({"track_id": t["id"], "artist": artists, "title": t["name"]})
        if result.get("next") is None:
            break
        offset += len(items)
    return tracks

def fetch_playlist_name(sp, playlist_id: str) -> str:
    try:
        return sp.playlist(playlist_id, fields="name")["name"]
    except Exception:
        return playlist_id

# ---------------------------------------------------------------------------
# SQL helpers
# ---------------------------------------------------------------------------

def _sq(val: Optional[str]) -> str:
    if val is None:
        return "NULL"
    return "'" + str(val).replace("\\", "\\\\").replace("'", "\\'") + "'"

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> int:
    parser = argparse.ArgumentParser(
        description="Import songs from a Spotify playlist into the songs table."
    )
    parser.add_argument("playlist", help="Spotify playlist URL or ID")
    parser.add_argument("--dry-run", action="store_true",
                        help="Print SQL to stdout; do not write to DB.")
    parser.add_argument("--stats", action="store_true",
                        help="Report counts only; make no changes.")
    parser.add_argument("--in-repertoire", action="store_true",
                        help="Set in_repertoire=1 on inserted rows (default: 0).")
    args = parser.parse_args()

    playlist_id = _extract_playlist_id(args.playlist)
    env = _get_env()

    conn = _connect_db(env)
    if conn is None:
        print("  ERROR: Cannot connect to DB.", file=sys.stderr)
        return 1

    # Load existing songs
    with conn.cursor() as cur:
        cur.execute(
            "SELECT id, artist, title, spotify_track_id "
            "FROM songs WHERE deleted_at IS NULL ORDER BY id"
        )
        rows = cur.fetchall()

    songs     = [{"id": r[0], "artist": r[1], "title": r[2], "spotify_track_id": r[3]} for r in rows]
    song_keys = [_song_key(s["artist"], s["title"]) for s in songs]
    known_ids = {s["spotify_track_id"] for s in songs if s["spotify_track_id"]}

    print(f"  DB: {len(songs)} songs, {len(known_ids)} with Spotify ID", file=sys.stderr)

    sp = _get_spotify(env)
    if sp is None:
        conn.close()
        return 1

    playlist_name = fetch_playlist_name(sp, playlist_id)
    print(f"  Playlist: {playlist_name} ({playlist_id})", file=sys.stderr)

    try:
        tracks = fetch_playlist_tracks(sp, playlist_id)
    except Exception as e:
        print(f"  ERROR: failed to fetch playlist: {e}", file=sys.stderr)
        conn.close()
        return 1

    print(f"  Tracks: {len(tracks)}", file=sys.stderr)

    updates: list[tuple[int, str]]  = []   # (song_id, track_id)
    inserts: list[dict]             = []   # new song dicts
    skipped = 0

    for t in tracks:
        if t["track_id"] in known_ids:
            skipped += 1
            continue

        tk = _song_key(t["artist"], t["title"])
        match = _match_song(tk, songs, song_keys)

        if match:
            if match["spotify_track_id"] is None:
                updates.append((match["id"], t["track_id"]))
                # Update in-memory so subsequent tracks don't double-match
                match["spotify_track_id"] = t["track_id"]
                known_ids.add(t["track_id"])
            else:
                skipped += 1  # already resolved via a different track
        else:
            inserts.append(t)
            known_ids.add(t["track_id"])

    print(f"  Already resolved: {skipped}", file=sys.stderr)
    print(f"  Will update (existing song, was missing ID): {len(updates)}", file=sys.stderr)
    print(f"  Will insert (new song): {len(inserts)}", file=sys.stderr)

    if args.stats:
        conn.close()
        return 0

    in_repertoire = 1 if args.in_repertoire else 0

    if args.dry_run:
        for (sid, tid) in updates:
            print(f"UPDATE songs SET spotify_track_id = {_sq(tid)} WHERE id = {sid};")
        for t in inserts:
            print(
                f"INSERT INTO songs (artist, title, spotify_track_id, in_repertoire) "
                f"VALUES ({_sq(t['artist'])}, {_sq(t['title'])}, {_sq(t['track_id'])}, {in_repertoire});"
            )
    else:
        with conn.cursor() as cur:
            for (sid, tid) in updates:
                cur.execute(
                    "UPDATE songs SET spotify_track_id = %s WHERE id = %s",
                    (tid, sid)
                )
            for t in inserts:
                # ON DUPLICATE KEY: if title+artist already exists, fill in
                # the Spotify ID only if it was NULL (don't overwrite a known ID).
                cur.execute(
                    "INSERT INTO songs (artist, title, spotify_track_id, in_repertoire) "
                    "VALUES (%s, %s, %s, %s) "
                    "ON DUPLICATE KEY UPDATE "
                    "spotify_track_id = IF(spotify_track_id IS NULL, VALUES(spotify_track_id), spotify_track_id)",
                    (t["artist"], t["title"], t["track_id"], in_repertoire)
                )
        conn.commit()
        print(f"  Done. {len(updates)} updated, {len(inserts)} inserted.", file=sys.stderr)

    conn.close()
    return 0

if __name__ == "__main__":
    sys.exit(main())

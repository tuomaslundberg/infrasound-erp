#!/usr/bin/env python3
"""
cli/etl/analyze_setlists.py

Setlist analytics report against setlist_songs, setlists, songs, and gigs tables.

Sections
--------
  1. Play frequency   — per-song total + yearly breakdown; top-40; zero-play songs
  2. Recency          — last played date; in_repertoire songs not played in >2 years
  3. Set structure    — set length distribution; common openers, closers, transitions
  4. Co-occurrence    — most common song pairs in same set
  5. Default setlist  — CLI-only: generate a set from the repertoire

The SetlistBuilder class encapsulates generation logic for eventual reuse in the ERP
(via a PHP-invoked subprocess or a future port to PHP/SQL).

Usage
-----
  python cli/etl/analyze_setlists.py               # full Markdown report
  python cli/etl/analyze_setlists.py --json        # machine-readable JSON (same data)
  python cli/etl/analyze_setlists.py --generate 20 # propose a 20-song setlist
  python cli/etl/analyze_setlists.py --fill "101,204,78" --target 90
                                                   # fill + order customer picks to 90 min

  The --fill flag accepts comma-separated song IDs (customer picks) and generates a
  time-matched, ordered setlist divided into sets. This is the primary real-world use
  case: customer provides unordered song wishes → band gets back an ordered set plan.

Prerequisites
-------------
  Running dev DB (make dev) with setlist data loaded.
  pymysql installed (pip install pymysql).

Design
------
  SetlistAnalytics  — runs all DB queries; returns plain dicts / lists
  SetlistBuilder    — stateless generator; depends only on SetlistAnalytics output
  report_markdown() — renders SetlistAnalytics data as a Markdown string
  report_json()     — same data as JSON

  Both SetlistAnalytics and SetlistBuilder are intentionally free of I/O so they
  can be embedded in a future PHP-invokable wrapper without modification.
"""

from __future__ import annotations

import argparse
import json
import math
import os
import random
import sys
from collections import defaultdict
from datetime import date, timedelta
from pathlib import Path
from typing import Optional

REPO_ROOT = Path(__file__).resolve().parent.parent.parent

AVG_SONG_DURATION_MIN: float = 3.5   # fallback when no Spotify duration data
RECENCY_THRESHOLD_DAYS: int = 730    # 2 years

# ---------------------------------------------------------------------------
# DB connection (same pattern as other ETL scripts)
# ---------------------------------------------------------------------------

def _load_env() -> dict:
    env: dict = {}
    for path in [REPO_ROOT / ".env", REPO_ROOT / ".env.dev"]:
        if path.exists():
            for line in path.read_text(encoding="utf-8").splitlines():
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                k, v = line.split("=", 1)
                env[k.strip()] = v.strip()
    return env


def _connect(env: dict):
    try:
        import pymysql
    except ImportError:
        sys.exit("pymysql not installed — run: pip install pymysql")

    port = int(env.get("ETL_DB_PORT", 3306))
    return pymysql.connect(
        host="127.0.0.1",
        port=port,
        user=env["MYSQL_USER"],
        password=env["MYSQL_PASSWORD"],
        database=env["MYSQL_DATABASE"],
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
    )


# ---------------------------------------------------------------------------
# SetlistAnalytics
# ---------------------------------------------------------------------------

class SetlistAnalytics:
    """
    Runs analytics queries and caches results.  All public methods return plain
    Python structures (dicts/lists) — no DB dependency after __init__.

    Intended to be the single source of truth for both the CLI report and the
    PHP admin page (which mirrors the key queries in PDO).
    """

    def __init__(self, conn) -> None:
        self._conn = conn
        self._today = date.today()
        # Lazily populated
        self._freq: Optional[list] = None
        self._recency: Optional[list] = None
        self._structure: Optional[dict] = None
        self._cooccurrence: Optional[list] = None

    # ------------------------------------------------------------------
    # 1. Play frequency
    # ------------------------------------------------------------------

    def play_frequency(self) -> list[dict]:
        """
        Returns one dict per song:
          id, artist, title, in_repertoire, is_jazz, play_count,
          by_year: {year: count, ...}

        Sorted by play_count DESC, then artist+title ASC.
        Songs with 0 plays are included (left join).
        """
        if self._freq is not None:
            return self._freq

        with self._conn.cursor() as cur:
            # Total play counts
            cur.execute(
                """
                SELECT
                    s.id,
                    s.artist,
                    s.title,
                    s.in_repertoire,
                    s.is_jazz,
                    s.genre,
                    COUNT(ss.id) AS play_count
                FROM songs s
                LEFT JOIN setlist_songs ss ON ss.song_id = s.id
                WHERE s.deleted_at IS NULL
                GROUP BY s.id, s.artist, s.title, s.in_repertoire, s.is_jazz, s.genre
                ORDER BY play_count DESC, s.artist ASC, s.title ASC
                """
            )
            rows = cur.fetchall()

            # Year breakdowns
            cur.execute(
                """
                SELECT
                    s.id,
                    YEAR(g.gig_date) AS yr,
                    COUNT(ss.id) AS cnt
                FROM songs s
                JOIN setlist_songs ss ON ss.song_id = s.id
                JOIN setlists sl      ON sl.id = ss.setlist_id
                JOIN gigs g           ON g.id = sl.gig_id
                WHERE s.deleted_at IS NULL
                  AND g.gig_date IS NOT NULL
                GROUP BY s.id, YEAR(g.gig_date)
                """
            )
            by_year_raw = cur.fetchall()

        by_year: dict = defaultdict(dict)
        for r in by_year_raw:
            by_year[r["id"]][r["yr"]] = r["cnt"]

        result = []
        for row in rows:
            d = dict(row)
            d["by_year"] = dict(sorted(by_year.get(d["id"], {}).items()))
            result.append(d)

        self._freq = result
        return result

    def top_n(self, n: int = 40) -> list[dict]:
        return [r for r in self.play_frequency() if r["play_count"] > 0][:n]

    def zero_play(self) -> list[dict]:
        return [r for r in self.play_frequency() if r["play_count"] == 0]

    # ------------------------------------------------------------------
    # 2. Recency
    # ------------------------------------------------------------------

    def recency(self) -> list[dict]:
        """
        Returns one dict per song:
          id, artist, title, in_repertoire, last_played (date|None),
          days_since (int|None), stale (bool — in_repertoire & days > threshold)

        Sorted by last_played ASC (never-played first, then oldest first).
        """
        if self._recency is not None:
            return self._recency

        with self._conn.cursor() as cur:
            cur.execute(
                """
                SELECT
                    s.id,
                    s.artist,
                    s.title,
                    s.in_repertoire,
                    MAX(g.gig_date) AS last_played
                FROM songs s
                LEFT JOIN setlist_songs ss ON ss.song_id = s.id
                LEFT JOIN setlists sl      ON sl.id = ss.setlist_id
                LEFT JOIN gigs g           ON g.id = sl.gig_id
                                          AND g.gig_date IS NOT NULL
                WHERE s.deleted_at IS NULL
                GROUP BY s.id, s.artist, s.title, s.in_repertoire
                ORDER BY last_played ASC, s.artist ASC
                """
            )
            rows = cur.fetchall()

        result = []
        for row in rows:
            d = dict(row)
            lp = d["last_played"]
            if lp is not None:
                if isinstance(lp, str):
                    from datetime import datetime
                    lp = datetime.strptime(lp, "%Y-%m-%d").date()
                days = (self._today - lp).days
            else:
                days = None
            d["last_played"] = lp.isoformat() if lp else None
            d["days_since"] = days
            d["stale"] = bool(
                d["in_repertoire"]
                and (days is None or days > RECENCY_THRESHOLD_DAYS)
            )
            result.append(d)

        self._recency = result
        return result

    def stale_songs(self) -> list[dict]:
        return [r for r in self.recency() if r["stale"]]

    # ------------------------------------------------------------------
    # 3. Set structure
    # ------------------------------------------------------------------

    def set_structure(self) -> dict:
        """
        Returns:
          set_lengths: list of ints (songs per 'set'-type setlist)
          avg_set_length: float
          median_set_length: float
          openers: [{artist, title, count}, ...]  (top 10)
          closers: [{artist, title, count}, ...]  (top 10)
          transitions: [{from_artist, from_title, to_artist, to_title, count}, ...] (top 20)
          sets_per_gig: {count: frequency}
        """
        if self._structure is not None:
            return self._structure

        with self._conn.cursor() as cur:
            # Set lengths (standard 'set' type only)
            cur.execute(
                """
                SELECT sl.id AS setlist_id, COUNT(ss.id) AS song_count
                FROM setlists sl
                JOIN setlist_songs ss ON ss.setlist_id = sl.id
                WHERE sl.set_type = 'set'
                GROUP BY sl.id
                """
            )
            lengths_raw = cur.fetchall()

            # Sets per gig (all types)
            cur.execute(
                """
                SELECT gig_id, COUNT(*) AS set_count
                FROM setlists
                GROUP BY gig_id
                """
            )
            sets_per_gig_raw = cur.fetchall()

            # Openers: first song (MIN sort_order) per setlist
            cur.execute(
                """
                SELECT s.artist, s.title, COUNT(*) AS cnt
                FROM setlist_songs ss
                JOIN songs s ON s.id = ss.song_id
                WHERE ss.sort_order = (
                    SELECT MIN(ss2.sort_order)
                    FROM setlist_songs ss2
                    WHERE ss2.setlist_id = ss.setlist_id
                )
                GROUP BY ss.song_id
                ORDER BY cnt DESC
                LIMIT 15
                """
            )
            openers = cur.fetchall()

            # Closers: last song (MAX sort_order) per setlist
            cur.execute(
                """
                SELECT s.artist, s.title, COUNT(*) AS cnt
                FROM setlist_songs ss
                JOIN songs s ON s.id = ss.song_id
                WHERE ss.sort_order = (
                    SELECT MAX(ss2.sort_order)
                    FROM setlist_songs ss2
                    WHERE ss2.setlist_id = ss.setlist_id
                )
                GROUP BY ss.song_id
                ORDER BY cnt DESC
                LIMIT 15
                """
            )
            closers = cur.fetchall()

            # Transitions: consecutive pairs using ROW_NUMBER
            # sort_order has gaps (swap-based reorder), so we rank within each set
            cur.execute(
                """
                WITH ranked AS (
                    SELECT
                        setlist_id,
                        song_id,
                        ROW_NUMBER() OVER (
                            PARTITION BY setlist_id ORDER BY sort_order
                        ) AS rn
                    FROM setlist_songs
                )
                SELECT
                    s1.artist  AS from_artist,
                    s1.title   AS from_title,
                    s2.artist  AS to_artist,
                    s2.title   AS to_title,
                    COUNT(*)   AS cnt
                FROM ranked r1
                JOIN ranked r2 ON r2.setlist_id = r1.setlist_id
                               AND r2.rn = r1.rn + 1
                JOIN songs s1  ON s1.id = r1.song_id
                JOIN songs s2  ON s2.id = r2.song_id
                GROUP BY r1.song_id, r2.song_id
                ORDER BY cnt DESC
                LIMIT 20
                """
            )
            transitions = cur.fetchall()

        set_lengths = [r["song_count"] for r in lengths_raw]
        avg_len = sum(set_lengths) / len(set_lengths) if set_lengths else 0.0
        sorted_len = sorted(set_lengths)
        n = len(sorted_len)
        median_len = (
            sorted_len[n // 2]
            if n % 2 == 1
            else (sorted_len[n // 2 - 1] + sorted_len[n // 2]) / 2
        ) if sorted_len else 0.0

        spg_dist: dict = defaultdict(int)
        for r in sets_per_gig_raw:
            spg_dist[r["set_count"]] += 1

        self._structure = {
            "set_lengths": set_lengths,
            "avg_set_length": round(avg_len, 1),
            "median_set_length": float(median_len),
            "openers": [dict(r) for r in openers],
            "closers": [dict(r) for r in closers],
            "transitions": [dict(r) for r in transitions],
            "sets_per_gig": dict(sorted(spg_dist.items())),
        }
        return self._structure

    # ------------------------------------------------------------------
    # 4. Co-occurrence
    # ------------------------------------------------------------------

    def cooccurrence(self, top_n: int = 50) -> list[dict]:
        """
        Song pairs that appear together in the same set, ordered by frequency.
        Returns [{song1_id, artist1, title1, song2_id, artist2, title2, count}, ...]

        Pairs are canonical (song1_id < song2_id) to avoid double-counting.
        """
        if self._cooccurrence is not None:
            return self._cooccurrence[:top_n]

        with self._conn.cursor() as cur:
            cur.execute(
                """
                SELECT
                    s1.id    AS song1_id,
                    s1.artist AS artist1,
                    s1.title  AS title1,
                    s2.id    AS song2_id,
                    s2.artist AS artist2,
                    s2.title  AS title2,
                    COUNT(DISTINCT ss1.setlist_id) AS cooccurrence_count
                FROM setlist_songs ss1
                JOIN setlist_songs ss2 ON ss2.setlist_id = ss1.setlist_id
                                      AND ss2.song_id > ss1.song_id
                JOIN songs s1 ON s1.id = ss1.song_id
                JOIN songs s2 ON s2.id = ss2.song_id
                GROUP BY ss1.song_id, ss2.song_id
                ORDER BY cooccurrence_count DESC
                LIMIT %s
                """,
                (top_n,),
            )
            rows = cur.fetchall()

        self._cooccurrence = [dict(r) for r in rows]
        return self._cooccurrence

    def cooccurrence_matrix(self) -> dict[tuple[int, int], int]:
        """Returns a dict keyed by (min_id, max_id) → count for use in SetlistBuilder."""
        matrix: dict[tuple[int, int], int] = {}
        for r in self.cooccurrence(top_n=500):
            key = (r["song1_id"], r["song2_id"])
            matrix[key] = r["cooccurrence_count"]
        return matrix


# ---------------------------------------------------------------------------
# SetlistBuilder
# ---------------------------------------------------------------------------

class SetlistBuilder:
    """
    Generates ordered, time-matched setlists.

    Primary use case (fill_and_order):
      Customer provides unordered song picks (possibly mismatched to target runtime).
      Builder fills gaps or trims, then orders by co-occurrence and divides into sets.

    Secondary use case (generate_fresh):
      No customer picks — generate from scratch using frequency + recency weighting.

    All methods are pure given the analytics data; no DB access.
    """

    def __init__(
        self,
        analytics: SetlistAnalytics,
        avg_song_duration_min: float = AVG_SONG_DURATION_MIN,
    ) -> None:
        self._analytics = analytics
        self._duration = avg_song_duration_min

        # Build lookup maps from frequency data
        self._song_map: dict[int, dict] = {
            s["id"]: s for s in analytics.play_frequency()
        }
        self._recency_map: dict[int, dict] = {
            s["id"]: s for s in analytics.recency()
        }
        self._cooc = analytics.cooccurrence_matrix()

        # Normalisation factors
        plays = [s["play_count"] for s in self._song_map.values()]
        self._max_plays = max(plays) if plays else 1

        days_values = [
            r["days_since"]
            for r in self._recency_map.values()
            if r["days_since"] is not None
        ]
        self._max_days = max(days_values) if days_values else RECENCY_THRESHOLD_DAYS

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------

    def _score(self, song_id: int, w_freq: float = 0.6, w_recency: float = 0.4) -> float:
        """
        Combined priority score for a song (higher → more desirable to include).
        freq component: normalised play count (popular songs score higher)
        recency component: normalised days since last play (stale songs score higher —
          they need an airing, and are less likely to feel repetitive to the audience)
        """
        song = self._song_map.get(song_id)
        rec = self._recency_map.get(song_id)
        if not song:
            return 0.0

        freq_score = song["play_count"] / self._max_plays

        if rec and rec["days_since"] is not None:
            recency_score = min(rec["days_since"] / self._max_days, 1.0)
        else:
            recency_score = 1.0  # never played → highest recency score

        return w_freq * freq_score + w_recency * recency_score

    def _cooc_score(self, song_id: int, placed: list[int]) -> float:
        """
        Co-occurrence affinity of song_id with respect to already-placed songs.
        Returns the sum of co-occurrence counts between song_id and placed songs.
        """
        total = 0
        for p in placed:
            key = (min(p, song_id), max(p, song_id))
            total += self._cooc.get(key, 0)
        return float(total)

    def _order_songs(self, song_ids: list[int]) -> list[int]:
        """
        Greedy nearest-neighbour ordering using co-occurrence as the affinity metric.
        Ties broken by individual play count (higher = earlier in set).

        Starting song: highest overall play count (well-known → good opener).
        """
        if not song_ids:
            return []

        remaining = list(song_ids)
        # Start from highest play count
        remaining.sort(
            key=lambda sid: self._song_map.get(sid, {}).get("play_count", 0),
            reverse=True,
        )
        ordered = [remaining.pop(0)]

        while remaining:
            last = ordered[-1]
            # Pick next song with highest co-occurrence score relative to already placed
            best = max(
                remaining,
                key=lambda sid: (
                    self._cooc_score(sid, ordered[-3:]),  # look-back 3 to avoid local minima
                    self._song_map.get(sid, {}).get("play_count", 0),
                ),
            )
            ordered.append(best)
            remaining.remove(best)

        return ordered

    def _split_into_sets(self, ordered_ids: list[int], set_count: int) -> list[list[int]]:
        """Divide ordered song list into set_count roughly equal sets."""
        n = len(ordered_ids)
        base = n // set_count
        remainder = n % set_count
        sets = []
        i = 0
        for s in range(set_count):
            size = base + (1 if s < remainder else 0)
            sets.append(ordered_ids[i : i + size])
            i += size
        return sets

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------

    def fill_and_order(
        self,
        seed_song_ids: list[int],
        target_runtime_min: int,
        set_count: int = 2,
    ) -> list[list[dict]]:
        """
        Primary builder function.

        Given unordered customer picks (seed_song_ids), fills or trims the selection
        to match target_runtime_min, then orders and divides into set_count sets.

        Returns a list of sets; each set is an ordered list of song dicts:
          {id, artist, title, position_in_set, play_count, last_played, notes}

        Parameters
        ----------
        seed_song_ids   : Song IDs from customer picks (may be empty for fresh generation)
        target_runtime_min : Total desired performance time in minutes
        set_count       : Number of sets to divide into (default 2)

        Algorithm
        ---------
        1. Target song count: floor(target_runtime_min / avg_duration)
        2. Validate seeds: filter to known in_repertoire songs
        3. Gap fill: if len(seeds) < target, add from candidate pool scored by
           (frequency + recency), weighted random sampling without replacement.
           Candidates exclude seed songs, jazz songs (is_jazz=1), and out-of-repertoire
           songs (in_repertoire=0).
        4. Trim: if len(seeds) > target, drop lowest-scored non-seed songs first,
           then lowest-scored seed songs.
        5. Order: greedy co-occurrence TSP (see _order_songs).
        6. Split: balanced partition into set_count sets.
        """
        target_count = max(1, round(target_runtime_min / self._duration))

        # Filter seeds to known, active, in-repertoire songs
        valid_seeds = [
            sid for sid in seed_song_ids
            if sid in self._song_map
            and self._song_map[sid].get("in_repertoire")
            and not self._song_map[sid].get("is_jazz")
        ]
        seed_set = set(valid_seeds)

        # Candidate pool for filling gaps
        candidates = [
            sid
            for sid, s in self._song_map.items()
            if sid not in seed_set
            and s.get("in_repertoire")
            and not s.get("is_jazz")
        ]

        current = list(valid_seeds)

        if len(current) < target_count:
            # Score + sample candidates (weighted by score, no replacement)
            needed = target_count - len(current)
            scored = [(sid, self._score(sid)) for sid in candidates]
            scored.sort(key=lambda x: x[1], reverse=True)

            # Weighted random sampling: top-2× pool, sample `needed` using weights
            pool_size = min(len(scored), needed * 2 + 20)
            pool = scored[:pool_size]
            weights = [max(s, 0.01) for _, s in pool]
            ids = [sid for sid, _ in pool]

            k = min(needed, len(ids))
            chosen = random.choices(ids, weights=weights, k=k * 3)  # oversample
            seen: set = set()
            deduped = []
            for c in chosen:
                if c not in seen and c not in seed_set:
                    seen.add(c)
                    deduped.append(c)
                    if len(deduped) >= k:
                        break

            current.extend(deduped)

        elif len(current) > target_count:
            # Trim: drop lowest-scored non-seed songs first
            non_seeds = [sid for sid in current if sid not in seed_set]
            non_seeds.sort(key=lambda sid: self._score(sid))
            to_drop = len(current) - target_count
            drop_set = set(non_seeds[:to_drop])
            if len(drop_set) < to_drop:
                # Still over — also trim lowest-scored seeds
                remaining_seeds = [sid for sid in current if sid in seed_set]
                remaining_seeds.sort(key=lambda sid: self._score(sid))
                extra = to_drop - len(drop_set)
                drop_set.update(remaining_seeds[:extra])
            current = [sid for sid in current if sid not in drop_set]

        # Order by co-occurrence affinity
        ordered_ids = self._order_songs(current)

        # Divide into sets
        sets_of_ids = self._split_into_sets(ordered_ids, set_count)

        # Build output dicts
        result_sets = []
        for set_idx, set_ids in enumerate(sets_of_ids, start=1):
            set_songs = []
            for pos, sid in enumerate(set_ids, start=1):
                s = self._song_map[sid]
                rec = self._recency_map.get(sid, {})
                set_songs.append(
                    {
                        "id": sid,
                        "artist": s["artist"],
                        "title": s["title"],
                        "set_number": set_idx,
                        "position_in_set": pos,
                        "play_count": s["play_count"],
                        "last_played": rec.get("last_played"),
                        "days_since": rec.get("days_since"),
                    }
                )
            result_sets.append(set_songs)

        return result_sets

    def generate_fresh(self, target_length: int = 20, set_count: int = 2) -> list[list[dict]]:
        """
        Generate a complete setlist from scratch (no customer picks).
        Equivalent to fill_and_order([], target_length * avg_duration, set_count).
        Uses target_length as number of songs (not minutes).
        """
        target_min = round(target_length * self._duration)
        return self.fill_and_order([], target_min, set_count)


# ---------------------------------------------------------------------------
# Report renderers
# ---------------------------------------------------------------------------

def report_markdown(analytics: SetlistAnalytics) -> str:
    lines = []
    _h = lines.append

    _h("# Setlist Analytics Report")
    _h(f"\n_Generated: {date.today().isoformat()}_\n")

    # ----------------------------------------------------------------
    # 1. Play frequency
    # ----------------------------------------------------------------
    _h("---\n")
    _h("## 1. Play Frequency\n")

    freq = analytics.play_frequency()
    total_songs = len(freq)
    total_plays = sum(r["play_count"] for r in freq)
    in_rep = [r for r in freq if r["in_repertoire"] and not r["is_jazz"]]
    zero = analytics.zero_play()
    top40 = analytics.top_n(40)

    _h(f"**Corpus:** {total_songs} songs total · {len(in_rep)} in main repertoire "
       f"· {sum(1 for r in freq if r['is_jazz'])} jazz · {total_plays} total play slots\n")

    _h("### Top 40 most-played\n")
    _h("| # | Artist | Title | Total | " + " | ".join(
        str(y) for y in range(2013, date.today().year + 1)
    ) + " |")
    _h("|---|--------|-------|-------|" + "|".join(
        "------" for _ in range(2013, date.today().year + 1)
    ) + "|")
    for rank, song in enumerate(top40, start=1):
        by_year = song["by_year"]
        year_cols = " | ".join(
            str(by_year.get(y, "")) for y in range(2013, date.today().year + 1)
        )
        _h(f"| {rank} | {song['artist']} | {song['title']} | {song['play_count']} | {year_cols} |")

    _h("")
    _h(f"### Zero-play songs ({len(zero)} songs in repertoire never setlisted)\n")
    if zero:
        _h("| Artist | Title | In repertoire | Jazz |")
        _h("|--------|-------|---------------|------|")
        for s in zero:
            rep = "✓" if s["in_repertoire"] else "—"
            jazz = "✓" if s["is_jazz"] else "—"
            _h(f"| {s['artist']} | {s['title']} | {rep} | {jazz} |")
    else:
        _h("_All songs have been played at least once._")
    _h("")

    # ----------------------------------------------------------------
    # 2. Recency
    # ----------------------------------------------------------------
    _h("---\n")
    _h("## 2. Recency\n")

    stale = analytics.stale_songs()
    _h(
        f"**Stale candidates** (in_repertoire = 1, not played in >{RECENCY_THRESHOLD_DAYS // 365} years): "
        f"**{len(stale)} songs**\n"
    )

    if stale:
        _h("| Artist | Title | Last played | Days since |")
        _h("|--------|-------|------------|------------|")
        for s in stale:
            last = s["last_played"] or "never"
            days = str(s["days_since"]) if s["days_since"] is not None else "∞"
            _h(f"| {s['artist']} | {s['title']} | {last} | {days} |")
    _h("")

    # ----------------------------------------------------------------
    # 3. Set structure
    # ----------------------------------------------------------------
    _h("---\n")
    _h("## 3. Set Structure\n")

    struct = analytics.set_structure()
    lengths = struct["set_lengths"]

    _h(f"**Standard sets analysed:** {len(lengths)}")
    _h(f"  Average length: **{struct['avg_set_length']} songs/set**")
    _h(f"  Median length:  **{struct['median_set_length']} songs/set**\n")

    if lengths:
        from collections import Counter
        dist = Counter(lengths)
        _h("**Set length distribution:**\n")
        _h("| Songs/set | Sets |")
        _h("|-----------|------|")
        for length, count in sorted(dist.items()):
            _h(f"| {length} | {count} |")
        _h("")

    spg = struct["sets_per_gig"]
    if spg:
        _h("**Sets per gig distribution:**\n")
        _h("| Sets/gig | Gigs |")
        _h("|----------|------|")
        for k, v in sorted(spg.items()):
            _h(f"| {k} | {v} |")
        _h("")

    _h("### Common openers (Set 1 / any set, first position)\n")
    _h("| # | Artist | Title | Times as opener |")
    _h("|---|--------|-------|----------------|")
    for i, o in enumerate(struct["openers"][:10], start=1):
        _h(f"| {i} | {o['artist']} | {o['title']} | {o['cnt']} |")
    _h("")

    _h("### Common closers (last position in any set)\n")
    _h("| # | Artist | Title | Times as closer |")
    _h("|---|--------|-------|----------------|")
    for i, c in enumerate(struct["closers"][:10], start=1):
        _h(f"| {i} | {c['artist']} | {c['title']} | {c['cnt']} |")
    _h("")

    _h("### Most common transitions (consecutive pairs)\n")
    _h("| # | From | To | Count |")
    _h("|---|------|-----|-------|")
    for i, t in enumerate(struct["transitions"][:20], start=1):
        frm = f"{t['from_artist']} – {t['from_title']}"
        to = f"{t['to_artist']} – {t['to_title']}"
        _h(f"| {i} | {frm} | {to} | {t['cnt']} |")
    _h("")

    # ----------------------------------------------------------------
    # 4. Co-occurrence
    # ----------------------------------------------------------------
    _h("---\n")
    _h("## 4. Co-occurrence (same set)\n")
    _h("Most frequent song pairs appearing together in the same set.\n")

    pairs = analytics.cooccurrence(top_n=40)
    if pairs:
        _h("| # | Song A | Song B | Sets together |")
        _h("|---|--------|--------|---------------|")
        for i, p in enumerate(pairs, start=1):
            a = f"{p['artist1']} – {p['title1']}"
            b = f"{p['artist2']} – {p['title2']}"
            _h(f"| {i} | {a} | {b} | {p['cooccurrence_count']} |")
    _h("")

    return "\n".join(lines)


def report_json(analytics: SetlistAnalytics) -> str:
    data = {
        "generated": date.today().isoformat(),
        "play_frequency": analytics.play_frequency(),
        "recency": analytics.recency(),
        "set_structure": analytics.set_structure(),
        "cooccurrence": analytics.cooccurrence(top_n=100),
    }
    return json.dumps(data, default=str, ensure_ascii=False, indent=2)


def format_setlist(sets: list[list[dict]], target_runtime_min: Optional[int] = None) -> str:
    lines = []
    total_songs = sum(len(s) for s in sets)
    est_min = total_songs * AVG_SONG_DURATION_MIN
    header = f"## Proposed Setlist — {total_songs} songs"
    if target_runtime_min:
        header += f" (target: {target_runtime_min} min, est. ~{est_min:.0f} min)"
    else:
        header += f" (~{est_min:.0f} min estimated)"
    lines.append(header)
    lines.append("")

    for set_songs in sets:
        if not set_songs:
            continue
        set_num = set_songs[0]["set_number"]
        set_est = len(set_songs) * AVG_SONG_DURATION_MIN
        lines.append(f"### Set {set_num} ({len(set_songs)} songs, ~{set_est:.0f} min)\n")
        lines.append("| # | Artist | Title | Plays | Last played |")
        lines.append("|---|--------|-------|-------|-------------|")
        for song in set_songs:
            lp = song["last_played"] or "never"
            lines.append(
                f"| {song['position_in_set']} | {song['artist']} | {song['title']} "
                f"| {song['play_count']} | {lp} |"
            )
        lines.append("")

    return "\n".join(lines)


# ---------------------------------------------------------------------------
# CLI entry point
# ---------------------------------------------------------------------------

def main() -> None:
    parser = argparse.ArgumentParser(
        description="Setlist analytics report + default setlist generator."
    )
    parser.add_argument(
        "--json",
        action="store_true",
        help="Output analytics as JSON instead of Markdown.",
    )
    parser.add_argument(
        "--generate",
        type=int,
        metavar="N",
        help="Generate a fresh N-song setlist (no customer picks).",
    )
    parser.add_argument(
        "--fill",
        metavar="ID,ID,...",
        help="Comma-separated song IDs from customer picks to fill and order.",
    )
    parser.add_argument(
        "--target",
        type=int,
        default=90,
        metavar="MINUTES",
        help="Target runtime in minutes for --fill (default: 90).",
    )
    parser.add_argument(
        "--sets",
        type=int,
        default=2,
        metavar="N",
        help="Number of sets to divide into (default: 2).",
    )
    parser.add_argument(
        "--seed",
        type=int,
        default=None,
        help="Random seed for reproducible setlist generation.",
    )
    args = parser.parse_args()

    if args.seed is not None:
        random.seed(args.seed)

    env = _load_env()
    conn = _connect(env)

    try:
        analytics = SetlistAnalytics(conn)

        if args.generate:
            builder = SetlistBuilder(analytics)
            sets = builder.generate_fresh(target_length=args.generate, set_count=args.sets)
            print(format_setlist(sets))
        elif args.fill:
            try:
                seed_ids = [int(x.strip()) for x in args.fill.split(",") if x.strip()]
            except ValueError:
                sys.exit("--fill expects comma-separated integer song IDs")
            builder = SetlistBuilder(analytics)
            sets = builder.fill_and_order(
                seed_song_ids=seed_ids,
                target_runtime_min=args.target,
                set_count=args.sets,
            )
            print(format_setlist(sets, target_runtime_min=args.target))
        elif args.json:
            print(report_json(analytics))
        else:
            print(report_markdown(analytics))

    finally:
        conn.close()


if __name__ == "__main__":
    main()

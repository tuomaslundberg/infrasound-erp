# Setlist ETL — Design Specification

*Written after full exploration of `old-files/info/`, 5 recent gig setlists, 3 era-representative
historical setlists, and the Ableton project XML structure.*

---

## Source files

### Global repertoire (`old-files/info/`)

| File | Role in ETL |
|------|-------------|
| `playlist-gig.txt` | **Primary song source.** Canonical list of all active songs with HD/guide-tone prefix, shorthand key, GTR2 flag, and genre/language grouping. |
| `keys.txt` | Supplementary key data: original key, transposition semitones, `SÄVELLAJI PITÄÄ PÄÄTTÄÄ` (undecided) flag, karaoke-key notes. |
| `hd-list.txt` | HD status: `TEHTY` = configured in project; `TEKEMÄTTÄ` = pending (unreliable — some TEKEMÄTTÄ rows may actually be done). |
| `hd-extra-info.txt` | Four edge-case categories for songs with special guide-tone or transposition requirements. |
| `to-be-played.txt` | Practice-needed list. Indented (tab) entries = already played on a gig. Incomplete but may catch edge cases. |
| `playlist-jazz.txt` | Jazz repertoire. Structurally separate; `is_jazz = 1`, not customer-orderable. |
| `playlist-w-extra-info.txt` | `release_year` source (mirrors `playlist-gig.txt` plus year column). |
| `playlist.txt` | Client-facing text only. No relational data; skip. |
| `launch-notes-correspondence.txt` | Guide-tone key mapping: drummer's keyboard key → MIDI note. Needed to document `guide_tone_key` semantics; not directly parsed for ETL. |

### Per-gig files (`old-files/past-gigs/YY/YYMMDD-customer/`)

Primary: `setlist-gig-YYMMDD.txt` (exists from 2023 onwards)
Fallback: `setlist-internal-YYMMDD.txt` (2021-2022; also coexists in 2023 as a simplified duplicate)

`setlist-customer-YYMMDD.txt` — client-facing, no technical data; skip for ETL.

---

## Setlist file format — three eras

### Era 1 (2021–2022): `setlist-internal-YYMMDD.txt`

Mikael-era format. Keys are full English and reflect Mikael's transpositions, **not** Alina's
canonical keys. Import for play-count analytics only; ignore key data.

```
(kappaleita/sävellajeja/sovituksia/taustanauhoja/tekemättömiä taustanauhoja)

SETTI N (stats)

Artist – Song Name
- F major (orig. A minor)
- [optional: taustanauha / sovitus / notes]

...
```

Parse: extract `Artist – Song Name` lines (any line not starting with `-`, `(`, whitespace,
or being blank). Set header: `SETTI N` or `ENCORE` keyword at start of capitalised header line.

### Era 2 (early 2023): `setlist-gig-YYMMDD.txt` with bare numeric codes

Transitional format. No keys. Codes like `133`, `172` are launchpad coords from that period
(may not match current `playlist-gig.txt` slot assignments).

```
---------- SETTI N ----------

NNN Artist – Song Name
ETSI Artist – Song Name   ← "ETSI" = placeholder, song not yet prepared; skip
```

Parse: strip leading `NNN ` or `ETSI ` before matching artist+title.

### Era 3 (late 2023+, current): `setlist-gig-YYMMDD.txt` with prefix column

Current format. Authoritative.

```
---------- SETTI N ----------

[prefix]\t[Artist – Song Name]     [key]   [GTR2]
```

Prefix values:
- `---` — no HD, no guide tone
- `NNN` (3-digit) — launchpad coord (user=N[0], row=N[1], col=N[2]); song has a configured backing track
- `[letter]` (single char) — guide tone; drummer plays this key on keyboard (see `launch-notes-correspondence.txt`)
- `XXX` — guide tone needed, key not yet decided

Key shorthand: `Cm`, `F#m`, `Ebm`, `Ab`, `F#`, `Db` etc.
GTR2: literal string `GTR2` after 3 spaces following key.
Annotations in song name: e.g., `(HÄÄTANSSI)`, `(HÄÄTANSSI, ALEKSI & ALINA)` — store in `setlist_songs.notes`.
`!!!` after key: ad-hoc transposition from canonical for that gig — do NOT store in DB.

Set header pattern: `---------- [LABEL] ----------`
Label mapping to `set_type` ENUM:
- `SETTI N` → `'set'`, `set_number = N`
- `KARAOKE-SETTI` → `'karaoke'`, `set_number = sequential`
- `ENCORE` → `'encore'`, `set_number = sequential`
- `LOUNGE` / `JAZZ` / `VIIHDE` → `'lounge'`, `set_number = sequential`

`set_number` is always sequential within a gig (1-based), regardless of type. The `set_type`
column carries the semantic meaning; `set_number` is purely an ordering key.

---

## `playlist-gig.txt` — parsing map

Format per song line:
```
[prefix]\t[Artist – Song Name]     [key]   [GTR2 note]
```

Five-space separator before key; three-space before GTR2.

Genre blocks: separated by a blank line between block header and first song; genre name is
the header line (no tab, no dash, not a song line).

Language groups within a genre: separated by a **double blank line** (one blank line = normal
spacing between songs; two consecutive blank lines = language boundary).
Language order within a genre is consistently Finnish → Swedish → English (not always all three
present).

Extra songs section: starts at header line `Ohjelmiston ulkopuoliset` (line 444 in current
file). Songs in this section: `in_repertoire = 0`.

---

## `keys.txt` — structure and parsing

Sections (in order):

| Section header | Meaning | ETL use |
|---|---|---|
| `VANHA RUNKOSETTI` | Old standard setlist keys (Alina-compatible unless noted) | Import key data |
| `RUNKOSETIN ULKOPUOLISET` | Per-gig song additions; subsections headed `YYMMDD Customer` | Import key data per song |
| `RUNKOSETTIIN TULEVAT, EI-TOIVEBIISIT` | Songs that were to be added to the (then-new) standard repertoire — i.e., they are now in the standard repertoire | Import key data |
| `EI TULE UUTEEN RUNKOSETTIIN` | Songs retired from the standard setlist; may resurface as song wishes | Import key data (see dual-format note below) |
| `UUDET (OHJEMISTOSSA)` | New songs in the standard repertoire ("ohjelmisto" = repertoire/setlist, not Ableton) | Import key data |
| `UUDET (OHJELMISTON ULKOPUOLELLA)` | New songs outside the standard repertoire | Import key data |

Per-song record format:
```
Artist – Song Name
- [Key] (orig. [Key])          ← "our key" and original; if "(orig.)" only, our key = original
- +N / -M ST                   ← transposition in semitones (optional)
- HUOM! [note]                 ← extra note (optional, may repeat)
```

Key name format: full English (`C-sharp minor`, `F major`, `E-flat major`).
`SÄVELLAJI PITÄÄ PÄÄTTÄÄ` = key undecided → `key_our = NULL`.

### Key normalisation map (full English → shorthand)

| Full English | Shorthand | Full English | Shorthand |
|---|---|---|---|
| C major | C | C minor | Cm |
| C-sharp major | C# | C-sharp minor | C#m |
| D-flat major | Db | D-flat minor | Dbm |
| D major | D | D minor | Dm |
| E-flat major | Eb | E-flat minor | Ebm |
| E major | E | E minor | Em |
| F major | F | F minor | Fm |
| F-sharp major | F# | F-sharp minor | F#m |
| G-flat major | Gb | G-flat minor | Gbm |
| G major | G | G minor | Gm |
| G-sharp major | G# | G-sharp minor | G#m |
| A-flat major | Ab | A-flat minor | Abm |
| A major | A | A minor | Am |
| B-flat major | Bb | B-flat minor | Bbm |
| B major | B | B minor | Bm |

`(orig.)` alone (no key after) = our key equals original → `key_orig = key_our`.

### Dual format in `EI TULE UUTEEN RUNKOSETTIIN`

Songs in this section that have been played with Alina use the normal format (our key + orig.
key). Songs not yet played with Alina use an abbreviated format where only the original key
is recorded:

```
Wham! – Last Christmas
- orig. D major
```

Parse rule: if the key line starts with `orig.` (no our-key prefix), set `key_orig` from
that value and leave `key_our = NULL`. This is indistinguishable from `SÄVELLAJI PITÄÄ
PÄÄTTÄÄ` in effect — the key is unknown for Alina — but the cause differs (never played
with her vs. undecided). Both map to `key_our = NULL`; `key_orig` is populated either way.

### HUOM notes to flag on the `songs` row:
- `KARAOKE, ALINAN SÄVELLAJIA EI OLE PÄÄTETTY` → `key_our = NULL` (key undecided for Alina)
- `DUETTO, RIIPPUU TOISESTA LAULAJASTA` → note in song metadata (no DB field needed; flag in `key_our` as NULL or a separate notes column if added later)

---

## Schema changes required — migration 013

### `songs` table extensions

```sql
ALTER TABLE songs
    ADD COLUMN spotify_track_id  VARCHAR(22)                                  DEFAULT NULL,
    ADD COLUMN genre             VARCHAR(100)                                  DEFAULT NULL,
    ADD COLUMN language          ENUM('fi', 'sv', 'en', 'other')              DEFAULT NULL,
    ADD COLUMN release_year      SMALLINT UNSIGNED                             DEFAULT NULL,
    ADD COLUMN is_jazz           TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN in_repertoire     TINYINT(1)   NOT NULL DEFAULT 1,
    ADD COLUMN hd_slot           CHAR(3)                                       DEFAULT NULL,
    ADD COLUMN hd_status         ENUM('none', 'done', 'pending') NOT NULL DEFAULT 'none',
    ADD COLUMN guide_tone_key    CHAR(1)                                       DEFAULT NULL,
    ADD COLUMN key_our           VARCHAR(10)                                   DEFAULT NULL,
    ADD COLUMN key_orig          VARCHAR(10)                                   DEFAULT NULL,
    ADD COLUMN key_transposition_st TINYINT                                    DEFAULT NULL,
    ADD COLUMN has_gtr2          TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN karaoke_eligible  TINYINT(1)   NOT NULL DEFAULT 0,
    ADD UNIQUE KEY uq_spotify_track_id (spotify_track_id);
```

### `setlists` table extension

```sql
ALTER TABLE setlists
    ADD COLUMN set_type ENUM('set', 'lounge', 'encore', 'karaoke') NOT NULL DEFAULT 'set'
    AFTER set_number;
```

---

## Spotify integration

### Why

Spotify track IDs on `songs` enable:
1. Automated setlist mirroring: DB setlist → Spotify playlist (currently a manual, error-prone
   step done from two near-parallel text files).
2. Internal (private) vs. client (shared) playlist diverge legitimately (surprise songs, best-man
   requests). A DB-driven export can produce both from the same setlist with a flag.
3. Song metadata enrichment: release year (canonical), Spotify popularity, audio features
   (tempo, energy, valence) for analytics.

### Credentials required

Add to `.env`:
```
SPOTIFY_CLIENT_ID=
SPOTIFY_CLIENT_SECRET=
```

Add to `.env.dev` if dev environment should also resolve tracks.

The ETL uses the **Client Credentials** flow (no user login needed for track search/lookup).

### Known public playlists (high-confidence seed source)

Four manually-curated public playlists exist and should be used as the primary `spotify_track_id`
source before falling back to search. All are accessible via Client Credentials.

| Playlist | Spotify ID | ETL role |
|---|---|---|
| Saturday repertoire | `7macuFdR2Oipb4tzfcgb8B` | Seed track IDs for main repertoire songs |
| Saturday suggestions | `4POTR14VsbrqC85DtAA37M` | Seed track IDs for song wishes / extras |
| Saturday live karaoke | `3Q3mAtmIcrQjLFVsoAzHER` | Seed track IDs **and** set `karaoke_eligible = 1` |
| Saturday Jazz | `5aY2nEjdBSf9PVw0QoZEgk` | Seed track IDs for jazz songs |

Fetch each playlist: `GET /v1/playlists/{id}/tracks?fields=items(track(id,name,artists))&limit=100`
(paginate if `next` is non-null).

### Resolution strategy

Two-phase approach in `enrich_spotify.py`:

**Phase 1 — Playlist seeding (high confidence):**
1. For each playlist above, fetch all tracks.
2. For each track, fuzzy-match `artist + title` against unresolved `songs` rows
   (same `difflib.SequenceMatcher` approach, threshold ≥ 0.85).
3. On match: set `spotify_track_id`. For the karaoke playlist: also emit
   `UPDATE songs SET karaoke_eligible = 1` for matched rows.
4. Log playlist tracks that don't match any song row to `db/seeds/spotify_unmatched.txt`
   (these may be wishes never added to `songs`, or duplicates).

**Phase 2 — Search-based resolution (fallback for remaining unmatched songs):**
1. For each song still missing `spotify_track_id`: query Spotify Search API:
   `GET /v1/search?q=artist:{artist} track:{title}&type=track&limit=5`
2. Pick best match using string similarity on artist + title.
3. Score threshold ≥ 0.85 → auto-assign `spotify_track_id`.
4. Below threshold → log to `db/seeds/spotify_unmatched.txt` for manual review.
5. Finnish artists often have lower match rates; fallback: search `{artist} {title}` without
   field qualifiers.

Separate enrichment script: `cli/etl/enrich_spotify.py`
Output: `db/seeds/legacy_spotify.sql` — UPDATE statements setting `spotify_track_id`
and `karaoke_eligible`.

### Playlist sync (future `cli/spotify_sync.py`)

Per-gig export flow (not part of ETL, but the goal):
1. Query `setlist_songs` JOIN `songs` for a given `gig_id` + flag (`client` vs `internal`).
2. POST `/v1/users/{user_id}/playlists` to create or PUT `/v1/playlists/{id}/tracks` to replace.
3. Delta sync: compare existing playlist tracks to desired list; only call API if changed.

---

## Analytics use cases (motivating the data model)

These drive the schema decisions (why we need `set_type`, `set_number`, `sort_order`, etc.).

- **Song frequency**: number of times a song appears across all gigs → rehearsal priority signal.
- **Recency-weighted play count**: exponential decay model over play dates; approximates
  "how well is this in memory right now". Useful for rehearsal scheduling.
- **Sequence correlations**: `(song_A, song_B)` pairwise co-occurrence within same gig and/or
  immediately adjacent positions (`|sort_order_B - sort_order_A| = 1`). Identifies natural
  transitions (e.g., "Sex on Fire → Mr. Brightside" co-occurrence frequency).
- **Set-type placement**: probability that a song appears in Set 1 vs Set 3 vs Encore. Informs
  setlist drafting.
- **First-dance pool**: songs marked `(HÄÄTANSSI)` in `setlist_songs.notes` — frequency and
  key patterns.
- **Genre balance**: songs per genre per gig; track drift in repertoire composition over time.
- **Vocabulary growth**: new songs debuted per year; rate of retirement.

---

## Ableton project XML (`old-files/saturday-live-043`)

Ableton 12.2.5 project, uncompressed XML, 13MB.
Structure: `<Ableton><LiveSet><Tracks>` containing `AudioTrack` elements.
Track names observed: `MV ALINA`, `MV ALINA 2`, `MV ALINA 3` etc. (MV = main vocal, Alina's
backing tracks / vocal processing chains).
Clip slots in session view correspond to song backing tracks; clip names match song names.
The launchpad slot coordinates in `playlist-gig.txt` (e.g., `123`) map to clip slot row/col
in the Launchpad X 8×8 grid.

**Important**: Ableton timeline coverage is NOT a reliable proxy for `hd_status`. Historically
all songs had timeline segments, but at some point segments stopped being created for songs
that don't need HD, since that was redundant manual work. Songs spanning the entire repertoire
history (old → new → song wishes) may or may not have timeline entries regardless of HD status.
`hd_status` must come exclusively from `hd-list.txt`.

Future automation:
- Parse XML to extract `<ClipSlot>` → song name → launchpad coordinate mapping.
- Validate that `playlist-gig.txt` slot assignments are in sync with actual project state.
- Potentially automate new backing track imports (assign to next available slot, update
  `playlist-gig.txt` entry).

---

## Prerequisites before writing the ETL script

1. ✅ **Migration 013**: `songs` columns + `setlists.set_type` — write and apply before ETL.
2. **Spotify credentials**: `SPOTIFY_CLIENT_ID` and `SPOTIFY_CLIENT_SECRET` in `.env`.
   Set up a Spotify Developer app at `developer.spotify.com/dashboard`.
   The Client Credentials flow requires no user authorization.
3. **`spotipy` Python dependency**: `pip install spotipy` — lightweight Spotify API wrapper.
   Add to `cli/etl/requirements.txt` (create if not present).

---

## Output files (when implemented)

| File | Contents |
|------|----------|
| `db/seeds/legacy_songs.sql` | INSERT songs from `playlist-gig.txt` + `playlist-jazz.txt`; UPDATE with key data from `keys.txt`; UPDATE hd_status from `hd-list.txt` |
| `db/seeds/legacy_setlists.sql` | INSERT setlists + setlist_songs for all gigs in `past-gigs/` |
| `db/seeds/legacy_spotify.sql` | UPDATE `songs.spotify_track_id` from Spotify resolution pass |
| `db/seeds/legacy_songs_unmatched.txt` | Songs in setlist files not matched to a `songs` row |
| `db/seeds/spotify_unmatched.txt` | Songs below Spotify match threshold; manual review needed |

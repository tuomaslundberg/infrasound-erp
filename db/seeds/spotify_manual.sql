-- spotify_manual.sql
-- Assigns Spotify track IDs to the 15 repertoire songs that enrich_spotify.py and
-- import_spotify_playlist.py could not resolve automatically (artist string mismatch
-- on featured-artist versions).
--
-- The playlist importer inserted featured-artist duplicates for these songs
-- (e.g. "Charlie Puth, Meghan Trainor – Marvin Gaye (feat. Meghan Trainor)").
-- This script soft-deletes those duplicates and assigns the IDs to the original rows.
--
-- Load into dev DB:
--   docker compose -p infrasound_dev exec -T db \
--     sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
--     < db/seeds/spotify_manual.sql
--
-- Load into prod DB: same without -p infrasound_dev / without -dev suffix

SET NAMES utf8mb4;

-- Step 1: Soft-delete the featured-artist duplicates inserted by import_spotify_playlist.py
-- (must clear their IDs first to free the unique constraint)
UPDATE songs SET spotify_track_id = NULL, deleted_at = UTC_TIMESTAMP()
WHERE spotify_track_id IN (
  '3rKYiySCDMUKTw5kGVVhaa',
  '7FXj7Qg3YorUxdrzvrcY25',
  '0O45fw2L5vsWpdsOdXwNAR',
  '6aHVPCIzIovTCYyOEvJu7K',
  '1dzQoRqT5ucxXVaAhTcT0J',
  '6xIu4wz5ihfzX5h7D8lTYr',
  '66wkCYWlXzSTQAfnsPBptt',
  '1r299qCKBLgUS9XJ9m1kEx',
  '6RPv4aPuVb46VpgciPtaix',
  '19tIdetwOIKsxSjaFxawvk',
  '5WytJMdICvcrJF6JY0W4pl',
  '19IqC66BdlmBDGOtk2yYBA',
  '3a6FcTyvSf0ED3VXeH3PJ5',
  '2V65y3PX4DkRhy1djlxd9p',
  '72uMyJYhDP5oecxWMQFA6W'
) AND in_repertoire = 0;

-- Step 2: Assign IDs to the original repertoire rows (artist + title from extract_songs.py)
-- Charlie Puth – Marvin Gaye
UPDATE songs SET spotify_track_id = '3rKYiySCDMUKTw5kGVVhaa' WHERE id = 87;
-- Frank Sinatra – Fly Me to the Moon
UPDATE songs SET spotify_track_id = '7FXj7Qg3YorUxdrzvrcY25' WHERE id = 17;
-- Justin Timberlake – SexyBack
UPDATE songs SET spotify_track_id = '0O45fw2L5vsWpdsOdXwNAR' WHERE id = 364;
-- JVG – Häissä
UPDATE songs SET spotify_track_id = '6aHVPCIzIovTCYyOEvJu7K' WHERE id = 329;
-- Lady Gaga – Just Dance
UPDATE songs SET spotify_track_id = '1dzQoRqT5ucxXVaAhTcT0J' WHERE id = 366;
-- Leidit – Hittiputki
UPDATE songs SET spotify_track_id = '6xIu4wz5ihfzX5h7D8lTYr' WHERE id = 325;
-- Mac Miller – My Favorite Part
UPDATE songs SET spotify_track_id = '66wkCYWlXzSTQAfnsPBptt' WHERE id = 202;
-- Maroon 5 – Moves Like Jagger
UPDATE songs SET spotify_track_id = '1r299qCKBLgUS9XJ9m1kEx' WHERE id = 370;
-- Massimo Faraò – Polkadots and Moonbeams
UPDATE songs SET spotify_track_id = '6RPv4aPuVb46VpgciPtaix' WHERE id = 398;
-- Pink Martini – Amado mio
UPDATE songs SET spotify_track_id = '19tIdetwOIKsxSjaFxawvk' WHERE id = 168;
-- Robin – Hula Hula
UPDATE songs SET spotify_track_id = '5WytJMdICvcrJF6JY0W4pl' WHERE id = 338;
-- Roope Salminen & Koirat – Madafakin darra
UPDATE songs SET spotify_track_id = '19IqC66BdlmBDGOtk2yYBA' WHERE id = 336;
-- Silk Sonic – Fly As Me
UPDATE songs SET spotify_track_id = '3a6FcTyvSf0ED3VXeH3PJ5' WHERE id = 206;
-- Swedish House Mafia – Don't You Worry Child
UPDATE songs SET spotify_track_id = '2V65y3PX4DkRhy1djlxd9p' WHERE id = 373;
-- The Crash – Lauren Caught My Eye
UPDATE songs SET spotify_track_id = '72uMyJYhDP5oecxWMQFA6W' WHERE id = 148;

-- spotify_manual.sql
-- Manual Spotify track ID fixes for songs not resolved by enrich_spotify.py.
--
-- How to fill in:
--   1. Find the song on Spotify (app or web player)
--   2. Share → Copy Song Link → URL looks like:
--      https://open.spotify.com/track/4uLU6hMCjMI75M1A2tKUQC?si=...
--      The track ID is the 22-character string after /track/
--   3. Replace 'FIXME' with the track ID (keep the single quotes)
--   4. Delete lines you can't resolve rather than leaving 'FIXME'
--
-- Load into dev DB:  make shell-db-dev  then paste, or:
--   docker compose -p infrasound_dev exec -T db \
--     sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"' \
--     < db/seeds/spotify_manual.sql
--
-- Load into prod DB: same without -p infrasound_dev / without -dev suffix

SET NAMES utf8mb4;

-- Antti Tuisku – Sata salamaa
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 335;

-- Basshunter – Boten Anna
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 365;

-- Charlie Puth – Marvin Gaye
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 87;

-- David Hasselhoff – True Survivor
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 218;

-- DJ Oku Luukkainen, HesaÄijä, Erika Vikman, Danny – 7300 päivää
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 340;

-- Don Huonot – Seireeni
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 254;

-- Frank Sinatra – Fly Me to the Moon
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 17;

-- Frederik – Tsingis Khan
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 226;

-- Fredi – Kolmatta linjaa takaisin
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 24;

-- Justin Timberlake – SexyBack
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 364;

-- JVG – Häissä
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 329;

-- Kari Tapio – Delfiinipoika
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 35;

-- Lady Gaga – Just Dance
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 366;

-- Leidit – Hittiputki
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 325;

-- Lord Est, Petri Nygård – Reggaerekka
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 328;

-- Mac Miller – My Favorite Part
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 202;

-- Maroon 5 – Moves Like Jagger
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 370;

-- Massimo Faraò – Polkadots and Moonbeams
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 398;

-- Olavi Virta – Hopeinen kuu
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 21;

-- Paula Koivuniemi – Sata kesää, tuhat yötä
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 228;

-- Petri Nygård, Lord Est – Selvä päivä
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 327;

-- Pink Martini – Amado mio
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 168;

-- Portion Boys, Matti ja Teppo – Vauhti kiihtyy
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 345;

-- Queen – Don't Stop Me Now
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 137;

-- Rex Orange County – Loving Is Easy
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 203;

-- Robin – Hula Hula
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 338;

-- Roope Salminen & Koirat – Madafakin darra
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 336;

-- Sade – Smooth Operator
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 167;

-- Silk Sonic – Fly As Me
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 206;

-- Swedish House Mafia – Don't You Worry Child
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 373;

-- The Beatles – Let It Be
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 136;

-- The Crash – Lauren Caught My Eye
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 148;

-- The Weeknd – I Feel It Coming
UPDATE songs SET spotify_track_id = 'FIXME' WHERE id = 246;

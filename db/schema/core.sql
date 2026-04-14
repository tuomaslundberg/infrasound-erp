-- ------------------------------------------------------------
-- Core schema — inquiry and gig management
-- ------------------------------------------------------------
-- Conventions (see AGENTS.md §7 and db/init.sql header):
--   - All timestamps in UTC
--   - Monetary values as INT (eurocents)
--   - Soft deletes via deleted_at (NULL = active)
--   - No DROP statements here; schema changes go in db/migrations/
-- ------------------------------------------------------------

-- ------------------------------------------------------------
-- customers
-- A customer is the contracting party — either a person (e.g.
-- a wedding couple) or a company/organisation.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name        VARCHAR(255)    NOT NULL,
    type        ENUM('person', 'company') NOT NULL DEFAULT 'person',
    notes       TEXT,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  DATETIME                 DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- contacts
-- A contact is the human we correspond with. For personal
-- customers this is usually the customer themselves; for
-- companies it may be a separate event coordinator.
-- One contact can be associated with multiple customers
-- (e.g. a promoter who books for several venues).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contacts (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    first_name  VARCHAR(100)    NOT NULL,
    last_name   VARCHAR(100)    NOT NULL,
    email       VARCHAR(255),
    phone       VARCHAR(50),
    notes       TEXT,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  DATETIME                 DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- customer_contacts  (bridge table)
-- Many-to-many: a customer can have multiple contacts, and a
-- contact can represent multiple customers.
-- is_primary = 1 marks the default contact for a customer.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customer_contacts (
    customer_id INT UNSIGNED    NOT NULL,
    contact_id  INT UNSIGNED    NOT NULL,
    is_primary  TINYINT(1)      NOT NULL DEFAULT 0,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (customer_id, contact_id),
    CONSTRAINT fk_cc_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_cc_contact  FOREIGN KEY (contact_id)  REFERENCES contacts  (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- venues
-- A venue is a physical location where a gig takes place.
-- Distance fields support travel cost calculation.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS venues (
    id                      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name                    VARCHAR(255)    NOT NULL,
    address_line            VARCHAR(255),
    city                    VARCHAR(100),
    postal_code             VARCHAR(10),
    country                 CHAR(2)         NOT NULL DEFAULT 'FI',
    -- Straight-line distance from Turku city centre (used for distance premium)
    distance_from_turku_km  DECIMAL(7,1),
    -- Geocoordinates (Nominatim; cached for TravelCalculator multi-waypoint routing)
    lat                     DECIMAL(9,6)             DEFAULT NULL,
    lng                     DECIMAL(9,6)             DEFAULT NULL,
    notes                   TEXT,
    -- Ferry: set once per island venue; TravelCalculator bills 2 vehicles × 2 ways
    requires_ferry           TINYINT(1)      NOT NULL DEFAULT 0,
    ferry_cost_estimate_cents INT                     DEFAULT NULL,
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at              DATETIME                 DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- gigs
-- Central table. Represents one booking/inquiry lifecycle
-- from first contact through to delivery and invoicing.
--
-- venue_id and contact_id are nullable because they may not
-- be known at the inquiry stage.
--
-- Monetary fields:
--   base_price_cents       calculated base price before any adjustment
--   quoted_price_cents     the actual price sent to the customer
--   other_travel_costs_cents  costs not covered by per-km calculation
--     (e.g. ferry, tolls)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS gigs (
    id                      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    customer_id             INT UNSIGNED    NOT NULL,
    -- Who we correspond with for this specific gig (may differ from customer)
    contact_id              INT UNSIGNED             DEFAULT NULL,
    -- Venue may be unknown at inquiry stage
    venue_id                INT UNSIGNED             DEFAULT NULL,
    -- NULL allowed: inquiry-status gigs may arrive without a date specified
    gig_date                DATE                     DEFAULT NULL,
    status                  ENUM(
                                'inquiry',      -- received, not yet quoted
                                'quoted',       -- quote sent, awaiting reply
                                'confirmed',    -- customer accepted, date reserved
                                'delivered',    -- gig performed
                                'cancelled',    -- confirmed but subsequently cancelled
                                'declined'      -- we were unavailable or declined
                            ) NOT NULL DEFAULT 'inquiry',
    -- Sales channel through which the inquiry arrived.
    -- buukkaa_bandi has a distinct operational flow (own booking system,
    -- commission invoicing, customer email not exposed pre-booking).
    -- All other channels route through the shared inbox but are tracked
    -- individually for analytics.  See migration 001 for ALTER TABLE statement.
    channel                 ENUM(
                                'mail',            -- direct email / unidentified source
                                'buukkaa_bandi',   -- buukkaa-bandi.fi (own system)
                                'saturday_band',   -- saturday.band platform
                                'venuu',           -- venuu.fi
                                'haamusiikki',     -- haamusiikki.com
                                'voodoolive',      -- voodoolive.fi
                                'ohjelmanaiset',   -- ohjelmanaiset.fi
                                'palkkaamuusikko', -- palkkaamuusikko.fi
                                'facebook',        -- facebook.com
                                'whatsapp',        -- WhatsApp (web.whatsapp.com)
                                'phone',           -- inbound phone call
                                'other'            -- catch-all
                            ) NOT NULL DEFAULT 'mail',
    -- Customer category — determines which mail template family is used
    customer_type           ENUM('wedding', 'company', 'other') NOT NULL DEFAULT 'wedding',
    -- Human-readable order summary, e.g. "3 x 45 min + ennakkoroudaus"
    order_description       VARCHAR(255),
    base_price_cents        INT                      DEFAULT NULL,
    quoted_price_cents      INT                      DEFAULT NULL,
    -- Travel distance fields (see PriceCalculator for formula)
    car1_distance_km        DECIMAL(7,1)             DEFAULT NULL,
    car2_distance_km        DECIMAL(7,1)             DEFAULT 0.0,
    other_travel_costs_cents INT                     DEFAULT 0,
    -- Route detail JSON shape: {"waypoints":[{"label":"tuomas (Car 1 driver)","lat":60.451,"lng":22.267}],"one_way_km":42.3,"legs_km":[3.8,5.1,33.4]}
    car1_route_json         TEXT                     DEFAULT NULL,
    car2_route_json         TEXT                     DEFAULT NULL,
    -- Granular pricing inputs (see PriceCalculator; persisted so the edit form pre-populates)
    pricing_tier1            TINYINT(1)              NOT NULL DEFAULT 0, -- on-season Saturday flag
    pricing_tier2            TINYINT(1)              NOT NULL DEFAULT 0, -- high-demand date flag
    qty_ennakkoroudaus        TINYINT UNSIGNED        NOT NULL DEFAULT 0,
    qty_song_requests_extra   TINYINT UNSIGNED        NOT NULL DEFAULT 0,
    qty_extra_performances    TINYINT UNSIGNED        NOT NULL DEFAULT 0,
    qty_background_music_h    TINYINT UNSIGNED        NOT NULL DEFAULT 0,
    qty_live_album            TINYINT UNSIGNED        NOT NULL DEFAULT 0,
    discount_cents            INT                     NOT NULL DEFAULT 0, -- gross discount in eurocents
    notes                   TEXT,
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at              DATETIME                 DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_gig_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_gig_contact  FOREIGN KEY (contact_id)  REFERENCES contacts  (id),
    CONSTRAINT fk_gig_venue    FOREIGN KEY (venue_id)    REFERENCES venues     (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- gig_personnel
-- Assigns users (band members) to a gig with their instrument role and fee.
-- See migration 004_gig_personnel.sql.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS gig_personnel (
    id                 INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    gig_id             INT UNSIGNED   NOT NULL,
    user_id            INT UNSIGNED   NOT NULL,
    role               ENUM(
                           'vocals',
                           'guitar',
                           'bass',
                           'drums',
                           'keyboards',
                           'sound_engineering',
                           'other'
                       ) NOT NULL DEFAULT 'other',
    fee_cents          INT                     DEFAULT NULL,
    -- transport_override: NULL = use users.transport_mode default
    --   passenger  = overrides to band car passenger for this gig
    --   local      = drives own car to venue, not billed (e.g. Lauri already in Turku)
    --   car_owner  = drives own non-band car this gig, not billed (treated same as local by TravelCalculator)
    transport_override ENUM('car_owner','passenger','local') DEFAULT NULL,
    confirmed_at       DATETIME                DEFAULT NULL,
    created_at         DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gig_user (gig_id, user_id),
    CONSTRAINT fk_gp_gig  FOREIGN KEY (gig_id)  REFERENCES gigs  (id),
    CONSTRAINT fk_gp_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- song_requests
-- Customer song wishes associated with a gig.
-- sort_order preserves the customer's preferred sequence.
-- is_first_dance flags the wedding first dance (performed free of charge).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS song_requests (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    gig_id          INT UNSIGNED    NOT NULL,
    artist          VARCHAR(255)    NOT NULL,
    title           VARCHAR(255)    NOT NULL,
    is_first_dance  TINYINT(1)      NOT NULL DEFAULT 0,
    sort_order      TINYINT UNSIGNED         DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_sr_gig FOREIGN KEY (gig_id) REFERENCES gigs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- songs
-- Global repertoire library. Deduped by (title, artist) unique key.
-- Application queries use LOWER() on both sides for case-insensitive matching.
-- See migration 008_setlists.sql.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS songs (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    title       VARCHAR(255)    NOT NULL,
    artist      VARCHAR(255)    NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  DATETIME                 DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_song_title_artist (title, artist)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- setlists
-- One named set per gig (Set 1, Set 2, …). Unique on (gig_id, set_number).
-- No soft-delete: empty sets are removed entirely on last song deletion.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS setlists (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    gig_id      INT UNSIGNED     NOT NULL,
    set_number  TINYINT UNSIGNED NOT NULL,
    name        VARCHAR(100)              DEFAULT NULL,
    created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_setlist_gig_set (gig_id, set_number),
    CONSTRAINT fk_sl_gig FOREIGN KEY (gig_id) REFERENCES gigs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- setlist_songs
-- Ordered junction between setlists and songs.
-- sort_order: ascending; gaps allowed (swap-based reorder).
-- No unique key on (setlist_id, song_id): same song may repeat in a set.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS setlist_songs (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    setlist_id  INT UNSIGNED     NOT NULL,
    song_id     INT UNSIGNED     NOT NULL,
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    notes       VARCHAR(255)              DEFAULT NULL,
    created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_ss_setlist FOREIGN KEY (setlist_id) REFERENCES setlists (id),
    CONSTRAINT fk_ss_song    FOREIGN KEY (song_id)    REFERENCES songs     (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- users
-- Session-based authentication. Roles in ascending order:
--   musician < owner < admin < developer
-- See config/auth.php for role hierarchy logic.
-- Bootstrap: INSERT a first owner row after applying migration 002.
--   php -r "echo password_hash('password', PASSWORD_DEFAULT);"
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    username       VARCHAR(64)   NOT NULL,
    email          VARCHAR(255)           DEFAULT NULL,
    home_address   VARCHAR(255)           DEFAULT NULL,
    home_lat       DECIMAL(9,6)           DEFAULT NULL,
    home_lng       DECIMAL(9,6)           DEFAULT NULL,
    -- transport_mode: car_owner = designated driver of a billed band car (Tuomas=Car1, Mortti/Maxwell=Car2);
    --   passenger = needs a lift in a band car; public_transport = travels independently (train/bus), no pickup.
    --   Note: 'local' (drives own car to venue, unbilled) is only a gig_personnel.transport_override value.
    transport_mode ENUM('car_owner','passenger','public_transport')
                                 NOT NULL DEFAULT 'passenger',
    -- default_car: which band car this person belongs to by default (1=Caddy/Car1, 2=Car2).
    -- car_owner with default_car=1 → Car 1 driver (Tuomas); default_car=2 → Car 2 driver (Mortti/Maxwell).
    -- passenger with default_car=1 → Car 1 stop; default_car=2 → Car 2 pickup (e.g. Lauri, Helsinki).
    -- Gig-level overrides go on gig_personnel.transport_override.
    default_car    TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    password_hash  VARCHAR(255)  NOT NULL,
    role           ENUM(
                       'developer',
                       'admin',
                       'owner',
                       'musician',
                       'guest'
                   ) NOT NULL DEFAULT 'musician',
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at     DATETIME               DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

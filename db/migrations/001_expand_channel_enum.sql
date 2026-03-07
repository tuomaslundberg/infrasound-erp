-- Migration 001: expand gigs.channel ENUM
-- Run once against both dev and prod databases.
--
-- Adds new booking channels discovered during legacy data extraction.
-- All existing 'mail' and 'buukkaa_bandi' values remain valid.
--
-- Channel semantics:
--   mail             — direct email / unidentified email source
--   buukkaa_bandi    — buukkaa-bandi.fi (own booking system, commission invoicing)
--   saturday_band    — saturday.band platform
--   venuu            — venuu.fi
--   haamusiikki      — haamusiikki.com
--   voodoolive       — voodoolive.fi
--   ohjelmanaiset    — ohjelmanaiset.fi
--   palkkaamuusikko  — palkkaamuusikko.fi
--   facebook         — facebook.com direct messages / page
--   whatsapp         — WhatsApp (web.whatsapp.com)
--   phone            — inbound phone call
--   other            — catch-all for any future or unrecognised source

ALTER TABLE gigs
    MODIFY COLUMN channel ENUM(
        'mail',
        'buukkaa_bandi',
        'saturday_band',
        'venuu',
        'haamusiikki',
        'voodoolive',
        'ohjelmanaiset',
        'palkkaamuusikko',
        'facebook',
        'whatsapp',
        'phone',
        'other'
    ) NOT NULL DEFAULT 'mail';

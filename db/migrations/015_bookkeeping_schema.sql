-- Migration 015: Bookkeeping ledger schema
-- Phase 6-7 foundation: double-entry ledger, VAT rate schedule, partner credits
--
-- Design principles:
--   - Double-entry accounting (every journal_event has ≥2 journal_lines summing to 0)
--   - Payment-based bookkeeping (maksuperusteinen), matching Tappio's approach
--   - VAT rate is stored per line (not derived from account), with fiscal-year parameterisation
--   - All amounts in eurocents (integers), never floats
--   - Partner credit balances derived via VIEW, not stored redundantly
--   - Tappio account codes used verbatim as the chart of accounts
--   - Soft deletes only (deleted_at)
--   - UTC timestamps throughout

-- ---------------------------------------------------------------------------
-- Chart of accounts
-- ---------------------------------------------------------------------------

CREATE TABLE accounts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code          VARCHAR(10)  NOT NULL UNIQUE,  -- e.g. '3000', '4410', '17631'
    name          VARCHAR(255) NOT NULL,
    type          ENUM(
                    'asset',       -- tase: vastaavaa
                    'liability',   -- tase: vastattavaa
                    'equity',      -- tase: oma pääoma
                    'revenue',     -- tulos: tuotot
                    'expense'      -- tulos: kulut
                  ) NOT NULL,
    vat_role      ENUM(
                    'none',        -- no VAT involvement (e.g. km reimbursements)
                    'output',      -- VAT collected on sales (Alv. myynneistä)
                    'input',       -- VAT paid on purchases (Alv. ostoista)
                    'settlement'   -- VAT payable/receivable account (2938)
                  ) NOT NULL DEFAULT 'none',
    notes         TEXT,
    created_at    DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
    deleted_at    DATETIME DEFAULT NULL
);

-- Seed with Tappio account codes used by Infrasound Oy
-- Revenue accounts
INSERT INTO accounts (code, name, type, vat_role, notes) VALUES
  ('3000', 'Keikkapalvelut', 'revenue', 'output', '14% (13.5% from 2026)'),
  ('3010', 'Soitinvuokraus', 'revenue', 'output', '25.5%'),
  ('3020', 'Äänitekniikka', 'revenue', 'output', '25.5%'),
  ('3030', 'Kaluston kuljetus', 'revenue', 'output', '25.5%'),
  ('3040', 'Matkakorvausten uudelleenveloitus', 'revenue', 'output', '14% (13.5% from 2026)'),
  ('3050', 'Muut palvelumaksut', 'revenue', 'output', '14% (13.5% from 2026)'),
  ('3060', 'Muut tuotot', 'revenue', 'output', '14% (13.5% from 2026)'),
  ('3760', 'Vuokratuotot', 'revenue', 'output', '25.5%'),
  ('3990', 'Arvonlisäveroton myynti', 'revenue', 'none', '0%'),
  ('3950', 'Muu arvonlisäveroton myynti', 'revenue', 'none', '0%'),
-- Expense accounts
  ('4410', 'Artistipalkkiot', 'expense', 'none', 'VAT-exempt; Suomen Keikkalasku, musician invoices'),
  ('7630', 'Matkakulut (julkiset)', 'expense', 'input', 'Train/bus/taxi; VAT at receipt rate'),
  ('7650', 'Päivärahat', 'expense', 'none', 'Daily allowances, no VAT'),
  ('7680', 'Kilometrikorvaukset', 'expense', 'none', 'Mileage reimbursements, no VAT'),
  ('7800', 'Markkinointikulut', 'expense', 'input', '25.5% or EU reverse charge'),
  ('7900', 'Hallintokulut', 'expense', 'input', 'Various'),
  ('4900', 'Kaluston poisto', 'expense', 'none', 'Depreciation; year-end Tappio entry'),
-- Balance sheet accounts
  ('1900', 'Kassa', 'asset', 'none', 'Cash on hand'),
  ('1910', 'Pankkitili', 'asset', 'none', 'Nordea current account'),
  ('1600', 'Myyntisaamiset', 'asset', 'none', 'Accounts receivable'),
  ('2900', 'Ostovelat', 'liability', 'none', 'Accounts payable'),
  ('2930', 'Siirtovelat (muut)', 'liability', 'none', 'Accrued liabilities (e.g. Mikael siirtovelat)'),
  ('2938', 'Alv. tili', 'liability', 'settlement', 'VAT payable/receivable'),
  ('2960', 'Verovelka', 'liability', 'none', 'Income tax accrual'),
  ('3100', 'Oma pääoma (SVOP)', 'equity', 'none', 'Invested unrestricted equity'),
  ('17631', 'Vähennettävä alv. (ostot 25.5%)', 'asset', 'input', 'Input VAT clearing'),
  ('17632', 'Vähennettävä alv. (EU tavara käännetty vero)', 'asset', 'input', 'Reverse charge goods'),
  ('17633', 'Vähennettävä alv. (EU palvelu käännetty vero)', 'asset', 'input', 'Reverse charge services'),
  ('29394', 'Suoritettava alv. (EU tavara käännetty vero)', 'liability', 'output', 'Reverse charge goods output'),
  ('29395', 'Suoritettava alv. (EU palvelu käännetty vero)', 'liability', 'output', 'Reverse charge services output');

-- ---------------------------------------------------------------------------
-- VAT rate schedule
-- ---------------------------------------------------------------------------
-- VAT rates change over time. This table maps (account_code, valid_from) → rate.
-- Always pick the latest entry WHERE valid_from <= event_date.

CREATE TABLE vat_rate_schedule (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_code  VARCHAR(10)  NOT NULL,
    rate_permille INT UNSIGNED NOT NULL,  -- e.g. 140 = 14.0%, 135 = 13.5%, 255 = 25.5%
    valid_from    DATE NOT NULL,
    notes         TEXT,
    UNIQUE KEY uq_account_valid (account_code, valid_from),
    CONSTRAINT fk_vrs_account FOREIGN KEY (account_code)
        REFERENCES accounts(code) ON UPDATE CASCADE
);

-- Revenue/service accounts: 14% through 2025-12-31, 13.5% from 2026-01-01
INSERT INTO vat_rate_schedule (account_code, rate_permille, valid_from, notes) VALUES
  ('3000', 140, '2021-01-01', 'keikkapalvelut 14%'),
  ('3000', 135, '2026-01-01', 'keikkapalvelut 13.5% from 2026'),
  ('3040', 140, '2021-01-01', 'matkakorvausten uudelleenveloitus 14%'),
  ('3040', 135, '2026-01-01', '13.5% from 2026'),
  ('3050', 140, '2021-01-01', '14%'),
  ('3050', 135, '2026-01-01', '13.5%'),
  ('3060', 140, '2021-01-01', '14%'),
  ('3060', 135, '2026-01-01', '13.5%'),
-- These accounts stay at 25.5%
  ('3010', 255, '2021-01-01', '25.5%'),
  ('3020', 255, '2021-01-01', '25.5%'),
  ('3030', 255, '2021-01-01', '25.5%'),
  ('3760', 255, '2021-01-01', 'vuokratuotot 25.5%'),
  ('7630', 255, '2021-01-01', 'matkakulut input VAT 25.5% (or receipt rate)'),
  ('7800', 255, '2021-01-01', 'markkinointikulut 25.5%');

-- ---------------------------------------------------------------------------
-- Journal events (one per payment / accrual event)
-- ---------------------------------------------------------------------------

CREATE TABLE journal_events (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_date      DATE        NOT NULL,        -- date of payment/accrual
    description     TEXT        NOT NULL,        -- free-text (counterparty, invoice ref, etc.)
    source          ENUM(
                      'nda_import',    -- auto-parsed from .nda bank statement
                      'tappio_import', -- bulk-imported from .tlk
                      'manual',        -- entered via ERP UI
                      'etl',           -- inserted by legacy ETL scripts
                      'invoice_auto',  -- created from auto-extracted invoice (needs verification)
                      'invoice_llm'    -- created from LLM-extracted invoice (needs verification)
                    ) NOT NULL DEFAULT 'manual',
    source_ref      VARCHAR(255) DEFAULT NULL,   -- e.g. NDA transaction ID, Tappio line offset
    invoice_id      INT UNSIGNED DEFAULT NULL,   -- FK to invoices table (future)
    notes           TEXT,
    created_at      DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
    deleted_at      DATETIME DEFAULT NULL,
    INDEX idx_je_date (event_date),
    INDEX idx_je_source (source)
);

-- ---------------------------------------------------------------------------
-- Journal lines (debit/credit legs of each event)
-- ---------------------------------------------------------------------------

CREATE TABLE journal_lines (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id        INT UNSIGNED NOT NULL,
    account_code    VARCHAR(10)  NOT NULL,
    -- amount_cents: positive = debit, negative = credit (double-entry convention)
    amount_cents    INT          NOT NULL,
    -- vat_cents: VAT component embedded in amount_cents (0 if N/A)
    vat_cents       INT          NOT NULL DEFAULT 0,
    vat_rate_permille INT UNSIGNED NOT NULL DEFAULT 0,
    notes           TEXT,
    created_at      DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
    CONSTRAINT fk_jl_event   FOREIGN KEY (event_id)     REFERENCES journal_events(id),
    CONSTRAINT fk_jl_account FOREIGN KEY (account_code) REFERENCES accounts(code) ON UPDATE CASCADE,
    INDEX idx_jl_event   (event_id),
    INDEX idx_jl_account (account_code)
);

-- Integrity check view: events where lines don't sum to zero
-- (use for validation; in prod enforce via application layer)
CREATE VIEW journal_balance_check AS
SELECT
    je.id,
    je.event_date,
    je.description,
    SUM(jl.amount_cents) AS balance
FROM journal_events je
JOIN journal_lines jl ON jl.event_id = je.id
WHERE je.deleted_at IS NULL
GROUP BY je.id, je.event_date, je.description
HAVING SUM(jl.amount_cents) <> 0;

-- ---------------------------------------------------------------------------
-- Partner credit accounts
-- ---------------------------------------------------------------------------
-- These track the informal per-partner credit balance (gig earnings + hourly work
-- - payments made). Separate from the double-entry ledger since they are internal
-- management accounts, not statutory accounting.

CREATE TABLE partner_credit_events (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,    -- FK to users table
    event_date      DATE        NOT NULL,
    type            ENUM(
                      'gig_earnings',    -- share of gig payment (~50% of calculated split)
                      'hourly_work',     -- tuntikrjp hours × rate
                      'purchase_deduction', -- equipment purchase deducted at net (ex-VAT)
                      'other_earnings',  -- miscellaneous (currently unused in live data)
                      'payment_out',     -- actual cash paid to partner
                      'adjustment'       -- ad-hoc correction (currently unused in live data)
                    ) NOT NULL,
    -- Note: travel reimbursements (matkalaskut) do NOT touch partner credit —
    -- they are pure cost pass-throughs. Record them as journal_events only.
    amount_cents    INT         NOT NULL,   -- positive = credit earned, negative = paid out
    description     TEXT,
    source_ref      VARCHAR(255) DEFAULT NULL,  -- e.g. 'gig_id:123', 'matkalaskut_id:172'
    created_at      DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
    deleted_at      DATETIME DEFAULT NULL,
    CONSTRAINT fk_pce_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_pce_user (user_id),
    INDEX idx_pce_date (event_date)
);

-- Running balance view per partner
CREATE VIEW partner_credit_balances AS
SELECT
    u.id         AS user_id,
    u.name,
    SUM(pce.amount_cents)                    AS balance_cents,
    ROUND(SUM(pce.amount_cents) / 100.0, 2) AS balance_eur
FROM users u
JOIN partner_credit_events pce ON pce.user_id = u.id AND pce.deleted_at IS NULL
WHERE u.deleted_at IS NULL
GROUP BY u.id, u.name;

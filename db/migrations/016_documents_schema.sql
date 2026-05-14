-- Migration 016: Document storage system + km_rates config table
--
-- Context: ERP takes over document storage from Dropbox (management/).
-- Files are stored under storage/documents/ (host bind-mount, outside web root).
-- PHP serves them via a controlled endpoint after auth check — never directly via Apache.
-- This migration:
--   1. km_rates — pending config table from AGENTS.md §7
--   2. documents — document index (one row per stored file)
--   3. ALTER journal_events — adds nullable document_id FK
--
-- Storage path convention (relative to storage/documents/):
--   {type}/{yyyy}/{number}-{yymmdd}-{slug}.{ext}
--   e.g. purchase_invoices/2025/250510-hp-finland-oy.pdf
--        travel_invoices/163-250616-tuomas-lundberg.xlsx
--        bank_statements/2025/2512-infrasound-oy-tiliote.pdf

-- ---------------------------------------------------------------------------
-- km_rates — Finnish Verohallinto mileage reimbursement rates
-- ---------------------------------------------------------------------------
-- Rate changes annually. Always look up by (year, category) at invoice/quote time.
-- NEVER hardcode a rate in business logic.

CREATE TABLE km_rates (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    valid_from      DATE NOT NULL,         -- usually Jan 1 of the rate year
    category        ENUM(
                      'base',             -- standard rate (auto)
                      'trailer',          -- trailer supplement
                      'passenger'         -- per-passenger supplement
                    ) NOT NULL,
    rate_cents      INT UNSIGNED NOT NULL, -- e.g. 55 = 0.55 €/km, 4 = 0.04 €/km
    notes           TEXT,
    UNIQUE KEY uq_km_rate (valid_from, category)
);

-- Seed known rates (Finnish Verohallinto decisions)
INSERT INTO km_rates (valid_from, category, rate_cents, notes) VALUES
  ('2022-01-01', 'base',      46,  'Verohallinto 2022; raised mid-year'),
  ('2022-07-01', 'base',      53,  'Mid-year increase 2022'),
  ('2023-01-01', 'base',      53,  '2023'),
  ('2024-01-01', 'base',      57,  '2024'),
  ('2025-01-01', 'base',      59,  '2025'),
  ('2026-01-01', 'base',      55,  '2026 — lower than 2025'),
  -- Trailer supplement (consistent across years observed)
  ('2022-01-01', 'trailer',   10,  '10 snt/km perävaunusta'),
  -- Passenger supplement (per additional passenger carried for company)
  ('2022-01-01', 'passenger',  4,  '4 snt/km per additional passenger');

-- ---------------------------------------------------------------------------
-- documents — file index for all stored documents
-- ---------------------------------------------------------------------------

CREATE TABLE documents (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Document identity
    document_number  INT UNSIGNED DEFAULT NULL,   -- invoice/statement number if applicable
    document_date    DATE         NOT NULL,        -- date on the document (not import date)
    type             ENUM(
                       'purchase_invoice',   -- ostolaskut (vendor invoices)
                       'sales_invoice',      -- myyntilaskut (client gig invoices, rentals)
                       'travel_invoice',     -- matkalaskut (partner reimbursements)
                       'bank_statement',     -- tiliote PDF
                       'bank_statement_nda', -- tiliote .nda machine-readable
                       'vat_return',         -- ALV-laskelma + Verovirasto receipt
                       'year_end',           -- tilinpäätös documents
                       'other'
                     ) NOT NULL,
    -- Counterparty (vendor for purchases, customer for sales, person for travel)
    counterparty     VARCHAR(255) DEFAULT NULL,
    -- File storage
    original_filename VARCHAR(500) NOT NULL,  -- filename as imported from management/
    storage_path     VARCHAR(500) NOT NULL,   -- relative to storage/documents/ root
    mime_type        VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
    file_size_bytes  INT UNSIGNED DEFAULT NULL,
    -- Period fields (for statements and VAT returns)
    period_start     DATE DEFAULT NULL,
    period_end       DATE DEFAULT NULL,
    -- Financial summary (populated by extraction pipeline; NULL = not yet extracted)
    amount_cents     INT DEFAULT NULL,        -- total inc. VAT
    vat_cents        INT DEFAULT NULL,        -- VAT component
    -- Extraction pipeline status
    -- Tracks how extracted fields (amount, vat, counterparty) were populated.
    -- Legacy documents migrated from management/ stay at 'none' — their data
    -- already lives in the ledger; no extraction needed. Only new inbound
    -- invoices flow through the pipeline.
    extraction_status ENUM(
                        'none',        -- not attempted (legacy migrated docs, ERP-generated)
                        'auto',        -- populated by text-only extraction (pdftotext/pdfminer)
                        'llm',         -- Claude structured extraction from clean PDF text
                        'llm_vision',  -- Claude vision path (scanned/handwritten/image-based)
                        'verified'     -- human-confirmed; fields are authoritative
                      ) NOT NULL DEFAULT 'none',
    extracted_raw    JSON DEFAULT NULL,       -- raw extractor output before normalisation
    -- Provenance
    imported_at      DATETIME DEFAULT NULL,   -- set by file migration; NULL = uploaded via ERP UI
    source_path      VARCHAR(500) DEFAULT NULL, -- original management/ path (migration only)
    notes            TEXT,
    created_at       DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
    deleted_at       DATETIME DEFAULT NULL,
    INDEX idx_doc_date   (document_date),
    INDEX idx_doc_type   (type),
    INDEX idx_doc_number (document_number)
);

-- ---------------------------------------------------------------------------
-- Link journal_events to their source documents
-- ---------------------------------------------------------------------------
-- Nullable: not all journal events have a stored document (e.g. year-end accruals,
-- ETL-imported Tappio events for historical years).
-- One-to-one is sufficient for most cases:
--   purchase invoice → one journal event
--   travel invoice   → one journal event (reimbursement payment)
--   bank statement   → many journal events (FK on the event, not the document)

ALTER TABLE journal_events
    ADD COLUMN document_id INT UNSIGNED DEFAULT NULL AFTER source_ref,
    ADD CONSTRAINT fk_je_document
        FOREIGN KEY (document_id) REFERENCES documents(id);

-- ---------------------------------------------------------------------------
-- Phase 6-7 implementation notes
-- ---------------------------------------------------------------------------
--
-- LEGACY DOCUMENT MIGRATION (one-time, extract_documents.py)
-- ----------------------------------------------------------
-- Goal: copy existing management/ files into storage/documents/ and index them.
-- No content extraction is needed — their financial data already lives in the
-- ledger (Tappio) and operational spreadsheets.
--
-- Script should:
--   1. Walk management/ subdirectories (ostolaskut/{yyyy}/, ostolaskut/matkalaskut/,
--      myyntilaskut/{yyyy}/, tiliotteet/{yyyy}/, alv/{yyqq}/, tilinpaatos/{yy}/)
--   2. Parse type + date + counterparty from filename convention (~70% auto-parseable;
--      remainder needs a small manual mapping table or review pass)
--   3. Copy file to storage/documents/{type}/{yyyy}/{filename}
--   4. INSERT with extraction_status = 'none', source_path = original management/ path
--
-- INBOUND INVOICE EXTRACTION PIPELINE (ongoing, Phase 6-7+)
-- ----------------------------------------------------------
-- Applies ONLY to PDF-only inbound vendor invoices (e.g. DNA, HP, Webflow, scanned
-- paper receipts). Any document that has an XLSX source (travel invoices, sales invoices)
-- is parsed from the XLSX directly — the PDF is filed as an archive copy and never
-- re-extracted. New purchase invoices (uploaded via ERP or received by email) flow through
-- a tiered extraction pipeline to auto-populate amount_cents, vat_cents, counterparty:
--
--   Tier 1 — text extraction (pdftotext / pdfminer2):
--     If PDF has selectable text → extract and attempt structured parse.
--     ~80% of inbound invoices (vendor e-invoices, web-downloaded PDFs) are
--     machine-generated and will succeed here.
--     On success: populate fields, set extraction_status = 'auto'.
--
--   Tier 2 — LLM structured extraction (Claude with tool use):
--     If tier 1 text is present but ambiguous (unusual format, Finnish/Swedish mix,
--     multi-page with line-item detail needed).
--     Claude receives the extracted text and calls a structured `extract_invoice_fields`
--     tool that returns {date, counterparty, amount_cents, vat_cents, vat_rate}.
--     On success: set extraction_status = 'llm'.
--
--   Tier 3 — LLM vision (Claude vision / sub-agent):
--     If PDF is a scanned image or tier 1 returns < threshold chars.
--     Covers handwritten receipts, paper scans, camera photos of receipts.
--     On success: set extraction_status = 'llm_vision'.
--     Note: confidence is lower here; always surface for human review.
--
--   Human review queue:
--     All documents with extraction_status IN ('auto', 'llm', 'llm_vision') that have
--     not been manually confirmed should appear in a review queue. On approval:
--     set extraction_status = 'verified'. The journal_event for this document should
--     only be considered authoritative once status = 'verified'.
--
-- ERP-GENERATED DOCUMENTS (invoicing module)
-- ----------------------------------------------------------
--   When the ERP generates a sales invoice PDF, the invoicing module writes the file
--   to storage/documents/sales_invoices/{yyyy}/ and INSERTs a documents row with
--   extraction_status = 'none' (no extraction needed; data came from ERP itself).

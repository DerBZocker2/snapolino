-- Migration 0005: Direktzahlung per Stripe statt reiner Anfrage, fortlaufende
-- Rechnungsnummern, Business-Rechnungsdaten fuer die PDF-Rechnung.
-- Auf einer bereits laufenden Installation von Hand ausfuehren:
--   mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0005_payments_and_invoices.sql

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS stripe_session_id VARCHAR(255) NULL UNIQUE AFTER edit_token,
    ADD COLUMN IF NOT EXISTS stripe_payment_intent VARCHAR(255) NULL AFTER stripe_session_id,
    ADD COLUMN IF NOT EXISTS paid_at DATETIME NULL AFTER wants_quote,
    ADD COLUMN IF NOT EXISTS invoice_number VARCHAR(30) NULL UNIQUE AFTER paid_at;

-- Atomarer Zaehler fuer fortlaufende Rechnungsnummern (ein Zaehler pro Jahr,
-- Format wird in PHP zu z.B. "2026-0001" zusammengesetzt).
CREATE TABLE IF NOT EXISTS invoice_counters (
    year        INT UNSIGNED PRIMARY KEY,
    next_number INT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (name, value) VALUES
    ('business_name', 'Bitte im Panel unter Einstellungen ausfuellen'),
    ('business_address', 'Straße Hausnummer\nPLZ Ort'),
    ('business_tax_note', 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.');

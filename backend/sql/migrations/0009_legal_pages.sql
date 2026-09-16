-- Migration 0009: Impressum/Datenschutz/AGB auf der Buchungsseite.
-- Kontaktdaten fuers Impressum + Zeitstempel, wann eine Buchung den AGB
-- und der Datenschutzerklaerung zugestimmt hat (Nachweis, siehe buchen.php).
-- Auf einer bereits laufenden Installation von Hand ausfuehren:
--   mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0009_legal_pages.sql

INSERT IGNORE INTO settings (name, value) VALUES
    ('business_email', 'info@snapolino.de'),
    ('business_phone', '');

ALTER TABLE bookings ADD COLUMN agb_accepted_at DATETIME NULL AFTER wants_quote;

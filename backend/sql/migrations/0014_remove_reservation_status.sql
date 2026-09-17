-- Es gibt keine unverbindliche "Reservierung" mehr - nur eine Box, daher
-- lohnt sich die automatisch verfallende Zwischenstufe nicht (siehe
-- CLAUDE.md, Buchungssystem). Jede Terminwahl wird ab sofort direkt als
-- echte Anfrage ('angefragt') angelegt und blockiert den Kalender, bis ein
-- Admin sie ablehnt/storniert oder sie bezahlt/bestaetigt wird.
-- Bestehende, noch offene "reserviert"-Datensaetze (angefangene, nie zu
-- Ende gefuehrte Buchungsversuche) werden dabei zu echten Anfragen
-- hochgestuft statt geloescht - der Admin sichtet/lehnt unvollstaendige
-- Anfragen im Panel unter Buchungen wie gewohnt von Hand ab.
UPDATE bookings SET status = 'angefragt' WHERE status = 'reserviert';

ALTER TABLE bookings
    ALTER COLUMN status SET DEFAULT 'angefragt';

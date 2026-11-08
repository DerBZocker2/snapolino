-- Erinnerungsmail fuer unvollstaendige Buchungsanfragen (Schritt 2 des
-- Assistenten abgeschlossen, aber nie bis Schritt 5/Zahlung weitergemacht -
-- analog zu Shopifys "Abandoned Checkout"-Erinnerung). Siehe
-- bin/send_abandoned_reminders.php und abandoned_reminder_hours() in
-- includes/functions.php.
ALTER TABLE bookings
    ADD COLUMN abandoned_reminder_sent_at DATETIME NULL AFTER review_requested_at;

INSERT IGNORE INTO settings (name, value) VALUES ('abandoned_reminder_hours', '24');

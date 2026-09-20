-- Automatische Erinnerungsmail einige Tage vor dem Event:
-- bin/send_event_reminders.php (fuer einen taeglichen Cronjob gedacht,
-- siehe backend/README.md) verschickt sie an alle bestaetigten Buchungen,
-- deren Eventdatum innerhalb von reminder_days_before_event Tagen liegt und
-- die noch keine Erinnerung bekommen haben (reminder_sent_at). Einstellung
-- im Panel unter Einstellungen editierbar.
ALTER TABLE bookings
    ADD COLUMN reminder_sent_at DATETIME NULL AFTER gallery_deleted_at;

INSERT IGNORE INTO settings (name, value) VALUES ('reminder_days_before_event', '7');

-- Automatische Bewertungsanfrage einige Tage nach dem Event:
-- bin/send_review_requests.php (taeglicher Cronjob) verschickt an alle
-- bestaetigten Buchungen, deren Eventdatum mindestens
-- review_request_days_after_event Tage zurueckliegt (Panel unter
-- Einstellungen, Standard 3) und die noch keine Anfrage bekommen haben,
-- eine Mail mit Bitte um eine Google-Bewertung (google_review_url).
ALTER TABLE bookings
    ADD COLUMN review_requested_at DATETIME NULL AFTER referral_reward_sent_at;

INSERT IGNORE INTO settings (name, value) VALUES
    ('review_request_days_after_event', '3'),
    ('google_review_url', '');

-- Automatischer Stammkundenrabatt fuer wiederkehrende Kunden (z.B.
-- jaehrliche Firmenfeiern): calc_booking_pricing() (includes/functions.php)
-- erkennt per is_returning_customer(), ob die angegebene E-Mail-Adresse
-- bereits eine andere bestaetigte Buchung hat, und wendet dann automatisch
-- den Rabatt aus returning_customer_discount_percent an - zusaetzlich zu
-- einem eventuell eingeloesten Gutschein, auf den bereits um den Gutschein
-- reduzierten Betrag.
ALTER TABLE bookings
    ADD COLUMN returning_discount_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER discount_cents;

INSERT IGNORE INTO settings (name, value) VALUES ('returning_customer_discount_percent', '10');

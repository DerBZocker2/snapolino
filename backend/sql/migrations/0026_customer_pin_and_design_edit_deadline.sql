-- Kunden-PIN: 4-stellige, ueber den Ziffernblock der Box leicht eintippbare
-- PIN je Buchung (nicht je Box - anders als boxes.admin_pin), schuetzt dort
-- den neuen Bereich zum nachtraeglichen Aktivieren/Deaktivieren der eigenen
-- Design-Layouts (siehe main.py, _open_design_manager()). Wird bei der
-- Buchungserstellung generiert (buchen.php), per Mail mitgeschickt
-- (mailer.php) und im Admin-Panel angezeigt (booking_detail.php).
ALTER TABLE bookings ADD COLUMN customer_pin CHAR(4) NULL AFTER edit_token;
UPDATE bookings SET customer_pin = LPAD(FLOOR(RAND() * 10000), 4, '0') WHERE customer_pin IS NULL;
ALTER TABLE bookings MODIFY customer_pin CHAR(4) NOT NULL;

-- Bis zu wie vielen Tagen vor dem Eventdatum eine bereits bestaetigte
-- Buchung fuer den Kunden noch aenderbar bleibt (Design/Extras/Adresse,
-- siehe booking_customer_editable() in includes/customer_auth.php) - davor
-- war eine bestaetigte Buchung nur nach ausdruecklicher Freischaltung durch
-- einen Admin aenderbar.
INSERT IGNORE INTO settings (name, value) VALUES ('design_edit_deadline_days', '7');

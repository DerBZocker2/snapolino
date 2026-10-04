-- Mindestvorlauf in Tagen, ab dem ein Wunschtermin im Buchungsassistenten
-- ueberhaupt waehlbar ist (Panel unter Einstellungen) - siehe
-- booking_min_lead_days() in includes/functions.php. Verhindert zu
-- kurzfristige Anfragen, auf die sich Versand/Vorbereitung nicht mehr
-- einrichten liesse.
INSERT IGNORE INTO settings (name, value) VALUES ('booking_min_lead_days', '3');

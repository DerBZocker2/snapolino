-- Mindestvorlauf in Tagen fuer die oeffentliche Warteliste (warteliste.php),
-- eigene (meist kuerzere) Frist als booking_min_lead_days - siehe
-- waitlist_min_lead_days() in includes/functions.php. Vorher hatte die
-- Warteliste gar keinen Mindestvorlauf, nur eine Pruefung gegen die
-- Vergangenheit - dadurch liess sich z.B. auch der heutige Tag eintragen.
INSERT IGNORE INTO settings (name, value) VALUES ('waitlist_min_lead_days', '2');

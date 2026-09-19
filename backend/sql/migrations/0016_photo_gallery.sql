-- Automatische Online-Galerie: sobald die Fotobox nach dem Event wieder
-- Internet hat, laedt sie alle Einzelbilder und Collagen der Buchung hoch
-- (siehe gallery.py auf der Box, upload_photo.php hier). gallery_token
-- macht die Galerie per unratbarem Link oeffentlich erreichbar (galerie.php),
-- ohne Kundenkonto-Login - analog zu edit_token bei buchen.php. Wird erst
-- beim ersten Foto-Upload erzeugt (ensure_gallery_token()), nicht schon bei
-- der Buchung selbst.
ALTER TABLE bookings
    ADD COLUMN gallery_token VARCHAR(64) NULL UNIQUE AFTER edit_token;

CREATE TABLE IF NOT EXISTS gallery_photos (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id  INT UNSIGNED NOT NULL,
    filename    VARCHAR(150) NOT NULL,
    -- 'foto' = Einzelbild einer Session, 'collage' = fertige Collage -
    -- steuert nur die Anzeigereihenfolge/Beschriftung in der Galerie.
    kind        VARCHAR(10) NOT NULL DEFAULT 'foto',
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_booking_filename (booking_id, filename),
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

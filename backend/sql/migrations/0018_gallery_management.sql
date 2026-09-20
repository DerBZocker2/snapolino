-- Verwalter-/Gaeste-Trennung fuer die Online-Galerie sowie automatische
-- Loeschung nach Ablauf der Aufbewahrungsfrist (DSGVO):
--
-- - gallery_token (bisher der einzige Link) ist ab jetzt der Verwalter-Link
--   (Organisator kann Fotos ausblenden, sieht auch ausgeblendete Fotos und
--   den Gaeste-Link zum Weitergeben).
-- - gallery_guest_token ist der neue, separate Link zum Teilen mit Gaesten -
--   read-only, zeigt nur nicht ausgeblendete Fotos.
-- - gallery_photos.hidden: vom Organisator ausgeblendete Fotos verschwinden
--   nur aus der Gaeste-Ansicht, bleiben aber fuer den Organisator sichtbar
--   (zum spaeteren Wieder-Einblenden).
-- - gallery_deleted_at: wird von bin/purge_expired_galleries.php gesetzt,
--   sobald die Fotos einer Buchung geloescht wurden - galerie.php zeigt dann
--   einen Hinweis statt "noch keine Fotos" anzuzeigen.
-- - Einstellung gallery_retention_days (Panel unter Einstellungen editierbar)
--   steuert, nach wie vielen Tagen NACH DEM EVENTDATUM die Fotos geloescht
--   werden (siehe bin/purge_expired_galleries.php, gedacht fuer einen
--   taeglichen Cronjob).
ALTER TABLE bookings
    ADD COLUMN gallery_guest_token VARCHAR(64) NULL UNIQUE AFTER gallery_token,
    ADD COLUMN gallery_deleted_at DATETIME NULL AFTER gallery_guest_token;

ALTER TABLE gallery_photos
    ADD COLUMN hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER kind;

INSERT IGNORE INTO settings (name, value) VALUES ('gallery_retention_days', '30');

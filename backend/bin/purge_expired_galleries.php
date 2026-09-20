<?php
declare(strict_types=1);

// Loescht die Online-Galerie-Fotos aller Buchungen, deren Aufbewahrungsfrist
// abgelaufen ist (DSGVO) - Frist ist "gallery_retention_days" (Panel unter
// Einstellungen, Standard 30) Tage NACH DEM EVENTDATUM. Gedacht fuer einen
// taeglichen Cronjob (siehe backend/README.md), kann aber jederzeit von Hand
// aufgerufen werden:
//   php bin/purge_expired_galleries.php
//
// Loescht die Bilddateien von der Platte, die gallery_photos-Zeilen sowie
// den Bildordner der Buchung, und setzt bookings.gallery_deleted_at -
// galerie.php zeigt dann einen Hinweis statt "noch keine Fotos hochgeladen".
// Die Galerie-Tokens selbst bleiben bestehen, damit ein alter Link weiterhin
// zu einer verstaendlichen Meldung statt zu "Galerie nicht gefunden" fuehrt.

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';

$retentionDays = gallery_retention_days();
$cutoff = (new DateTimeImmutable("-{$retentionDays} days"))->format('Y-m-d');

$stmt = db()->prepare(
    'SELECT DISTINCT b.id FROM bookings b
     INNER JOIN gallery_photos gp ON gp.booking_id = b.id
     WHERE b.event_date <= ?'
);
$stmt->execute([$cutoff]);
$bookingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

$storageDir = gallery_storage_dir();
$deletedPhotos = 0;

foreach ($bookingIds as $bookingId) {
    $bookingId = (int) $bookingId;
    $dir = $storageDir . '/' . $bookingId;

    $photoStmt = db()->prepare('SELECT filename FROM gallery_photos WHERE booking_id = ?');
    $photoStmt->execute([$bookingId]);
    foreach ($photoStmt->fetchAll(PDO::FETCH_COLUMN) as $filename) {
        $path = $dir . '/' . basename((string) $filename);
        if (is_file($path) && @unlink($path)) {
            $deletedPhotos++;
        }
    }
    if (is_dir($dir)) {
        @rmdir($dir);
    }

    db()->prepare('DELETE FROM gallery_photos WHERE booking_id = ?')->execute([$bookingId]);
    db()->prepare('UPDATE bookings SET gallery_deleted_at = NOW() WHERE id = ?')->execute([$bookingId]);
}

$bookingCount = count($bookingIds);
echo "Galerie-Aufbewahrungsfrist: {$retentionDays} Tage nach Eventdatum.\n";
echo "{$deletedPhotos} Foto(s) aus {$bookingCount} Buchung(en) geloescht.\n";

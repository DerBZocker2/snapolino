<?php
declare(strict_types=1);

// Liefert ein einzelnes Foto/Collage der Online-Galerie aus. Aufruf:
// GET /gallery_photo.php?token=<gallery_token oder gallery_guest_token>&file=<name>.jpg
// Optional &download=1 fuer "Datei speichern unter" statt Inline-Anzeige.
// Die Datei liegt ausserhalb des Webroots (backend/storage/gallery/), der
// Token ist der einzige Zugriffsschutz - wie edit_token bei buchen.php:
// unratbar (24 Zufallsbytes), aber kein Passwort/Login noetig, damit auch
// Gaeste ohne eigenes Konto Fotos ansehen/laden koennen. Der Gaeste-Token
// darf ausgeblendete Fotos nicht ausliefern, selbst wenn der Dateiname
// erraten wuerde (Verteidigung in der Tiefe - normalerweise taucht der
// Dateiname fuer Gaeste ohnehin nirgends auf, siehe galerie.php).

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

function gallery_photo_error(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

$token = trim((string) ($_GET['token'] ?? ''));
$file = basename((string) ($_GET['file'] ?? ''));

if ($token === '' || $file === '' || !preg_match('/^[A-Za-z0-9_\-]+\.jpg$/', $file)) {
    gallery_photo_error(400, 'token und file sind erforderlich');
}

$stmt = db()->prepare('SELECT id, gallery_token, gallery_guest_token FROM bookings WHERE gallery_token = ? OR gallery_guest_token = ?');
$stmt->execute([$token, $token]);
$booking = $stmt->fetch();

$isGuest = false;
if ($booking && hash_equals((string) $booking['gallery_token'], $token)) {
    $isGuest = false;
} elseif ($booking && hash_equals((string) $booking['gallery_guest_token'], $token)) {
    $isGuest = true;
} else {
    $booking = null;
}

if (!$booking) {
    gallery_photo_error(404, 'Galerie nicht gefunden');
}

$stmt = db()->prepare('SELECT hidden FROM gallery_photos WHERE booking_id = ? AND filename = ?');
$stmt->execute([$booking['id'], $file]);
$photo = $stmt->fetch();
if (!$photo || ($isGuest && (int) $photo['hidden'] === 1)) {
    gallery_photo_error(404, 'Foto nicht gefunden');
}

$path = gallery_storage_dir() . '/' . $booking['id'] . '/' . $file;
if (!is_file($path)) {
    gallery_photo_error(404, 'Datei fehlt auf dem Server');
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
if (($_GET['download'] ?? '') === '1') {
    header('Content-Disposition: attachment; filename="' . $file . '"');
}
readfile($path);

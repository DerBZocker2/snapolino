<?php
declare(strict_types=1);

// Nimmt ein einzelnes Foto/Collage von der Fotobox fuer die automatische
// Online-Galerie entgegen. Aufruf: POST /upload_photo.php mit Header
// "X-API-Key: <api_key>", Formularfeldern "box" (box_key) und "booking_id",
// sowie der Datei im Feld "photo" (multipart/form-data). Wird von der Box
// aufgerufen, sobald sie nach dem Event wieder Internet hat (siehe
// gallery.py) - unabhaengig vom lokalen Speichern/Drucken, das immer
// funktioniert auch ganz ohne Internet (Offline-First).

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function upload_error(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function upload_api_key(): string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $name => $value) {
        if (strcasecmp($name, 'X-API-Key') === 0) {
            return trim((string) $value);
        }
    }
    return trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    upload_error(405, 'Nur POST erlaubt');
}

$boxKey = trim((string) ($_POST['box'] ?? ''));
$bookingId = (int) ($_POST['booking_id'] ?? 0);
$apiKey = upload_api_key();

if ($boxKey === '' || $bookingId <= 0 || $apiKey === '') {
    upload_error(400, 'box, booking_id und X-API-Key Header sind erforderlich');
}

$stmt = db()->prepare('SELECT id, api_key FROM boxes WHERE box_key = ?');
$stmt->execute([$boxKey]);
$box = $stmt->fetch();

if (!$box || !hash_equals($box['api_key'], $apiKey)) {
    upload_error(401, 'Unbekannte Box oder falscher API-Key');
}

// Die Buchung muss aktuell dieser Box zugeordnet sein - verhindert, dass
// eine Box (bzw. ein gestohlener API-Key) Fotos einer fremden Buchung
// hochlaedt. Wird eine Box zwischen Event-Ende und Foto-Upload bereits
// einer anderen Buchung neu zugeordnet ("Zuordnung aufheben" im Panel),
// schlaegt der Upload dieser letzten Fotos fehl - sie bleiben aber lokal
// auf der Box gespeichert (Offline-First), nur der Galerie-Upload muss
// dann von Hand nachgeholt werden.
$stmt = db()->prepare('SELECT id FROM bookings WHERE id = ? AND box_id = ?');
$stmt->execute([$bookingId, $box['id']]);
if (!$stmt->fetch()) {
    upload_error(404, 'Buchung nicht gefunden oder dieser Box nicht (mehr) zugeordnet');
}

$file = $_FILES['photo'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    upload_error(400, 'Foto fehlt oder Upload fehlgeschlagen');
}

$filename = basename((string) ($_POST['filename'] ?? $file['name'] ?? ''));
// Nur Dateinamen im von main.py erzeugten Format zulassen (siehe
// finish_session()): "<Zeitstempel>.jpg" fuer die Collage,
// "<Zeitstempel>_fotoN.jpg" fuer ein Einzelbild.
if (preg_match('/^\d{8}_\d{6}_foto\d+\.jpg$/', $filename)) {
    $kind = 'foto';
} elseif (preg_match('/^\d{8}_\d{6}\.jpg$/', $filename)) {
    $kind = 'collage';
} else {
    upload_error(400, 'Ungueltiger Dateiname');
}

if (($file['size'] ?? 0) > 20 * 1024 * 1024) {
    upload_error(400, 'Datei zu gross');
}

$info = @getimagesize($file['tmp_name']);
if (!$info || $info[2] !== IMAGETYPE_JPEG) {
    upload_error(400, 'Nur JPEG-Dateien erlaubt');
}

$dir = gallery_storage_dir() . '/' . $bookingId;
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    upload_error(500, 'Zielordner konnte nicht angelegt werden');
}

if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
    upload_error(500, 'Datei konnte nicht gespeichert werden');
}

ensure_gallery_token($bookingId);

db()->prepare(
    'INSERT INTO gallery_photos (booking_id, filename, kind) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE uploaded_at = CURRENT_TIMESTAMP'
)->execute([$bookingId, $filename, $kind]);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);

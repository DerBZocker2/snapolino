<?php
declare(strict_types=1);

// Liefert eine Rahmen-PNG aus. Aufruf: GET /frame.php?file=<name>.png
// mit Header "X-API-Key: <api_key>". Die Datei wird nur ausgeliefert,
// wenn die anfragende Box tatsaechlich ein Layout zugeordnet hat, das
// genau diese Datei referenziert (kein wahlloses Ausliefern beliebiger
// Dateien aus dem Upload-Verzeichnis).

require_once __DIR__ . '/../includes/db.php';

function frame_error(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function frame_api_key(): string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $name => $value) {
        if (strcasecmp($name, 'X-API-Key') === 0) {
            return trim((string) $value);
        }
    }
    return trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
}

$file = (string) ($_GET['file'] ?? '');
$apiKey = frame_api_key();

if ($file === '' || $apiKey === '') {
    frame_error(400, 'file und X-API-Key Header sind erforderlich');
}

// Nur einfache Dateinamen erlauben, keine Pfadbestandteile.
if (basename($file) !== $file || !preg_match('/^[A-Za-z0-9_\-]+\.png$/', $file)) {
    frame_error(400, 'Ungueltiger Dateiname');
}

$stmt = db()->prepare('SELECT id, api_key FROM boxes WHERE api_key = ?');
$stmt->execute([$apiKey]);
$box = $stmt->fetch();

if (!$box || !hash_equals($box['api_key'], $apiKey)) {
    frame_error(401, 'Unbekannter API-Key');
}

$stmt = db()->prepare(
    'SELECT 1 FROM box_layouts bl
     INNER JOIN layouts l ON l.id = bl.layout_id
     WHERE bl.box_id = ? AND l.frame_file = ?
     LIMIT 1'
);
$stmt->execute([$box['id'], $file]);

if (!$stmt->fetch()) {
    frame_error(404, 'Rahmen nicht gefunden oder dieser Box nicht zugeordnet');
}

$cfg = backend_config();
$path = rtrim($cfg['storage_dir'], '/') . '/' . $file;

if (!is_file($path)) {
    frame_error(404, 'Rahmendatei fehlt auf dem Server');
}

header('Content-Type: image/png');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);

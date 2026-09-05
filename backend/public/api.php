<?php
declare(strict_types=1);

// Konfigurations-Endpunkt fuer die Fotobox.
// Aufruf: GET /api.php?box=<box_key>  mit Header "X-API-Key: <api_key>"
// Optional: ?since=<config_version> fuer einen guenstigen Preflight-Check.
// Antwort: JSON mit allen Layouts (inkl. Slot-Koordinaten), die dieser
// Box zugeordnet sind. Wird nur vor dem Versand im Vorbereitungsmodus
// aufgerufen, niemals waehrend eines laufenden Events.

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

function api_error(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function request_api_key(): string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $name => $value) {
        if (strcasecmp($name, 'X-API-Key') === 0) {
            return trim((string) $value);
        }
    }
    return trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
}

$boxKey = trim((string) ($_GET['box'] ?? ''));
$apiKey = request_api_key();

if ($boxKey === '' || $apiKey === '') {
    api_error(400, 'box und X-API-Key Header sind erforderlich');
}

$stmt = db()->prepare('SELECT * FROM boxes WHERE box_key = ?');
$stmt->execute([$boxKey]);
$box = $stmt->fetch();

if (!$box || !hash_equals($box['api_key'], $apiKey)) {
    api_error(401, 'Unbekannte Box oder falscher API-Key');
}

$currentVersion = (int) $box['config_version'];

$since = $_GET['since'] ?? null;
if ($since !== null && ctype_digit((string) $since) && (int) $since === $currentVersion) {
    http_response_code(304);
    exit;
}

$stmt = db()->prepare(
    'SELECT l.* FROM layouts l
     INNER JOIN box_layouts bl ON bl.layout_id = l.id
     WHERE bl.box_id = ?
     ORDER BY bl.sort_order, l.id'
);
$stmt->execute([$box['id']]);
$layouts = $stmt->fetchAll();

$cfg = backend_config();
$slotStmt = db()->prepare('SELECT slot_index AS `index`, x, y, width, height FROM layout_slots WHERE layout_id = ? ORDER BY slot_index');

$result = [];
foreach ($layouts as $layout) {
    $slotStmt->execute([$layout['id']]);
    $result[] = [
        'id'              => (int) $layout['id'],
        'name'            => $layout['name'],
        'slot_count'      => (int) $layout['slot_count'],
        'is_default'      => (bool) $layout['is_default'],
        'surcharge_cents' => (int) $layout['surcharge_cents'],
        'canvas_width'    => (int) $layout['canvas_width'],
        'canvas_height'   => (int) $layout['canvas_height'],
        'frame_file'      => $layout['frame_file'],
        'frame_url'       => rtrim($cfg['base_url'], '/') . '/frame.php?file=' . rawurlencode($layout['frame_file']),
        'slots'           => array_map(
            static fn (array $s): array => [
                'index'  => (int) $s['index'],
                'x'      => (int) $s['x'],
                'y'      => (int) $s['y'],
                'width'  => (int) $s['width'],
                'height' => (int) $s['height'],
            ],
            $slotStmt->fetchAll()
        ),
    ];
}

echo json_encode([
    'box_key'        => $box['box_key'],
    'box_name'       => $box['name'],
    'config_version' => $currentVersion,
    'layouts'        => $result,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

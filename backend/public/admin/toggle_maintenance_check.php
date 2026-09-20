<?php
declare(strict_types=1);

// Setzt/entfernt per AJAX das Haekchen eines Wartungspunkts fuer eine Box
// (siehe boxes.php). Analog zu assign_box.php: JSON-Antwort statt Redirect,
// damit die Checkliste ohne Neuladen der Seite reagiert.

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

function toggle_maintenance_json_error(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    toggle_maintenance_json_error(405, 'Methode nicht erlaubt');
}

$token = $_POST['csrf_token'] ?? '';
if (!is_string($token) || $token === '' || !hash_equals(csrf_token(), $token)) {
    toggle_maintenance_json_error(400, 'Formular abgelaufen, bitte Seite neu laden');
}

$boxId = (int) ($_POST['box_id'] ?? 0);
$itemId = (int) ($_POST['item_id'] ?? 0);
$checked = (string) ($_POST['checked'] ?? '') === '1';

if ($boxId <= 0 || $itemId <= 0) {
    toggle_maintenance_json_error(400, 'Ungültige Anfrage');
}

if ($checked) {
    db()->prepare(
        'INSERT INTO box_maintenance_checks (box_id, item_id, checked_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE checked_at = NOW()'
    )->execute([$boxId, $itemId]);
} else {
    db()->prepare('DELETE FROM box_maintenance_checks WHERE box_id = ? AND item_id = ?')->execute([$boxId, $itemId]);
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);

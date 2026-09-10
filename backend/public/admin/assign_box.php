<?php
declare(strict_types=1);

// Nimmt die Drag&Drop-Zuordnung einer Buchung zu einer Box entgegen
// (boxes.php) und ruft dieselbe assign_box_and_confirm() auf, die auch
// beim Bestaetigen einer Anfrage bzw. automatisch nach Zahlungseingang
// laeuft - die Buchung wird dabei auch auf "bestaetigt" gesetzt.

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

function assign_box_json_error(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    assign_box_json_error(405, 'Methode nicht erlaubt');
}

$token = $_POST['csrf_token'] ?? '';
if (!is_string($token) || $token === '' || !hash_equals(csrf_token(), $token)) {
    assign_box_json_error(400, 'Formular abgelaufen, bitte Seite neu laden');
}

$bookingId = (int) ($_POST['booking_id'] ?? 0);
$boxId = (int) ($_POST['box_id'] ?? 0);

if ($bookingId <= 0 || $boxId <= 0) {
    assign_box_json_error(400, 'Ungültige Anfrage');
}

$ok = assign_box_and_confirm($bookingId, $boxId);
echo json_encode(['ok' => $ok, 'error' => $ok ? null : 'Zuordnung fehlgeschlagen'], JSON_UNESCAPED_UNICODE);

<?php
declare(strict_types=1);

// Liefert die aktuell blockierten Tage fuer den Buchungskalender.
// Oeffentlich, enthaelt keine Kundendaten - nur Datumswerte.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['blocked' => fetch_blocked_dates()], JSON_UNESCAPED_UNICODE);

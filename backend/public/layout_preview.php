<?php
declare(strict_types=1);

// Oeffentliche Vorschau einer Rahmen-PNG fuer die Design-Galerie im
// Buchungsassistenten. Anders als frame.php (fuer die Box, mit API-Key)
// ist das hier bewusst oeffentlich - die Designs sollen ja beworben werden.

require_once __DIR__ . '/../includes/db.php';

$layoutId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT frame_file FROM layouts WHERE id = ?');
$stmt->execute([$layoutId]);
$frameFile = $stmt->fetchColumn();

if (!$frameFile) {
    http_response_code(404);
    exit;
}

$cfg = backend_config();
$path = rtrim($cfg['storage_dir'], '/') . '/' . basename((string) $frameFile);

if (!is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
readfile($path);

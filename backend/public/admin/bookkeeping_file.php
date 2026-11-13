<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/bookkeeping.php';

require_login();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM bookkeeping_entries WHERE id = ?');
$stmt->execute([$id]);
$entry = $stmt->fetch();

if (!$entry || !$entry['stored_filename']) {
    http_response_code(404);
    exit('Nicht gefunden.');
}

$path = bookkeeping_storage_dir() . '/' . basename((string) $entry['stored_filename']);
if (!is_file($path)) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

header('Content-Type: ' . ($entry['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . basename((string) $entry['original_filename'] ?: $entry['stored_filename']) . '"');
readfile($path);

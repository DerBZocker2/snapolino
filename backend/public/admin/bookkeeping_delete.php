<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/bookkeeping.php';

require_login();
check_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id > 0) {
    $stmt = db()->prepare('SELECT * FROM bookkeeping_entries WHERE id = ?');
    $stmt->execute([$id]);
    $entry = $stmt->fetch();
    if ($entry) {
        if ($entry['stored_filename']) {
            $path = bookkeeping_storage_dir() . '/' . basename((string) $entry['stored_filename']);
            if (is_file($path)) {
                unlink($path);
            }
        }
        db()->prepare('DELETE FROM bookkeeping_entries WHERE id = ?')->execute([$id]);
    }
}

header('Location: bookkeeping.php');

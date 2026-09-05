<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: layouts.php');
    exit;
}

check_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id > 0) {
    // Betroffene Boxen informieren, bevor das Layout weg ist.
    bump_boxes_for_layout($id);

    $stmt = db()->prepare('SELECT frame_file FROM layouts WHERE id = ?');
    $stmt->execute([$id]);
    $frameFile = $stmt->fetchColumn();

    $stmt = db()->prepare('DELETE FROM layouts WHERE id = ?');
    $stmt->execute([$id]);

    if ($frameFile) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM layouts WHERE frame_file = ?');
        $stmt->execute([$frameFile]);
        if ((int) $stmt->fetchColumn() === 0) {
            $cfg = backend_config();
            $path = rtrim($cfg['storage_dir'], '/') . '/' . $frameFile;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

header('Location: layouts.php');

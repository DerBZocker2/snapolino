<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: extras.php');
    exit;
}

check_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id > 0) {
    $stmt = db()->prepare('DELETE FROM extras WHERE id = ?');
    $stmt->execute([$id]);
}

header('Location: extras.php');

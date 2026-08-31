<?php
declare(strict_types=1);

// Legt einen Admin-Login an oder setzt dessen Passwort neu.
// Aufruf: php bin/create_admin.php <benutzername> <passwort>

require __DIR__ . '/../includes/db.php';

if ($argc !== 3) {
    fwrite(STDERR, "Aufruf: php bin/create_admin.php <benutzername> <passwort>\n");
    exit(1);
}

[, $username, $password] = $argv;

if (strlen($password) < 8) {
    fwrite(STDERR, "Passwort muss mindestens 8 Zeichen lang sein.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = db()->prepare(
    'INSERT INTO admins (username, password_hash) VALUES (:u, :h)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
);
$stmt->execute(['u' => $username, 'h' => $hash]);

echo "Admin '{$username}' angelegt bzw. Passwort aktualisiert.\n";

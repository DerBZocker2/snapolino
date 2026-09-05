<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

start_session();

if (current_admin_id() !== null) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = db()->prepare('SELECT * FROM admins WHERE username = ?');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password_hash'])) {
        login_admin((int) $admin['id']);
        header('Location: dashboard.php');
        exit;
    }

    $error = 'Benutzername oder Passwort falsch.';
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Snapolino Panel &ndash; Anmeldung</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-page">
    <form class="login-box" method="post" action="login.php">
        <h1>Snapolino Panel</h1>
        <?php if ($error !== ''): ?>
            <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
        <?php endif; ?>
        <?= csrf_field() ?>
        <label>Benutzername
            <input type="text" name="username" autocomplete="username" required autofocus>
        </label>
        <label>Passwort
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit">Anmelden</button>
    </form>
</body>
</html>

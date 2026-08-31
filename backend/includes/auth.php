<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

// Einfache Session-Authentifizierung fuers Admin-Panel plus CSRF-Schutz
// fuer alle Formulare. Kein Rollen-/Rechtesystem noetig, es gibt nur Admins.

function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function current_admin_id(): ?int
{
    start_session();
    return $_SESSION['admin_id'] ?? null;
}

function require_login(): void
{
    if (current_admin_id() === null) {
        header('Location: login.php');
        exit;
    }
}

function login_admin(int $id): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $id;
}

function logout_admin(): void
{
    start_session();
    $_SESSION = [];
    session_destroy();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function check_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(400);
        die('Ungueltiges Formular (CSRF-Token abgelaufen). Bitte Seite neu laden und erneut versuchen.');
    }
}

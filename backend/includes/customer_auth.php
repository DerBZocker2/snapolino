<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

// Kundenkonto-Login per E-Mail-Code statt Passwort - eigene Session-Variable
// (customer_account_id), unabhaengig vom Admin-Login (admin_id, siehe
// auth.php). Ein Konto pro E-Mail-Adresse, wird automatisch beim Anlegen
// einer Reservierung angelegt (siehe buchen.php Schritt 2) oder beim
// erstmaligen Anfordern eines Login-Codes.

const CUSTOMER_LOGIN_CODE_TTL_MINUTES = 15;
const CUSTOMER_LOGIN_CODE_MAX_ATTEMPTS = 5;
const CUSTOMER_LOGIN_CODE_RESEND_SECONDS = 60;

function current_customer_account_id(): ?int
{
    start_session();
    return $_SESSION['customer_account_id'] ?? null;
}

// Liefert das eingeloggte Konto oder leitet zum Login um.
function require_customer_login(): array
{
    $id = current_customer_account_id();
    if ($id !== null) {
        $stmt = db()->prepare('SELECT * FROM customer_accounts WHERE id = ?');
        $stmt->execute([$id]);
        $account = $stmt->fetch();
        if ($account) {
            return $account;
        }
    }

    header('Location: konto.php');
    exit;
}

function login_customer(int $accountId): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['customer_account_id'] = $accountId;
}

function logout_customer(): void
{
    start_session();
    unset($_SESSION['customer_account_id']);
}

// Legt bei Bedarf ein Konto fuer diese E-Mail-Adresse an (z.B. beim
// Anlegen einer Reservierung) und gibt dessen ID zurueck - ein Konto pro
// E-Mail-Adresse, mehrfacher Aufruf mit derselben Adresse ist unschaedlich.
function find_or_create_customer_account(string $email): int
{
    $email = trim($email);
    $stmt = db()->prepare('SELECT id FROM customer_accounts WHERE email = ?');
    $stmt->execute([$email]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $stmt = db()->prepare('INSERT INTO customer_accounts (email) VALUES (?)');
    $stmt->execute([$email]);
    return (int) db()->lastInsertId();
}

// Erzeugt einen neuen 6-stelligen Code und verschickt ihn per Mail. Liefert
// false, wenn gerade erst einer verschickt wurde (Rate-Limit, ein evtl.
// noch gueltiger vorheriger Code bleibt dann nutzbar) oder der Versand
// fehlschlaegt (z.B. SMTP nicht konfiguriert).
function send_customer_login_code(string $email): bool
{
    $email = trim($email);
    $stmt = db()->prepare('SELECT * FROM customer_accounts WHERE email = ?');
    $stmt->execute([$email]);
    $account = $stmt->fetch();

    if ($account && $account['login_code_sent_at'] !== null) {
        $sentAt = new DateTimeImmutable($account['login_code_sent_at']);
        if ($sentAt > new DateTimeImmutable('-' . CUSTOMER_LOGIN_CODE_RESEND_SECONDS . ' seconds')) {
            return false;
        }
    }

    $accountId = $account ? (int) $account['id'] : find_or_create_customer_account($email);
    $code = (string) random_int(100000, 999999);
    $expiresAt = (new DateTimeImmutable('+' . CUSTOMER_LOGIN_CODE_TTL_MINUTES . ' minutes'))->format('Y-m-d H:i:s');

    db()->prepare(
        'UPDATE customer_accounts
         SET login_code = ?, login_code_expires_at = ?, login_code_attempts = 0, login_code_sent_at = NOW()
         WHERE id = ?'
    )->execute([$code, $expiresAt, $accountId]);

    return send_customer_login_code_email($email, $code);
}

// Prueft einen eingegebenen Code gegen das Konto der angegebenen
// E-Mail-Adresse. Liefert die Konto-ID bei Erfolg, sonst null (falsche
// Adresse, falscher/abgelaufener Code oder zu viele Fehlversuche in Folge).
// Ein richtiger Code wird sofort verbraucht (kann nicht zweimal benutzt
// werden).
function verify_customer_login_code(string $email, string $code): ?int
{
    $stmt = db()->prepare('SELECT * FROM customer_accounts WHERE email = ?');
    $stmt->execute([trim($email)]);
    $account = $stmt->fetch();

    if (!$account || $account['login_code'] === null) {
        return null;
    }
    if ((int) $account['login_code_attempts'] >= CUSTOMER_LOGIN_CODE_MAX_ATTEMPTS) {
        return null;
    }
    if ($account['login_code_expires_at'] === null
        || new DateTimeImmutable($account['login_code_expires_at']) < new DateTimeImmutable()) {
        return null;
    }

    if (!hash_equals((string) $account['login_code'], trim($code))) {
        db()->prepare('UPDATE customer_accounts SET login_code_attempts = login_code_attempts + 1 WHERE id = ?')
            ->execute([$account['id']]);
        return null;
    }

    db()->prepare(
        'UPDATE customer_accounts SET login_code = NULL, login_code_expires_at = NULL, login_code_attempts = 0 WHERE id = ?'
    )->execute([$account['id']]);

    return (int) $account['id'];
}

// Buchungen dieses Kontos - inkl. Alt-Buchungen von vor Einfuehrung der
// Kundenkonten, die per Migration ueber die E-Mail-Adresse verknuepft
// wurden (siehe 0011_customer_accounts.sql), sowie Buchungen, deren
// E-Mail-Adresse sich seither geaendert hat aber noch auf dieses Konto
// zeigt.
function customer_account_bookings(int $accountId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM bookings WHERE customer_account_id = ? ORDER BY event_date DESC'
    );
    $stmt->execute([$accountId]);
    return $stmt->fetchAll();
}

// Ob eine Buchung fuer den Kunden noch aenderbar ist: solange sie noch
// nicht abschliessend bestaetigt ist (angefragt), oder wenn ein Admin die
// Bearbeitung fuer diese eine Buchung ausdruecklich wieder freigeschaltet
// hat (siehe booking_detail.php).
function booking_customer_editable(array $booking): bool
{
    if ($booking['status'] === 'angefragt') {
        return true;
    }
    return $booking['status'] === 'bestaetigt' && (int) $booking['edit_unlocked_by_admin'] === 1;
}

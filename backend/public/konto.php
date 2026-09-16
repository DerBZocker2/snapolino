<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/customer_auth.php';

$pageTitle = 'Mein Konto';
$activeNav = 'konto';
require __DIR__ . '/_site_header.php';

start_session();
$errors = [];
$info = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string) ($_POST['form_action'] ?? '');

    if ($action === 'logout') {
        logout_customer();
        header('Location: konto.php');
        exit;
    }

    if ($action === 'request_code') {
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
        } else {
            // Rueckgabewert bewusst ignoriert: ob die Adresse bekannt ist
            // oder der Versand aus einem anderen Grund (Rate-Limit) gerade
            // nicht passiert, bekommt der Besucher denselben neutralen
            // Hinweis - kein Aufschluss darueber, ob ein Konto existiert.
            send_customer_login_code($email);
            $_SESSION['pending_login_email'] = $email;
            $info = 'Falls diese E-Mail-Adresse bei uns bekannt ist, haben wir dir gerade einen Anmeldecode geschickt.';
        }
    } elseif ($action === 'verify_code') {
        $email = trim((string) ($_POST['email'] ?? ($_SESSION['pending_login_email'] ?? '')));
        $code = trim((string) ($_POST['code'] ?? ''));
        $accountId = verify_customer_login_code($email, $code);
        if ($accountId === null) {
            $errors[] = 'Der Code ist ungültig oder abgelaufen. Bitte einen neuen Code anfordern.';
            $_SESSION['pending_login_email'] = $email;
        } else {
            unset($_SESSION['pending_login_email']);
            login_customer($accountId);
            header('Location: konto.php');
            exit;
        }
    }
}

$accountId = current_customer_account_id();
$account = null;
$bookings = [];
if ($accountId !== null) {
    $stmt = db()->prepare('SELECT * FROM customer_accounts WHERE id = ?');
    $stmt->execute([$accountId]);
    $account = $stmt->fetch();
    if (!$account) {
        logout_customer();
        $accountId = null;
    } else {
        $bookings = customer_account_bookings($accountId);
    }
}

$pendingEmail = (string) ($_SESSION['pending_login_email'] ?? '');
?>

<section class="section legal-page">
    <?php if ($account): ?>
        <h2>Mein Konto</h2>
        <p class="muted">Angemeldet als <strong><?= htmlspecialchars($account['email'], ENT_QUOTES) ?></strong></p>

        <?php if (!$bookings): ?>
            <div class="panel-box">
                <p class="muted">Noch keine Buchung unter dieser E-Mail-Adresse gefunden.</p>
                <a class="button" href="buchen.php" style="margin-top:12px;display:inline-block;">Jetzt buchen</a>
            </div>
        <?php else: ?>
            <?php foreach ($bookings as $booking): ?>
                <?php
                $event = new DateTimeImmutable($booking['event_date']);
                $editable = booking_customer_editable($booking);
                ?>
                <div class="panel-box" style="margin-bottom:14px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                        <div>
                            <strong>📅 <?= htmlspecialchars(german_weekday($event) . ', ' . $event->format('d.m.Y'), ENT_QUOTES) ?></strong>
                            <span class="status-pill status-<?= htmlspecialchars($booking['status'], ENT_QUOTES) ?>">
                                <?= htmlspecialchars(booking_status_label($booking['status']), ENT_QUOTES) ?>
                            </span>
                        </div>
                        <div>
                            <?= $booking['total_price_cents'] !== null ? money_from_cents((int) $booking['total_price_cents']) : '—' ?>
                        </div>
                    </div>
                    <div style="margin-top:10px;">
                        <?php if ($editable): ?>
                            <a class="button-secondary" href="buchen.php?step=3&token=<?= urlencode((string) $booking['edit_token']) ?>">Bearbeiten</a>
                        <?php else: ?>
                            <a class="button-secondary" href="buchen.php?step=5&token=<?= urlencode((string) $booking['edit_token']) ?>">Ansehen</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <form method="post" action="konto.php" style="margin-top:20px;">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="logout">
            <button type="submit" class="button-secondary">Abmelden</button>
        </form>

    <?php elseif ($pendingEmail !== ''): ?>
        <h2>Anmeldecode eingeben</h2>
        <div class="panel-box" style="max-width:420px;margin:0 auto;">
            <p class="muted">Wir haben einen Code an <strong><?= htmlspecialchars($pendingEmail, ENT_QUOTES) ?></strong> geschickt (falls diese Adresse bei uns bekannt ist). Er ist <?= CUSTOMER_LOGIN_CODE_TTL_MINUTES ?> Minuten gültig.</p>

            <?php foreach ($errors as $err): ?>
                <p class="error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
            <?php endforeach; ?>

            <form method="post" action="konto.php">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="verify_code">
                <input type="hidden" name="email" value="<?= htmlspecialchars($pendingEmail, ENT_QUOTES) ?>">
                <label>Code
                    <input type="text" name="code" inputmode="numeric" maxlength="6" required autofocus>
                </label>
                <button type="submit">Anmelden</button>
            </form>

            <form method="post" action="konto.php" style="margin-top:12px;">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="request_code">
                <input type="hidden" name="email" value="<?= htmlspecialchars($pendingEmail, ENT_QUOTES) ?>">
                <button type="submit" class="button-secondary">Neuen Code anfordern</button>
            </form>
        </div>
    <?php else: ?>
        <h2>Mein Konto</h2>
        <div class="panel-box" style="max-width:420px;margin:0 auto;">
            <p class="muted">Melde dich mit deiner E-Mail-Adresse an, um deine Buchungen anzusehen oder anzupassen - ganz ohne Passwort, per Code per E-Mail.</p>

            <?php foreach ($errors as $err): ?>
                <p class="error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
            <?php endforeach; ?>
            <?php if ($info !== ''): ?>
                <p class="muted"><?= htmlspecialchars($info, ENT_QUOTES) ?></p>
            <?php endif; ?>

            <form method="post" action="konto.php">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="request_code">
                <label>E-Mail-Adresse
                    <input type="email" name="email" required autofocus>
                </label>
                <button type="submit">Code anfordern</button>
            </form>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/_site_footer.php'; ?>

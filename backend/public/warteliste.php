<?php
declare(strict_types=1);

// Oeffentliche Warteliste fuer bereits ausgebuchte Termine - erreichbar
// ueber den Link in buchen.php Schritt 1 sowie per Klick auf einen
// ausgegrauten (aber nicht vergangenen) Tag im Buchungskalender dort.
// Legt nur einen Eintrag in waitlist_entries an, keine echte Buchung.
// Wird der Termin spaeter frei (Ablehnung/Stornierung einer Buchung durch
// den Admin), benachrichtigt notify_waitlist_for_freed_range()
// (includes/functions.php) automatisch per Mail.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

$pageTitle = 'Warteliste';
$errors = [];
$success = false;
$eventDate = trim((string) ($_GET['date'] ?? $_POST['event_date'] ?? ''));
$customerName = '';
$customerEmail = '';
$customerPhone = '';
$note = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        header('Location: warteliste.php?danke=1');
        exit;
    }

    $eventDate = trim((string) ($_POST['event_date'] ?? ''));
    $customerName = trim((string) ($_POST['customer_name'] ?? ''));
    $customerEmail = trim((string) ($_POST['customer_email'] ?? ''));
    $customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));

    $eventDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $eventDate) ?: null;
    if (!$eventDateObj) {
        $errors[] = 'Bitte einen Wunschtermin angeben.';
    } elseif ($eventDateObj < new DateTimeImmutable('today')) {
        $errors[] = 'Das Datum darf nicht in der Vergangenheit liegen.';
    }
    if ($customerName === '') {
        $errors[] = 'Bitte deinen Namen angeben.';
    }
    if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
    }

    if (!$errors) {
        $stmt = db()->prepare(
            'INSERT INTO waitlist_entries (event_date, customer_name, customer_email, customer_phone, note)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $eventDate,
            $customerName,
            $customerEmail,
            $customerPhone !== '' ? $customerPhone : null,
            $note !== '' ? $note : null,
        ]);
        $entryId = (int) db()->lastInsertId();
        send_waitlist_signup_email([
            'id' => $entryId,
            'event_date' => $eventDate,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
        ]);
        header('Location: warteliste.php?danke=1&date=' . urlencode($eventDate));
        exit;
    }
}

if (isset($_GET['danke'])) {
    $success = true;
    $eventDate = trim((string) ($_GET['date'] ?? ''));
}

require __DIR__ . '/_site_header.php';
?>

<section class="section">
    <h2>⏳ Warteliste</h2>
    <p class="lead">Dein Wunschtermin ist schon vergeben? Trag dich hier ein - wir melden uns automatisch per
        E-Mail, sobald der Tag wieder frei wird.</p>

    <?php $successDateObj = $success ? (DateTimeImmutable::createFromFormat('Y-m-d', $eventDate) ?: null) : null; ?>
    <?php if ($success): ?>
        <div class="panel-box" style="max-width:520px;margin:0 auto;text-align:center;">
            <h3>🎉 Danke!</h3>
            <p class="muted">Du bist auf der Warteliste<?= $successDateObj ? ' für den ' . htmlspecialchars($successDateObj->format('d.m.Y'), ENT_QUOTES) : '' ?>.
                Wir melden uns, sobald der Termin wieder frei wird.</p>
            <a class="button" href="/">Zur Startseite</a>
        </div>
    <?php else: ?>
        <div class="panel-box" style="max-width:480px;margin:0 auto;">
            <?php foreach ($errors as $err): ?>
                <p class="error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
            <?php endforeach; ?>
            <form method="post" action="warteliste.php">
                <?= csrf_field() ?>
                <input type="text" name="website" class="honeypot" tabindex="-1" autocomplete="off">
                <label>Wunschtermin
                    <input type="date" name="event_date" required min="<?= (new DateTimeImmutable('today'))->format('Y-m-d') ?>"
                        value="<?= htmlspecialchars($eventDate, ENT_QUOTES) ?>">
                </label>
                <label>Name
                    <input type="text" name="customer_name" required value="<?= htmlspecialchars($customerName, ENT_QUOTES) ?>">
                </label>
                <label>E-Mail
                    <input type="email" name="customer_email" required value="<?= htmlspecialchars($customerEmail, ENT_QUOTES) ?>">
                </label>
                <label>Telefon (optional)
                    <input type="text" name="customer_phone" value="<?= htmlspecialchars($customerPhone, ENT_QUOTES) ?>">
                </label>
                <label>Nachricht (optional)
                    <textarea name="note" rows="3"><?= htmlspecialchars($note, ENT_QUOTES) ?></textarea>
                </label>
                <button type="submit" class="btn-gradient">Auf die Warteliste</button>
            </form>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/_site_footer.php'; ?>

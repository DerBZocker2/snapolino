<?php
declare(strict_types=1);

$pageTitle = 'Einstellungen';
require __DIR__ . '/_header.php';

$errors = [];
$priceEuro = number_format(base_price_cents() / 100, 2, '.', '');
$priceLabel = base_price_label();
$businessName = (string) get_setting('business_name', '');
$businessAddress = (string) get_setting('business_address', '');
$businessTaxNote = (string) get_setting('business_tax_note', '');
$businessEmail = (string) get_setting('business_email', '');
$businessPhone = (string) get_setting('business_phone', '');
$galleryRetentionDays = (string) gallery_retention_days();
$reminderDaysBeforeEvent = (string) reminder_days_before_event();
$referralDiscountPercent = (string) referral_discount_percent();
$referralRewardEuro = number_format(referral_reward_cents() / 100, 2, '.', '');
$reviewRequestDaysAfterEvent = (string) review_request_days_after_event();
$googleReviewUrl = google_review_url();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $priceEuro = (string) ($_POST['price_euro'] ?? '');
    $priceLabel = trim((string) ($_POST['price_label'] ?? ''));
    $businessName = trim((string) ($_POST['business_name'] ?? ''));
    $businessAddress = trim((string) ($_POST['business_address'] ?? ''));
    $businessTaxNote = trim((string) ($_POST['business_tax_note'] ?? ''));
    $businessEmail = trim((string) ($_POST['business_email'] ?? ''));
    $businessPhone = trim((string) ($_POST['business_phone'] ?? ''));
    $galleryRetentionDays = trim((string) ($_POST['gallery_retention_days'] ?? ''));
    $reminderDaysBeforeEvent = trim((string) ($_POST['reminder_days_before_event'] ?? ''));
    $referralDiscountPercent = trim((string) ($_POST['referral_discount_percent'] ?? ''));
    $referralRewardEuro = trim((string) ($_POST['referral_reward_euro'] ?? ''));
    $reviewRequestDaysAfterEvent = trim((string) ($_POST['review_request_days_after_event'] ?? ''));
    $googleReviewUrl = trim((string) ($_POST['google_review_url'] ?? ''));

    if (!is_numeric($priceEuro) || (float) $priceEuro < 0) {
        $errors[] = 'Basispreis muss eine Zahl >= 0 sein.';
    }
    if ($businessEmail !== '' && !filter_var($businessEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Bitte eine gültige Kontakt-E-Mail-Adresse angeben.';
    }
    if (!ctype_digit($galleryRetentionDays) || (int) $galleryRetentionDays < 1) {
        $errors[] = 'Aufbewahrungsfrist der Galerie-Fotos muss eine ganze Zahl >= 1 sein.';
    }
    if (!ctype_digit($reminderDaysBeforeEvent)) {
        $errors[] = 'Vorlauf der Erinnerungsmail muss eine ganze Zahl >= 0 sein.';
    }
    if (!ctype_digit($referralDiscountPercent) || (int) $referralDiscountPercent < 0 || (int) $referralDiscountPercent > 100) {
        $errors[] = 'Empfehlungsrabatt muss zwischen 0 und 100 Prozent liegen.';
    }
    if (!is_numeric($referralRewardEuro) || (float) $referralRewardEuro < 0) {
        $errors[] = 'Empfehlungspraemie muss eine Zahl >= 0 sein.';
    }
    if (!ctype_digit($reviewRequestDaysAfterEvent)) {
        $errors[] = 'Vorlauf der Bewertungsanfrage muss eine ganze Zahl >= 0 sein.';
    }
    if ($googleReviewUrl !== '' && !filter_var($googleReviewUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'Bitte eine gültige URL für den Bewertungslink angeben.';
    }

    if (!$errors) {
        set_setting('base_price_cents', (string) (int) round(((float) $priceEuro) * 100));
        set_setting('base_price_label', $priceLabel);
        set_setting('business_name', $businessName);
        set_setting('business_address', $businessAddress);
        set_setting('business_tax_note', $businessTaxNote);
        set_setting('business_email', $businessEmail);
        set_setting('business_phone', $businessPhone);
        set_setting('gallery_retention_days', (string) (int) $galleryRetentionDays);
        set_setting('reminder_days_before_event', (string) (int) $reminderDaysBeforeEvent);
        set_setting('referral_discount_percent', (string) (int) $referralDiscountPercent);
        set_setting('referral_reward_cents', (string) (int) round(((float) $referralRewardEuro) * 100));
        set_setting('review_request_days_after_event', (string) (int) $reviewRequestDaysAfterEvent);
        set_setting('google_review_url', $googleReviewUrl);
        header('Location: settings.php?gespeichert=1');
        exit;
    }
}
?>

<section class="panel">
    <h2>Basispreis</h2>
    <p class="muted-text">Wird im Buchungsassistenten als Startpreis angezeigt, bevor Zusatzformate oder Extras dazukommen.</p>

    <?php foreach ($errors as $err): ?>
        <p class="error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
    <?php endforeach; ?>
    <?php if (isset($_GET['gespeichert'])): ?>
        <p class="badge">Gespeichert</p>
    <?php endif; ?>

    <form method="post" action="settings.php">
        <?= csrf_field() ?>
        <div class="grid3">
            <label>Basispreis in EUR
                <input type="text" name="price_euro" required value="<?= htmlspecialchars($priceEuro, ENT_QUOTES) ?>">
            </label>
            <label>Beschriftung (z.B. Paketname)
                <input type="text" name="price_label" value="<?= htmlspecialchars($priceLabel, ENT_QUOTES) ?>">
            </label>
        </div>

        <h2>Rechnungs- &amp; Kontaktdaten</h2>
        <p class="muted-text">Erscheint als Absender auf jeder automatisch erzeugten Rechnungs-PDF sowie im
            Impressum und in der Datenschutzerklärung auf der Buchungsseite (siehe <a href="../impressum.php" target="_blank">Impressum</a>,
            <a href="../datenschutz.php" target="_blank">Datenschutz</a>) - bitte vollständig und korrekt ausfüllen.</p>
        <label>Name / Firma
            <input type="text" name="business_name" value="<?= htmlspecialchars($businessName, ENT_QUOTES) ?>">
        </label>
        <label>Anschrift (mehrzeilig, z.B. Straße Hausnummer und PLZ Ort in je einer Zeile)
            <textarea name="business_address" rows="2"><?= htmlspecialchars($businessAddress, ENT_QUOTES) ?></textarea>
        </label>
        <div class="grid3">
            <label>Kontakt-E-Mail
                <input type="email" name="business_email" value="<?= htmlspecialchars($businessEmail, ENT_QUOTES) ?>">
            </label>
            <label>Telefon (optional, fürs Impressum)
                <input type="text" name="business_phone" value="<?= htmlspecialchars($businessPhone, ENT_QUOTES) ?>">
            </label>
        </div>
        <label>Steuerlicher Hinweis (Rechnung &amp; Impressum, z.B. §19 UStG-Hinweis oder USt-IdNr.)
            <input type="text" name="business_tax_note" value="<?= htmlspecialchars($businessTaxNote, ENT_QUOTES) ?>">
        </label>

        <h2>Online-Galerie</h2>
        <p class="muted-text">Nach wie vielen Tagen <strong>nach dem Eventdatum</strong> die automatisch
            hochgeladenen Galerie-Fotos endgültig gelöscht werden (DSGVO) - erfordert einen täglichen Cronjob
            für <code>bin/purge_expired_galleries.php</code>, siehe <code>backend/README.md</code>.</p>
        <label>Aufbewahrungsfrist in Tagen
            <input type="number" min="1" name="gallery_retention_days" value="<?= htmlspecialchars($galleryRetentionDays, ENT_QUOTES) ?>" style="max-width:120px;">
        </label>

        <h2>Erinnerungsmail</h2>
        <p class="muted-text">Wie viele Tage <strong>vor dem Eventdatum</strong> Kunden mit bestätigter Buchung
            automatisch eine Erinnerungsmail bekommen - erfordert einen täglichen Cronjob für
            <code>bin/send_event_reminders.php</code>, siehe <code>backend/README.md</code>. 0 = am Eventtag selbst.</p>
        <label>Vorlauf in Tagen
            <input type="number" min="0" name="reminder_days_before_event" value="<?= htmlspecialchars($reminderDaysBeforeEvent, ENT_QUOTES) ?>" style="max-width:120px;">
        </label>

        <h2>Empfehlungsprogramm</h2>
        <p class="muted-text">Jede bestätigte Buchung bekommt automatisch einen persönlichen Rabattcode zum
            Weitergeben. Löst eine neue Buchung diesen Code ein und wird selbst bestätigt, bekommt die werbende
            Person automatisch einen Belohnungsgutschein per Mail.</p>
        <div class="grid3">
            <label>Rabatt für die geworbene Person in %
                <input type="number" min="0" max="100" name="referral_discount_percent" value="<?= htmlspecialchars($referralDiscountPercent, ENT_QUOTES) ?>">
            </label>
            <label>Prämie für die werbende Person in EUR
                <input type="text" name="referral_reward_euro" value="<?= htmlspecialchars($referralRewardEuro, ENT_QUOTES) ?>">
            </label>
        </div>

        <h2>Bewertungsanfrage</h2>
        <p class="muted-text">Wie viele Tage <strong>nach dem Eventdatum</strong> Kunden mit bestätigter Buchung
            automatisch um eine Google-Bewertung gebeten werden - erfordert einen täglichen Cronjob für
            <code>bin/send_review_requests.php</code>, siehe <code>backend/README.md</code>. Ohne hinterlegten Link
            enthält die Mail keinen Bewertungs-Knopf.</p>
        <div class="grid3">
            <label>Vorlauf in Tagen
                <input type="number" min="0" name="review_request_days_after_event" value="<?= htmlspecialchars($reviewRequestDaysAfterEvent, ENT_QUOTES) ?>">
            </label>
            <label>Google-Bewertungslink
                <input type="text" name="google_review_url" placeholder="https://g.page/r/.../review" value="<?= htmlspecialchars($googleReviewUrl, ENT_QUOTES) ?>">
            </label>
        </div>

        <button type="submit">Speichern</button>
    </form>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

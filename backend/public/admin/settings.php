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

    if (!is_numeric($priceEuro) || (float) $priceEuro < 0) {
        $errors[] = 'Basispreis muss eine Zahl >= 0 sein.';
    }
    if ($businessEmail !== '' && !filter_var($businessEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Bitte eine gültige Kontakt-E-Mail-Adresse angeben.';
    }
    if (!ctype_digit($galleryRetentionDays) || (int) $galleryRetentionDays < 1) {
        $errors[] = 'Aufbewahrungsfrist der Galerie-Fotos muss eine ganze Zahl >= 1 sein.';
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

        <button type="submit">Speichern</button>
    </form>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

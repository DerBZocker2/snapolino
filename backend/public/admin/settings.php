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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $priceEuro = (string) ($_POST['price_euro'] ?? '');
    $priceLabel = trim((string) ($_POST['price_label'] ?? ''));
    $businessName = trim((string) ($_POST['business_name'] ?? ''));
    $businessAddress = trim((string) ($_POST['business_address'] ?? ''));
    $businessTaxNote = trim((string) ($_POST['business_tax_note'] ?? ''));

    if (!is_numeric($priceEuro) || (float) $priceEuro < 0) {
        $errors[] = 'Basispreis muss eine Zahl >= 0 sein.';
    }

    if (!$errors) {
        set_setting('base_price_cents', (string) (int) round(((float) $priceEuro) * 100));
        set_setting('base_price_label', $priceLabel);
        set_setting('business_name', $businessName);
        set_setting('business_address', $businessAddress);
        set_setting('business_tax_note', $businessTaxNote);
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

        <h2>Rechnungsdaten</h2>
        <p class="muted-text">Erscheint als Absender auf jeder automatisch erzeugten Rechnungs-PDF, die nach einer Zahlung per E-Mail verschickt wird.</p>
        <label>Name / Firma
            <input type="text" name="business_name" value="<?= htmlspecialchars($businessName, ENT_QUOTES) ?>">
        </label>
        <label>Anschrift (mehrzeilig, z.B. Straße Hausnummer und PLZ Ort in je einer Zeile)
            <textarea name="business_address" rows="2"><?= htmlspecialchars($businessAddress, ENT_QUOTES) ?></textarea>
        </label>
        <label>Steuerlicher Hinweis auf der Rechnung
            <input type="text" name="business_tax_note" value="<?= htmlspecialchars($businessTaxNote, ENT_QUOTES) ?>">
        </label>

        <button type="submit">Speichern</button>
    </form>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

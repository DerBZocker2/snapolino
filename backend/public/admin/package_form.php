<?php
declare(strict_types=1);

$pageTitle = 'Paket';
require __DIR__ . '/_header.php';

$packageId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$package = null;
$selectedExtraIds = [];

if ($packageId > 0) {
    $stmt = db()->prepare('SELECT * FROM packages WHERE id = ?');
    $stmt->execute([$packageId]);
    $package = $stmt->fetch();
    if (!$package) {
        http_response_code(404);
        echo '<p>Paket nicht gefunden.</p>';
        require __DIR__ . '/_footer.php';
        exit;
    }
    $selectedExtraIds = package_included_extra_ids($packageId);
}

$errors = [];

$name = (string) ($_POST['name'] ?? $package['name'] ?? '');
$description = (string) ($_POST['description'] ?? $package['description'] ?? '');
$priceEuro = isset($_POST['price_euro'])
    ? (string) $_POST['price_euro']
    : ($package ? number_format($package['price_cents'] / 100, 2, '.', '') : '');
$suppliesNote = (string) ($_POST['supplies_note'] ?? $package['supplies_note'] ?? '');
$sortOrder = (string) ($_POST['sort_order'] ?? $package['sort_order'] ?? '0');
$isActive = isset($_POST['is_active']) ? true : (bool) ($package['is_active'] ?? true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $postedExtraIds = array_map('intval', $_POST['extra_ids'] ?? ($package ? $selectedExtraIds : []));

    $name = trim($name);
    $description = trim($description);
    $suppliesNote = trim($suppliesNote);

    if ($name === '') {
        $errors[] = 'Bitte einen Namen angeben.';
    }
    if (!is_numeric($priceEuro) || (float) $priceEuro < 0) {
        $errors[] = 'Bitte einen gültigen Preis angeben.';
    }
    if (!ctype_digit($sortOrder)) {
        $errors[] = 'Reihenfolge muss eine ganze Zahl sein.';
    }

    if (!$errors) {
        $priceCents = (int) round(((float) $priceEuro) * 100);

        db()->beginTransaction();

        if ($packageId > 0) {
            $stmt = db()->prepare(
                'UPDATE packages SET name = ?, description = ?, price_cents = ?, supplies_note = ?,
                 sort_order = ?, is_active = ? WHERE id = ?'
            );
            $stmt->execute([$name, $description ?: null, $priceCents, $suppliesNote ?: null, (int) $sortOrder, $isActive ? 1 : 0, $packageId]);
        } else {
            $stmt = db()->prepare(
                'INSERT INTO packages (name, description, price_cents, supplies_note, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $description ?: null, $priceCents, $suppliesNote ?: null, (int) $sortOrder, $isActive ? 1 : 0]);
            $packageId = (int) db()->lastInsertId();
        }

        db()->prepare('DELETE FROM package_extras WHERE package_id = ?')->execute([$packageId]);
        $ins = db()->prepare('INSERT INTO package_extras (package_id, extra_id) VALUES (?, ?)');
        foreach ($postedExtraIds as $extraId) {
            $ins->execute([$packageId, $extraId]);
        }

        db()->commit();

        header('Location: packages.php');
        exit;
    }

    $selectedExtraIds = $postedExtraIds;
}

$allExtras = fetch_all_extras();
?>

<section class="panel">
    <?php foreach ($errors as $err): ?>
        <p class="error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
    <?php endforeach; ?>

    <form method="post" action="package_form.php">
        <?= csrf_field() ?>
        <?php if ($packageId > 0): ?>
            <input type="hidden" name="id" value="<?= $packageId ?>">
        <?php endif; ?>

        <label>Name *
            <input type="text" name="name" required value="<?= htmlspecialchars($name, ENT_QUOTES) ?>" placeholder="z.B. Premium">
        </label>

        <label>Beschreibung <span class="muted-text">(erscheint im Buchungsassistenten)</span>
            <input type="text" name="description" value="<?= htmlspecialchars($description, ENT_QUOTES) ?>" maxlength="255">
        </label>

        <div class="grid2">
            <label>Preis in EUR *
                <input type="text" name="price_euro" required value="<?= htmlspecialchars($priceEuro, ENT_QUOTES) ?>" placeholder="z.B. 269,00">
            </label>
            <label>Reihenfolge <span class="muted-text">(kleinere Zahl zuerst)</span>
                <input type="number" name="sort_order" min="0" value="<?= htmlspecialchars($sortOrder, ENT_QUOTES) ?>">
            </label>
        </div>

        <label>Packliste fürs Verpacken (optional, nur intern im Panel sichtbar)
            <input type="text" name="supplies_note" value="<?= htmlspecialchars($suppliesNote, ENT_QUOTES) ?>" placeholder="z.B. 2x Fotopapier + 2x Farbband">
        </label>

        <div id="extras-field">
            <p class="muted-text">Diese Extras sind in diesem Paket kostenlos inklusive (zählen nicht zum Preis, Kunde muss sie nicht extra ankreuzen):</p>
            <?php foreach ($allExtras as $extra): ?>
                <label class="checkbox">
                    <input type="checkbox" name="extra_ids[]" value="<?= (int) $extra['id'] ?>"
                        <?= in_array((int) $extra['id'], $selectedExtraIds, true) ? 'checked' : '' ?>>
                    <?= htmlspecialchars($extra['name'], ENT_QUOTES) ?>
                </label>
            <?php endforeach; ?>
            <?php if (!$allExtras): ?>
                <p class="muted-text">Noch keine <a href="extras.php">Extras</a> angelegt.</p>
            <?php endif; ?>
        </div>

        <label class="checkbox">
            <input type="checkbox" name="is_active" <?= $isActive ? 'checked' : '' ?>>
            Aktiv (im Buchungsassistenten wählbar)
        </label>

        <button type="submit">Speichern</button>
        <a href="packages.php" class="button-secondary">Abbrechen</a>
    </form>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

<?php
declare(strict_types=1);

$pageTitle = 'Extra';
require __DIR__ . '/_header.php';

$extraId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$extra = null;

if ($extraId > 0) {
    $stmt = db()->prepare('SELECT * FROM extras WHERE id = ?');
    $stmt->execute([$extraId]);
    $extra = $stmt->fetch();
    if (!$extra) {
        http_response_code(404);
        echo '<p>Extra nicht gefunden.</p>';
        require __DIR__ . '/_footer.php';
        exit;
    }
}

$errors = [];

$name        = (string) ($_POST['name'] ?? $extra['name'] ?? '');
$description = (string) ($_POST['description'] ?? $extra['description'] ?? '');
$icon        = (string) ($_POST['icon'] ?? $extra['icon'] ?? '');
$priceEuro   = isset($_POST['price_euro'])
    ? (string) $_POST['price_euro']
    : ($extra ? number_format($extra['price_cents'] / 100, 2, '.', '') : '0.00');
$type        = (string) ($_POST['type'] ?? $extra['type'] ?? 'toggle');
$unitLabel   = (string) ($_POST['unit_label'] ?? $extra['unit_label'] ?? '');
$maxQuantity = (string) ($_POST['max_quantity'] ?? $extra['max_quantity'] ?? '');
$sortOrder   = (int) ($_POST['sort_order'] ?? $extra['sort_order'] ?? 0);
$isActive    = isset($_POST['is_active']) ? true : (bool) ($extra['is_active'] ?? true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    if ($name === '') {
        $errors[] = 'Bitte einen Namen angeben.';
    }
    if (!is_numeric($priceEuro)) {
        $errors[] = 'Preis muss eine Zahl sein (negativ für Rabatte erlaubt).';
    }
    if (!in_array($type, ['toggle', 'quantity'], true)) {
        $errors[] = 'Ungültiger Typ.';
    }

    if (!$errors) {
        $priceCents = (int) round(((float) $priceEuro) * 100);
        $maxQuantityValue = $maxQuantity !== '' ? (int) $maxQuantity : null;

        if ($extraId > 0) {
            $stmt = db()->prepare(
                'UPDATE extras SET name = ?, description = ?, icon = ?, price_cents = ?, type = ?,
                 unit_label = ?, max_quantity = ?, is_active = ?, sort_order = ? WHERE id = ?'
            );
            $stmt->execute([
                $name, $description ?: null, $icon ?: null, $priceCents, $type,
                $unitLabel ?: null, $maxQuantityValue, $isActive ? 1 : 0, $sortOrder, $extraId,
            ]);
        } else {
            $stmt = db()->prepare(
                'INSERT INTO extras (name, description, icon, price_cents, type, unit_label, max_quantity, is_active, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $name, $description ?: null, $icon ?: null, $priceCents, $type,
                $unitLabel ?: null, $maxQuantityValue, $isActive ? 1 : 0, $sortOrder,
            ]);
        }

        header('Location: extras.php');
        exit;
    }
}
?>

<section class="panel">
    <?php foreach ($errors as $err): ?>
        <p class="error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
    <?php endforeach; ?>

    <form method="post" action="extra_form.php">
        <?= csrf_field() ?>
        <?php if ($extraId > 0): ?>
            <input type="hidden" name="id" value="<?= $extraId ?>">
        <?php endif; ?>

        <label>Name *
            <input type="text" name="name" required value="<?= htmlspecialchars($name, ENT_QUOTES) ?>">
        </label>
        <label>Beschreibung
            <input type="text" name="description" value="<?= htmlspecialchars($description, ENT_QUOTES) ?>">
        </label>

        <div class="grid3">
            <label>Icon (Emoji)
                <input type="text" name="icon" value="<?= htmlspecialchars($icon, ENT_QUOTES) ?>" placeholder="⚡">
            </label>
            <label>Preis in EUR (negativ = Rabatt)
                <input type="text" name="price_euro" required value="<?= htmlspecialchars($priceEuro, ENT_QUOTES) ?>">
            </label>
            <label>Sortierung
                <input type="number" name="sort_order" value="<?= $sortOrder ?>">
            </label>
        </div>

        <div class="grid3">
            <label>Typ
                <select name="type">
                    <option value="toggle" <?= $type === 'toggle' ? 'selected' : '' ?>>Ein/Aus</option>
                    <option value="quantity" <?= $type === 'quantity' ? 'selected' : '' ?>>Menge (z.B. Tage)</option>
                </select>
            </label>
            <label>Einheit (nur bei Menge, z.B. "Tag")
                <input type="text" name="unit_label" value="<?= htmlspecialchars($unitLabel, ENT_QUOTES) ?>">
            </label>
            <label>Maximale Menge (optional)
                <input type="number" name="max_quantity" value="<?= htmlspecialchars($maxQuantity, ENT_QUOTES) ?>">
            </label>
        </div>

        <label class="checkbox">
            <input type="checkbox" name="is_active" <?= $isActive ? 'checked' : '' ?>>
            Aktiv (im Buchungsformular sichtbar)
        </label>

        <button type="submit">Speichern</button>
        <a href="extras.php" class="button-secondary">Abbrechen</a>
    </form>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

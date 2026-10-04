<?php
declare(strict_types=1);

$pageTitle = 'Rabattaktion';
require __DIR__ . '/_header.php';

$promotionId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$promotion = null;
$selectedExtraIds = [];

if ($promotionId > 0) {
    $stmt = db()->prepare('SELECT * FROM promotions WHERE id = ?');
    $stmt->execute([$promotionId]);
    $promotion = $stmt->fetch();
    if (!$promotion) {
        http_response_code(404);
        echo '<p>Rabattaktion nicht gefunden.</p>';
        require __DIR__ . '/_footer.php';
        exit;
    }
    $selectedExtraIds = fetch_promotion_extra_ids($promotionId);
}

$errors = [];

$name = (string) ($_POST['name'] ?? $promotion['name'] ?? '');
$discountType = (string) ($_POST['discount_type'] ?? $promotion['discount_type'] ?? 'percent');
$discountValueEuro = isset($_POST['discount_value_euro'])
    ? (string) $_POST['discount_value_euro']
    : ($promotion && $promotion['discount_type'] === 'fixed' ? number_format($promotion['discount_value'] / 100, 2, '.', '') : '');
$discountPercent = (string) ($_POST['discount_percent'] ?? ($promotion && $promotion['discount_type'] === 'percent' ? (string) $promotion['discount_value'] : ''));
$scope = (string) ($_POST['scope'] ?? $promotion['scope'] ?? 'all');
$validFrom = (string) ($_POST['valid_from'] ?? $promotion['valid_from'] ?? '');
$validUntil = (string) ($_POST['valid_until'] ?? $promotion['valid_until'] ?? '');
$isActive = isset($_POST['is_active']) ? true : (bool) ($promotion['is_active'] ?? true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $postedExtraIds = array_map('intval', $_POST['extra_ids'] ?? ($promotion ? $selectedExtraIds : []));

    $name = trim($name);
    if ($name === '') {
        $errors[] = 'Bitte einen Namen angeben.';
    }
    if (!in_array($discountType, ['percent', 'fixed'], true)) {
        $errors[] = 'Ungültiger Rabatt-Typ.';
    }
    if (!in_array($scope, ['all', 'extras'], true)) {
        $errors[] = 'Ungültiger Geltungsbereich.';
    }
    if ($scope === 'extras' && !$postedExtraIds) {
        $errors[] = 'Bitte mindestens ein Extra auswählen, oder stattdessen "Alles" als Geltungsbereich wählen.';
    }

    $discountValue = 0;
    if ($discountType === 'percent') {
        if (!ctype_digit($discountPercent) || (int) $discountPercent < 1 || (int) $discountPercent > 100) {
            $errors[] = 'Prozentrabatt muss zwischen 1 und 100 liegen.';
        } else {
            $discountValue = (int) $discountPercent;
        }
    } else {
        if (!is_numeric($discountValueEuro) || (float) $discountValueEuro <= 0) {
            $errors[] = 'Rabattbetrag muss eine Zahl größer 0 sein.';
        } else {
            $discountValue = (int) round(((float) $discountValueEuro) * 100);
        }
    }

    $validFromValue = $validFrom !== '' ? $validFrom : null;
    $validUntilValue = $validUntil !== '' ? $validUntil : null;
    if ($validFromValue && $validUntilValue && $validFromValue > $validUntilValue) {
        $errors[] = '"Gültig ab" muss vor "Gültig bis" liegen.';
    }

    if (!$errors) {
        db()->beginTransaction();

        if ($promotionId > 0) {
            $stmt = db()->prepare(
                'UPDATE promotions SET name = ?, discount_type = ?, discount_value = ?, scope = ?,
                 valid_from = ?, valid_until = ?, is_active = ? WHERE id = ?'
            );
            $stmt->execute([$name, $discountType, $discountValue, $scope, $validFromValue, $validUntilValue, $isActive ? 1 : 0, $promotionId]);
        } else {
            $stmt = db()->prepare(
                'INSERT INTO promotions (name, discount_type, discount_value, scope, valid_from, valid_until, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $discountType, $discountValue, $scope, $validFromValue, $validUntilValue, $isActive ? 1 : 0]);
            $promotionId = (int) db()->lastInsertId();
        }

        db()->prepare('DELETE FROM promotion_extras WHERE promotion_id = ?')->execute([$promotionId]);
        if ($scope === 'extras') {
            $ins = db()->prepare('INSERT INTO promotion_extras (promotion_id, extra_id) VALUES (?, ?)');
            foreach ($postedExtraIds as $extraId) {
                $ins->execute([$promotionId, $extraId]);
            }
        }

        db()->commit();

        header('Location: promotions.php');
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

    <form method="post" action="promotion_form.php">
        <?= csrf_field() ?>
        <?php if ($promotionId > 0): ?>
            <input type="hidden" name="id" value="<?= $promotionId ?>">
        <?php endif; ?>

        <label>Name * <span class="muted-text">(für den Admin, erscheint auch als Rechnungszeile)</span>
            <input type="text" name="name" required value="<?= htmlspecialchars($name, ENT_QUOTES) ?>" placeholder="z.B. Sommer-Aktion">
        </label>

        <div class="grid3">
            <label>Rabatt-Typ
                <select name="discount_type" id="discount-type-select">
                    <option value="percent" <?= $discountType === 'percent' ? 'selected' : '' ?>>Prozent</option>
                    <option value="fixed" <?= $discountType === 'fixed' ? 'selected' : '' ?>>Fester Betrag (EUR)</option>
                </select>
            </label>
            <label id="discount-percent-field">Rabatt in %
                <input type="number" name="discount_percent" min="1" max="100" value="<?= htmlspecialchars($discountPercent, ENT_QUOTES) ?>">
            </label>
            <label id="discount-fixed-field">Rabatt in EUR
                <input type="text" name="discount_value_euro" value="<?= htmlspecialchars($discountValueEuro, ENT_QUOTES) ?>">
            </label>
        </div>

        <label>Geltungsbereich
            <select name="scope" id="scope-select">
                <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>Alles (Basispreis + alle Extras)</option>
                <option value="extras" <?= $scope === 'extras' ? 'selected' : '' ?>>Nur bestimmte Extras</option>
            </select>
        </label>

        <div id="extras-field">
            <p class="muted-text">Welche Extras sind von dieser Aktion betroffen:</p>
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

        <div class="grid3">
            <label>Gültig ab (optional, sonst sofort)
                <input type="date" name="valid_from" value="<?= htmlspecialchars($validFrom, ENT_QUOTES) ?>">
            </label>
            <label>Gültig bis (optional, sonst unbegrenzt)
                <input type="date" name="valid_until" value="<?= htmlspecialchars($validUntil, ENT_QUOTES) ?>">
            </label>
        </div>

        <label class="checkbox">
            <input type="checkbox" name="is_active" <?= $isActive ? 'checked' : '' ?>>
            Aktiv
        </label>

        <button type="submit">Speichern</button>
        <a href="promotions.php" class="button-secondary">Abbrechen</a>
    </form>
</section>

<script>
(function () {
    var typeSelect = document.getElementById('discount-type-select');
    var percentField = document.getElementById('discount-percent-field');
    var fixedField = document.getElementById('discount-fixed-field');
    function syncType() {
        // display:none per Inline-Style statt [hidden]-Attribut - letzteres
        // wird vom generischen "label { display: block }"-Regel in
        // style.css ueberschrieben (Autoren-Herkunft schlaegt User-Agent-
        // Stylesheet unabhaengig von Spezifitaet).
        percentField.style.display = typeSelect.value === 'percent' ? '' : 'none';
        fixedField.style.display = typeSelect.value === 'fixed' ? '' : 'none';
    }
    typeSelect.addEventListener('change', syncType);
    syncType();

    var scopeSelect = document.getElementById('scope-select');
    var extrasField = document.getElementById('extras-field');
    function syncScope() {
        extrasField.style.display = scopeSelect.value === 'extras' ? '' : 'none';
    }
    scopeSelect.addEventListener('change', syncScope);
    syncScope();
})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>

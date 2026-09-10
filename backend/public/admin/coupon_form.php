<?php
declare(strict_types=1);

$pageTitle = 'Gutschein';
require __DIR__ . '/_header.php';

$couponId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$coupon = null;

if ($couponId > 0) {
    $stmt = db()->prepare('SELECT * FROM coupons WHERE id = ?');
    $stmt->execute([$couponId]);
    $coupon = $stmt->fetch();
    if (!$coupon) {
        http_response_code(404);
        echo '<p>Gutschein nicht gefunden.</p>';
        require __DIR__ . '/_footer.php';
        exit;
    }
}

$errors = [];

$code = (string) ($_POST['code'] ?? $coupon['code'] ?? '');
$discountType = (string) ($_POST['discount_type'] ?? $coupon['discount_type'] ?? 'percent');
$discountValueEuro = isset($_POST['discount_value_euro'])
    ? (string) $_POST['discount_value_euro']
    : ($coupon && $coupon['discount_type'] === 'fixed' ? number_format($coupon['discount_value'] / 100, 2, '.', '') : '');
$discountPercent = (string) ($_POST['discount_percent'] ?? ($coupon && $coupon['discount_type'] === 'percent' ? (string) $coupon['discount_value'] : ''));
$maxRedemptions = (string) ($_POST['max_redemptions'] ?? $coupon['max_redemptions'] ?? '');
$validUntil = (string) ($_POST['valid_until'] ?? $coupon['valid_until'] ?? '');
$isActive = isset($_POST['is_active']) ? true : (bool) ($coupon['is_active'] ?? true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $code = strtoupper(trim($code));
    if ($code === '' || !preg_match('/^[A-Z0-9_-]+$/', $code)) {
        $errors[] = 'Bitte einen Code nur aus Buchstaben, Ziffern, "-" und "_" angeben.';
    }
    if (!in_array($discountType, ['percent', 'fixed'], true)) {
        $errors[] = 'Ungültiger Rabatt-Typ.';
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

    $maxRedemptionsValue = $maxRedemptions !== '' ? (int) $maxRedemptions : null;
    $validUntilValue = $validUntil !== '' ? $validUntil : null;

    if (!$errors) {
        $stmt = db()->prepare('SELECT id FROM coupons WHERE code = ? AND id <> ?');
        $stmt->execute([$code, $couponId]);
        if ($stmt->fetch()) {
            $errors[] = 'Dieser Code existiert bereits.';
        }
    }

    if (!$errors) {
        if ($couponId > 0) {
            $stmt = db()->prepare(
                'UPDATE coupons SET code = ?, discount_type = ?, discount_value = ?,
                 max_redemptions = ?, valid_until = ?, is_active = ? WHERE id = ?'
            );
            $stmt->execute([$code, $discountType, $discountValue, $maxRedemptionsValue, $validUntilValue, $isActive ? 1 : 0, $couponId]);
        } else {
            $stmt = db()->prepare(
                'INSERT INTO coupons (code, discount_type, discount_value, max_redemptions, valid_until, is_active)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$code, $discountType, $discountValue, $maxRedemptionsValue, $validUntilValue, $isActive ? 1 : 0]);
        }

        header('Location: coupons.php');
        exit;
    }
}
?>

<section class="panel">
    <?php foreach ($errors as $err): ?>
        <p class="error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
    <?php endforeach; ?>

    <form method="post" action="coupon_form.php">
        <?= csrf_field() ?>
        <?php if ($couponId > 0): ?>
            <input type="hidden" name="id" value="<?= $couponId ?>">
        <?php endif; ?>

        <label>Code *
            <input type="text" name="code" required value="<?= htmlspecialchars($code, ENT_QUOTES) ?>" placeholder="SOMMER10" style="text-transform:uppercase;">
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

        <div class="grid3">
            <label>Max. Einlösungen (optional)
                <input type="number" name="max_redemptions" min="1" value="<?= htmlspecialchars($maxRedemptions, ENT_QUOTES) ?>">
            </label>
            <label>Gültig bis (optional)
                <input type="date" name="valid_until" value="<?= htmlspecialchars($validUntil, ENT_QUOTES) ?>">
            </label>
        </div>

        <label class="checkbox">
            <input type="checkbox" name="is_active" <?= $isActive ? 'checked' : '' ?>>
            Aktiv (im Buchungsassistenten einlösbar)
        </label>

        <button type="submit">Speichern</button>
        <a href="coupons.php" class="button-secondary">Abbrechen</a>
    </form>
</section>

<script>
(function () {
    var select = document.getElementById('discount-type-select');
    var percentField = document.getElementById('discount-percent-field');
    var fixedField = document.getElementById('discount-fixed-field');
    function sync() {
        percentField.hidden = select.value !== 'percent';
        fixedField.hidden = select.value !== 'fixed';
    }
    select.addEventListener('change', sync);
    sync();
})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>

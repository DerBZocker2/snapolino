<?php
declare(strict_types=1);

$pageTitle = 'Gutscheine';
require __DIR__ . '/_header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    db()->prepare('UPDATE coupons SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
    header('Location: coupons.php');
    exit;
}

$coupons = db()->query('SELECT * FROM coupons ORDER BY created_at DESC')->fetchAll();
?>

<p><a href="coupon_form.php" class="button">+ Neuer Gutschein</a></p>

<section class="panel">
    <table>
        <thead>
        <tr>
            <th>Code</th>
            <th>Rabatt</th>
            <th>Eingelöst</th>
            <th>Gültig bis</th>
            <th>Status</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($coupons as $coupon): ?>
            <?php
            $expired = $coupon['valid_until'] && $coupon['valid_until'] < date('Y-m-d');
            $exhausted = $coupon['max_redemptions'] !== null && (int) $coupon['redemption_count'] >= (int) $coupon['max_redemptions'];
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($coupon['code'], ENT_QUOTES) ?></strong></td>
                <td><?= $coupon['discount_type'] === 'percent' ? (int) $coupon['discount_value'] . ' %' : money_from_cents((int) $coupon['discount_value']) ?></td>
                <td><?= (int) $coupon['redemption_count'] ?><?= $coupon['max_redemptions'] !== null ? ' / ' . (int) $coupon['max_redemptions'] : '' ?></td>
                <td><?= $coupon['valid_until'] ? htmlspecialchars($coupon['valid_until'], ENT_QUOTES) : '—' ?></td>
                <td>
                    <?php if (!$coupon['is_active']): ?>
                        <span class="muted-text">deaktiviert</span>
                    <?php elseif ($expired): ?>
                        <span class="muted-text">abgelaufen</span>
                    <?php elseif ($exhausted): ?>
                        <span class="muted-text">ausgeschöpft</span>
                    <?php else: ?>
                        <span class="badge">aktiv</span>
                    <?php endif; ?>
                </td>
                <td class="actions">
                    <a href="coupon_form.php?id=<?= (int) $coupon['id'] ?>">Bearbeiten</a>
                    <form method="post" action="coupons.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="id" value="<?= (int) $coupon['id'] ?>">
                        <button type="submit"><?= $coupon['is_active'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$coupons): ?>
            <tr><td colspan="6">Noch keine Gutscheine angelegt.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

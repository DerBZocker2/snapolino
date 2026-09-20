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

// Automatisch generierte Empfehlungscodes (persoenlicher Code pro Buchung
// sowie Belohnungsgutscheine, siehe "Empfehlungsprogramm" in CLAUDE.md)
// koennen schnell zahlreich werden - standardmaessig ausgeblendet, damit die
// selbst angelegten Gutscheine uebersichtlich bleiben.
$showReferral = isset($_GET['referral']);
$referralFilter = "referral_owner_booking_id IS NOT NULL OR code LIKE 'DANKE-%'";
$coupons = db()->query(
    'SELECT * FROM coupons' . ($showReferral ? '' : " WHERE NOT ($referralFilter)") . ' ORDER BY created_at DESC'
)->fetchAll();
$referralCount = (int) db()->query("SELECT COUNT(*) FROM coupons WHERE $referralFilter")->fetchColumn();
?>

<p><a href="coupon_form.php" class="button">+ Neuer Gutschein</a></p>

<?php if ($referralCount > 0): ?>
    <p class="muted-text">
        <?php if ($showReferral): ?>
            Zeigt auch die <?= $referralCount ?> automatisch generierten Empfehlungscodes.
            <a href="coupons.php">Nur eigene Gutscheine anzeigen</a>
        <?php else: ?>
            <?= $referralCount ?> automatisch generierte Empfehlungscodes ausgeblendet.
            <a href="coupons.php?referral=1">Alle anzeigen</a>
        <?php endif; ?>
    </p>
<?php endif; ?>

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
                <td>
                    <strong><?= htmlspecialchars($coupon['code'], ENT_QUOTES) ?></strong>
                    <?php if ($coupon['referral_owner_booking_id']): ?>
                        <br><a class="muted-text" href="booking_detail.php?id=<?= (int) $coupon['referral_owner_booking_id'] ?>">Empfehlungscode von Buchung #<?= (int) $coupon['referral_owner_booking_id'] ?></a>
                    <?php elseif (str_starts_with((string) $coupon['code'], 'DANKE-')): ?>
                        <br><span class="muted-text">Empfehlungs-Prämie</span>
                    <?php endif; ?>
                </td>
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
                    <div class="actions-row">
                        <a href="coupon_form.php?id=<?= (int) $coupon['id'] ?>">Bearbeiten</a>
                        <form method="post" action="coupons.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= (int) $coupon['id'] ?>">
                            <button type="submit"><?= $coupon['is_active'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
                        </form>
                    </div>
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

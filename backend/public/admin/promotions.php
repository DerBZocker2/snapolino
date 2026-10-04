<?php
declare(strict_types=1);

$pageTitle = 'Rabattaktionen';
require __DIR__ . '/_header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    db()->prepare('UPDATE promotions SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
    header('Location: promotions.php');
    exit;
}

$promotions = fetch_all_promotions();
$extraNamesById = [];
foreach (fetch_all_extras() as $extra) {
    $extraNamesById[(int) $extra['id']] = $extra['name'];
}

$today = date('Y-m-d');
?>

<p><a href="promotion_form.php" class="button">+ Neue Rabattaktion</a></p>
<p class="muted-text">Automatisch angewendet (kein Code nötig) - anders als <a href="coupons.php">Gutscheine</a>,
    die der Kunde selbst eingeben muss. Im Buchungsassistenten auffällig als Banner markiert.</p>

<section class="panel">
    <table>
        <thead>
        <tr>
            <th>Name</th>
            <th>Rabatt</th>
            <th>Geltungsbereich</th>
            <th>Zeitraum</th>
            <th>Status</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($promotions as $promotion): ?>
            <?php
            $notYetStarted = $promotion['valid_from'] && $promotion['valid_from'] > $today;
            $expired = $promotion['valid_until'] && $promotion['valid_until'] < $today;
            if ($promotion['scope'] === 'extras') {
                $extraIds = fetch_promotion_extra_ids((int) $promotion['id']);
                $scopeLabel = $extraIds
                    ? implode(', ', array_map(static fn (int $id) => $extraNamesById[$id] ?? '?', $extraIds))
                    : 'keine Extras ausgewählt';
            } else {
                $scopeLabel = 'Alles (Basispreis + Extras)';
            }
            ?>
            <tr>
                <td><?= htmlspecialchars($promotion['name'], ENT_QUOTES) ?></td>
                <td><?= $promotion['discount_type'] === 'percent' ? (int) $promotion['discount_value'] . ' %' : money_from_cents((int) $promotion['discount_value']) ?></td>
                <td><?= htmlspecialchars($scopeLabel, ENT_QUOTES) ?></td>
                <td>
                    <?= $promotion['valid_from'] ? htmlspecialchars($promotion['valid_from'], ENT_QUOTES) : 'sofort' ?>
                    &ndash;
                    <?= $promotion['valid_until'] ? htmlspecialchars($promotion['valid_until'], ENT_QUOTES) : 'unbegrenzt' ?>
                </td>
                <td>
                    <?php if (!$promotion['is_active']): ?>
                        <span class="muted-text">deaktiviert</span>
                    <?php elseif ($notYetStarted): ?>
                        <span class="muted-text">startet noch</span>
                    <?php elseif ($expired): ?>
                        <span class="muted-text">abgelaufen</span>
                    <?php else: ?>
                        <span class="badge">aktiv</span>
                    <?php endif; ?>
                </td>
                <td class="actions">
                    <div class="actions-row">
                        <a href="promotion_form.php?id=<?= (int) $promotion['id'] ?>">Bearbeiten</a>
                        <form method="post" action="promotions.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= (int) $promotion['id'] ?>">
                            <button type="submit"><?= $promotion['is_active'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$promotions): ?>
            <tr><td colspan="6">Noch keine Rabattaktionen angelegt.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

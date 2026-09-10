<?php
declare(strict_types=1);

$pageTitle = 'Extras';
require __DIR__ . '/_header.php';

$extras = fetch_all_extras();
?>

<p><a href="extra_form.php" class="button">+ Neues Extra</a></p>

<section class="panel">
    <table>
        <thead>
        <tr>
            <th></th>
            <th>Name</th>
            <th>Typ</th>
            <th>Preis</th>
            <th>Aktiv</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($extras as $extra): ?>
            <tr>
                <td><?= htmlspecialchars((string) $extra['icon'], ENT_QUOTES) ?></td>
                <td>
                    <?= htmlspecialchars($extra['name'], ENT_QUOTES) ?>
                    <?php if ($extra['description']): ?>
                        <br><span class="muted-text"><?= htmlspecialchars($extra['description'], ENT_QUOTES) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $extra['type'] === 'quantity' ? 'Menge (' . htmlspecialchars((string) $extra['unit_label'], ENT_QUOTES) . ')' : 'Ein/Aus' ?></td>
                <td><?= ($extra['price_cents'] >= 0 ? '+' : '') . money_from_cents((int) $extra['price_cents']) ?></td>
                <td><?= $extra['is_active'] ? '<span class="badge">aktiv</span>' : '<span class="muted-text">inaktiv</span>' ?></td>
                <td class="actions">
                    <a href="extra_form.php?id=<?= (int) $extra['id'] ?>">Bearbeiten</a>
                    <form method="post" action="extra_delete.php" onsubmit="return confirm('Extra wirklich löschen?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $extra['id'] ?>">
                        <button type="submit" class="danger">Löschen</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$extras): ?>
            <tr><td colspan="6">Noch keine Extras angelegt.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

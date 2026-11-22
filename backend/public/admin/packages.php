<?php
declare(strict_types=1);

$pageTitle = 'Pakete';
require __DIR__ . '/_header.php';

$packages = fetch_all_packages();
?>

<p><a href="package_form.php" class="button">+ Neues Paket</a></p>

<section class="panel">
    <table>
        <thead>
        <tr>
            <th>Name</th>
            <th>Preis</th>
            <th>Inklusive Extras</th>
            <th>Aktiv</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($packages as $package): ?>
            <?php
            $includedExtraIds = package_included_extra_ids((int) $package['id']);
            $includedNames = [];
            if ($includedExtraIds) {
                $placeholders = implode(',', array_fill(0, count($includedExtraIds), '?'));
                $stmt = db()->prepare("SELECT name FROM extras WHERE id IN ($placeholders)");
                $stmt->execute($includedExtraIds);
                $includedNames = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            ?>
            <tr>
                <td>
                    <?= htmlspecialchars($package['name'], ENT_QUOTES) ?>
                    <?php if ($package['description']): ?>
                        <br><span class="muted-text"><?= htmlspecialchars($package['description'], ENT_QUOTES) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= money_from_cents((int) $package['price_cents']) ?></td>
                <td><?= $includedNames ? htmlspecialchars(implode(', ', $includedNames), ENT_QUOTES) : '—' ?></td>
                <td><?= $package['is_active'] ? '<span class="badge">aktiv</span>' : '<span class="muted-text">inaktiv</span>' ?></td>
                <td class="actions">
                    <div class="actions-row">
                        <a href="package_form.php?id=<?= (int) $package['id'] ?>">Bearbeiten</a>
                        <form method="post" action="package_delete.php" onsubmit="return confirm('Paket wirklich löschen? Buchungen mit diesem Paket zeigen danach keinen Paketnamen mehr.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $package['id'] ?>">
                            <button type="submit" class="danger">Löschen</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$packages): ?>
            <tr><td colspan="5">Noch keine Pakete angelegt.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

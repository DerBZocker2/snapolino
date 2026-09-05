<?php
declare(strict_types=1);

$pageTitle = 'Layouts';
require __DIR__ . '/_header.php';

$layouts = db()->query('SELECT * FROM layouts ORDER BY is_default DESC, name')->fetchAll();
?>

<p><a href="layout_form.php" class="button">+ Neues Layout</a></p>

<section class="panel">
    <table>
        <thead>
        <tr>
            <th></th>
            <th>Name</th>
            <th>Bilder</th>
            <th>Leinwand</th>
            <th>Aufpreis</th>
            <th>Rahmen-Datei</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($layouts as $layout): ?>
            <tr>
                <td>
                    <?php if ($layout['is_default']): ?><span class="badge">Standard</span><?php endif; ?>
                </td>
                <td><?= htmlspecialchars($layout['name'], ENT_QUOTES) ?></td>
                <td><?= (int) $layout['slot_count'] ?></td>
                <td><?= (int) $layout['canvas_width'] ?>&times;<?= (int) $layout['canvas_height'] ?> px</td>
                <td><?= money_from_cents((int) $layout['surcharge_cents']) ?></td>
                <td><code><?= htmlspecialchars($layout['frame_file'], ENT_QUOTES) ?></code></td>
                <td class="actions">
                    <a href="layout_form.php?id=<?= (int) $layout['id'] ?>">Bearbeiten</a>
                    <form method="post" action="layout_delete.php" onsubmit="return confirm('Layout wirklich loeschen? Boxen, denen es zugeordnet ist, verlieren es sofort.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $layout['id'] ?>">
                        <button type="submit" class="danger">Loeschen</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$layouts): ?>
            <tr><td colspan="7">Noch keine Layouts angelegt.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

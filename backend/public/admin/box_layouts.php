<?php
declare(strict_types=1);

$pageTitle = 'Box: Layouts & Zugang';
require __DIR__ . '/_header.php';

$boxId = (int) ($_GET['id'] ?? $_POST['box_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM boxes WHERE id = ?');
$stmt->execute([$boxId]);
$box = $stmt->fetch();

if (!$box) {
    http_response_code(404);
    echo '<p>Box nicht gefunden.</p>';
    require __DIR__ . '/_footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $selected = array_map('intval', $_POST['layout_ids'] ?? []);

    db()->beginTransaction();
    $del = db()->prepare('DELETE FROM box_layouts WHERE box_id = ?');
    $del->execute([$boxId]);

    $ins = db()->prepare(
        'INSERT INTO box_layouts (box_id, layout_id, sort_order) VALUES (?, ?, ?)'
    );
    foreach (array_values($selected) as $order => $layoutId) {
        $ins->execute([$boxId, $layoutId, $order]);
    }
    bump_box_version($boxId);
    db()->commit();

    header('Location: box_layouts.php?id=' . $boxId);
    exit;
}

$layouts = db()->query('SELECT * FROM layouts ORDER BY is_default DESC, name')->fetchAll();

$stmt = db()->prepare('SELECT layout_id FROM box_layouts WHERE box_id = ?');
$stmt->execute([$boxId]);
$assigned = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

// Box-Daten fuer die neue Auslesung nach dem Speichern.
$stmt = db()->prepare('SELECT * FROM boxes WHERE id = ?');
$stmt->execute([$boxId]);
$box = $stmt->fetch();
?>

<section class="panel">
    <h2><?= htmlspecialchars($box['name'], ENT_QUOTES) ?></h2>
    <p>Diese Werte gehoeren in die <code>box.ini</code> auf der Box, bevor sie versendet wird:</p>
    <table class="key-table">
        <tr><th>box_key</th><td><code><?= htmlspecialchars($box['box_key'], ENT_QUOTES) ?></code></td></tr>
        <tr><th>api_key</th><td><code><?= htmlspecialchars($box['api_key'], ENT_QUOTES) ?></code></td></tr>
        <tr><th>Aktuelle Version</th><td><?= (int) $box['config_version'] ?></td></tr>
    </table>
</section>

<section class="panel">
    <h2>Zugeordnete Layouts</h2>
    <p>Standardmaessig ist die 4er-Collage aktiv. Weitere Formate nur ankreuzen,
        wenn der Kunde sie bei der Buchung dazugebucht hat.</p>
    <form method="post" action="box_layouts.php">
        <?= csrf_field() ?>
        <input type="hidden" name="box_id" value="<?= (int) $box['id'] ?>">
        <table>
            <thead>
            <tr>
                <th></th>
                <th>Name</th>
                <th>Bilder</th>
                <th>Leinwand</th>
                <th>Aufpreis</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($layouts as $layout): ?>
                <tr>
                    <td>
                        <input type="checkbox" name="layout_ids[]" value="<?= (int) $layout['id'] ?>"
                            <?= in_array((int) $layout['id'], $assigned, true) ? 'checked' : '' ?>>
                    </td>
                    <td>
                        <?= htmlspecialchars($layout['name'], ENT_QUOTES) ?>
                        <?php if ($layout['is_default']): ?><span class="badge">Standard</span><?php endif; ?>
                    </td>
                    <td><?= (int) $layout['slot_count'] ?></td>
                    <td><?= (int) $layout['canvas_width'] ?>&times;<?= (int) $layout['canvas_height'] ?> px</td>
                    <td><?= money_from_cents((int) $layout['surcharge_cents']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$layouts): ?>
                <tr><td colspan="5">Noch keine Layouts angelegt. Siehe <a href="layouts.php">Layouts</a>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <button type="submit">Zuordnung speichern</button>
    </form>
</section>

<p><a href="boxes.php">&larr; zurueck zur Boxenliste</a></p>

<?php require __DIR__ . '/_footer.php'; ?>

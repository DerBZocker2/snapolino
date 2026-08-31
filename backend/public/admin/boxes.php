<?php
declare(strict_types=1);

$pageTitle = 'Boxen';
require __DIR__ . '/_header.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $name = trim((string) ($_POST['name'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));

    if ($name === '') {
        $error = 'Bitte einen Namen fuer die Box angeben.';
    } else {
        $boxKey = random_key(8);
        $apiKey = random_key(24);

        db()->beginTransaction();
        $stmt = db()->prepare(
            'INSERT INTO boxes (box_key, api_key, name, note) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$boxKey, $apiKey, $name, $note !== '' ? $note : null]);
        $boxId = (int) db()->lastInsertId();

        // Standard-Layout automatisch zuordnen, damit jede Box sofort
        // die 4er-Collage ausliefern kann.
        $stmt = db()->prepare('SELECT id FROM layouts WHERE is_default = 1 ORDER BY id LIMIT 1');
        $stmt->execute();
        $defaultLayoutId = $stmt->fetchColumn();
        if ($defaultLayoutId !== false) {
            $stmt = db()->prepare(
                'INSERT INTO box_layouts (box_id, layout_id, sort_order) VALUES (?, ?, 0)'
            );
            $stmt->execute([$boxId, (int) $defaultLayoutId]);
        }
        db()->commit();

        header('Location: box_layouts.php?id=' . $boxId);
        exit;
    }
}

$boxes = db()->query('SELECT * FROM boxes ORDER BY created_at DESC')->fetchAll();
?>

<section class="panel">
    <h2>Neue Box anlegen</h2>
    <?php if ($error !== ''): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <form method="post" action="boxes.php" class="inline-form">
        <?= csrf_field() ?>
        <label>Name
            <input type="text" name="name" required placeholder="z.B. Box 3 - Hochzeit Mueller">
        </label>
        <label>Notiz
            <input type="text" name="note" placeholder="optional">
        </label>
        <button type="submit">Anlegen</button>
    </form>
</section>

<section class="panel">
    <h2>Vorhandene Boxen</h2>
    <table>
        <thead>
        <tr>
            <th>Name</th>
            <th>Box-Key</th>
            <th>Version</th>
            <th>Notiz</th>
            <th>Angelegt</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($boxes as $box): ?>
            <tr>
                <td><?= htmlspecialchars($box['name'], ENT_QUOTES) ?></td>
                <td><code><?= htmlspecialchars($box['box_key'], ENT_QUOTES) ?></code></td>
                <td><?= (int) $box['config_version'] ?></td>
                <td><?= htmlspecialchars((string) $box['note'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($box['created_at'], ENT_QUOTES) ?></td>
                <td class="actions">
                    <a href="box_layouts.php?id=<?= (int) $box['id'] ?>">Layouts &amp; Zugang</a>
                    <form method="post" action="box_delete.php" onsubmit="return confirm('Box wirklich loeschen?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $box['id'] ?>">
                        <button type="submit" class="danger">Loeschen</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$boxes): ?>
            <tr><td colspan="6">Noch keine Box angelegt.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

<?php
declare(strict_types=1);

$pageTitle = 'Bewertungen';
require __DIR__ . '/_header.php';

try {
    db()->query('SELECT 1 FROM testimonials LIMIT 1');
} catch (PDOException $e) {
    echo '<p class="error">Die Bewertungstabelle fehlt noch. Einmalig ausführen: '
        . '<code>mysql -u snapolino -p snapolino &lt; backend/sql/migrations/0025_testimonials.sql</code></p>';
    require __DIR__ . '/_footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    db()->prepare('UPDATE testimonials SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
    header('Location: testimonials.php');
    exit;
}

$testimonials = fetch_all_testimonials();
?>

<p><a href="testimonial_form.php" class="button">+ Neue Bewertung</a></p>
<p class="muted-text">Erscheinen auf der Startseite unter "Das sagen unsere Kunden" - der Abschnitt wird nur
    angezeigt, wenn mindestens eine Bewertung hier aktiv ist. Nur echte Kundenstimmen eintragen, z.B. Antworten
    auf die automatische Bewertungsanfrage nach dem Event.</p>

<section class="panel">
    <table>
        <thead>
        <tr>
            <th>Name</th>
            <th>Anlass</th>
            <th>Sterne</th>
            <th>Zitat</th>
            <th>Status</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($testimonials as $t): ?>
            <tr>
                <td><?= htmlspecialchars($t['customer_name'], ENT_QUOTES) ?></td>
                <td><?= $t['event_type'] ? htmlspecialchars($t['event_type'], ENT_QUOTES) : '—' ?></td>
                <td><?= str_repeat('★', (int) $t['rating']) . str_repeat('☆', 5 - (int) $t['rating']) ?></td>
                <td class="muted-text"><?= htmlspecialchars(mb_strimwidth($t['quote'], 0, 80, '…'), ENT_QUOTES) ?></td>
                <td>
                    <?php if ($t['is_active']): ?>
                        <span class="badge">aktiv</span>
                    <?php else: ?>
                        <span class="muted-text">inaktiv</span>
                    <?php endif; ?>
                </td>
                <td class="actions">
                    <div class="actions-row">
                        <a href="testimonial_form.php?id=<?= (int) $t['id'] ?>">Bearbeiten</a>
                        <form method="post" action="testimonials.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <button type="submit"><?= $t['is_active'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
                        </form>
                        <form method="post" action="testimonial_delete.php" onsubmit="return confirm('Bewertung wirklich löschen?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <button type="submit" class="danger">Löschen</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$testimonials): ?>
            <tr><td colspan="6">Noch keine Bewertungen angelegt.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

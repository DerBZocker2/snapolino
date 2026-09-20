<?php
declare(strict_types=1);

$pageTitle = 'Warteliste';
require __DIR__ . '/_header.php';

try {
    db()->query('SELECT 1 FROM waitlist_entries LIMIT 1');
} catch (PDOException $e) {
    echo '<p class="error">Die Wartelisten-Tabelle fehlt noch. Einmalig ausführen: '
        . '<code>mysql -u snapolino -p snapolino &lt; backend/sql/migrations/0020_waitlist.sql</code></p>';
    require __DIR__ . '/_footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $entryId = (int) ($_POST['entry_id'] ?? 0);
    if ((string) ($_POST['action'] ?? '') === 'delete') {
        db()->prepare('DELETE FROM waitlist_entries WHERE id = ?')->execute([$entryId]);
    }
    header('Location: waitlist.php');
    exit;
}

$entries = db()->query('SELECT * FROM waitlist_entries ORDER BY event_date ASC, created_at ASC')->fetchAll();
?>

<section class="panel">
    <p class="muted-text">Interessenten für bereits ausgebuchte Termine (siehe <code>/warteliste.php</code>). Wird ein
        Termin durch Ablehnen/Stornieren einer Buchung wieder frei, bekommen alle noch nicht benachrichtigten
        Einträge dieses Tages automatisch eine Mail.</p>

    <div class="table-responsive">
    <table>
        <thead>
        <tr>
            <th>Wunschtermin</th>
            <th>Name</th>
            <th>Kontakt</th>
            <th>Nachricht</th>
            <th>Status</th>
            <th>Eingetragen am</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $entry): ?>
            <tr>
                <td class="nowrap"><?= htmlspecialchars((new DateTimeImmutable($entry['event_date']))->format('d.m.Y'), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($entry['customer_name'], ENT_QUOTES) ?></td>
                <td>
                    <a href="mailto:<?= htmlspecialchars($entry['customer_email'], ENT_QUOTES) ?>"><?= htmlspecialchars($entry['customer_email'], ENT_QUOTES) ?></a>
                    <?php if ($entry['customer_phone']): ?>
                        <br><span class="muted-text"><?= htmlspecialchars($entry['customer_phone'], ENT_QUOTES) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $entry['note'] ? htmlspecialchars($entry['note'], ENT_QUOTES) : '—' ?></td>
                <td class="nowrap">
                    <?php if ($entry['notified_at']): ?>
                        <span class="badge">Benachrichtigt</span>
                    <?php else: ?>
                        <span class="badge badge-warning">Wartet</span>
                    <?php endif; ?>
                </td>
                <td class="nowrap"><?= htmlspecialchars((new DateTimeImmutable($entry['created_at']))->format('d.m.Y'), ENT_QUOTES) ?></td>
                <td class="actions">
                    <div class="actions-row">
                        <form method="post" action="waitlist.php" onsubmit="return confirm('Eintrag wirklich löschen?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" class="danger">Löschen</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$entries): ?>
            <tr><td colspan="7">Noch keine Wartelisten-Einträge.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

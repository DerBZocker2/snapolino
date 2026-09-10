<?php
declare(strict_types=1);

$pageTitle = 'Buchungsdetails';
require __DIR__ . '/_header.php';

$bookingId = (int) ($_GET['id'] ?? $_POST['booking_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    http_response_code(404);
    echo '<p>Buchung nicht gefunden.</p>';
    require __DIR__ . '/_footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $note = trim((string) ($_POST['admin_note'] ?? ''));
    $stmt = db()->prepare('UPDATE bookings SET admin_note = ? WHERE id = ?');
    $stmt->execute([$note !== '' ? $note : null, $bookingId]);
    header('Location: booking_detail.php?id=' . $bookingId);
    exit;
}

$stmt = db()->prepare(
    'SELECT l.name, l.surcharge_cents FROM booking_layouts bl
     INNER JOIN layouts l ON l.id = bl.layout_id WHERE bl.booking_id = ?'
);
$stmt->execute([$bookingId]);
$layouts = $stmt->fetchAll();

$stmt = db()->prepare(
    'SELECT e.name, e.icon, e.unit_label, e.price_cents, be.quantity FROM booking_extras be
     INNER JOIN extras e ON e.id = be.extra_id WHERE be.booking_id = ?'
);
$stmt->execute([$bookingId]);
$extras = $stmt->fetchAll();

$boxName = null;
if ($booking['box_id']) {
    $stmt = db()->prepare('SELECT name FROM boxes WHERE id = ?');
    $stmt->execute([$booking['box_id']]);
    $boxName = $stmt->fetchColumn() ?: null;
}
?>

<p><a href="bookings.php">&larr; zurück zu den Buchungen</a></p>

<section class="panel">
    <h2>
        <?= htmlspecialchars($booking['customer_name'], ENT_QUOTES) ?>
        <span class="status-pill status-<?= htmlspecialchars($booking['status'], ENT_QUOTES) ?>">
            <?= htmlspecialchars(booking_status_label($booking['status']), ENT_QUOTES) ?>
        </span>
    </h2>
    <table class="key-table">
        <tr><th>Eventdatum</th><td><?= htmlspecialchars($booking['event_date'], ENT_QUOTES) ?></td></tr>
        <tr><th>E-Mail</th><td><a href="mailto:<?= htmlspecialchars($booking['customer_email'], ENT_QUOTES) ?>"><?= htmlspecialchars($booking['customer_email'], ENT_QUOTES) ?></a></td></tr>
        <tr><th>Telefon</th><td><?= htmlspecialchars((string) $booking['customer_phone'], ENT_QUOTES) ?: '—' ?></td></tr>
        <tr><th>Versandadresse</th><td><?= nl2br(htmlspecialchars($booking['customer_address'], ENT_QUOTES)) ?></td></tr>
        <tr><th>Gewünschte Layouts</th><td>
            <?php foreach ($layouts as $layout): ?>
                <?= htmlspecialchars($layout['name'], ENT_QUOTES) ?>
                <?php if ($layout['surcharge_cents']): ?>(+<?= money_from_cents((int) $layout['surcharge_cents']) ?>)<?php endif; ?><br>
            <?php endforeach; ?>
        </td></tr>
        <tr><th>Extras</th><td>
            <?php if (!$extras): ?>
                —
            <?php else: ?>
                <?php foreach ($extras as $extra): ?>
                    <?= htmlspecialchars((string) $extra['icon'], ENT_QUOTES) ?>
                    <?= htmlspecialchars($extra['name'], ENT_QUOTES) ?>
                    <?php if ((int) $extra['quantity'] > 1): ?>
                        (<?= (int) $extra['quantity'] ?><?= $extra['unit_label'] ? ' ' . htmlspecialchars($extra['unit_label'], ENT_QUOTES) : '' ?>)
                    <?php endif; ?>
                    – <?= money_from_cents((int) $extra['price_cents'] * (int) $extra['quantity']) ?><br>
                <?php endforeach; ?>
            <?php endif; ?>
        </td></tr>
        <tr><th>Gesamtpreis</th><td>
            <?= $booking['total_price_cents'] !== null ? '<strong>' . money_from_cents((int) $booking['total_price_cents']) . '</strong>' : '— (Buchung noch nicht abgeschlossen)' ?>
        </td></tr>
        <tr><th>Schriftliches Angebot gewünscht</th><td><?= $booking['wants_quote'] ? 'Ja' : 'Nein' ?></td></tr>
        <tr><th>Nachricht</th><td><?= $booking['message'] ? nl2br(htmlspecialchars($booking['message'], ENT_QUOTES)) : '—' ?></td></tr>
        <tr><th>Zugeordnete Box</th><td><?= $boxName ? htmlspecialchars($boxName, ENT_QUOTES) : '—' ?></td></tr>
        <tr><th>Angefragt am</th><td><?= htmlspecialchars($booking['created_at'], ENT_QUOTES) ?></td></tr>
    </table>
</section>

<section class="panel">
    <h2>Interne Notiz</h2>
    <form method="post" action="booking_detail.php">
        <?= csrf_field() ?>
        <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
        <textarea name="admin_note" rows="4"><?= htmlspecialchars((string) $booking['admin_note'], ENT_QUOTES) ?></textarea>
        <button type="submit" style="margin-top:10px;">Notiz speichern</button>
    </form>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

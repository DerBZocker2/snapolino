<?php
declare(strict_types=1);

$pageTitle = 'Buchungen';
require __DIR__ . '/_header.php';

try {
    db()->query('SELECT 1 FROM bookings LIMIT 1');
} catch (PDOException $e) {
    echo '<p class="error">Die Buchungstabellen fehlen noch. Einmalig ausführen: '
        . '<code>mysql -u snapolino -p snapolino &lt; backend/sql/migrations/0002_bookings.sql</code></p>';
    require __DIR__ . '/_footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $bookingId = (int) ($_POST['booking_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    $stmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if ($booking) {
        if ($action === 'reject') {
            $stmt = db()->prepare("UPDATE bookings SET status = 'abgelehnt' WHERE id = ?");
            $stmt->execute([$bookingId]);
        } elseif ($action === 'cancel') {
            $stmt = db()->prepare("UPDATE bookings SET status = 'storniert' WHERE id = ?");
            $stmt->execute([$bookingId]);
        }
    }

    header('Location: bookings.php');
    exit;
}

$statusFilter = (string) ($_GET['status'] ?? '');
if ($statusFilter !== '' && in_array($statusFilter, BOOKING_STATUSES, true)) {
    $stmt = db()->prepare(
        "SELECT * FROM bookings WHERE status = ?
         ORDER BY FIELD(status, 'angefragt', 'bestaetigt', 'reserviert', 'abgelehnt', 'storniert'), event_date"
    );
    $stmt->execute([$statusFilter]);
} else {
    $stmt = db()->query(
        "SELECT * FROM bookings
         ORDER BY FIELD(status, 'angefragt', 'bestaetigt', 'reserviert', 'abgelehnt', 'storniert'), event_date"
    );
}
$bookings = $stmt->fetchAll();

$boxes = db()->query('SELECT id, name FROM boxes ORDER BY name')->fetchAll();

$layoutStmt = db()->prepare(
    'SELECT l.name FROM booking_layouts bl INNER JOIN layouts l ON l.id = bl.layout_id WHERE bl.booking_id = ?'
);
?>

<section class="panel">
    <div class="inline-form" style="margin-bottom:16px;">
        <a href="bookings.php" class="button-secondary <?= $statusFilter === '' ? 'active' : '' ?>">Alle</a>
        <?php foreach (BOOKING_STATUSES as $status): ?>
            <a href="bookings.php?status=<?= urlencode($status) ?>" class="button-secondary">
                <?= htmlspecialchars(booking_status_label($status), ENT_QUOTES) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <table>
        <thead>
        <tr>
            <th>Eventdatum</th>
            <th>Kunde</th>
            <th>Layouts</th>
            <th>Preis</th>
            <th>Status</th>
            <th>Box</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($bookings as $booking): ?>
            <?php
            $layoutStmt->execute([$booking['id']]);
            $layoutNames = $layoutStmt->fetchAll(PDO::FETCH_COLUMN);
            $boxName = '';
            if ($booking['box_id']) {
                foreach ($boxes as $box) {
                    if ((int) $box['id'] === (int) $booking['box_id']) {
                        $boxName = $box['name'];
                    }
                }
            }
            ?>
            <tr>
                <td><?= htmlspecialchars($booking['event_date'], ENT_QUOTES) ?></td>
                <td>
                    <?= htmlspecialchars($booking['customer_name'], ENT_QUOTES) ?><br>
                    <span class="muted-text"><?= htmlspecialchars($booking['customer_email'], ENT_QUOTES) ?></span>
                    <?php if ($booking['customer_phone']): ?>
                        <br><span class="muted-text"><?= htmlspecialchars($booking['customer_phone'], ENT_QUOTES) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars(implode(', ', $layoutNames), ENT_QUOTES) ?></td>
                <td><?= $booking['total_price_cents'] !== null ? money_from_cents((int) $booking['total_price_cents']) : '—' ?></td>
                <td><span class="status-pill status-<?= htmlspecialchars($booking['status'], ENT_QUOTES) ?>">
                    <?= htmlspecialchars(booking_status_label($booking['status']), ENT_QUOTES) ?>
                </span></td>
                <td><?= htmlspecialchars($boxName ?: '—', ENT_QUOTES) ?></td>
                <td class="actions">
                    <a href="booking_detail.php?id=<?= (int) $booking['id'] ?>">Details</a>
                    <?php if ($booking['status'] === 'angefragt'): ?>
                        <a href="boxes.php" title="Auf der Boxen-Seite per Drag &amp; Drop einer Box zuordnen, um zu bestätigen">→ Box zuordnen</a>
                        <form method="post" action="bookings.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <button type="submit" class="danger">Ablehnen</button>
                        </form>
                    <?php elseif ($booking['status'] === 'bestaetigt'): ?>
                        <?php if (!$booking['box_id']): ?>
                            <a href="boxes.php" title="Auf der Boxen-Seite per Drag &amp; Drop zuordnen">→ Box zuordnen</a>
                        <?php endif; ?>
                        <form method="post" action="bookings.php" onsubmit="return confirm('Buchung wirklich stornieren?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                            <input type="hidden" name="action" value="cancel">
                            <button type="submit" class="danger">Stornieren</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$bookings): ?>
            <tr><td colspan="7">Keine Buchungen gefunden.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

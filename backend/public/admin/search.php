<?php
declare(strict_types=1);

$pageTitle = 'Suche';
require __DIR__ . '/_header.php';

$query = trim((string) ($_GET['q'] ?? ''));

$bookingResults = [];
$customerResults = [];

if ($query !== '') {
    $like = '%' . $query . '%';

    $stmt = db()->prepare(
        "SELECT * FROM bookings
         WHERE customer_name LIKE ? OR customer_email LIKE ? OR customer_phone LIKE ?
         ORDER BY event_date DESC LIMIT 25"
    );
    $stmt->execute([$like, $like, $like]);
    $bookingResults = $stmt->fetchAll();

    try {
        $stmt = db()->prepare(
            "SELECT * FROM customer_accounts
             WHERE customer_number LIKE ? OR name LIKE ? OR email LIKE ? OR phone LIKE ?
             ORDER BY created_at DESC LIMIT 25"
        );
        $stmt->execute([$like, $like, $like, $like]);
        $customerResults = $stmt->fetchAll();
    } catch (PDOException $e) {
        $customerResults = [];
    }
}
?>

<section class="panel">
    <form method="get" action="search.php" class="inline-form">
        <label style="margin-bottom:0;min-width:280px;">Suchbegriff
            <input type="text" name="q" value="<?= htmlspecialchars($query, ENT_QUOTES) ?>" autofocus
                placeholder="Name, E-Mail, Telefon, Kundennummer…">
        </label>
        <button type="submit">Suchen</button>
    </form>
</section>

<?php if ($query !== ''): ?>
    <section class="panel">
        <h2>Kunden <span class="muted-text">(<?= count($customerResults) ?>)</span></h2>
        <?php if ($customerResults): ?>
            <div class="table-responsive">
            <table>
                <thead><tr><th>Kundennummer</th><th>Name</th><th>Kontakt</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($customerResults as $customer): ?>
                    <tr>
                        <td class="nowrap"><code><?= htmlspecialchars((string) ($customer['customer_number'] ?? '—'), ENT_QUOTES) ?></code></td>
                        <td><?= htmlspecialchars($customer['name'] ?: '(ohne Namen)', ENT_QUOTES) ?></td>
                        <td>
                            <?= htmlspecialchars($customer['email'], ENT_QUOTES) ?>
                            <?php if ($customer['phone']): ?><br><span class="muted-text"><?= htmlspecialchars($customer['phone'], ENT_QUOTES) ?></span><?php endif; ?>
                        </td>
                        <td><a href="customer_detail.php?id=<?= (int) $customer['id'] ?>">Details</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php else: ?>
            <p class="muted-text">Keine passenden Kunden gefunden.</p>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Buchungen <span class="muted-text">(<?= count($bookingResults) ?>)</span></h2>
        <?php if ($bookingResults): ?>
            <div class="table-responsive">
            <table>
                <thead><tr><th>Event</th><th>Kunde</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($bookingResults as $booking): ?>
                    <tr>
                        <td class="nowrap"><?= htmlspecialchars((new DateTimeImmutable($booking['event_date']))->format('d.m.Y'), ENT_QUOTES) ?></td>
                        <td>
                            <?= htmlspecialchars($booking['customer_name'], ENT_QUOTES) ?><br>
                            <span class="muted-text"><?= htmlspecialchars($booking['customer_email'], ENT_QUOTES) ?></span>
                        </td>
                        <td class="nowrap"><span class="status-pill status-<?= htmlspecialchars($booking['status'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars(booking_status_label($booking['status']), ENT_QUOTES) ?>
                        </span></td>
                        <td><a href="booking_detail.php?id=<?= (int) $booking['id'] ?>">Details</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php else: ?>
            <p class="muted-text">Keine passenden Buchungen gefunden.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>

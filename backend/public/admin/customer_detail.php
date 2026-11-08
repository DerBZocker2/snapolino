<?php
declare(strict_types=1);

$pageTitle = 'Kundendetails';
require __DIR__ . '/_header.php';

$customerId = (int) ($_GET['id'] ?? $_POST['customer_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM customer_accounts WHERE id = ?');
$stmt->execute([$customerId]);
$customer = $stmt->fetch();

if (!$customer) {
    http_response_code(404);
    echo '<p>Kunde nicht gefunden.</p>';
    require __DIR__ . '/_footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    db()->prepare('UPDATE customer_accounts SET name = ?, phone = ?, notes = ? WHERE id = ?')
        ->execute([$name !== '' ? $name : null, $phone !== '' ? $phone : null, $notes !== '' ? $notes : null, $customerId]);

    header('Location: customer_detail.php?id=' . $customerId . '&gespeichert=1');
    exit;
}

$stmt = db()->prepare("SELECT * FROM bookings WHERE customer_account_id = ? ORDER BY event_date DESC");
$stmt->execute([$customerId]);
$bookings = $stmt->fetchAll();

$totalPaidCents = 0;
$bookingCount = count($bookings);
foreach ($bookings as $b) {
    if ($b['paid_at']) {
        $totalPaidCents += (int) $b['total_price_cents'];
    }
}
?>

<section class="panel">
    <?php if (isset($_GET['gespeichert'])): ?>
        <p class="badge">Gespeichert</p>
    <?php endif; ?>

    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-card-label">Kundennummer</div>
            <div class="stat-card-value"><code><?= htmlspecialchars((string) ($customer['customer_number'] ?? '—'), ENT_QUOTES) ?></code></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-label">Buchungen</div>
            <div class="stat-card-value"><?= $bookingCount ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-label">Umsatz (bezahlt)</div>
            <div class="stat-card-value"><?= money_from_cents($totalPaidCents) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-label">Kunde seit</div>
            <div class="stat-card-value" style="font-size:16px;"><?= htmlspecialchars((new DateTimeImmutable($customer['created_at']))->format('d.m.Y'), ENT_QUOTES) ?></div>
        </div>
    </div>

    <h2 style="margin-top:20px;">Profil</h2>
    <form method="post" action="customer_detail.php?id=<?= (int) $customerId ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="customer_id" value="<?= (int) $customerId ?>">
        <div class="grid3">
            <label>Name
                <input type="text" name="name" value="<?= htmlspecialchars((string) $customer['name'], ENT_QUOTES) ?>">
            </label>
            <label>Telefon
                <input type="text" name="phone" value="<?= htmlspecialchars((string) $customer['phone'], ENT_QUOTES) ?>">
            </label>
            <label>E-Mail
                <input type="text" value="<?= htmlspecialchars($customer['email'], ENT_QUOTES) ?>" disabled>
            </label>
        </div>
        <label>Interne Notiz <span class="muted-text">(nie für den Kunden sichtbar)</span>
            <textarea name="notes" rows="3"><?= htmlspecialchars((string) $customer['notes'], ENT_QUOTES) ?></textarea>
        </label>
        <button type="submit">Speichern</button>
    </form>
</section>

<section class="panel">
    <h2>Buchungen</h2>
    <?php if ($bookings): ?>
        <div class="table-responsive">
        <table>
            <thead><tr><th>Eventdatum</th><th>Status</th><th>Preis</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($bookings as $b): ?>
                    <tr>
                        <td class="nowrap"><?= htmlspecialchars((new DateTimeImmutable($b['event_date']))->format('d.m.Y'), ENT_QUOTES) ?></td>
                        <td class="nowrap"><span class="status-pill status-<?= htmlspecialchars($b['status'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars(booking_status_label($b['status']), ENT_QUOTES) ?>
                        </span></td>
                        <td class="nowrap"><?= $b['total_price_cents'] !== null ? money_from_cents((int) $b['total_price_cents']) : '—' ?></td>
                        <td><a href="booking_detail.php?id=<?= (int) $b['id'] ?>">Details</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <p class="muted-text">Noch keine Buchungen über dieses Konto.</p>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

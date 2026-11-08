<?php
declare(strict_types=1);

$pageTitle = 'Kunden';
require __DIR__ . '/_header.php';

try {
    db()->query('SELECT 1 FROM customer_accounts LIMIT 1');
} catch (PDOException $e) {
    echo '<p class="error">Die Kundentabelle fehlt noch. Einmalig ausführen: '
        . '<code>mysql -u snapolino -p snapolino &lt; backend/sql/migrations/0031_customer_profiles.sql</code></p>';
    require __DIR__ . '/_footer.php';
    exit;
}

$search = trim((string) ($_GET['q'] ?? ''));

// Buchungsanzahl und bezahlter Gesamtumsatz je Kundenkonto in einer
// Abfrage dazu - analog zu Shopifys Kundenliste (Anzahl Bestellungen,
// Gesamtumsatz), hier ueber customer_account_id statt einer eigenen
// Bestellhistorie-Tabelle, da bookings bereits direkt verknuepft ist.
$sql = "SELECT ca.*,
            COUNT(b.id) AS booking_count,
            COALESCE(SUM(CASE WHEN b.paid_at IS NOT NULL THEN b.total_price_cents ELSE 0 END), 0) AS total_paid_cents,
            MAX(b.event_date) AS last_event_date
        FROM customer_accounts ca
        LEFT JOIN bookings b ON b.customer_account_id = ca.id";
$params = [];
if ($search !== '') {
    $sql .= ' WHERE ca.customer_number LIKE ? OR ca.name LIKE ? OR ca.email LIKE ? OR ca.phone LIKE ?';
    $like = '%' . $search . '%';
    $params = [$like, $like, $like, $like];
}
$sql .= ' GROUP BY ca.id ORDER BY ca.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$totalCustomers = (int) db()->query('SELECT COUNT(*) FROM customer_accounts')->fetchColumn();
?>

<section class="panel">
    <div class="inline-form" style="margin-bottom:16px;justify-content:space-between;">
        <form method="get" action="customers.php" class="inline-form" style="margin-bottom:0;">
            <label style="margin-bottom:0;min-width:240px;">Suche
                <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>"
                    placeholder="Kundennummer, Name, E-Mail, Telefon">
            </label>
            <button type="submit" class="button-secondary">Suchen</button>
            <?php if ($search !== ''): ?>
                <a href="customers.php" class="button-secondary">Zurücksetzen</a>
            <?php endif; ?>
        </form>
        <a href="customers_export.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" class="button-secondary">Als CSV exportieren</a>
    </div>

    <p class="muted-text">
        <?= count($customers) ?> von <?= $totalCustomers ?> Kunde<?= $totalCustomers === 1 ? '' : 'n' ?>
        <?= $search !== '' ? 'gefunden' : '' ?>
    </p>

    <div class="table-responsive">
    <table>
        <thead>
        <tr>
            <th>Kundennummer</th>
            <th>Name</th>
            <th>Kontakt</th>
            <th>Buchungen</th>
            <th>Umsatz (bezahlt)</th>
            <th>Letztes Event</th>
            <th>Kunde seit</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($customers as $customer): ?>
            <tr>
                <td class="nowrap"><code><?= htmlspecialchars((string) ($customer['customer_number'] ?? '—'), ENT_QUOTES) ?></code></td>
                <td><a href="customer_detail.php?id=<?= (int) $customer['id'] ?>"><strong><?= htmlspecialchars($customer['name'] ?: '(ohne Namen)', ENT_QUOTES) ?></strong></a></td>
                <td>
                    <?= htmlspecialchars($customer['email'], ENT_QUOTES) ?>
                    <?php if ($customer['phone']): ?><br><span class="muted-text"><?= htmlspecialchars($customer['phone'], ENT_QUOTES) ?></span><?php endif; ?>
                </td>
                <td class="nowrap"><?= (int) $customer['booking_count'] ?></td>
                <td class="nowrap"><?= money_from_cents((int) $customer['total_paid_cents']) ?></td>
                <td class="nowrap"><?= $customer['last_event_date'] ? htmlspecialchars((new DateTimeImmutable($customer['last_event_date']))->format('d.m.Y'), ENT_QUOTES) : '—' ?></td>
                <td class="nowrap"><?= htmlspecialchars((new DateTimeImmutable($customer['created_at']))->format('d.m.Y'), ENT_QUOTES) ?></td>
                <td class="actions"><div class="actions-row"><a href="customer_detail.php?id=<?= (int) $customer['id'] ?>">Details</a></div></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$customers): ?>
            <tr><td colspan="8">Keine Kunden gefunden.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

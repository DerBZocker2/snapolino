<?php
declare(strict_types=1);

// Sammelt alle bei Stripe gehosteten Rechnungen (Hauptbuchung,
// Zusatzzahlungen, Stornorechnungen) an einer Stelle, damit der Admin nicht
// erst durch jede einzelne Buchung klicken muss, um eine Rechnung zu finden
// oder herunterzuladen. Erzeugt selbst keine PDFs - verlinkt nur die von
// Stripe gelieferten stripe_invoice_pdf_url/stripe_credit_note_pdf_url
// (siehe "Buchungssystem" in CLAUDE.md, Abschnitt Rechnungen).

$pageTitle = 'Rechnungen';
require __DIR__ . '/_header.php';

$invoices = [];

try {
    $stmt = db()->query(
        "SELECT id, customer_name, customer_email, paid_at, total_price_cents,
                stripe_invoice_pdf_url, stripe_invoice_hosted_url
         FROM bookings
         WHERE stripe_invoice_pdf_url IS NOT NULL OR stripe_invoice_hosted_url IS NOT NULL"
    );
    foreach ($stmt->fetchAll() as $b) {
        $invoices[] = [
            'date' => $b['paid_at'],
            'type' => 'Buchung',
            'customer_name' => $b['customer_name'],
            'customer_email' => $b['customer_email'],
            'amount_cents' => $b['total_price_cents'] !== null ? (int) $b['total_price_cents'] : null,
            'pdf_url' => $b['stripe_invoice_pdf_url'],
            'hosted_url' => $b['stripe_invoice_hosted_url'],
            'booking_id' => (int) $b['id'],
        ];
    }

    $stmt = db()->query(
        "SELECT id, customer_name, customer_email, cancelled_at, total_price_cents, stripe_credit_note_pdf_url
         FROM bookings
         WHERE stripe_credit_note_pdf_url IS NOT NULL"
    );
    foreach ($stmt->fetchAll() as $b) {
        $invoices[] = [
            'date' => $b['cancelled_at'],
            'type' => 'Stornorechnung',
            'customer_name' => $b['customer_name'],
            'customer_email' => $b['customer_email'],
            'amount_cents' => $b['total_price_cents'] !== null ? -(int) $b['total_price_cents'] : null,
            'pdf_url' => $b['stripe_credit_note_pdf_url'],
            'hosted_url' => null,
            'booking_id' => (int) $b['id'],
        ];
    }

    $stmt = db()->query(
        "SELECT bac.id, bac.booking_id, bac.description, bac.amount_cents, bac.paid_at,
                bac.stripe_invoice_pdf_url, bac.stripe_invoice_hosted_url,
                b.customer_name, b.customer_email
         FROM booking_addon_charges bac
         INNER JOIN bookings b ON b.id = bac.booking_id
         WHERE bac.stripe_invoice_pdf_url IS NOT NULL OR bac.stripe_invoice_hosted_url IS NOT NULL"
    );
    foreach ($stmt->fetchAll() as $c) {
        $invoices[] = [
            'date' => $c['paid_at'],
            'type' => 'Zusatzzahlung',
            'customer_name' => $c['customer_name'],
            'customer_email' => $c['customer_email'],
            'amount_cents' => (int) $c['amount_cents'],
            'pdf_url' => $c['stripe_invoice_pdf_url'],
            'hosted_url' => $c['stripe_invoice_hosted_url'],
            'booking_id' => (int) $c['booking_id'],
        ];
    }

    usort($invoices, static fn (array $a, array $b) => strcmp((string) $b['date'], (string) $a['date']));
} catch (PDOException $e) {
    $invoices = [];
}

$totalRevenue = 0;
foreach ($invoices as $inv) {
    if ($inv['type'] !== 'Stornorechnung' && $inv['amount_cents'] !== null) {
        $totalRevenue += $inv['amount_cents'];
    }
}
?>

<section class="panel">
    <p class="muted-text">
        <?= count($invoices) ?> Rechnung(en) · <?= money_from_cents($totalRevenue) ?> Umsatz (ohne Stornorechnungen)
    </p>
    <p class="muted-text">
        Alle Rechnungen werden ausschließlich von Stripe erstellt und gehostet - diese Liste verlinkt nur
        dorthin, es gibt keine eigenen PDFs auf diesem Server.
    </p>

    <div class="table-responsive">
    <table>
        <thead>
        <tr>
            <th>Datum</th>
            <th>Kunde</th>
            <th>Typ</th>
            <th>Betrag</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($invoices as $inv): ?>
            <tr>
                <td class="nowrap">
                    <?= $inv['date'] ? htmlspecialchars((new DateTimeImmutable($inv['date']))->format('d.m.Y'), ENT_QUOTES) : '—' ?>
                </td>
                <td>
                    <?= htmlspecialchars($inv['customer_name'], ENT_QUOTES) ?><br>
                    <span class="muted-text"><?= htmlspecialchars($inv['customer_email'], ENT_QUOTES) ?></span>
                </td>
                <td class="nowrap">
                    <?php if ($inv['type'] === 'Stornorechnung'): ?>
                        <span class="badge badge-warning">Stornorechnung</span>
                    <?php elseif ($inv['type'] === 'Zusatzzahlung'): ?>
                        <span class="badge">Zusatzzahlung</span>
                    <?php else: ?>
                        <span class="badge">Buchung</span>
                    <?php endif; ?>
                </td>
                <td class="nowrap">
                    <?= $inv['amount_cents'] !== null ? money_from_cents($inv['amount_cents']) : '—' ?>
                </td>
                <td class="actions">
                    <div class="actions-row">
                        <?php if ($inv['pdf_url']): ?>
                            <a href="<?= htmlspecialchars($inv['pdf_url'], ENT_QUOTES) ?>" target="_blank" rel="noopener">PDF</a>
                        <?php endif; ?>
                        <?php if ($inv['hosted_url']): ?>
                            <a href="<?= htmlspecialchars($inv['hosted_url'], ENT_QUOTES) ?>" target="_blank" rel="noopener">Ansehen</a>
                        <?php endif; ?>
                        <a href="booking_detail.php?id=<?= $inv['booking_id'] ?>">Buchung</a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$invoices): ?>
            <tr><td colspan="5">Noch keine Rechnungen vorhanden.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

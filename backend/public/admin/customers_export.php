<?php
declare(strict_types=1);

// CSV-Export der Kundenliste (analog zu Shopifys "Kunden exportieren") -
// respektiert dieselbe Suche wie customers.php (?q=...), damit sich auch
// eine gefilterte Teilmenge exportieren laesst.

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_login();

$search = trim((string) ($_GET['q'] ?? ''));

$sql = "SELECT ca.*,
            COUNT(b.id) AS booking_count,
            COALESCE(SUM(CASE WHEN b.paid_at IS NOT NULL THEN b.total_price_cents ELSE 0 END), 0) AS total_paid_cents
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

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="snapolino-kunden-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
// UTF-8 BOM, damit Excel Umlaute korrekt anzeigt statt die Datei als
// Windows-1252 zu interpretieren.
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Kundennummer', 'Name', 'E-Mail', 'Telefon', 'Buchungen', 'Umsatz bezahlt (EUR)', 'Kunde seit'], ';');
foreach ($customers as $customer) {
    fputcsv($out, [
        $customer['customer_number'],
        $customer['name'],
        $customer['email'],
        $customer['phone'],
        $customer['booking_count'],
        number_format(((int) $customer['total_paid_cents']) / 100, 2, ',', ''),
        (new DateTimeImmutable($customer['created_at']))->format('d.m.Y'),
    ], ';');
}
fclose($out);

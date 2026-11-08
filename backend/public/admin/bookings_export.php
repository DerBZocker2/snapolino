<?php
declare(strict_types=1);

// CSV-Export der Buchungsliste (analog zu Shopifys "Bestellungen
// exportieren") - respektiert denselben Status-Filter wie bookings.php
// (?status=...), damit sich auch eine gefilterte Teilmenge exportieren laesst.

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_login();

$statusFilter = (string) ($_GET['status'] ?? '');
if ($statusFilter !== '' && in_array($statusFilter, BOOKING_STATUSES, true)) {
    $stmt = db()->prepare(
        "SELECT * FROM bookings WHERE status = ?
         ORDER BY FIELD(status, 'angefragt', 'bestaetigt', 'abgelehnt', 'storniert'), event_date"
    );
    $stmt->execute([$statusFilter]);
} else {
    $stmt = db()->query(
        "SELECT * FROM bookings
         ORDER BY FIELD(status, 'angefragt', 'bestaetigt', 'abgelehnt', 'storniert'), event_date"
    );
}
$bookings = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="snapolino-buchungen-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Eventdatum', 'Kunde', 'E-Mail', 'Telefon', 'Status', 'Preis (EUR)', 'Bezahlt am', 'Angefragt am'], ';');
foreach ($bookings as $b) {
    fputcsv($out, [
        (new DateTimeImmutable($b['event_date']))->format('d.m.Y'),
        $b['customer_name'],
        $b['customer_email'],
        $b['customer_phone'],
        booking_status_label($b['status']),
        $b['total_price_cents'] !== null ? number_format(((int) $b['total_price_cents']) / 100, 2, ',', '') : '',
        $b['paid_at'] ? (new DateTimeImmutable($b['paid_at']))->format('d.m.Y') : '',
        $b['created_at'] ? (new DateTimeImmutable($b['created_at']))->format('d.m.Y') : '',
    ], ';');
}
fclose($out);

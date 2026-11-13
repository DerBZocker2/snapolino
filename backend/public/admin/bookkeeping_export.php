<?php
declare(strict_types=1);

// CSV-Export der Buchhaltungseintraege eines Jahres (fuer den Steuerberater/
// die eigene EUER) - respektiert dieselben Filter wie bookkeeping.php.

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

require_login();

$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$direction = $_GET['direction'] ?? '';
$direction = in_array($direction, ['einnahme', 'ausgabe'], true) ? $direction : '';

$sql = 'SELECT * FROM bookkeeping_entries WHERE YEAR(document_date) = ?';
$params = [$year];
if ($direction !== '') {
    $sql .= ' AND direction = ?';
    $params[] = $direction;
}
$sql .= ' ORDER BY document_date ASC, id ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="snapolino-buchhaltung-' . $year . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Datum', 'Richtung', 'Von/An', 'Beschreibung', 'Kategorie', 'Betrag (EUR)'], ';');
foreach ($entries as $entry) {
    fputcsv($out, [
        (new DateTimeImmutable($entry['document_date']))->format('d.m.Y'),
        $entry['direction'] === 'einnahme' ? 'Einnahme' : 'Ausgabe',
        $entry['counterparty'],
        $entry['description'],
        $entry['category'],
        number_format(((int) $entry['amount_cents']) / 100, 2, ',', ''),
    ], ';');
}
fclose($out);

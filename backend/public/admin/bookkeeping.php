<?php
declare(strict_types=1);

$pageTitle = 'Buchhaltung';
require __DIR__ . '/_header.php';
require_once __DIR__ . '/../../includes/bookkeeping.php';

$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$direction = $_GET['direction'] ?? '';
$direction = in_array($direction, ['einnahme', 'ausgabe'], true) ? $direction : '';

$availableYears = db()->query(
    'SELECT DISTINCT YEAR(document_date) AS y FROM bookkeeping_entries ORDER BY y DESC'
)->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($year, array_map('intval', $availableYears), true)) {
    $availableYears[] = $year;
    rsort($availableYears);
}

$sql = 'SELECT * FROM bookkeeping_entries WHERE YEAR(document_date) = ?';
$params = [$year];
if ($direction !== '') {
    $sql .= ' AND direction = ?';
    $params[] = $direction;
}
$sql .= ' ORDER BY document_date DESC, id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();

// EUER-Summe fuer das gewaehlte Jahr: Betriebseinnahmen minus
// Betriebsausgaben = Gewinn/Verlust (unabhaengig vom Richtungs-Filter oben,
// der nur die Tabelle darunter einschraenkt - die Summenkacheln zeigen
// immer das volle Jahresbild).
$stmt = db()->prepare(
    "SELECT direction, COALESCE(SUM(amount_cents), 0) AS total
     FROM bookkeeping_entries WHERE YEAR(document_date) = ? GROUP BY direction"
);
$stmt->execute([$year]);
$sums = ['einnahme' => 0, 'ausgabe' => 0];
foreach ($stmt->fetchAll() as $row) {
    $sums[$row['direction']] = (int) $row['total'];
}
$profit = $sums['einnahme'] - $sums['ausgabe'];

function euro(int $cents): string
{
    return number_format($cents / 100, 2, ',', '.') . ' €';
}
?>

<section class="panel">
    <div class="stat-cards">
        <div class="stat-card">
            <div class="stat-card-label">Betriebseinnahmen <?= $year ?></div>
            <div class="stat-card-value"><?= euro($sums['einnahme']) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-label">Betriebsausgaben <?= $year ?></div>
            <div class="stat-card-value"><?= euro($sums['ausgabe']) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-label">EÜR-Ergebnis <?= $year ?></div>
            <div class="stat-card-value" style="color:<?= $profit >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                <?= $profit >= 0 ? '+' : '' ?><?= euro($profit) ?>
            </div>
        </div>
    </div>
    <p class="muted-text">
        Einnahmenüberschussrechnung (§4 Abs. 3 EStG) für die Kleinunternehmerregelung
        (§19 UStG) - Bruttobeträge, kein Vorsteuerabzug/USt-Ausweis nötig.
        Ausgangsrechnungen aus Buchungen werden bereits automatisch unter
        <a href="invoices.php">Rechnungen</a> erfasst - hier geht es vor
        allem um Eingangsrechnungen (Einkäufe) und sonstige Belege.
    </p>
</section>

<section class="panel">
    <div class="actions-row" style="margin-bottom:16px;">
        <a href="bookkeeping_upload.php">+ Beleg hochladen</a>
        <a class="button-secondary" href="bookkeeping_export.php?year=<?= $year ?><?= $direction !== '' ? '&direction=' . $direction : '' ?>">Als CSV exportieren</a>
    </div>

    <form method="get" action="bookkeeping.php" class="inline-form">
        <label style="margin-bottom:0;">Jahr
            <select name="year" onchange="this.form.submit()">
                <?php foreach ($availableYears as $y): ?>
                    <option value="<?= (int) $y ?>" <?= (int) $y === $year ? 'selected' : '' ?>><?= (int) $y ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="margin-bottom:0;">Richtung
            <select name="direction" onchange="this.form.submit()">
                <option value="">Alle</option>
                <option value="einnahme" <?= $direction === 'einnahme' ? 'selected' : '' ?>>Einnahmen</option>
                <option value="ausgabe" <?= $direction === 'ausgabe' ? 'selected' : '' ?>>Ausgaben</option>
            </select>
        </label>
    </form>

    <?php if ($entries): ?>
        <div class="table-responsive">
        <table>
            <thead>
            <tr>
                <th>Datum</th>
                <th>Richtung</th>
                <th>Von/An</th>
                <th>Beschreibung</th>
                <th>Kategorie</th>
                <th>Betrag</th>
                <th>Beleg</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td class="nowrap"><?= htmlspecialchars((new DateTimeImmutable($entry['document_date']))->format('d.m.Y'), ENT_QUOTES) ?></td>
                    <td class="nowrap">
                        <span class="status-pill status-<?= $entry['direction'] === 'einnahme' ? 'bestaetigt' : 'angefragt' ?>">
                            <?= $entry['direction'] === 'einnahme' ? 'Einnahme' : 'Ausgabe' ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($entry['counterparty'] ?: '—', ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($entry['description'] ?: '—', ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($entry['category'] ?: '—', ENT_QUOTES) ?></td>
                    <td class="nowrap"><?= euro((int) $entry['amount_cents']) ?></td>
                    <td class="nowrap">
                        <?php if ($entry['stored_filename']): ?>
                            <a href="bookkeeping_file.php?id=<?= (int) $entry['id'] ?>" target="_blank">Ansehen</a>
                        <?php else: ?>
                            <span class="muted-text">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap">
                        <form method="post" action="bookkeeping_delete.php" onsubmit="return confirm('Eintrag wirklich löschen?');" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $entry['id'] ?>">
                            <button type="submit" class="danger">Löschen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <p class="muted-text">Keine Einträge für <?= $year ?><?= $direction !== '' ? ' (' . bookkeeping_direction_label($direction) . ')' : '' ?>.</p>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

<?php
declare(strict_types=1);

$pageTitle = 'Übersicht';
require __DIR__ . '/_header.php';

$boxCount = (int) db()->query('SELECT COUNT(*) FROM boxes')->fetchColumn();
$layoutCount = (int) db()->query('SELECT COUNT(*) FROM layouts')->fetchColumn();
try {
    $openBookingCount = (int) db()->query("SELECT COUNT(*) FROM bookings WHERE status = 'angefragt'")->fetchColumn();
    $unassignedCount = (int) db()->query(
        "SELECT COUNT(*) FROM bookings WHERE status = 'bestaetigt' AND box_id IS NULL"
    )->fetchColumn();
    $bookingsAvailable = true;
} catch (PDOException $e) {
    $openBookingCount = 0;
    $unassignedCount = 0;
    $bookingsAvailable = false;
}
?>
<div class="cards">
    <a class="card" href="bookings.php">
        <span class="card-number"><?= $openBookingCount ?></span>
        <span class="card-label">Neue Buchungsanfragen</span>
    </a>
    <a class="card" href="boxes.php">
        <span class="card-number"><?= $unassignedCount ?></span>
        <span class="card-label">Buchungen ohne Box</span>
    </a>
    <a class="card" href="boxes.php">
        <span class="card-number"><?= $boxCount ?></span>
        <span class="card-label">Boxen</span>
    </a>
    <a class="card" href="layouts.php">
        <span class="card-number"><?= $layoutCount ?></span>
        <span class="card-label">Layouts</span>
    </a>
</div>

<?php if (!$bookingsAvailable): ?>
    <p class="error">
        Die Buchungstabellen fehlen noch in der Datenbank. Einmalig ausführen:
        <code>mysql -u snapolino -p snapolino &lt; backend/sql/migrations/0002_bookings.sql</code>
    </p>
<?php endif; ?>

<p>
    Neue Buchungsanfragen unter <a href="bookings.php">Buchungen</a> annehmen
    oder ablehnen, dann unter <a href="boxes.php">Boxen</a> per Drag &amp; Drop
    einer Box zuordnen - erst dadurch bekommt die Box beim nächsten Sync die
    Kundendaten und die gebuchten Layouts. Collagen-Vorlagen werden unter
    <a href="layouts.php">Layouts</a> gepflegt.
</p>

<?php require __DIR__ . '/_footer.php'; ?>

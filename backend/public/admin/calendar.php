<?php
declare(strict_types=1);

// Monatskalender als Zeitplan-Uebersicht: zeigt Events (Eventdatum einer
// Buchung) und Versand-Faelligkeiten (booking_ship_date(), siehe
// functions.php) direkt im jeweiligen Tag - Ergaenzung zur Listenansicht in
// bookings.php, fuer einen schnellen Blick "was steht diesen Monat an".

$pageTitle = 'Kalender';
require __DIR__ . '/_header.php';

// Deutsche Monatsnamen, da DateTime::format('F') englische Namen liefert und
// die intl-Extension nicht vorausgesetzt werden soll (gleiches Muster wie
// DASHBOARD_MONTH_LABELS in dashboard.php).
const CALENDAR_MONTH_NAMES = [
    1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni',
    7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
];

$monthParam = (string) ($_GET['month'] ?? '');
if (preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    try {
        $firstOfMonth = new DateTimeImmutable($monthParam . '-01');
    } catch (Exception $e) {
        $firstOfMonth = new DateTimeImmutable('first day of this month');
    }
} else {
    $firstOfMonth = new DateTimeImmutable('first day of this month');
}

$prevMonth = $firstOfMonth->modify('-1 month')->format('Y-m');
$nextMonth = $firstOfMonth->modify('+1 month')->format('Y-m');
$today = new DateTimeImmutable('today');

// Montag als Wochenstart, wie im oeffentlichen Buchungskalender
// (buchen.php) - 0 = Montag ... 6 = Sonntag.
$startWeekday = ((int) $firstOfMonth->format('N')) - 1;
$daysInMonth = (int) $firstOfMonth->format('t');
$totalCells = (int) ceil(($startWeekday + $daysInMonth) / 7) * 7;

$gridStart = $firstOfMonth->modify('-' . $startWeekday . ' days');
$gridEnd = $gridStart->modify('+' . ($totalCells - 1) . ' days');

$statsAvailable = true;
$statsError = '';
$eventsByDay = [];
$shipmentsByDay = [];

try {
    // Bis zu BOOKING_BUFFER_DAYS Tage ueber das Rasterende hinaus mitladen,
    // damit eine Versand-Faelligkeit (Eventdatum minus Puffer) auch dann im
    // Raster auftaucht, wenn das zugehoerige Event selbst schon knapp
    // ausserhalb des sichtbaren Monatsrasters liegt.
    $queryEnd = $gridEnd->modify('+' . BOOKING_BUFFER_DAYS . ' days');
    $stmt = db()->prepare(
        "SELECT * FROM bookings WHERE event_date BETWEEN ? AND ?
         ORDER BY event_date"
    );
    $stmt->execute([$gridStart->format('Y-m-d'), $queryEnd->format('Y-m-d')]);
    $rangeBookings = $stmt->fetchAll();

    foreach ($rangeBookings as $booking) {
        $eventDateStr = $booking['event_date'];
        if ($eventDateStr >= $gridStart->format('Y-m-d') && $eventDateStr <= $gridEnd->format('Y-m-d')) {
            $eventsByDay[$eventDateStr][] = $booking;
        }

        // Versand-Faelligkeit nur bei noch aktiven Buchungen relevant
        // (abgelehnt/storniert blockieren wie in bookings.php keinen Versand
        // mehr).
        if (in_array($booking['status'], ['angefragt', 'bestaetigt'], true)) {
            $shipDateStr = booking_ship_date($eventDateStr)->format('Y-m-d');
            if ($shipDateStr >= $gridStart->format('Y-m-d') && $shipDateStr <= $gridEnd->format('Y-m-d')) {
                $shipmentsByDay[$shipDateStr][] = $booking;
            }
        }
    }
} catch (PDOException $e) {
    $statsAvailable = false;
    $statsError = $e->getMessage();
}
?>

<?php if (!$statsAvailable): ?>
    <p class="error">
        Der Kalender konnte nicht geladen werden - vermutlich fehlt eine Migration.
        Alle Migrationen der Reihe nach einspielen (siehe <code>backend/README.md</code>,
        Abschnitt "Bestehende Installation aktualisieren").
        <?php if ($statsError !== ''): ?><br><span class="muted-text"><?= htmlspecialchars($statsError, ENT_QUOTES) ?></span><?php endif; ?>
    </p>
<?php else: ?>

    <section class="panel">
        <div class="calendar-nav">
            <a class="button-secondary" href="calendar.php?month=<?= urlencode($prevMonth) ?>">&larr; Vormonat</a>
            <h2 class="calendar-nav-title">
                <?= CALENDAR_MONTH_NAMES[(int) $firstOfMonth->format('n')] ?> <?= $firstOfMonth->format('Y') ?>
            </h2>
            <a class="button-secondary" href="calendar.php">Heute</a>
            <a class="button-secondary" href="calendar.php?month=<?= urlencode($nextMonth) ?>">Nächster Monat &rarr;</a>
        </div>

        <div class="calendar-legend">
            <span class="calendar-legend-item"><span class="calendar-dot status-angefragt-dot"></span> Angefragt</span>
            <span class="calendar-legend-item"><span class="calendar-dot status-bestaetigt-dot"></span> Bestätigt</span>
            <span class="calendar-legend-item"><span class="calendar-dot calendar-ship-dot"></span> Versand fällig</span>
        </div>

        <div class="calendar-grid">
            <?php foreach (['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'] as $weekday): ?>
                <div class="calendar-weekday"><?= $weekday ?></div>
            <?php endforeach; ?>

            <?php for ($i = 0; $i < $totalCells; $i++): ?>
                <?php
                $cellDate = $gridStart->modify('+' . $i . ' days');
                $cellDateStr = $cellDate->format('Y-m-d');
                $isOutside = $cellDate->format('n') !== $firstOfMonth->format('n');
                $isToday = $cellDateStr === $today->format('Y-m-d');
                $dayEvents = $eventsByDay[$cellDateStr] ?? [];
                $dayShipments = $shipmentsByDay[$cellDateStr] ?? [];
                $cellClasses = 'calendar-day' . ($isOutside ? ' is-outside' : '') . ($isToday ? ' is-today' : '');
                ?>
                <div class="<?= $cellClasses ?>">
                    <div class="calendar-day-num"><?= (int) $cellDate->format('j') ?></div>
                    <?php if ($dayEvents || $dayShipments): ?>
                        <div class="calendar-day-events">
                            <?php foreach ($dayEvents as $booking): ?>
                                <a class="calendar-event status-<?= htmlspecialchars($booking['status'], ENT_QUOTES) ?>"
                                   href="booking_detail.php?id=<?= (int) $booking['id'] ?>"
                                   title="<?= htmlspecialchars($booking['customer_name'] . ' - ' . booking_status_label($booking['status']), ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($booking['customer_name'], ENT_QUOTES) ?>
                                </a>
                            <?php endforeach; ?>
                            <?php foreach ($dayShipments as $booking): ?>
                                <a class="calendar-shipment"
                                   href="booking_detail.php?id=<?= (int) $booking['id'] ?>"
                                   title="<?= htmlspecialchars('Versand fällig für ' . $booking['customer_name'], ENT_QUOTES) ?>">
                                    Versand: <?= htmlspecialchars($booking['customer_name'], ENT_QUOTES) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>
    </section>

<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>

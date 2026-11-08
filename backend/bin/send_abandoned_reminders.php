<?php
declare(strict_types=1);

// Verschickt eine Erinnerungsmail an Buchungsanfragen, die Schritt 2 des
// Assistenten (Name/E-Mail/Termin) abgeschlossen haben, aber nie bis
// Schritt 5 (Zusammenfassung/Zahlung) weitergemacht haben - erkennbar an
// total_price_cents IS NULL, das wird erst dort gesetzt (buchen.php).
// Analog zu Shopifys "Abandoned Checkout"-Erinnerung. Gedacht fuer einen
// taeglichen (oder stuendlichen) Cronjob (siehe backend/README.md), kann
// aber jederzeit von Hand aufgerufen werden:
//   php bin/send_abandoned_reminders.php
//
// abandoned_reminder_hours() (Panel unter Einstellungen, Standard 24) legt
// fest, wie lange seit dem Anlegen gewartet wird, bevor erinnert wird -
// abandoned_reminder_sent_at verhindert dabei eine doppelte Mail (nur eine
// einzige Erinnerung pro Buchung, kein wiederholtes Nachhaken). Buchungen
// mit bereits vergangenem Eventdatum werden nicht mehr angeschrieben - der
// Termin ist ohnehin nicht mehr aktuell.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

$hours = abandoned_reminder_hours();
$cutoff = (new DateTimeImmutable("-{$hours} hours"))->format('Y-m-d H:i:s');
$today = (new DateTimeImmutable('today'))->format('Y-m-d');

$stmt = db()->prepare(
    "SELECT * FROM bookings
     WHERE status = 'angefragt' AND total_price_cents IS NULL
       AND abandoned_reminder_sent_at IS NULL
       AND created_at <= ?
       AND event_date >= ?"
);
$stmt->execute([$cutoff, $today]);
$bookings = $stmt->fetchAll();

$sent = 0;
foreach ($bookings as $booking) {
    if (send_abandoned_booking_email($booking)) {
        db()->prepare('UPDATE bookings SET abandoned_reminder_sent_at = NOW() WHERE id = ?')->execute([$booking['id']]);
        $sent++;
    }
}

$total = count($bookings);
echo "Erinnerungsfenster: {$hours} Stunde(n) nach Buchungsbeginn ohne Abschluss.\n";
echo "{$sent}/{$total} Erinnerungsmail(s) verschickt.\n";

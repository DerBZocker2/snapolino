<?php
declare(strict_types=1);

// Verschickt die automatische Erinnerungsmail an alle bestaetigten Buchungen,
// deren Eventdatum innerhalb von "reminder_days_before_event" Tagen (Panel
// unter Einstellungen, Standard 7) liegt und die noch keine Erinnerung
// bekommen haben. Gedacht fuer einen taeglichen Cronjob (siehe
// backend/README.md), kann aber jederzeit von Hand aufgerufen werden:
//   php bin/send_event_reminders.php
//
// Das Zeitfenster (event_date zwischen heute und heute+X Tage, statt nur
// exakt X Tage vorher) statt eines einzigen Stichtags sorgt dafuer, dass ein
// versaeumter Cronjob-Lauf am naechsten Tag automatisch nachgeholt wird -
// reminder_sent_at verhindert dabei eine doppelte Mail. Bereits vergangene
// Events (event_date < heute) werden nicht mehr angeschrieben.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

$daysBefore = reminder_days_before_event();
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$cutoff = (new DateTimeImmutable("+{$daysBefore} days"))->format('Y-m-d');

$stmt = db()->prepare(
    "SELECT * FROM bookings
     WHERE status = 'bestaetigt' AND reminder_sent_at IS NULL
       AND event_date >= ? AND event_date <= ?"
);
$stmt->execute([$today, $cutoff]);
$bookings = $stmt->fetchAll();

$sent = 0;
foreach ($bookings as $booking) {
    if (send_event_reminder_email($booking)) {
        db()->prepare('UPDATE bookings SET reminder_sent_at = NOW() WHERE id = ?')->execute([$booking['id']]);
        $sent++;
    }
}

$total = count($bookings);
echo "Erinnerungsmail-Fenster: {$daysBefore} Tag(e) vor dem Event.\n";
echo "{$sent}/{$total} Erinnerungsmail(s) verschickt.\n";

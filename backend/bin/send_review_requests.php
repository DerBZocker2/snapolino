<?php
declare(strict_types=1);

// Verschickt die automatische Bewertungsanfrage an alle bestaetigten
// Buchungen, deren Eventdatum mindestens "review_request_days_after_event"
// Tage (Panel unter Einstellungen, Standard 3) zurueckliegt und die noch
// keine Anfrage bekommen haben. Gedacht fuer einen taeglichen Cronjob
// (siehe backend/README.md), kann aber jederzeit von Hand aufgerufen werden:
//   php bin/send_review_requests.php
//
// Ein Zeitfenster (statt eines einzigen Stichtags) sorgt dafuer, dass ein
// versaeumter Cronjob-Lauf am naechsten Tag automatisch nachgeholt wird -
// review_requested_at verhindert dabei eine doppelte Mail. Die obere Grenze
// des Fensters (zusaetzlich REVIEW_REQUEST_CATCHUP_DAYS) verhindert, dass
// eine erst Monate spaeter bestaetigte oder wiederentdeckte alte Buchung
// noch eine (dann unpassende) Bewertungsanfrage bekommt.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

const REVIEW_REQUEST_CATCHUP_DAYS = 4;

$daysAfter = review_request_days_after_event();
$upperBound = (new DateTimeImmutable("-{$daysAfter} days"))->format('Y-m-d');
$lowerBound = (new DateTimeImmutable('-' . ($daysAfter + REVIEW_REQUEST_CATCHUP_DAYS) . ' days'))->format('Y-m-d');

$stmt = db()->prepare(
    "SELECT * FROM bookings
     WHERE status = 'bestaetigt' AND review_requested_at IS NULL
       AND event_date >= ? AND event_date <= ?"
);
$stmt->execute([$lowerBound, $upperBound]);
$bookings = $stmt->fetchAll();

$sent = 0;
foreach ($bookings as $booking) {
    if (send_review_request_email($booking)) {
        db()->prepare('UPDATE bookings SET review_requested_at = NOW() WHERE id = ?')->execute([$booking['id']]);
        $sent++;
    }
}

$total = count($bookings);
echo "Bewertungsanfrage-Fenster: {$daysAfter} Tag(e) nach dem Event (+" . REVIEW_REQUEST_CATCHUP_DAYS . " Tage Nachholfenster).\n";
echo "{$sent}/{$total} Bewertungsanfrage(n) verschickt.\n";

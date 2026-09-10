<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/invoice.php';
require_once __DIR__ . '/mailer.php';

// Verarbeitet eine erfolgreiche Zahlung: bestaetigt die Buchung, vergibt
// eine Rechnungsnummer, weist automatisch eine Box zu (wenn eindeutig
// moeglich) und verschickt Bestaetigung + Rechnung per Mail. Idempotent -
// Stripe kann denselben Webhook-Event mehrfach zustellen, ein bereits
// bezahlter Booking-Datensatz (paid_at gesetzt) wird nicht doppelt
// verarbeitet.
function mark_booking_paid(int $bookingId, string $paymentIntentId): void
{
    $stmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking || $booking['paid_at'] !== null) {
        return;
    }

    $invoiceNumber = next_invoice_number();
    db()->prepare(
        "UPDATE bookings SET status = 'bestaetigt', paid_at = NOW(), stripe_payment_intent = ?, invoice_number = ? WHERE id = ?"
    )->execute([$paymentIntentId, $invoiceNumber, $bookingId]);

    // Best-effort: nur automatisch zuweisen, wenn es genau eine Box gibt.
    // Bei 0 oder mehreren Boxen bleibt box_id leer, Admin weist im Panel zu
    // (die Buchung ist trotzdem schon "bestaetigt" - bezahlt ist bezahlt).
    assign_box_and_confirm($bookingId);

    $stmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    try {
        $pdfPath = generate_invoice_pdf($booking);
        send_booking_confirmation_email($booking, $pdfPath);
    } catch (Throwable $e) {
        error_log('Rechnung/Mail fehlgeschlagen fuer Buchung #' . $bookingId . ': ' . $e->getMessage());
    }
}

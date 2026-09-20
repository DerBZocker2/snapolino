<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/stripe.php';

// Holt die von Stripe bei der Checkout-Session automatisch erstellte
// Rechnung (invoice_creation, siehe stripe.php) und speichert PDF-/Ansicht-
// Link auf der uebergebenen Tabelle, loest ausserdem den Mailversand durch
// Stripe selbst aus. Best-effort - falls Stripe (noch) keine Rechnungs-ID
// liefert oder der Abruf fehlschlaegt, bleibt die eigene Rechnung
// (invoice_number/generate_invoice_pdf) trotzdem massgeblich.
function store_stripe_invoice(string $table, int $id, ?string $stripeInvoiceId): void
{
    if (!$stripeInvoiceId || !in_array($table, ['bookings', 'booking_addon_charges'], true)) {
        return;
    }

    $invoice = fetch_stripe_invoice($stripeInvoiceId);
    if (!$invoice) {
        return;
    }

    db()->prepare(
        "UPDATE $table SET stripe_invoice_id = ?, stripe_invoice_pdf_url = ?, stripe_invoice_hosted_url = ? WHERE id = ?"
    )->execute([
        $stripeInvoiceId,
        $invoice['invoice_pdf'] ?? null,
        $invoice['hosted_invoice_url'] ?? null,
        $id,
    ]);

    send_stripe_invoice($stripeInvoiceId);
}

// Verarbeitet eine erfolgreiche Zahlung: bestaetigt die Buchung, weist
// automatisch eine Box zu (wenn eindeutig moeglich) und verschickt die
// Bestaetigung per Mail - die Rechnung dazu stellt ausschliesslich Stripe
// aus (invoice_creation, siehe stripe.php), es gibt keine eigene PDF/
// Rechnungsnummer mehr. Idempotent - Stripe kann denselben Webhook-Event
// mehrfach zustellen, ein bereits bezahlter Booking-Datensatz (paid_at
// gesetzt) wird nicht doppelt verarbeitet.
function mark_booking_paid(int $bookingId, string $paymentIntentId, ?string $stripeInvoiceId = null): void
{
    $stmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking || $booking['paid_at'] !== null) {
        return;
    }

    db()->prepare(
        "UPDATE bookings SET status = 'bestaetigt', paid_at = NOW(), stripe_payment_intent = ? WHERE id = ?"
    )->execute([$paymentIntentId, $bookingId]);

    // Best-effort: nur automatisch zuweisen, wenn es genau eine Box gibt.
    // Bei 0 oder mehreren Boxen bleibt box_id leer, Admin weist im Panel zu
    // (die Buchung ist trotzdem schon "bestaetigt" - bezahlt ist bezahlt).
    assign_box_and_confirm($bookingId);
    store_stripe_invoice('bookings', $bookingId, $stripeInvoiceId);

    $stmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    try {
        send_booking_confirmation_email($booking);
    } catch (Throwable $e) {
        error_log('Bestaetigungsmail fehlgeschlagen fuer Buchung #' . $bookingId . ': ' . $e->getMessage());
    }
}

// Storniert eine Buchung: setzt den Status, und wenn sie bereits bezahlt war
// (paid_at gesetzt) erstattet Stripe die Zahlung vollstaendig zurueck und
// erhaelt eine Stornorechnung (Credit Note) zur bestehenden Stripe-Rechnung -
// beides best-effort, ein Fehlschlag dabei darf die Stornierung selbst nicht
// verhindern (wird aber geloggt, damit von Hand nachgeholt werden kann).
// Verschickt anschliessend in jedem Fall eine Stornobestaetigung per Mail.
// Idempotent - eine bereits stornierte Buchung (cancelled_at gesetzt) wird
// nicht doppelt zurueckerstattet.
function cancel_booking(int $bookingId): void
{
    $stmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking || $booking['cancelled_at'] !== null) {
        return;
    }

    $wasRefunded = false;
    $creditNotePdfUrl = null;

    if ($booking['paid_at'] !== null && !empty($booking['stripe_payment_intent'])) {
        $refund = stripe_refund_payment((string) $booking['stripe_payment_intent']);
        if ($refund) {
            $wasRefunded = true;
            db()->prepare('UPDATE bookings SET stripe_refund_id = ? WHERE id = ?')
                ->execute([$refund['id'], $bookingId]);
        } else {
            error_log('Stornierung Buchung #' . $bookingId . ': Rueckerstattung fehlgeschlagen, bitte manuell im Stripe-Dashboard pruefen/ausloesen.');
        }

        if (!empty($booking['stripe_invoice_id'])) {
            $creditNote = stripe_create_credit_note((string) $booking['stripe_invoice_id']);
            if ($creditNote) {
                $creditNotePdfUrl = $creditNote['pdf'] ?? null;
                db()->prepare('UPDATE bookings SET stripe_credit_note_id = ?, stripe_credit_note_pdf_url = ? WHERE id = ?')
                    ->execute([$creditNote['id'], $creditNotePdfUrl, $bookingId]);
            } else {
                error_log('Stornierung Buchung #' . $bookingId . ': Stornorechnung (Credit Note) fehlgeschlagen, bitte manuell im Stripe-Dashboard erstellen.');
            }
        }
    }

    db()->prepare("UPDATE bookings SET status = 'storniert', cancelled_at = NOW() WHERE id = ?")->execute([$bookingId]);

    try {
        send_booking_cancelled_email($booking, $wasRefunded, $creditNotePdfUrl);
    } catch (Throwable $e) {
        error_log('Stornobestaetigung fehlgeschlagen fuer Buchung #' . $bookingId . ': ' . $e->getMessage());
    }
}

// Verarbeitet die erfolgreiche Zahlung einer nachtraeglichen Zusatzkosten-
// Nachforderung (siehe booking_addon_charges, admin/booking_detail.php).
// Die vom Admin vorgeschlagene Aenderung (pending_changes_json) wurde bei
// der Erstellung des Zahlungslinks bewusst NICHT auf die Buchung angewendet -
// das passiert erst hier, also erst wenn Stripe die Zahlung tatsaechlich
// bestaetigt hat. Idempotent wie mark_booking_paid(). Verschickt zusaetzlich
// (anders als bisher) eine Rechnung dafuer - ueber Stripe, da es fuer diese
// kleinen Nachforderungen keine eigene fortlaufende Rechnungsnummer gibt.
function mark_addon_charge_paid(int $addonChargeId, ?string $stripeInvoiceId = null): void
{
    $stmt = db()->prepare('SELECT * FROM booking_addon_charges WHERE id = ?');
    $stmt->execute([$addonChargeId]);
    $charge = $stmt->fetch();

    if (!$charge || $charge['paid_at'] !== null) {
        return;
    }

    db()->prepare('UPDATE booking_addon_charges SET paid_at = NOW() WHERE id = ?')->execute([$addonChargeId]);
    store_stripe_invoice('booking_addon_charges', $addonChargeId, $stripeInvoiceId);

    $stmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
    $stmt->execute([(int) $charge['booking_id']]);
    $booking = $stmt->fetch();
    if ($booking) {
        $stmt = db()->prepare('SELECT stripe_invoice_hosted_url FROM booking_addon_charges WHERE id = ?');
        $stmt->execute([$addonChargeId]);
        $hostedUrl = $stmt->fetchColumn() ?: null;
        send_addon_charge_paid_email($booking, (string) $charge['description'], (int) $charge['amount_cents'], $hostedUrl);
    }

    $pending = $charge['pending_changes_json'] ? json_decode((string) $charge['pending_changes_json'], true) : null;
    if (!is_array($pending)) {
        return;
    }

    $bookingId = (int) $charge['booking_id'];
    $layoutIds = array_map('intval', (array) ($pending['layout_ids'] ?? []));
    $extraSelections = [];
    foreach ((array) ($pending['extra_selections'] ?? []) as $extraId => $qty) {
        $extraSelections[(int) $extraId] = (int) $qty;
    }

    db()->beginTransaction();
    db()->prepare('UPDATE bookings SET event_date = ?, total_price_cents = ?, discount_cents = ?, returning_discount_cents = ? WHERE id = ?')
        ->execute([
            (string) ($pending['event_date'] ?? ''),
            (int) ($pending['total_price_cents'] ?? 0),
            (int) ($pending['discount_cents'] ?? 0),
            (int) ($pending['returning_discount_cents'] ?? 0),
            $bookingId,
        ]);

    db()->prepare('DELETE FROM booking_layouts WHERE booking_id = ?')->execute([$bookingId]);
    $ins = db()->prepare('INSERT INTO booking_layouts (booking_id, layout_id) VALUES (?, ?)');
    foreach ($layoutIds as $layoutId) {
        $ins->execute([$bookingId, $layoutId]);
    }

    db()->prepare('DELETE FROM booking_extras WHERE booking_id = ?')->execute([$bookingId]);
    $ins = db()->prepare('INSERT INTO booking_extras (booking_id, extra_id, quantity) VALUES (?, ?, ?)');
    foreach ($extraSelections as $extraId => $qty) {
        $ins->execute([$bookingId, $extraId, $qty]);
    }
    db()->commit();

    sync_booking_to_box($bookingId);
}

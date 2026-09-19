<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/PHPMailer/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Baut einen fertig konfigurierten PHPMailer auf, oder null wenn SMTP nicht
// eingerichtet ist (includes/config.php) - von allen send_*_email()-
// Funktionen hier gemeinsam genutzt.
function create_smtp_mailer(): ?PHPMailer
{
    $cfg = backend_config();
    if ((string) ($cfg['smtp_host'] ?? '') === '') {
        return null;
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $cfg['smtp_host'];
    $mail->Port = (int) ($cfg['smtp_port'] ?? 587);
    $mail->SMTPAuth = true;
    $mail->Username = (string) ($cfg['smtp_user'] ?? '');
    $mail->Password = (string) ($cfg['smtp_pass'] ?? '');
    $mail->SMTPSecure = $mail->Port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom((string) $cfg['smtp_from_email'], (string) ($cfg['smtp_from_name'] ?? 'Snapolino'));

    return $mail;
}

// Verschickt die Buchungsbestaetigung per SMTP, die Rechnung selbst kommt
// nur noch von Stripe (kein eigenes PDF mehr, siehe mark_booking_paid()) -
// die Mail verlinkt sie stattdessen. Gibt false zurueck (statt zu werfen),
// wenn SMTP nicht konfiguriert ist oder der Versand fehlschlaegt - die
// Buchung bleibt in jedem Fall bestaetigt, ein fehlgeschlagener Mailversand
// darf das nicht rueckgaengig machen.
function send_booking_confirmation_email(array $booking): bool
{
    $mail = create_smtp_mailer();
    if ($mail === null) {
        error_log('Mailversand uebersprungen: smtp_host fehlt in config.php (Buchung #' . $booking['id'] . ')');
        return false;
    }

    try {
        $mail->addAddress($booking['customer_email'], $booking['customer_name']);

        $eventDate = (new DateTimeImmutable($booking['event_date']))->format('d.m.Y');
        $total = money_from_cents((int) $booking['total_price_cents']);
        $fromName = (string) (backend_config()['smtp_from_name'] ?? 'Snapolino');

        $invoiceNote = !empty($booking['stripe_invoice_hosted_url'])
            ? "Die Rechnung dazu findest du hier:\n" . $booking['stripe_invoice_hosted_url'] . "\n\n"
            : '';

        $mail->Subject = 'Buchungsbestätigung – Snapolino (' . $eventDate . ')';
        $mail->Body = "Hallo " . $booking['customer_name'] . ",\n\n"
            . "vielen Dank für deine Buchung! Deine Zahlung über " . $total . " ist eingegangen, "
            . "die Fotobox ist für den " . $eventDate . " fest für dich reserviert.\n\n"
            . $invoiceNote
            . "Wir schicken dir die Box rechtzeitig vor deiner Veranstaltung zu.\n\n"
            . "Viele Grüße\n" . $fromName;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Mailversand fehlgeschlagen (Buchung #' . $booking['id'] . '): ' . $mail->ErrorInfo);
        return false;
    }
}

// Verschickt die Stornobestaetigung, wenn ein Admin eine Buchung storniert
// (siehe cancel_booking() in payments.php). War die Buchung bereits bezahlt,
// wurde die Zahlung ueber Stripe zurueckerstattet und $creditNotePdfUrl
// verlinkt die dazu erstellte Stornorechnung (Stripe Credit Note) - sonst
// (z.B. eine ohne Zahlung bestaetigte Angebots-Buchung) ist $wasRefunded
// false und es gibt nichts zurueckzubuchen.
function send_booking_cancelled_email(array $booking, bool $wasRefunded, ?string $creditNotePdfUrl): bool
{
    $mail = create_smtp_mailer();
    if ($mail === null) {
        error_log('Mailversand uebersprungen: smtp_host fehlt in config.php (Stornierung Buchung #' . $booking['id'] . ')');
        return false;
    }

    try {
        $mail->addAddress($booking['customer_email'], $booking['customer_name']);
        $eventDate = (new DateTimeImmutable($booking['event_date']))->format('d.m.Y');
        $fromName = (string) (backend_config()['smtp_from_name'] ?? 'Snapolino');

        if ($wasRefunded) {
            $total = money_from_cents((int) $booking['total_price_cents']);
            $creditNoteNote = $creditNotePdfUrl
                ? "Die Stornorechnung dazu findest du hier:\n" . $creditNotePdfUrl . "\n\n"
                : '';
            $body = "vielen Dank für deine Nachricht - deine Buchung für den " . $eventDate . " wurde storniert.\n\n"
                . "Deine Zahlung über " . $total . " wird dir über Stripe auf dein urspruengliches "
                . "Zahlungsmittel zurueckerstattet (je nach Zahlungsmittel kann das ein paar Tage dauern).\n\n"
                . $creditNoteNote;
        } else {
            $body = "deine Buchung für den " . $eventDate . " wurde storniert.\n\n";
        }

        $mail->Subject = 'Deine Buchung wurde storniert – Snapolino';
        $mail->Body = "Hallo " . $booking['customer_name'] . ",\n\n" . $body
            . "Bei Fragen melde dich gerne bei uns.\n\n"
            . "Viele Grüße\n" . $fromName;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Mailversand fehlgeschlagen (Stornierung Buchung #' . $booking['id'] . '): ' . $mail->ErrorInfo);
        return false;
    }
}

// Verschickt den Login-Code fuers Kundenkonto (siehe includes/customer_auth.php).
function send_customer_login_code_email(string $email, string $code): bool
{
    $mail = create_smtp_mailer();
    if ($mail === null) {
        error_log('Mailversand uebersprungen: smtp_host fehlt in config.php (Login-Code fuer ' . $email . ')');
        return false;
    }

    try {
        $mail->addAddress($email);
        $fromName = (string) (backend_config()['smtp_from_name'] ?? 'Snapolino');

        $mail->Subject = 'Dein Anmeldecode – Snapolino';
        $mail->Body = "Hallo,\n\n"
            . "dein Anmeldecode fuer dein Snapolino-Konto lautet:\n\n"
            . $code . "\n\n"
            . "Der Code ist " . CUSTOMER_LOGIN_CODE_TTL_MINUTES . " Minuten gueltig. "
            . "Falls du diesen Code nicht angefordert hast, kannst du diese E-Mail ignorieren.\n\n"
            . "Viele Grüße\n" . $fromName;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Mailversand fehlgeschlagen (Login-Code fuer ' . $email . '): ' . $mail->ErrorInfo);
        return false;
    }
}

// Verschickt einen Stripe-Zahlungslink fuer eine nachtraeglich vom Admin
// hinzugefuegte Leistung, die nicht kostenlos uebernommen werden soll
// (siehe booking_addon_charges, admin/booking_detail.php).
function send_addon_charge_payment_link_email(array $booking, string $description, int $amountCents, string $paymentUrl): bool
{
    $mail = create_smtp_mailer();
    if ($mail === null) {
        error_log('Mailversand uebersprungen: smtp_host fehlt in config.php (Zusatzzahlung Buchung #' . $booking['id'] . ')');
        return false;
    }

    try {
        $mail->addAddress($booking['customer_email'], $booking['customer_name']);
        $fromName = (string) (backend_config()['smtp_from_name'] ?? 'Snapolino');

        $mail->Subject = 'Zusaetzliche Zahlung zu deiner Buchung – Snapolino';
        $mail->Body = "Hallo " . $booking['customer_name'] . ",\n\n"
            . "fuer deine Buchung wurde folgende zusaetzliche Leistung vorgeschlagen:\n\n"
            . $description . " – " . money_from_cents($amountCents) . "\n\n"
            . "Sie wird erst nach Zahlungseingang wirksam. Du kannst hier sicher per Kreditkarte, Klarna o.ae. bezahlen:\n"
            . $paymentUrl . "\n\n"
            . "Bei Fragen melde dich gerne bei uns.\n\n"
            . "Viele Grüße\n" . $fromName;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Mailversand fehlgeschlagen (Zusatzzahlung Buchung #' . $booking['id'] . '): ' . $mail->ErrorInfo);
        return false;
    }
}

// Verschickt den Link zur automatischen Online-Galerie (siehe galerie.php,
// ensure_gallery_token()) an die Buchende Person - vom Admin per Knopf in
// booking_detail.php ausgeloest, sobald genug Fotos hochgeladen sind.
function send_gallery_email(array $booking, string $galleryUrl): bool
{
    $mail = create_smtp_mailer();
    if ($mail === null) {
        error_log('Mailversand uebersprungen: smtp_host fehlt in config.php (Galerie-Mail Buchung #' . $booking['id'] . ')');
        return false;
    }

    try {
        $mail->addAddress($booking['customer_email'], $booking['customer_name']);
        $fromName = (string) (backend_config()['smtp_from_name'] ?? 'Snapolino');

        $mail->Subject = 'Eure Fotos sind online – Snapolino';
        $mail->Body = "Hallo " . $booking['customer_name'] . ",\n\n"
            . "die Fotos von eurer Veranstaltung sind jetzt online. Ihr und eure Gäste könnt sie euch hier "
            . "ansehen und herunterladen:\n\n" . $galleryUrl . "\n\n"
            . "Der Link kann gerne an alle Gäste weitergegeben werden.\n\n"
            . "Viele Grüße\n" . $fromName;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Mailversand fehlgeschlagen (Galerie-Mail Buchung #' . $booking['id'] . '): ' . $mail->ErrorInfo);
        return false;
    }
}

// Verschickt die Bestaetigung, sobald eine Zusatzzahlung tatsaechlich
// eingegangen ist (siehe mark_addon_charge_paid()). Fuer diese kleinen
// Nachforderungen gibt es keine eigene fortlaufende Rechnungsnummer wie bei
// der Hauptbuchung - die Rechnung dafuer stellt stattdessen Stripe aus
// (invoice_creation, siehe stripe.php), $hostedInvoiceUrl verlinkt sie.
function send_addon_charge_paid_email(array $booking, string $description, int $amountCents, ?string $hostedInvoiceUrl): bool
{
    $mail = create_smtp_mailer();
    if ($mail === null) {
        error_log('Mailversand uebersprungen: smtp_host fehlt in config.php (Zusatzzahlung bezahlt, Buchung #' . $booking['id'] . ')');
        return false;
    }

    try {
        $mail->addAddress($booking['customer_email'], $booking['customer_name']);
        $fromName = (string) (backend_config()['smtp_from_name'] ?? 'Snapolino');
        $invoiceNote = $hostedInvoiceUrl
            ? "\nDie Rechnung dazu findest du hier:\n" . $hostedInvoiceUrl . "\n"
            : '';

        $mail->Subject = 'Zahlung erhalten – Snapolino';
        $mail->Body = "Hallo " . $booking['customer_name'] . ",\n\n"
            . "danke, deine Zahlung ist eingegangen:\n\n"
            . $description . " – " . money_from_cents($amountCents) . "\n\n"
            . "Die Aenderung ist jetzt auf deiner Buchung aktiv.\n" . $invoiceNote . "\n"
            . "Bei Fragen melde dich gerne bei uns.\n\n"
            . "Viele Grüße\n" . $fromName;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Mailversand fehlgeschlagen (Zusatzzahlung bezahlt, Buchung #' . $booking['id'] . '): ' . $mail->ErrorInfo);
        return false;
    }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/PHPMailer/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Verschickt die Buchungsbestaetigung mit der Rechnung als Anhang per SMTP.
// Gibt false zurueck (statt zu werfen), wenn SMTP nicht konfiguriert ist
// oder der Versand fehlschlaegt - die Buchung bleibt in jedem Fall bestaetigt,
// ein fehlgeschlagener Mailversand darf das nicht rueckgaengig machen.
function send_booking_confirmation_email(array $booking, string $invoicePdfPath): bool
{
    $cfg = backend_config();
    if ((string) ($cfg['smtp_host'] ?? '') === '') {
        error_log('Mailversand uebersprungen: smtp_host fehlt in config.php (Buchung #' . $booking['id'] . ')');
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $cfg['smtp_host'];
        $mail->Port = (int) ($cfg['smtp_port'] ?? 587);
        $mail->SMTPAuth = true;
        $mail->Username = (string) ($cfg['smtp_user'] ?? '');
        $mail->Password = (string) ($cfg['smtp_pass'] ?? '');
        $mail->SMTPSecure = $mail->Port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom((string) $cfg['smtp_from_email'], (string) ($cfg['smtp_from_name'] ?? 'Snapolino'));
        $mail->addAddress($booking['customer_email'], $booking['customer_name']);
        $mail->addAttachment($invoicePdfPath, 'Rechnung-' . $booking['invoice_number'] . '.pdf');

        $eventDate = (new DateTimeImmutable($booking['event_date']))->format('d.m.Y');
        $total = money_from_cents((int) $booking['total_price_cents']);

        $mail->Subject = 'Buchungsbestätigung & Rechnung – Snapolino (' . $eventDate . ')';
        $mail->Body = "Hallo " . $booking['customer_name'] . ",\n\n"
            . "vielen Dank für deine Buchung! Deine Zahlung über " . $total . " ist eingegangen, "
            . "die Fotobox ist für den " . $eventDate . " fest für dich reserviert.\n\n"
            . "Die Rechnung findest du im Anhang dieser E-Mail.\n\n"
            . "Wir schicken dir die Box rechtzeitig vor deiner Veranstaltung zu.\n\n"
            . "Viele Grüße\n" . (string) ($cfg['smtp_from_name'] ?? 'Snapolino');

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Mailversand fehlgeschlagen (Buchung #' . $booking['id'] . '): ' . $mail->ErrorInfo);
        return false;
    }
}

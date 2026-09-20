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

// ---------- HTML-E-Mail-Vorlage ----------
//
// Alle send_*_email()-Funktionen bauen ihren Inhalt ueber email_p()/
// email_button() zusammen und uebergeben ihn render_email_html(), das daraus
// den fertigen, zentrierten HTML-Body macht. Tabellenbasierter Aufbau (nicht
// nur divs mit CSS margin:auto), weil Outlook Desktop HTML-Mails mit der
// Word-Engine rendert und viel modernes CSS (inkl. zuverlaessiger Zentrierung
// per Flexbox/margin:auto) dort ignoriert - Tabellen mit align="center"
// funktionieren dagegen ueberall gleich. $mail->AltBody bekommt weiterhin
// eine reine Textversion (direkt in jeder send_*_email()-Funktion gesetzt)
// fuer Clients ohne HTML-Anzeige und als Spam-Filter-freundliches Feature.

// Bettet das Logo direkt in die Mail ein (Content-ID, <img src="cid:...">)
// statt es extern zu verlinken - zeigt es unabhaengig von Bilderblockierung
// des Mail-Clients sofort an und wirkt nicht wie eine "nachladende" externe
// Ressource, was manche Spam-Filter misstrauisch macht.
function email_embed_logo(PHPMailer $mail): string
{
    $path = __DIR__ . '/../public/assets/logo-icon.png';
    if (!is_file($path)) {
        return '';
    }
    $cid = 'logo';
    $mail->addEmbeddedImage($path, $cid, 'logo-icon.png');
    return $cid;
}

function render_email_html(PHPMailer $mail, string $heading, string $bodyHtml, string $preheader = ''): string
{
    $logoCid = email_embed_logo($mail);
    $logoImg = $logoCid !== ''
        ? '<img src="cid:' . $logoCid . '" width="56" height="56" alt="Snapolino" style="display:block;margin:0 auto 10px;border-radius:14px;">'
        : '';
    $preheaderHtml = $preheader !== ''
        ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . htmlspecialchars($preheader, ENT_QUOTES) . '</div>'
        : '';
    $headingHtml = htmlspecialchars($heading, ENT_QUOTES);

    return <<<HTML
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>Snapolino</title>
</head>
<body style="margin:0;padding:0;background:#fffaf5;font-family:'Segoe UI',Arial,sans-serif;">
{$preheaderHtml}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fffaf5;">
<tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;">
<tr><td align="center" style="padding-bottom:22px;">
{$logoImg}
<div style="font-size:20px;font-weight:700;color:#241b3a;">Snapolino</div>
</td></tr>
<tr><td style="background:#ffffff;border-radius:16px;padding:36px 32px;box-shadow:0 2px 16px rgba(36,27,58,0.08);">
<h1 style="margin:0 0 18px;font-size:21px;line-height:1.3;color:#241b3a;text-align:center;">{$headingHtml}</h1>
{$bodyHtml}
</td></tr>
<tr><td align="center" style="padding:22px 12px 0;font-size:12px;line-height:1.6;color:#9089a3;">
Diese E-Mail wurde automatisch von Snapolino verschickt.
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

function email_p(string $html): string
{
    return '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#2c2440;">' . $html . '</p>';
}

function email_muted(string $html): string
{
    return '<p style="margin:0 0 16px;font-size:13px;line-height:1.5;color:#7d7490;">' . $html . '</p>';
}

function email_button(string $url, string $label): string
{
    $url = htmlspecialchars($url, ENT_QUOTES);
    $label = htmlspecialchars($label, ENT_QUOTES);
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px auto 22px;">'
        . '<tr><td style="border-radius:10px;background:#ff6f59;">'
        . '<a href="' . $url . '" style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;'
        . 'color:#ffffff;text-decoration:none;border-radius:10px;">' . $label . '</a>'
        . '</td></tr></table>';
}

function email_signoff(string $fromName): string
{
    return email_p('Viele Grüße<br>' . htmlspecialchars($fromName, ENT_QUOTES));
}

function email_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES);
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
        $hostedInvoiceUrl = (string) ($booking['stripe_invoice_hosted_url'] ?? '');

        $mail->Subject = 'Buchungsbestätigung – Snapolino (' . $eventDate . ')';

        $mail->isHTML(true);
        $html = email_p('Hallo ' . email_e($booking['customer_name']) . ',')
            . email_p('vielen Dank für deine Buchung! Deine Zahlung über <strong>' . email_e($total) . '</strong> ist '
                . 'eingegangen, die Fotobox ist für den <strong>' . email_e($eventDate) . '</strong> fest für dich reserviert.')
            . ($hostedInvoiceUrl !== '' ? email_button($hostedInvoiceUrl, 'Rechnung ansehen') : '')
            . email_p('Wir schicken dir die Box rechtzeitig vor deiner Veranstaltung zu.')
            . email_signoff($fromName);
        $mail->Body = render_email_html($mail, 'Buchung bestätigt 🎉', $html, 'Deine Zahlung ist eingegangen - die Box ist für dich reserviert.');

        $invoiceNote = $hostedInvoiceUrl !== ''
            ? "Die Rechnung dazu findest du hier:\n" . $hostedInvoiceUrl . "\n\n"
            : '';
        $mail->AltBody = "Hallo " . $booking['customer_name'] . ",\n\n"
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

        $mail->Subject = 'Deine Buchung wurde storniert – Snapolino';
        $mail->isHTML(true);

        $intro = email_p('Hallo ' . email_e($booking['customer_name']) . ',');

        if ($wasRefunded) {
            $total = money_from_cents((int) $booking['total_price_cents']);
            $html = $intro
                . email_p('vielen Dank für deine Nachricht - deine Buchung für den <strong>' . email_e($eventDate)
                    . '</strong> wurde storniert.')
                . email_p('Deine Zahlung über <strong>' . email_e($total) . '</strong> wird dir über Stripe auf dein '
                    . 'ursprüngliches Zahlungsmittel zurückerstattet (je nach Zahlungsmittel kann das ein paar Tage dauern).')
                . ($creditNotePdfUrl ? email_button($creditNotePdfUrl, 'Stornorechnung ansehen') : '')
                . email_p('Bei Fragen melde dich gerne bei uns.')
                . email_signoff($fromName);

            $creditNoteNote = $creditNotePdfUrl
                ? "Die Stornorechnung dazu findest du hier:\n" . $creditNotePdfUrl . "\n\n"
                : '';
            $plainBody = "vielen Dank für deine Nachricht - deine Buchung für den " . $eventDate . " wurde storniert.\n\n"
                . "Deine Zahlung über " . $total . " wird dir über Stripe auf dein urspruengliches "
                . "Zahlungsmittel zurueckerstattet (je nach Zahlungsmittel kann das ein paar Tage dauern).\n\n"
                . $creditNoteNote;
        } else {
            $html = $intro
                . email_p('deine Buchung für den <strong>' . email_e($eventDate) . '</strong> wurde storniert.')
                . email_p('Bei Fragen melde dich gerne bei uns.')
                . email_signoff($fromName);
            $plainBody = "deine Buchung für den " . $eventDate . " wurde storniert.\n\n";
        }

        $mail->Body = render_email_html($mail, 'Buchung storniert', $html, 'Deine Buchung wurde storniert.');
        $mail->AltBody = "Hallo " . $booking['customer_name'] . ",\n\n" . $plainBody
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
        $mail->isHTML(true);

        $codeBox = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px auto 22px;">'
            . '<tr><td style="background:#f5f1fc;border:1px dashed #6c5ce7;border-radius:12px;padding:16px 32px;">'
            . '<span style="font-size:30px;font-weight:700;letter-spacing:10px;color:#241b3a;font-family:monospace;">'
            . email_e($code) . '</span></td></tr></table>';

        $html = email_p('Hallo,')
            . email_p('dein Anmeldecode für dein Snapolino-Konto lautet:')
            . $codeBox
            . email_muted('Der Code ist ' . CUSTOMER_LOGIN_CODE_TTL_MINUTES . ' Minuten gültig. Falls du diesen Code '
                . 'nicht angefordert hast, kannst du diese E-Mail ignorieren.')
            . email_signoff($fromName);
        $mail->Body = render_email_html($mail, 'Dein Anmeldecode', $html, 'Dein Anmeldecode: ' . $code);

        $mail->AltBody = "Hallo,\n\n"
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
        $amount = money_from_cents($amountCents);

        $mail->Subject = 'Zusaetzliche Zahlung zu deiner Buchung – Snapolino';
        $mail->isHTML(true);

        $html = email_p('Hallo ' . email_e($booking['customer_name']) . ',')
            . email_p('für deine Buchung wurde folgende zusätzliche Leistung vorgeschlagen:')
            . email_p('<strong>' . email_e($description) . '</strong> – ' . email_e($amount))
            . email_muted('Sie wird erst nach Zahlungseingang wirksam.')
            . email_button($paymentUrl, 'Jetzt bezahlen')
            . email_p('Du kannst sicher per Kreditkarte, Klarna o.ä. bezahlen.')
            . email_p('Bei Fragen melde dich gerne bei uns.')
            . email_signoff($fromName);
        $mail->Body = render_email_html($mail, 'Zusätzliche Zahlung', $html, $description . ' – ' . $amount);

        $mail->AltBody = "Hallo " . $booking['customer_name'] . ",\n\n"
            . "fuer deine Buchung wurde folgende zusaetzliche Leistung vorgeschlagen:\n\n"
            . $description . " – " . $amount . "\n\n"
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
// $galleryUrl ist der Verwalter-Link (siehe ensure_gallery_token()) - die
// Buchende Person kann darauf Fotos aus-/einblenden und findet dort selbst
// den separaten, read-only Gaeste-Link zum Weitergeben (siehe galerie.php).
// $deletionDateFormatted (d.m.Y) macht die DSGVO-Aufbewahrungsfrist bereits
// in der Mail transparent, nicht erst beim Aufruf der Galerie.
function send_gallery_email(array $booking, string $galleryUrl, string $deletionDateFormatted): bool
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
        $mail->isHTML(true);

        $html = email_p('Hallo ' . email_e($booking['customer_name']) . ',')
            . email_p('die Fotos von eurer Veranstaltung sind jetzt online. Über den Link unten könnt ihr sie '
                . 'euch ansehen, herunterladen und bei Bedarf einzelne Fotos ausblenden.')
            . email_button($galleryUrl, 'Galerie öffnen')
            . email_p('Auf der Seite findet ihr außerdem einen separaten Link zum Weitergeben an eure Gäste - '
                . 'der zeigt nur die Fotos, die ihr nicht ausgeblendet habt.')
            . email_muted('Aus Datenschutzgründen werden die Fotos automatisch am <strong>' . email_e($deletionDateFormatted)
                . '</strong> gelöscht - lädt euch gewünschte Fotos vorher herunter.')
            . email_signoff($fromName);
        $mail->Body = render_email_html($mail, 'Eure Fotos sind online 📸', $html, 'Eure Fotos von der Veranstaltung sind jetzt online.');

        $mail->AltBody = "Hallo " . $booking['customer_name'] . ",\n\n"
            . "die Fotos von eurer Veranstaltung sind jetzt online. Über den Link unten könnt ihr sie euch ansehen, "
            . "herunterladen und bei Bedarf einzelne Fotos ausblenden:\n\n" . $galleryUrl . "\n\n"
            . "Auf der Seite findet ihr ausserdem einen separaten Link zum Weitergeben an eure Gaeste - der zeigt "
            . "nur die Fotos, die ihr nicht ausgeblendet habt.\n\n"
            . "Aus Datenschutzgruenden werden die Fotos automatisch am " . $deletionDateFormatted . " geloescht - "
            . "ladet euch gewuenschte Fotos vorher herunter.\n\n"
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
        $amount = money_from_cents($amountCents);

        $mail->Subject = 'Zahlung erhalten – Snapolino';
        $mail->isHTML(true);

        $html = email_p('Hallo ' . email_e($booking['customer_name']) . ',')
            . email_p('danke, deine Zahlung ist eingegangen:')
            . email_p('<strong>' . email_e($description) . '</strong> – ' . email_e($amount))
            . email_p('Die Änderung ist jetzt auf deiner Buchung aktiv.')
            . ($hostedInvoiceUrl ? email_button($hostedInvoiceUrl, 'Rechnung ansehen') : '')
            . email_p('Bei Fragen melde dich gerne bei uns.')
            . email_signoff($fromName);
        $mail->Body = render_email_html($mail, 'Zahlung erhalten ✅', $html, $description . ' – ' . $amount);

        $invoiceNote = $hostedInvoiceUrl
            ? "\nDie Rechnung dazu findest du hier:\n" . $hostedInvoiceUrl . "\n"
            : '';
        $mail->AltBody = "Hallo " . $booking['customer_name'] . ",\n\n"
            . "danke, deine Zahlung ist eingegangen:\n\n"
            . $description . " – " . $amount . "\n\n"
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

<?php
declare(strict_types=1);

$pageTitle = 'Datenschutzerklärung';
require __DIR__ . '/_site_header.php';

$businessName = (string) get_setting('business_name', '');
$businessAddressRaw = (string) get_setting('business_address', '');
$businessPhone = (string) get_setting('business_phone', '');

$addressLines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $businessAddressRaw))));
$addressInline = $addressLines ? implode(', ', $addressLines) : '';
?>

<section class="section legal-page">
    <h2>Datenschutzerklärung</h2>

    <div class="panel-box">
        <h3>1. Verantwortlicher</h3>
        <p>
            Verantwortlicher im Sinne der Datenschutz-Grundverordnung (DSGVO) ist:<br>
            <?= legal_value($businessName, 'Name/Firma bitte im Panel unter Einstellungen ergänzen') ?><br>
            <?= legal_value($addressInline, 'Anschrift bitte im Panel unter Einstellungen ergänzen') ?><br>
            E-Mail: <?= legal_value($contactEmail, 'bitte im Panel unter Einstellungen ergänzen') ?>
            <?php if ($businessPhone !== ''): ?><br>Telefon: <?= htmlspecialchars($businessPhone, ENT_QUOTES) ?><?php endif; ?>
        </p>

        <h3>2. Welche Daten wir verarbeiten</h3>
        <p><strong>Beim Besuch dieser Website:</strong> Unser Hosting-Anbieter speichert
            automatisch sogenannte Server-Logfiles (u.a. IP-Adresse, Datum/Uhrzeit des Zugriffs,
            aufgerufene Seite, verwendeter Browser). Diese Daten dienen ausschließlich dem
            sicheren und stabilen Betrieb der Website (Art. 6 Abs. 1 lit. f DSGVO, berechtigtes
            Interesse) und werden nicht mit anderen Datenquellen zusammengeführt.</p>
        <p><strong>Bei einer Buchungsanfrage:</strong> Wenn du über den Buchungsassistenten einen
            Termin anfragst, verarbeiten wir die von dir eingegebenen Daten - Name, E-Mail-Adresse,
            Telefonnummer, Rechnungsadresse, Eventdatum, gewähltes Design sowie gebuchte Extras -,
            um deine Anfrage zu bearbeiten und den Mietvertrag durchzuführen (Art. 6 Abs. 1 lit. b
            DSGVO, Vertragserfüllung bzw. vorvertragliche Maßnahmen).</p>
        <p><strong>Bei einer Zahlung:</strong> Für die Zahlungsabwicklung nutzen wir den
            Zahlungsdienstleister Stripe (Stripe Payments Europe, Ltd.). Dabei werden die für die
            Zahlung notwendigen Daten (u.a. Name, Zahlungsbetrag, Zahlungsmittel) direkt an Stripe
            übermittelt - Kartendaten erreichen unseren eigenen Server nie. Es gilt zusätzlich die
            Datenschutzerklärung von Stripe:
            <a href="https://stripe.com/de/privacy" target="_blank" rel="noopener">stripe.com/de/privacy</a>.
            Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO.</p>
        <p><strong>Für Rechnung und Bestätigung:</strong> Nach erfolgreicher Buchung versenden wir
            eine Bestätigungs-E-Mail mit Rechnung über unser eigenes Postfach bei unserem
            E-Mail-Provider (Art. 6 Abs. 1 lit. b und lit. c DSGVO, Vertragserfüllung und
            handelsrechtliche Aufbewahrungspflicht).</p>
        <p><strong>Für den Versand:</strong> Für den Versand der Fotobox zu deiner Veranstaltung
            und zurück geben wir deinen Namen und deine Lieferadresse an unseren
            Versanddienstleister
            <?= legal_value('', 'bitte ergänzen, z.B. DHL/Hermes/DPD') ?> weiter (Art. 6 Abs. 1
            lit. b DSGVO).</p>

        <h3>3. Cookies</h3>
        <p>Diese Website verwendet ausschließlich ein technisch notwendiges Session-Cookie, das
            zur Absicherung des Buchungsformulars gegen Cross-Site-Request-Forgery (CSRF) benötigt
            wird. Es enthält keine personenbezogenen Angaben, wird nach Verlassen der Seite bzw.
            nach kurzer Zeit automatisch ungültig und dient keinerlei Analyse- oder
            Marketingzwecken. Für dieses rein technisch notwendige Cookie ist gemäß § 25 Abs. 2 Nr.
            2 TTDSG keine gesonderte Einwilligung erforderlich (Rechtsgrundlage: Art. 6 Abs. 1 lit.
            f DSGVO).</p>

        <h3>4. Speicherdauer</h3>
        <p>Wir speichern deine Buchungsdaten so lange, wie es für die Durchführung des
            Mietvertrags erforderlich ist, sowie anschließend im Rahmen der gesetzlichen
            Aufbewahrungsfristen für Geschäfts- und Rechnungsunterlagen (derzeit in der Regel 8
            Jahre gemäß § 147 AO, § 257 HGB). Unverbindliche Reservierungen, die nicht zu einer
            Buchung werden, löschen wir automatisch nach Ablauf der Reservierungsfrist.</p>

        <h3>5. Deine Rechte</h3>
        <p>Dir stehen nach der DSGVO folgende Rechte bezüglich deiner personenbezogenen Daten zu:</p>
        <ul>
            <li>Auskunft (Art. 15 DSGVO)</li>
            <li>Berichtigung (Art. 16 DSGVO)</li>
            <li>Löschung (Art. 17 DSGVO), soweit keine gesetzlichen Aufbewahrungspflichten
                entgegenstehen</li>
            <li>Einschränkung der Verarbeitung (Art. 18 DSGVO)</li>
            <li>Datenübertragbarkeit (Art. 20 DSGVO)</li>
            <li>Widerspruch gegen die Verarbeitung (Art. 21 DSGVO)</li>
            <li>Beschwerde bei einer Datenschutz-Aufsichtsbehörde (Art. 77 DSGVO)</li>
        </ul>
        <p>Wende dich dafür einfach an die oben genannte Kontakt-E-Mail-Adresse.</p>

        <h3>6. Sicherheit</h3>
        <p>Diese Website wird verschlüsselt per SSL/TLS (https) übertragen, ebenso alle über den
            Buchungsassistenten gesendeten Formulardaten.</p>
    </div>
</section>

<?php require __DIR__ . '/_site_footer.php'; ?>

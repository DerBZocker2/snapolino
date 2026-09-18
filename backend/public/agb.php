<?php
declare(strict_types=1);

$pageTitle = 'AGB';
require __DIR__ . '/_site_header.php';

$businessName = (string) get_setting('business_name', '');
$businessAddressRaw = (string) get_setting('business_address', '');
$addressLines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $businessAddressRaw))));
$addressInline = $addressLines ? implode(', ', $addressLines) : '';
?>

<section class="section legal-page">
    <h2>Allgemeine Geschäftsbedingungen</h2>

    <div class="panel-box">
        <h3>1. Geltungsbereich</h3>
        <p>Diese Allgemeinen Geschäftsbedingungen gelten für alle Verträge über die Miete einer
            Fotobox zwischen
            <?= legal_value($businessName, 'Name/Firma bitte im Panel unter Einstellungen ergänzen') ?>
            (<?= legal_value($addressInline, 'Anschrift bitte ergänzen') ?>, nachfolgend
            "Snapolino") und dem Kunden (nachfolgend "Mieter"), die über unseren
            Online-Buchungsassistenten unter /buchen.php geschlossen werden.</p>

        <h3>2. Leistungsbeschreibung</h3>
        <p>Snapolino vermietet eine Selbstbedienungs-Fotobox samt Zubehör (Rahmen/Layouts gemäß
            gewählter Buchung, ggf. Fotodrucker inkl. Verbrauchsmaterial) für den vom Mieter
            angegebenen Zeitraum. Die Fotobox wird dem Mieter rechtzeitig vor dem Event zugesandt
            und ist vom Mieter eigenständig aufzubauen und zu bedienen; eine Anleitung liegt bei.</p>

        <h3>3. Vertragsschluss</h3>
        <p>Der Mieter wählt im Buchungsassistenten einen freien Termin, hinterlegt seine Kontakt-
            und Rechnungsdaten sowie das gewünschte Design und optionale Extras. Mit dem Absenden
            der Anfrage wird der Termin für andere Buchungen gesperrt, bis wir sie bearbeitet
            haben. Der Vertrag kommt zustande, sobald entweder die Zahlung über unseren
            Zahlungsdienstleister Stripe erfolgreich abgeschlossen wurde oder wir eine
            angeforderte Buchung ohne Sofortzahlung ausdrücklich bestätigen. Wählt der Mieter
            stattdessen "nur ein schriftliches Angebot", entsteht durch die Anfrage allein noch
            kein Vertrag.</p>

        <h3>4. Preise und Zahlung</h3>
        <p>Es gelten die zum Zeitpunkt der Buchung im Buchungsassistenten angezeigten Preise
            (Grundpreis zzgl. gewählter Zusatzformate und Extras, abzüglich eines eventuell
            eingelösten Gutscheins). Die Zahlung erfolgt online über unseren Zahlungsdienstleister
            Stripe (Kreditkarte, Klarna u.a.). Nach erfolgreicher Zahlung erhält der Mieter eine
            Bestätigung nebst Rechnung per E-Mail.</p>

        <h3>5. Versand, Nutzungszeitraum und Rückgabe</h3>
        <p>Die Fotobox wird rechtzeitig vor dem Eventdatum an den Mieter versandt und ist
            innerhalb von <?= BOOKING_BUFFER_DAYS ?> Tagen nach dem Event vollständig, unbeschädigt
            und in der mitgelieferten Verpackung an Snapolino zurückzusenden. Die Fotobox sperrt
            sich softwareseitig automatisch kurz nach diesem Zeitraum und weist den Mieter zur
            Rücksendung an. Verzögert sich die Rücksendung über diesen Zeitraum hinaus, kann
            Snapolino die dadurch entstehenden Mehrkosten (z.B. entgangene Folgebuchungen) in
            angemessenem Umfang in Rechnung stellen.</p>

        <h3>6. Pflichten des Mieters</h3>
        <p>Der Mieter verpflichtet sich, die Fotobox und das Zubehör pfleglich zu behandeln, sie
            ausschließlich bestimmungsgemäß zu nutzen und vor Feuchtigkeit, Sturz und unbefugtem
            Zugriff zu schützen. Der Mieter haftet für Schäden an der Fotobox oder dem Zubehör, die
            durch unsachgemäße Behandlung während der Mietzeit entstehen, sowie für Verlust,
            jeweils im Rahmen der gesetzlichen Bestimmungen.</p>

        <h3>7. Stornierung durch den Mieter</h3>
        <p>Solange eine Anfrage noch nicht bestätigt bzw. bezahlt ist und damit noch kein Vertrag
            zustande gekommen ist (siehe Ziffer 3), kann der Mieter jederzeit kostenfrei davon
            zurücktreten. Für bereits bestätigte bzw. bezahlte Buchungen gilt:</p>
        <ul>
            <li>Stornierung bis 30 Tage vor dem Eventdatum: kostenfrei, bereits gezahlte Beträge
                werden vollständig erstattet.</li>
            <li>Stornierung 29 bis 14 Tage vor dem Eventdatum: 50 % des vereinbarten Mietpreises
                werden einbehalten.</li>
            <li>Stornierung weniger als 14 Tage vor dem Eventdatum: der vollständige Mietpreis
                wird einbehalten.</li>
        </ul>
        <p>Eine Stornierung ist per E-Mail an unsere oben im Impressum genannte Adresse zu
            erklären.</p>

        <h3>8. Rücktritt durch Snapolino</h3>
        <p>Sollte die gebuchte Fotobox aus von Snapolino nicht zu vertretenden Gründen (z.B.
            technischer Totalschaden bei einer vorherigen Nutzung, Verlust auf dem Rückversand)
            nicht rechtzeitig zur Verfügung stehen, informiert Snapolino den Mieter unverzüglich
            und erstattet bereits geleistete Zahlungen vollständig.</p>

        <h3>9. Haftungsbeschränkung</h3>
        <p>Snapolino haftet unbeschränkt für Vorsatz und grobe Fahrlässigkeit sowie nach den
            Vorschriften des Produkthaftungsgesetzes und für Schäden aus der Verletzung des
            Lebens, des Körpers oder der Gesundheit. Für leicht fahrlässig verursachte Schäden
            haftet Snapolino nur bei der Verletzung wesentlicher Vertragspflichten
            (Kardinalpflichten) und der Höhe nach begrenzt auf den vertragstypisch vorhersehbaren
            Schaden. Im Übrigen ist die Haftung für leichte Fahrlässigkeit ausgeschlossen.</p>

        <h3>10. Widerrufsrecht</h3>
        <p>Da es sich bei der Miete der Fotobox um eine Dienstleistung im Zusammenhang mit einer
            Freizeitbetätigung handelt, für deren Erbringung ein spezifischer Termin vorgesehen ist
            (dein gewähltes Eventdatum), besteht für über unseren Online-Buchungsassistenten
            geschlossene Verträge kein gesetzliches Widerrufsrecht gemäß § 312g Abs. 2 Nr. 9 BGB.
            Unabhängig davon kann der Mieter im Rahmen der in Ziffer 7 genannten Fristen und
            Bedingungen von der Buchung zurücktreten.</p>

        <h3>11. Datenschutz</h3>
        <p>Informationen zur Verarbeitung personenbezogener Daten findest du in unserer
            <a href="/datenschutz.php">Datenschutzerklärung</a>.</p>

        <h3>12. Schlussbestimmungen</h3>
        <p>Es gilt das Recht der Bundesrepublik Deutschland unter Ausschluss des
            UN-Kaufrechts. Ist der Mieter Verbraucher, gilt dies nur insoweit, als dadurch der
            durch zwingende Bestimmungen des Staates seines gewöhnlichen Aufenthaltsorts gewährte
            Schutz nicht entzogen wird. Sollte eine Bestimmung dieser AGB unwirksam sein, bleibt
            die Wirksamkeit der übrigen Bestimmungen unberührt.</p>
    </div>
</section>

<?php require __DIR__ . '/_site_footer.php'; ?>

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
    <h2>📜 Allgemeine Geschäftsbedingungen</h2>

    <div class="panel-box">
        <h3>1. 📍 Geltungsbereich</h3>
        <p>Diese Allgemeinen Geschäftsbedingungen gelten für alle Verträge über die Miete einer
            Fotobox zwischen
            <?= legal_value($businessName, 'Name/Firma bitte im Panel unter Einstellungen ergänzen') ?>
            (<?= legal_value($addressInline, 'Anschrift bitte ergänzen') ?>, nachfolgend
            "Snapolino") und dem Kunden (nachfolgend "Mieter"), die über unseren
            Online-Buchungsassistenten unter /buchen.php geschlossen werden.</p>

        <h3>2. 📦 Leistungsbeschreibung</h3>
        <p>Snapolino vermietet eine Selbstbedienungs-Fotobox samt Zubehör (Rahmen/Layouts gemäß
            gewählter Buchung, ggf. Fotodrucker inkl. Verbrauchsmaterial) für den vom Mieter
            angegebenen Zeitraum. Die Fotobox wird dem Mieter rechtzeitig vor dem Event zugesandt
            und ist vom Mieter eigenständig aufzubauen und zu bedienen; eine Anleitung liegt bei.</p>

        <h3>3. ✍️ Vertragsschluss</h3>
        <p>Der Mieter wählt im Buchungsassistenten einen freien Termin, hinterlegt seine Kontakt-
            und Rechnungsdaten sowie das gewünschte Design und optionale Extras. Mit dem Absenden
            der Anfrage wird der Termin für andere Buchungen gesperrt, bis wir sie bearbeitet
            haben. Der Vertrag kommt zustande, sobald entweder die Zahlung über unseren
            Zahlungsdienstleister Stripe erfolgreich abgeschlossen wurde oder wir eine
            angeforderte Buchung ohne Sofortzahlung ausdrücklich bestätigen. Wählt der Mieter
            stattdessen "nur ein schriftliches Angebot", entsteht durch die Anfrage allein noch
            kein Vertrag.</p>

        <h3>4. 💳 Preise und Zahlung</h3>
        <p>Es gelten die zum Zeitpunkt der Buchung im Buchungsassistenten angezeigten Preise
            (Grundpreis zzgl. gewählter Zusatzformate und Extras, abzüglich eines eventuell
            eingelösten Gutscheins). Die Zahlung erfolgt online über unseren Zahlungsdienstleister
            Stripe (Kreditkarte, Klarna u.a.). Nach erfolgreicher Zahlung erhält der Mieter eine
            Bestätigung nebst Rechnung per E-Mail.</p>

        <h3>5. 🚚 Versand, Nutzungszeitraum und Rückgabe</h3>
        <p>Die Fotobox wird rechtzeitig vor dem Eventdatum an den Mieter versandt. Bei Erhalt hat
            der Mieter das Paket sowie die Fotobox unverzüglich auf äußere und innere
            Transportschäden zu überprüfen und diese uns umgehend in Textform (z.B. per E-Mail an
            <?= legal_value($contactEmail, 'bitte im Panel unter Einstellungen ergänzen') ?>) zu
            melden; Beschädigungen am Paket sind zusätzlich sofort dem Versanddienstleister zu
            melden, damit dieser sie registrieren kann.</p>
        <p>Die Fotobox ist innerhalb von <?= BOOKING_BUFFER_DAYS ?> Tagen nach dem Event
            vollständig, unbeschädigt und in der mitgelieferten Verpackung an Snapolino
            zurückzusenden. Die Fotobox sperrt sich softwareseitig automatisch kurz nach diesem
            Zeitraum und weist den Mieter zur Rücksendung an. Erfolgt die Rücksendung nicht
            innerhalb dieser Frist, berechnen wir für jeden weiteren Tag der Verspätung pauschal
            125 € inkl. MwSt. Dem Mieter steht es frei, nachzuweisen, dass ein Schaden nicht
            entstanden oder wesentlich niedriger als die Pauschale ist - in diesem Fall entfällt
            die Entschädigung insoweit.</p>

        <h3>6. 🤝 Pflichten des Mieters, Haftung bei Schäden</h3>
        <p>Der Mieter verpflichtet sich, die Fotobox und das Zubehör pfleglich zu behandeln, sie
            ausschließlich bestimmungsgemäß und entsprechend der beiliegenden Anleitung/
            Sicherheitshinweise zu nutzen, sie so aufzustellen, dass sie keine Notausgänge oder
            Fluchtwege versperrt, und sie vor Feuchtigkeit, Sturz und unbefugtem Zugriff zu
            schützen.</p>
        <p>Für während der Mietzeit durch den Mieter oder seine Gäste verursachte Schäden an der
            Fotobox oder dem Zubehör sowie für Verlust haftet der Mieter mit einer maximalen
            Selbstbeteiligung von 150 €. Diese Haftungsbegrenzung gilt <strong>nicht</strong> für
            Schäden, die durch Nichtbeachtung der Aufbau-/Bedienanleitung oder der
            Sicherheitshinweise, durch unbefugtes Öffnen der Fotobox oder durch nicht
            bestimmungsgemäße Verwendung entstehen - hierfür haftet der Mieter im Rahmen der
            gesetzlichen Bestimmungen unbeschränkt.</p>

        <h3>7. 🖨️ Eigentum an Verbrauchsmaterial, USB-Stick und Collage</h3>
        <p>Fotobox und Zubehör bleiben Eigentum von Snapolino; das gilt auch für nicht
            verbrauchtes Druckmaterial (Farbband und Fotopapier). Nicht verbrauchtes
            Druckmaterial ist zusammen mit der Fotobox zurückzusenden, einschließlich
            angebrochener Rollen. Erst durch den Ausdruck über die Fotobox geht das jeweilige
            bedruckte Foto/die Collage in das Eigentum des Mieters über. Wird nicht verbrauchtes
            Druckmaterial nicht oder unvollständig zurückgegeben, kann Snapolino den
            Wiederbeschaffungswert in Rechnung stellen; dem Mieter steht der Nachweis offen, dass
            kein oder ein wesentlich geringerer Schaden entstanden ist.</p>
        <p>Steckt während der Veranstaltung ein USB-Stick an der Fotobox (z. B. ein vom Mieter
            selbst mitgebrachter), kopiert die Fotobox automatisch alle Fotos und Collagen
            zusätzlich darauf; ein USB-Stick ist nicht Bestandteil der Buchung. Urheber- und
            Verwertungsrechte an den Fotos verbleiben beim Mieter bzw. den fotografierten
            Personen. Zur technischen Umsetzung verbleiben die Fotos zusätzlich im internen
            Speicher der Fotobox und werden dort nicht automatisch nach der Rückgabe gelöscht;
            der Mieter erklärt sich mit dieser Form der Zwischenspeicherung einverstanden.</p>

        <h3>8. ❌ Stornierung durch den Mieter</h3>
        <p>Solange eine Anfrage noch nicht bestätigt bzw. bezahlt ist und damit noch kein Vertrag
            zustande gekommen ist (siehe Ziffer 3), kann der Mieter jederzeit kostenfrei davon
            zurücktreten. Für bereits bestätigte bzw. bezahlte Buchungen berechnen wir im Falle
            eines Rücktritts eine pauschalierte Entschädigung gestaffelt nach dem Zeitpunkt der
            Stornierung vor dem Eventdatum:</p>
        <ul>
            <li>Bis 6 Wochen vor dem Eventdatum: 10 % des Mietpreises</li>
            <li>Bis 4 Wochen vor dem Eventdatum: 30 % des Mietpreises</li>
            <li>Bis 2 Wochen vor dem Eventdatum: 60 % des Mietpreises</li>
            <li>Unter 14 Tagen vor dem Eventdatum: 80 % des Mietpreises</li>
        </ul>
        <p>Dem Mieter steht es frei, nachzuweisen, dass ein Schaden nicht entstanden oder
            wesentlich niedriger als die jeweilige Pauschale ist - in diesem Fall entfällt die
            Entschädigung insoweit. Eine Stornierung ist per E-Mail an unsere oben im Impressum
            genannte Adresse zu erklären.</p>

        <h3>9. 🔁 Rücktritt durch Snapolino</h3>
        <p>Sollte die gebuchte Fotobox aus von Snapolino nicht zu vertretenden Gründen (z.B.
            technischer Totalschaden bei einer vorherigen Nutzung, Verlust auf dem Rückversand)
            nicht rechtzeitig zur Verfügung stehen, informiert Snapolino den Mieter unverzüglich
            und erstattet bereits geleistete Zahlungen vollständig.</p>

        <h3>10. 🌐 Online-Galerie (optionales Extra)</h3>
        <p>Hat der Mieter das Extra "Online-Galerie" gebucht, lädt die Fotobox die während der
            Veranstaltung entstandenen Einzelbilder und Collagen automatisch hoch, sobald sie nach
            dem Event wieder mit dem Internet verbunden ist. Der Mieter erhält per E-Mail einen
            persönlichen Verwalter-Link, über den er Fotos ein-/ausblenden und einen separaten,
            beliebig weitergebbaren Gäste-Link findet - beide Links sind unratbare, individuelle
            Zugangs-Links (kein Passwort erforderlich, der Link selbst ist der Zugangsschutz).</p>
        <p>Die Fotos stehen für <?= gallery_retention_days() ?> Tage nach dem Eventdatum zur
            Verfügung und werden danach automatisch und unwiderruflich gelöscht; das genaue
            Löschdatum wird auf der Galerie-Seite selbst angezeigt. Die Bilder sind ausschließlich
            für private Zwecke bestimmt, eine kommerzielle Nutzung oder Weitergabe zu gewerblichen
            Zwecken ist ohne ausdrückliche schriftliche Genehmigung von Snapolino untersagt.
            Snapolino übernimmt keine Haftung für die von Gästen erstellten Bildinhalte
            (insbesondere Persönlichkeitsrechts- oder Urheberrechtsverletzungen); der Mieter ist
            dafür verantwortlich, dass alle abgebildeten Personen mit der Bereitstellung in der
            Galerie einverstanden sind, und für die Weitergabe des Gäste-Links an Dritte. Weitere
            Hinweise zur Datenverarbeitung finden sich in der
            <a href="/datenschutz.php">Datenschutzerklärung</a>.</p>

        <h3>11. ⚖️ Haftungsbeschränkung</h3>
        <p>Snapolino haftet unbeschränkt für Vorsatz und grobe Fahrlässigkeit sowie nach den
            Vorschriften des Produkthaftungsgesetzes und für Schäden aus der Verletzung des
            Lebens, des Körpers oder der Gesundheit. Für leicht fahrlässig verursachte Schäden
            haftet Snapolino nur bei der Verletzung wesentlicher Vertragspflichten
            (Kardinalpflichten) und der Höhe nach begrenzt auf den vertragstypisch vorhersehbaren
            Schaden. Im Übrigen ist die Haftung für leichte Fahrlässigkeit ausgeschlossen.</p>

        <h3>12. 🚫 Widerrufsrecht</h3>
        <p>Da es sich bei der Miete der Fotobox um eine Dienstleistung im Zusammenhang mit einer
            Freizeitbetätigung handelt, für deren Erbringung ein spezifischer Termin vorgesehen ist
            (dein gewähltes Eventdatum), besteht für über unseren Online-Buchungsassistenten
            geschlossene Verträge kein gesetzliches Widerrufsrecht gemäß § 312g Abs. 2 Nr. 9 BGB.
            Unabhängig davon kann der Mieter im Rahmen der in Ziffer 8 genannten Fristen und
            Bedingungen von der Buchung zurücktreten.</p>

        <h3>13. 🔒 Datenschutz</h3>
        <p>Informationen zur Verarbeitung personenbezogener Daten findest du in unserer
            <a href="/datenschutz.php">Datenschutzerklärung</a>.</p>

        <h3>14. ⚙️ Gerichtsstand</h3>
        <p>Ist der Mieter Kaufmann im Sinne des Handelsgesetzbuches, ist unser Geschäftssitz
            Gerichtsstand; wir sind jedoch berechtigt, den Mieter auch an seinem Wohnsitzgericht zu
            verklagen. Hinweise zur außergerichtlichen Streitbeilegung findest du in unserem
            <a href="/impressum.php">Impressum</a>.</p>

        <h3>15. 📄 Schlussbestimmungen</h3>
        <p>Es gilt das Recht der Bundesrepublik Deutschland unter Ausschluss des
            UN-Kaufrechts. Ist der Mieter Verbraucher, gilt dies nur insoweit, als dadurch der
            durch zwingende Bestimmungen des Staates seines gewöhnlichen Aufenthaltsorts gewährte
            Schutz nicht entzogen wird. Sollte eine Bestimmung dieser AGB unwirksam sein, bleibt
            die Wirksamkeit der übrigen Bestimmungen unberührt.</p>
    </div>
</section>

<?php require __DIR__ . '/_site_footer.php'; ?>

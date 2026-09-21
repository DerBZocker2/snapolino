<?php
declare(strict_types=1);

// Oeffentliche FAQ-Seite mit Kategorie-Filter. Antworten sind bewusst an
// echte, im Code/Panel konfigurierbare Werte gekoppelt (Preis, Extras,
// Fristen), damit sie nicht veralten, sobald jemand eine Einstellung
// aendert. Die kurze FAQ-Auswahl auf der Startseite (index.php) bleibt
// als Teaser bestehen und verlinkt hierher.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = 'Häufige Fragen';
$activeNav = 'faq';

try {
    $priceCents = base_price_cents();
    $priceLabel = base_price_label();
    $retentionDays = gallery_retention_days();
    $returningDiscount = returning_customer_discount_percent();
    $referralDiscount = referral_discount_percent();
    $referralReward = referral_reward_cents();
} catch (PDOException $e) {
    $priceCents = 0;
    $priceLabel = '';
    $retentionDays = 30;
    $returningDiscount = 10;
    $referralDiscount = 10;
    $referralReward = 1500;
}

require __DIR__ . '/_site_header.php';
?>

<section class="section" id="faq-intro">
    <h2>❓ Häufige Fragen</h2>
    <p class="lead">Nach Thema filtern oder einfach durchstöbern – Antwort erscheint per Klick.</p>

    <div class="category-tabs" role="tablist" aria-label="Themen filtern">
        <button type="button" class="cat-tab active" data-cat="alle">Alle</button>
        <button type="button" class="cat-tab" data-cat="ablauf">Ablauf &amp; Buchung</button>
        <button type="button" class="cat-tab" data-cat="kosten">Kosten &amp; Bezahlung</button>
        <button type="button" class="cat-tab" data-cat="technik">Technik &amp; Aufbau</button>
        <button type="button" class="cat-tab" data-cat="fotos">Fotos &amp; Drucke</button>
        <button type="button" class="cat-tab" data-cat="design">Design &amp; Vorlagen</button>
    </div>

    <div id="faq">
        <!-- Ablauf & Buchung -->
        <details class="panel-box" data-cat="ablauf">
            <summary>🚀 Wie läuft eine Buchung ab?</summary>
            <div class="faq-answer"><p>In fünf Schritten: Wunschtermin wählen, Kontaktdaten angeben, Design
                festlegen, optionale Extras dazubuchen und in der Zusammenfassung per Stripe bezahlen (oder
                nur ein unverbindliches Angebot anfragen). Schon mit Name und E-Mail in Schritt 2 wird der
                Termin für andere Anfragen gesperrt.</p></div>
        </details>
        <details class="panel-box" data-cat="ablauf">
            <summary>📅 Wie lange im Voraus sollte ich buchen?</summary>
            <div class="faq-answer"><p>Je früher, desto besser – vor allem an Wochenenden in der Hochsaison
                sind Termine schnell vergeben. Da aktuell nur eine Fotobox zur Verfügung steht, ist mit dem
                Absenden deiner Anfrage der Termin sofort für andere gesperrt, bis wir sie bearbeitet haben.</p></div>
        </details>
        <details class="panel-box" data-cat="ablauf">
            <summary>🙋 Muss jemand die Fotobox bedienen?</summary>
            <div class="faq-answer"><p>Nein. Deine Gäste tippen ein Layout an, schauen in die Kamera und der
                Countdown startet automatisch – ganz ohne Personal vor Ort.</p></div>
        </details>
        <details class="panel-box" data-cat="ablauf">
            <summary>✏️ Kann ich meine Buchung nachträglich ändern?</summary>
            <div class="faq-answer"><p>Ja. Sobald du deine E-Mail-Adresse zur Buchung angegeben hast, kannst
                du dich unter <a href="konto.php">Mein Konto</a> per E-Mail-Code einloggen und dort Datum,
                Design und Extras anpassen, solange die Buchung noch nicht bestätigt ist. Danach ist sie nur
                noch einsehbar, außer wir haben sie auf Anfrage für dich wieder freigeschaltet.</p></div>
        </details>
        <details class="panel-box" data-cat="ablauf">
            <summary>❌ Kann ich stornieren?</summary>
            <div class="faq-answer"><p>Ja. Solange deine Anfrage noch nicht bestätigt bzw. bezahlt ist, geht
                das jederzeit kostenfrei. Danach gilt eine gestaffelte Stornogebühr je nach Zeitpunkt vor dem
                Event – die genauen Fristen und Sätze findest du in unseren <a href="agb.php">AGB</a>.</p></div>
        </details>
        <details class="panel-box" data-cat="ablauf">
            <summary>⏳ Mein Wunschtermin ist schon ausgebucht – was nun?</summary>
            <div class="faq-answer"><p>Trag dich auf unserer <a href="warteliste.php">Warteliste</a> mit
                Wunschtermin und Kontaktdaten ein. Wird der Tag durch eine Absage oder Stornierung wieder
                frei, benachrichtigen wir dich automatisch per Mail mit einem Buchungslink.</p></div>
        </details>
        <details class="panel-box" data-cat="ablauf">
            <summary>📦 Wie kommt die Fotobox zu mir und wieder zurück?</summary>
            <div class="faq-answer"><p>Wir verschicken die fertig eingerichtete Box rechtzeitig vor deinem
                Event zu dir. Für Hin- und Rückversand blockieren wir automatisch ein paar Pufferttage rund
                um dein Eventdatum im Kalender, danach schickst du die Box in der Originalverpackung wieder
                zurück.</p></div>
        </details>
        <details class="panel-box" data-cat="ablauf">
            <summary>🔁 Ich feiere öfter mit euch – gibt es einen Stammkundenrabatt?</summary>
            <div class="faq-answer"><p>Ja. Hast du bereits eine andere, tatsächlich bestätigte Buchung bei
                uns, wird bei der nächsten Buchung automatisch ein Rabatt von
                <strong><?= $returningDiscount ?>&nbsp;%</strong> angewendet – zusätzlich zu einem eventuell
                eingelösten Gutscheincode.</p></div>
        </details>

        <!-- Kosten & Bezahlung -->
        <details class="panel-box" data-cat="kosten">
            <summary>💶 Was kostet die Fotobox?</summary>
            <div class="faq-answer"><p>
                <?php if ($priceCents > 0): ?>
                    Die Miete startet ab <strong><?= money_from_cents($priceCents) ?></strong><?= $priceLabel ? ' (' . htmlspecialchars($priceLabel, ENT_QUOTES) . ')' : '' ?>.
                <?php else: ?>
                    Den aktuellen Preis siehst du direkt im Buchungsassistenten.
                <?php endif; ?>
                Alle Design-Vorlagen sind kostenlos, außer den Formaten mit nur 1 oder 2 Fotos – ein
                eventueller Aufpreis wird dir in Schritt 3 direkt am Layout angezeigt. Dazu kommen optionale
                Extras, die du in Schritt 4 einzeln dazubuchen kannst.</p></div>
        </details>
        <details class="panel-box" data-cat="kosten">
            <summary>💳 Wie bezahle ich?</summary>
            <div class="faq-answer"><p>Direkt online per Stripe Checkout – Kreditkarte, Klarna und weitere
                Zahlarten sind möglich. Deine Zahlungsdaten laufen ausschließlich über Stripe, unser eigener
                Server bekommt sie nie zu Gesicht.</p></div>
        </details>
        <details class="panel-box" data-cat="kosten">
            <summary>🧾 Bekomme ich eine Rechnung?</summary>
            <div class="faq-answer"><p>Ja, automatisch. Nach erfolgreicher Zahlung erstellt Stripe eine
                Rechnung und schickt sie dir direkt zu; unsere Bestätigungsmail verlinkt sie zusätzlich.</p></div>
        </details>
        <details class="panel-box" data-cat="kosten">
            <summary>🎟️ Kann ich einen Gutscheincode einlösen?</summary>
            <div class="faq-answer"><p>Ja, in Schritt 5 des Buchungsassistenten. Gutscheine gibt es als
                Prozent- oder Festbetrags-Rabatt, teils zeitlich oder mengenmäßig begrenzt.</p></div>
        </details>
        <details class="panel-box" data-cat="kosten">
            <summary>🤝 Gibt es ein Empfehlungsprogramm?</summary>
            <div class="faq-answer"><p>Ja. Sobald deine Buchung bestätigt ist, bekommst du in
                <a href="konto.php">Mein Konto</a> automatisch einen persönlichen, beliebig oft einlösbaren
                Rabattcode (<?= $referralDiscount ?>&nbsp;% Rabatt für Beschenkte) zum Weitergeben. Bucht
                jemand darüber zum ersten Mal und die Buchung wird bestätigt, bekommst du selbst als Dankeschön
                automatisch einen Gutschein über <?= money_from_cents($referralReward) ?> zugeschickt.</p></div>
        </details>
        <details class="panel-box" data-cat="kosten">
            <summary>💰 Was, wenn ich die Box zu spät zurückschicke?</summary>
            <div class="faq-answer"><p>Dafür sehen unsere <a href="agb.php">AGB</a> eine Verspätungsgebühr
                pro Tag vor. Bei Problemen mit dem Rückversand melde dich am besten direkt bei uns, bevor die
                Frist abläuft.</p></div>
        </details>
        <details class="panel-box" data-cat="kosten">
            <summary>🔒 Sind meine Zahlungsdaten sicher?</summary>
            <div class="faq-answer"><p>Ja. Die Zahlung läuft komplett über den Zahlungsdienstleister Stripe –
                deine Kartendaten erreichen unseren Server nie. Details dazu in unserer
                <a href="datenschutz.php">Datenschutzerklärung</a>.</p></div>
        </details>
        <details class="panel-box" data-cat="kosten">
            <summary>📝 Kann ich auch erstmal nur ein Angebot anfragen?</summary>
            <div class="faq-answer"><p>Ja. In Schritt 5 kannst du statt direkt zu bezahlen die Option
                „nur ein schriftliches Angebot" wählen – dann entsteht noch kein Vertrag und du kannst in
                Ruhe entscheiden.</p></div>
        </details>

        <!-- Technik & Aufbau -->
        <details class="panel-box" data-cat="technik">
            <summary>🔌 Wie baue ich die Fotobox auf?</summary>
            <div class="faq-answer"><p>Die Box kommt fertig eingerichtet bei dir an, eine Anleitung liegt
                bei. Auspacken, aufstellen, einschalten – mehr ist nicht nötig.</p></div>
        </details>
        <details class="panel-box" data-cat="technik">
            <summary>📶 Brauche ich Internet vor Ort?</summary>
            <div class="faq-answer"><p>Nein. Die Box ist offline-first und speichert Aufnahme, Anzeige und
                Druck komplett lokal. Internet braucht sie nur vorab (Konfiguration) und danach, um Fotos in
                die Online-Galerie hochzuladen, falls gebucht.</p></div>
        </details>
        <details class="panel-box" data-cat="technik">
            <summary>🖨️ Was mache ich, wenn der Drucker ein Problem meldet?</summary>
            <div class="faq-answer"><p>Die Box zeigt den Druckerstatus laufend oben in der Leiste an und
                meldet sich bei einem Problem (z. B. Papier oder Farbband leer) mit einem Hinweisfenster.
                Nach dem Beheben setzt „Erneut versuchen" denselben Druck einfach fort – ein erneutes Foto ist
                nicht nötig. Wichtig: Alle Einzelbilder und die Collage werden trotzdem immer gespeichert,
                auch wenn der Drucker gerade streikt.</p></div>
        </details>
        <details class="panel-box" data-cat="technik">
            <summary>⏱️ Wie lange dauert ein Ausdruck?</summary>
            <div class="faq-answer"><p>Rund 41 Sekunden pro Foto – unser Canon Selphy CP1500 druckt im
                Thermosublimationsverfahren im Format 10×15&nbsp;cm.</p></div>
        </details>
        <details class="panel-box" data-cat="technik">
            <summary>📷 Was für eine Kamera ist verbaut?</summary>
            <div class="faq-answer"><p>Eine Logitech Brio Webcam – keine Spiegelreflexkamera. Sie bleibt
                während des gesamten Events dauerhaft geöffnet, damit sie zuverlässig funktioniert.</p></div>
        </details>
        <details class="panel-box" data-cat="technik">
            <summary>🖥️ Wie bedienen meine Gäste die Box?</summary>
            <div class="faq-answer"><p>Über einen Touchscreen im Vollbildmodus, ganz ohne Tastatur. Ein
                Antippen des gewünschten Layouts genügt, um direkt loszulegen.</p></div>
        </details>
        <details class="panel-box" data-cat="technik">
            <summary>🧳 Was ist im Lieferumfang enthalten?</summary>
            <div class="faq-answer"><p>Die fertig eingerichtete Fotobox inklusive Drucker, Kamera und deinem
                gewählten Design – einfach auspacken, aufstellen und einschalten.</p></div>
        </details>
        <details class="panel-box" data-cat="technik">
            <summary>🔑 Wie komme ich ins Admin-Menü, falls ich mal eingreifen muss?</summary>
            <div class="faq-answer"><p>Oben links über den Logo-Knopf, geschützt per Zifferncode. Dort kannst
                du das Programm beenden oder die aktuell hinterlegten Buchungsinfos (Name, Eventdatum, Extras)
                einsehen.</p></div>
        </details>

        <!-- Fotos & Drucke -->
        <details class="panel-box" data-cat="fotos">
            <summary>🖼️ Wie viele Fotos passen auf einen Ausdruck?</summary>
            <div class="faq-answer"><p>Standard sind 4 Bilder pro Collage. Auf Wunsch kannst du bei der
                Buchung zusätzlich Layouts mit 1, 2 oder 3 Fotos dazuwählen (bis zu drei Zusatzformate
                gleichzeitig) – deine Gäste entscheiden dann direkt an der Box, welches Format sie nehmen
                möchten.</p></div>
        </details>
        <details class="panel-box" data-cat="fotos">
            <summary>🖨️ Wie viele Abzüge bekomme ich pro Foto-Session?</summary>
            <div class="faq-answer"><p>Gedruckt wird die Collage. Ist zusätzlich das Extra „Einzelne Bilder
                drucken" gebucht, lässt sich am Ende jeder Session außerdem ein einzelnes Foto auswählen und
                bis zu dreimal als Extra-Abzug drucken – ganz ohne separate Buchung für die Anzahl.</p></div>
        </details>
        <details class="panel-box" data-cat="fotos">
            <summary>💾 Werden meine Fotos gespeichert – wie bekomme ich sie?</summary>
            <div class="faq-answer"><p>Physisch bekommst du deine Ausdrucke direkt vor Ort. Digital landen
                alle Einzelbilder und die Collage automatisch in unserer Online-Galerie, sobald du das Extra
                „Online-Galerie" gebucht hast und die Box nach dem Event wieder mit dem Internet verbunden ist.
                Steckt zusätzlich ein USB-Stick an der Box (z. B. dein eigener), kopiert sie die Fotos während
                der Veranstaltung automatisch auch darauf.</p></div>
        </details>
        <details class="panel-box" data-cat="fotos">
            <summary>🌐 Was ist die Online-Galerie?</summary>
            <div class="faq-answer"><p>Ein optionales Extra: Alle Fotos und Collagen deines Events landen in
                einer privaten Online-Galerie, sobald die Box nach dem Event wieder online ist. Du bekommst
                einen Verwalter-Link (alle Fotos, Sichtbarkeit steuerbar) und einen separaten Gäste-Link zum
                Weitergeben (nur sichtbare Fotos) – beide ohne Login, dafür mit nicht erratbarer, langer
                Adresse als Zugangsschutz.</p></div>
        </details>
        <details class="panel-box" data-cat="fotos">
            <summary>🗑️ Wie lange bleiben die Fotos in der Galerie?</summary>
            <div class="faq-answer"><p><?= $retentionDays ?> Tage nach deinem Eventdatum werden die Fotos
                aus Datenschutzgründen automatisch von unseren Servern gelöscht. Ein Banner in der Galerie
                zeigt dir das genaue Löschdatum an.</p></div>
        </details>
        <details class="panel-box" data-cat="fotos">
            <summary>📸 Kann ich einzelne Fotos zusätzlich ausdrucken lassen?</summary>
            <div class="faq-answer"><p>Ja, mit dem Extra „Einzelne Bilder drucken". Am Ende jeder
                Foto-Session lässt sich damit ein Bild auswählen und direkt mehrfach ausdrucken.</p></div>
        </details>
        <details class="panel-box" data-cat="fotos">
            <summary>🔐 Wer kann meine Online-Galerie sehen?</summary>
            <div class="faq-answer"><p>Nur, wer den Link kennt – beide Links sind bewusst nicht erratbar,
                aber ohne Login öffentlich abrufbar. Den Gäste-Link darfst und sollst du an deine Gäste
                weitergeben, den Verwalter-Link solltest du für dich behalten.</p></div>
        </details>
        <details class="panel-box" data-cat="fotos">
            <summary>🙈 Kann ich einzelne Fotos aus der Galerie ausblenden?</summary>
            <div class="faq-answer"><p>Ja, über deinen Verwalter-Link. Ausgeblendete Fotos verschwinden dann
                aus der Ansicht deiner Gäste, bleiben für dich selbst aber weiterhin sichtbar und jederzeit
                wieder einblendbar.</p></div>
        </details>

        <!-- Design & Vorlagen -->
        <details class="panel-box" data-cat="design">
            <summary>🎨 Wie gestalte ich mein Design?</summary>
            <div class="faq-answer"><p>Du hast drei Wege: eine fertige Design-Vorlage aus unserer nach
                Kategorie filterbaren Galerie wählen, im kostenlosen Online-Designer ein Layout selbst
                gestalten, oder ein eigenes PNG mit transparenten Fotoflächen hochladen.</p></div>
        </details>
        <details class="panel-box" data-cat="design">
            <summary>🖌️ Was kann ich im Online-Designer anpassen?</summary>
            <div class="faq-answer"><p>Hintergrundfarbe oder -muster, frei verschieb- und größenveränderbare
                Fotoflächen sowie beliebig viele Text- und Sticker/Emoji-Elemente (jeweils eigene Größe, Text
                zusätzlich in eigener Farbe). Damit lässt sich auch eine fertige Vorlage per „Anpassen"
                umgestalten. Ein eigenes Logo/Bild als Element hochzuladen oder Elemente zu drehen geht aktuell
                noch nicht.</p></div>
        </details>
        <details class="panel-box" data-cat="design">
            <summary>📤 Kann ich ein eigenes Design hochladen?</summary>
            <div class="faq-answer"><p>Ja. Lade während der Buchung ein fertiges PNG mit transparenten
                Fotoflächen hoch – unser Server erkennt die Flächen automatisch und setzt deine Fotos passend
                hinein.</p></div>
        </details>
    </div>

    <?php
    $germanMonths = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    ?>
    <p class="muted" style="margin-top:24px;font-size:13px;">
        Stand: <?= $germanMonths[(int) date('n')] . ' ' . date('Y') ?> · Alle Angaben ohne Gewähr
    </p>
</section>

<script>
(function () {
    var tabs = document.querySelectorAll('.category-tabs .cat-tab');
    var items = document.querySelectorAll('#faq .panel-box');

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            var cat = tab.getAttribute('data-cat');
            items.forEach(function (item) {
                var show = cat === 'alle' || item.getAttribute('data-cat') === cat;
                item.style.display = show ? '' : 'none';
            });
        });
    });
})();
</script>
<?php require __DIR__ . '/_site_footer.php'; ?>

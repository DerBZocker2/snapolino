<?php
declare(strict_types=1);

$pageTitle = 'Impressum';
require __DIR__ . '/_site_header.php';

$businessName = (string) get_setting('business_name', '');
$businessAddressRaw = (string) get_setting('business_address', '');
$businessPhone = (string) get_setting('business_phone', '');
$businessTaxNote = (string) get_setting('business_tax_note', '');

$addressLines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $businessAddressRaw))));
?>

<section class="section legal-page">
    <h2>Impressum</h2>

    <div class="panel-box">
        <h3>Angaben gemäß § 5 TMG</h3>
        <p>
            <?= legal_value($businessName, 'Name/Firma bitte im Panel unter Einstellungen ergänzen') ?><br>
            <?php if ($addressLines): ?>
                <?php foreach ($addressLines as $line): ?>
                    <?= htmlspecialchars($line, ENT_QUOTES) ?><br>
                <?php endforeach; ?>
            <?php else: ?>
                <?= legal_value('', 'Anschrift bitte im Panel unter Einstellungen ergänzen') ?><br>
            <?php endif; ?>
        </p>

        <h3>Kontakt</h3>
        <p>
            <?php if ($businessPhone !== ''): ?>
                Telefon: <?= htmlspecialchars($businessPhone, ENT_QUOTES) ?><br>
            <?php endif; ?>
            E-Mail: <?= legal_value($contactEmail, 'bitte im Panel unter Einstellungen ergänzen') ?>
        </p>

        <?php if ($businessTaxNote !== ''): ?>
            <h3>Umsatzsteuer</h3>
            <p><?= htmlspecialchars($businessTaxNote, ENT_QUOTES) ?></p>
        <?php endif; ?>

        <h3>Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV</h3>
        <p><?= legal_value($businessName, 'siehe oben, Angaben ergänzen') ?></p>

        <h3>Streitschlichtung</h3>
        <p>
            Die Europäische Kommission stellt eine Plattform zur Online-Streitbeilegung (OS) bereit:
            <a href="https://ec.europa.eu/consumers/odr/" target="_blank" rel="noopener">ec.europa.eu/consumers/odr</a>.
            Unsere E-Mail-Adresse findest du oben unter Kontakt. Wir sind zur Teilnahme an einem
            Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle weder verpflichtet
            noch bereit.
        </p>

        <h3>Haftung für Inhalte</h3>
        <p>
            Als Diensteanbieter sind wir gemäß § 7 Abs. 1 TMG für eigene Inhalte auf diesen Seiten
            nach den allgemeinen Gesetzen verantwortlich. Nach §§ 8 bis 10 TMG sind wir als
            Diensteanbieter jedoch nicht verpflichtet, übermittelte oder gespeicherte fremde
            Informationen zu überwachen oder nach Umständen zu forschen, die auf eine
            rechtswidrige Tätigkeit hinweisen. Verpflichtungen zur Entfernung oder Sperrung der
            Nutzung von Informationen nach den allgemeinen Gesetzen bleiben hiervon unberührt.
        </p>

        <h3>Haftung für Links</h3>
        <p>
            Unser Angebot kann Links zu externen Webseiten Dritter enthalten, auf deren Inhalte
            wir keinen Einfluss haben. Deshalb können wir für diese fremden Inhalte auch keine
            Gewähr übernehmen. Für die Inhalte der verlinkten Seiten ist stets der jeweilige
            Anbieter oder Betreiber der Seiten verantwortlich.
        </p>
    </div>
</section>

<?php require __DIR__ . '/_site_footer.php'; ?>

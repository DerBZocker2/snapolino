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
    <h2>🏢 Impressum</h2>

    <div class="panel-box">
        <h3>Angaben gemäß § 5 DDG</h3>
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

        <h3>Verbraucherstreitbeilegung / Universalschlichtungsstelle</h3>
        <p>
            Wir sind nicht bereit oder verpflichtet, an Streitbeilegungsverfahren vor einer
            Verbraucherschlichtungsstelle teilzunehmen.
        </p>

        <h3>Haftungsausschluss</h3>

        <h3>Haftung für Inhalte</h3>
        <p>
            Die Inhalte unserer Seiten wurden mit größter Sorgfalt erstellt. Für die Richtigkeit,
            Vollständigkeit und Aktualität der Inhalte können wir jedoch keine Gewähr übernehmen.
            Als Diensteanbieter sind wir gemäß § 7 Abs. 1 DDG für eigene Inhalte auf diesen Seiten
            nach den allgemeinen Gesetzen verantwortlich. Nach §§ 8 bis 10 DDG sind wir als
            Diensteanbieter jedoch nicht verpflichtet, übermittelte oder gespeicherte fremde
            Informationen zu überwachen oder nach Umständen zu forschen, die auf eine
            rechtswidrige Tätigkeit hinweisen. Verpflichtungen zur Entfernung oder Sperrung der
            Nutzung von Informationen nach den allgemeinen Gesetzen bleiben hiervon unberührt.
            Eine diesbezügliche Haftung ist jedoch erst ab dem Zeitpunkt der Kenntnis einer
            konkreten Rechtsverletzung möglich. Bei Bekanntwerden von entsprechenden
            Rechtsverletzungen werden wir diese Inhalte umgehend entfernen.
        </p>

        <h3>Haftung für Links</h3>
        <p>
            Unser Angebot enthält Links zu externen Webseiten Dritter, auf deren Inhalte wir
            keinen Einfluss haben. Deshalb können wir für diese fremden Inhalte auch keine Gewähr
            übernehmen. Für die Inhalte der verlinkten Seiten ist stets der jeweilige Anbieter
            oder Betreiber der Seiten verantwortlich. Die verlinkten Seiten wurden zum Zeitpunkt
            der Verlinkung auf mögliche Rechtsverstöße überprüft. Rechtswidrige Inhalte waren zum
            Zeitpunkt der Verlinkung nicht erkennbar. Eine permanente inhaltliche Kontrolle der
            verlinkten Seiten ist jedoch ohne konkrete Anhaltspunkte einer Rechtsverletzung nicht
            zumutbar. Bei Bekanntwerden von Rechtsverletzungen werden wir derartige Links
            umgehend entfernen.
        </p>

        <h3>Urheberrecht</h3>
        <p>
            Die durch die Seitenbetreiber erstellten Inhalte und Werke auf diesen Seiten
            unterliegen dem deutschen Urheberrecht. Die Vervielfältigung, Bearbeitung, Verbreitung
            und jede Art der Verwertung außerhalb der Grenzen des Urheberrechtes bedürfen der
            schriftlichen Zustimmung des jeweiligen Autors bzw. Erstellers. Downloads und Kopien
            dieser Seite sind nur für den privaten, nicht kommerziellen Gebrauch gestattet. Soweit
            die Inhalte auf dieser Seite nicht vom Betreiber erstellt wurden, werden die
            Urheberrechte Dritter beachtet. Insbesondere werden Inhalte Dritter als solche
            gekennzeichnet. Sollten Sie trotzdem auf eine Urheberrechtsverletzung aufmerksam
            werden, bitten wir um einen entsprechenden Hinweis. Bei Bekanntwerden von
            Rechtsverletzungen werden wir derartige Inhalte umgehend entfernen.
        </p>
    </div>
</section>

<?php require __DIR__ . '/_site_footer.php'; ?>

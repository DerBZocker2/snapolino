<?php
declare(strict_types=1);

// Oeffentliche Startseite. Zeigt den echten Basispreis aus den
// Einstellungen an (kein erfundener Rabattpreis). Buchung/Online-Designer/
// Layout-Upload: siehe backend/README.md fuer den aktuellen Baustand.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = 'Snapolino Fotobox – Design-Optionen';
$activeNav = 'home';

try {
    $priceCents = base_price_cents();
    $priceLabel = base_price_label();
    $presetCount = count(fetch_all_layouts());
} catch (PDOException $e) {
    $priceCents = 0;
    $priceLabel = '';
    $presetCount = 0;
}

require __DIR__ . '/_site_header.php';
?>

<section class="hero">
    <h1>Fotobox-Vermietung für deine <span class="accent-text">Veranstaltung</span></h1>
    <p>
        Selbstbedienungs-Fotobox mit Sofortdruck – wir schicken sie dir bequem
        zu, du stellst sie auf, deine Gäste machen die Fotos selbst.
    </p>
    <?php if ($priceCents > 0): ?>
        <p class="muted" style="margin-top:-16px;">
            ab <strong><?= money_from_cents($priceCents) ?></strong>
            <?= $priceLabel ? '· ' . htmlspecialchars($priceLabel, ENT_QUOTES) : '' ?>
        </p>
    <?php endif; ?>
    <a class="button" href="buchen.php">Jetzt buchen</a>
    <p class="muted" style="margin-top:16px;font-size:13px;">
        Keine Zahlungsdaten für die Reservierung nötig · Sichere Zahlung über Stripe · Made in Deutschland
    </p>
</section>

<section class="section" id="ablauf">
    <h2>So funktioniert's</h2>
    <p class="lead">In vier einfachen Schritten zu deiner eigenen Fotobox.</p>
    <div class="feature-grid">
        <div class="feature-card">
            <div class="icon">📅</div>
            <h3>1. Termin buchen</h3>
            <p>Wähle deinen Eventtag im Buchungsassistenten, gestalte dein Layout und schließe die Buchung sicher online ab.</p>
        </div>
        <div class="feature-card">
            <div class="icon">📦</div>
            <h3>2. Box erhalten</h3>
            <p>Wir schicken dir die fertig eingerichtete Fotobox rechtzeitig vor deinem Event bequem nach Hause.</p>
        </div>
        <div class="feature-card">
            <div class="icon">🎉</div>
            <h3>3. Losfeiern</h3>
            <p>Aufstellen, anschalten, loslegen – deine Gäste bedienen die Box ganz von selbst und Fotos werden direkt gedruckt.</p>
        </div>
        <div class="feature-card">
            <div class="icon">↩️</div>
            <h3>4. Zurückschicken</h3>
            <p>Nach dem Event einfach wieder einpacken und an uns zurückschicken – alle Details dazu bekommst du rechtzeitig von uns.</p>
        </div>
    </div>
</section>

<section class="section" id="design-optionen">
    <h2>So gestaltest du deine <span class="accent-text">Fotobox</span></h2>
    <p class="lead">
        Für jeden Anlass das passende Layout – drei Wege, wie du dein
        Design festlegst.
    </p>
    <div class="feature-grid">
        <div class="feature-card">
            <div class="icon">🎨</div>
            <h3><?= $presetCount > 0 ? $presetCount . ' Design-Vorlagen' : 'Design-Vorlagen' ?></h3>
            <p>Vorlagen für jeden Anlass – sofort einsatzbereit, nach Kategorie filterbar.</p>
        </div>
        <div class="feature-card">
            <div class="icon">🖌️</div>
            <h3>Kostenloser Online-Designer</h3>
            <p>Gestalte dein eigenes Layout mit unserem Editor – Farben, Muster und Text sind frei anpassbar.</p>
        </div>
        <div class="feature-card">
            <div class="icon">📤</div>
            <h3>Eigenes Design hochladen</h3>
            <p>Für Designer und Druckereien: Lade während der Buchung ein fertiges PNG mit transparenten Fotoflächen hoch.</p>
        </div>
    </div>
</section>

<section class="section" id="faq">
    <h2>Häufige Fragen</h2>
    <div class="panel-box">
        <h3>Muss jemand die Fotobox bedienen?</h3>
        <p class="muted">Nein. Deine Gäste tippen ein Layout an, schauen in die Kamera und der
            Countdown startet automatisch – ganz ohne Personal.</p>
    </div>
    <div class="panel-box">
        <h3>Was ist im Lieferumfang enthalten?</h3>
        <p class="muted">Die fertig eingerichtete Fotobox inkl. Drucker, Kamera und deinem
            gewählten Design – einfach auspacken, aufstellen und einschalten.</p>
    </div>
    <div class="panel-box">
        <h3>Wie lange vorher sollte ich buchen?</h3>
        <p class="muted">Je früher, desto besser – vor allem an Wochenenden in der Hochsaison sind
            Termine schnell vergeben. Eine Reservierung ist zunächst
            <?= RESERVATION_HOLD_DAYS ?> Tage unverbindlich möglich.</p>
    </div>
    <div class="panel-box">
        <h3>Kann ich stornieren?</h3>
        <p class="muted">Ja, die genauen Fristen und Bedingungen findest du in unseren
            <a href="agb.php">AGB</a>.</p>
    </div>
    <div class="panel-box">
        <h3>Sind meine Zahlungsdaten sicher?</h3>
        <p class="muted">Ja. Die Zahlung läuft komplett über den Zahlungsdienstleister Stripe –
            deine Kartendaten erreichen unseren Server nie. Details dazu in unserer
            <a href="datenschutz.php">Datenschutzerklärung</a>.</p>
    </div>
</section>

<?php require __DIR__ . '/_site_footer.php'; ?>

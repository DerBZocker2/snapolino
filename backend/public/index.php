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
    <div class="confetti" aria-hidden="true">
        <span class="confetti-piece" style="left:8%;background:var(--accent);animation-duration:3.2s;animation-delay:0s;"></span>
        <span class="confetti-piece" style="left:20%;background:var(--accent2);animation-duration:2.6s;animation-delay:0.4s;"></span>
        <span class="confetti-piece" style="left:32%;background:var(--teal);animation-duration:3.6s;animation-delay:0.9s;"></span>
        <span class="confetti-piece" style="left:48%;background:var(--accent);animation-duration:2.9s;animation-delay:0.2s;"></span>
        <span class="confetti-piece" style="left:62%;background:var(--accent2);animation-duration:3.3s;animation-delay:1.1s;"></span>
        <span class="confetti-piece" style="left:75%;background:var(--teal);animation-duration:2.7s;animation-delay:0.6s;"></span>
        <span class="confetti-piece" style="left:86%;background:var(--accent);animation-duration:3.1s;animation-delay:1.4s;"></span>
        <span class="confetti-piece" style="left:94%;background:var(--accent2);animation-duration:2.8s;animation-delay:0.1s;"></span>
    </div>
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

<div class="photo-strip" aria-hidden="true">
    <div class="polaroid">
        <svg viewBox="0 0 96 72" xmlns="http://www.w3.org/2000/svg">
            <rect width="96" height="72" fill="#2c2440"/>
            <circle cx="48" cy="38" r="16" fill="#ff6f59"/>
            <circle cx="48" cy="38" r="9" fill="#fffaf5"/>
            <path d="M30 20h10l4-6h8l4 6h10v6H30z" fill="#6c5ce7"/>
        </svg>
    </div>
    <div class="polaroid">
        <svg viewBox="0 0 96 72" xmlns="http://www.w3.org/2000/svg">
            <rect width="96" height="72" fill="#6c5ce7"/>
            <circle cx="34" cy="46" r="14" fill="#fffaf5"/>
            <circle cx="64" cy="40" r="18" fill="#17c3b2"/>
            <circle cx="60" cy="34" r="4" fill="#fffaf5"/>
        </svg>
    </div>
    <div class="polaroid">
        <svg viewBox="0 0 96 72" xmlns="http://www.w3.org/2000/svg">
            <rect width="96" height="72" fill="#ff6f59"/>
            <path d="M12 60L36 24l16 20 10-12 22 28z" fill="#fffaf5"/>
            <circle cx="72" cy="20" r="7" fill="#fffaf5"/>
        </svg>
    </div>
</div>

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
    <p class="lead">Frage antippen, Antwort erscheint.</p>

    <details class="panel-box">
        <summary>Muss jemand die Fotobox bedienen?</summary>
        <div class="faq-answer"><p>Nein. Deine Gäste tippen ein Layout an, schauen in die Kamera und der
            Countdown startet automatisch – ganz ohne Personal.</p></div>
    </details>
    <details class="panel-box">
        <summary>Was ist im Lieferumfang enthalten?</summary>
        <div class="faq-answer"><p>Die fertig eingerichtete Fotobox inkl. Drucker, Kamera und deinem
            gewählten Design – einfach auspacken, aufstellen und einschalten.</p></div>
    </details>
    <details class="panel-box">
        <summary>Brauche ich Internet vor Ort?</summary>
        <div class="faq-answer"><p>Nein. Die Box ist offline-first und speichert alles lokal – für
            Aufnahme, Anzeige und Druck brauchst du kein WLAN am Veranstaltungsort.</p></div>
    </details>
    <details class="panel-box">
        <summary>Wie viele Fotos landen auf einem Ausdruck?</summary>
        <div class="faq-answer"><p>Standard sind 4 Bilder pro Collage. Auf Wunsch kannst du bei der
            Buchung zusätzlich Layouts mit 1, 2 oder 3 Fotos dazuwählen (bis zu drei
            Zusatzformate gleichzeitig) – deine Gäste entscheiden dann direkt an der Box,
            welches Format sie nehmen möchten. Ist zusätzlich "Einzelne Bilder drucken"
            gebucht, lässt sich am Ende jeder Session außerdem ein einzelnes Foto mehrfach
            als Extra-Abzug drucken.</p></div>
    </details>
    <details class="panel-box">
        <summary>Wie gestalte ich mein Design?</summary>
        <div class="faq-answer"><p>Du hast drei Wege: eine fertige Design-Vorlage aus unserer Galerie
            wählen, im kostenlosen Online-Designer Farbe, Muster und Text selbst anpassen,
            oder ein eigenes PNG mit transparenten Fotoflächen hochladen.</p></div>
    </details>
    <details class="panel-box">
        <summary>Kann ich meine Buchung nachträglich ändern?</summary>
        <div class="faq-answer"><p>Ja. Sobald du deine E-Mail-Adresse zur Buchung angegeben hast,
            kannst du dich unter <a href="konto.php">Mein Konto</a> per E-Mail-Code einloggen
            und dort Datum, Design und Extras anpassen, solange noch nicht bezahlt wurde.
            Danach ist die Buchung nur noch einsehbar.</p></div>
    </details>
    <details class="panel-box">
        <summary>Wie lange vorher sollte ich buchen?</summary>
        <div class="faq-answer"><p>Je früher, desto besser – vor allem an Wochenenden in der Hochsaison sind
            Termine schnell vergeben. Da wir aktuell nur eine Box haben, ist mit dem Absenden
            deiner Anfrage der Termin sofort für andere gesperrt, bis wir sie bearbeitet haben.</p></div>
    </details>
    <details class="panel-box">
        <summary>Kann ich stornieren?</summary>
        <div class="faq-answer"><p>Ja, die genauen Fristen und Bedingungen findest du in unseren
            <a href="agb.php">AGB</a>.</p></div>
    </details>
    <details class="panel-box">
        <summary>Sind meine Zahlungsdaten sicher?</summary>
        <div class="faq-answer"><p>Ja. Die Zahlung läuft komplett über den Zahlungsdienstleister Stripe –
            deine Kartendaten erreichen unseren Server nie. Details dazu in unserer
            <a href="datenschutz.php">Datenschutzerklärung</a>.</p></div>
    </details>
</section>

<script>
(function () {
    if (!('IntersectionObserver' in window)) { return; }
    var targets = document.querySelectorAll('.feature-card, #faq .panel-box');
    targets.forEach(function (el) { el.classList.add('reveal'); });

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15 });

    targets.forEach(function (el) { observer.observe(el); });
})();
</script>
<?php require __DIR__ . '/_site_footer.php'; ?>

<?php
declare(strict_types=1);

// Oeffentliche Startseite. Zeigt den echten Basispreis aus den
// Einstellungen an (kein erfundener Rabattpreis). Buchung/Online-Designer/
// Layout-Upload: siehe backend/README.md fuer den aktuellen Baustand.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = 'Snapolino Fotobox – Design-Optionen';

try {
    $priceCents = base_price_cents();
    $priceLabel = base_price_label();
} catch (PDOException $e) {
    $priceCents = 0;
    $priceLabel = '';
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></title>
    <link rel="stylesheet" href="assets/site.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="/">Snapolino</a>
    <nav>
        <a href="mailto:info@snapolino.de">Kontakt</a>
        <a href="admin/login.php">Admin-Login</a>
    </nav>
</header>

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
            <h3>64 Preset-Designs</h3>
            <p>Professionell gestaltete Vorlagen für jeden Anlass – sofort einsatzbereit.</p>
        </div>
        <div class="feature-card">
            <div class="icon">🖌️</div>
            <h3>Kostenloser Online-Designer</h3>
            <p>Gestalte dein eigenes Layout mit unserem Editor – Farben, Texte und Logos sind frei anpassbar.</p>
        </div>
        <div class="feature-card">
            <div class="icon">📤</div>
            <h3>Eigenes Design hochladen</h3>
            <p>Für Designer und Druckereien: Lade während der Buchung ein fertiges PNG mit transparenten Fotoflächen hoch.</p>
        </div>
    </div>
</section>

<footer class="site-footer">
    &copy; <?= date('Y') ?> Snapolino &middot; <a href="mailto:info@snapolino.de">info@snapolino.de</a>
</footer>
</body>
</html>

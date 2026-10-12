<?php
declare(strict_types=1);

// Gemeinsamer Kopfbereich fuer alle oeffentlichen Seiten (index.php,
// buchen.php, impressum.php, datenschutz.php, agb.php) - an einer Stelle
// gepflegt, damit z.B. kein Admin-Link versehentlich auf einer der Seiten
// landet. Erwartet $pageTitle, optional $activeNav ('home'/'buchen').

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = $pageTitle ?? 'Snapolino';
$activeNav = $activeNav ?? '';
// Fuer <meta name="description">/Open Graph - jede Seite kann vor dem
// require eine eigene, konkrete Beschreibung setzen; ohne eigene Angabe
// greift eine generische Fallback-Beschreibung statt ganz zu fehlen.
$pageDescription = $pageDescription ?? 'Fotobox-Vermietung mit Sofortdruck für Hochzeiten, Geburtstage und Firmenfeiern - online buchen, Design wählen, Spaß garantiert.';
// Fuer Seiten mit persoenlichen/zugangsbeschraenkten Inhalten (Konto-Login,
// Galerie-Links) - sollen nicht in Suchmaschinen landen.
$pageNoindex = $pageNoindex ?? false;
$contactEmail = (string) get_setting('business_email', 'info@snapolino.de') ?: 'info@snapolino.de';

function nav_link_class(string $target, string $active, string $extra = ''): string
{
    $classes = trim($extra . ($target === $active ? ' active' : ''));
    return $classes !== '' ? ' class="' . htmlspecialchars($classes, ENT_QUOTES) . '"' : '';
}

// Fuer Impressum/Datenschutz/AGB: macht fehlende Pflichtangaben (Panel unter
// Einstellungen noch nicht ausgefuellt) sichtbar statt sie stillschweigend
// wegzulassen - Rueckgabewert ist bereits fertiges, sicheres HTML.
function legal_value(string $value, string $placeholder): string
{
    return $value !== ''
        ? htmlspecialchars($value, ENT_QUOTES)
        : '<span class="legal-missing">[' . htmlspecialchars($placeholder, ENT_QUOTES) . ']</span>';
}

// "Stand: <Monat> <Jahr>"-Fusszeile fuer Impressum/Datenschutz/AGB/FAQ -
// aus dem aktuellen Datum gebildet statt hartkodiert, damit sie nicht
// veraltet.
function legal_updated_label(): string
{
    $germanMonths = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    return 'Stand: ' . $germanMonths[(int) date('n')] . ' ' . date('Y');
}

// Fuer Formulare mit Inline-Feldfehlern (buchen.php Schritt 2,
// warteliste.php): $fieldErrors ordnet Validierungsfehler dem jeweiligen
// Feldnamen zu, statt nur eine generische Liste oben auf der Seite zu
// zeigen - direktes Feedback am betroffenen Feld ist fuer die meisten
// Nutzer schneller erfassbar.
function field_error_class(array $fieldErrors, string $name): string
{
    return isset($fieldErrors[$name]) ? ' input-error' : '';
}

function field_error_html(array $fieldErrors, string $name): string
{
    return isset($fieldErrors[$name])
        ? '<span class="field-error">' . htmlspecialchars($fieldErrors[$name], ENT_QUOTES) . '</span>'
        : '';
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?> &ndash; Snapolino</title>
    <meta name="description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Snapolino">
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES) ?>">
    <meta property="og:locale" content="de_DE">
    <?php if ($pageNoindex): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
    <link rel="icon" type="image/x-icon" href="<?= asset_url('assets/favicon.ico', __DIR__ . '/assets/favicon.ico') ?>">
    <link rel="apple-touch-icon" href="<?= asset_url('assets/apple-touch-icon.png', __DIR__ . '/assets/apple-touch-icon.png') ?>">
    <link rel="stylesheet" href="<?= asset_url('assets/site.css', __DIR__ . '/assets/site.css') ?>">
</head>
<body>
<header class="site-header">
    <a class="brand" href="/">
        <img class="brand-icon" src="<?= asset_url('assets/logo-icon.png', __DIR__ . '/assets/logo-icon.png') ?>" alt="Snapolino Logo" width="28" height="28">
        Snapolino
    </a>
    <button type="button" class="nav-toggle" id="nav-toggle" aria-label="Menü öffnen" aria-expanded="false" aria-controls="site-nav">
        <span></span><span></span><span></span>
    </button>
    <nav id="site-nav">
        <a href="/"<?= nav_link_class('home', $activeNav) ?>>Start</a>
        <a href="/faq"<?= nav_link_class('faq', $activeNav) ?>>FAQ</a>
        <a href="/konto"<?= nav_link_class('konto', $activeNav) ?>>Mein Konto</a>
        <a href="mailto:<?= htmlspecialchars($contactEmail, ENT_QUOTES) ?>">Kontakt</a>
        <a href="/buchen"<?= nav_link_class('buchen', $activeNav, 'nav-cta') ?>>Jetzt buchen</a>
    </nav>
</header>
<script>
(function () {
    var toggle = document.getElementById('nav-toggle');
    var nav = document.getElementById('site-nav');
    toggle.addEventListener('click', function () {
        var open = nav.classList.toggle('open');
        toggle.classList.toggle('open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
})();
</script>

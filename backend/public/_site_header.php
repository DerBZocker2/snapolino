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
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?> &ndash; Snapolino</title>
    <link rel="stylesheet" href="<?= asset_url('assets/site.css', __DIR__ . '/assets/site.css') ?>">
</head>
<body>
<header class="site-header">
    <a class="brand" href="/"><span class="brand-icon">📸</span> Snapolino</a>
    <button type="button" class="nav-toggle" id="nav-toggle" aria-label="Menü öffnen" aria-expanded="false" aria-controls="site-nav">
        <span></span><span></span><span></span>
    </button>
    <nav id="site-nav">
        <a href="/"<?= nav_link_class('home', $activeNav) ?>>Start</a>
        <a href="/konto.php"<?= nav_link_class('konto', $activeNav) ?>>Mein Konto</a>
        <a href="mailto:<?= htmlspecialchars($contactEmail, ENT_QUOTES) ?>">Kontakt</a>
        <a href="/buchen.php"<?= nav_link_class('buchen', $activeNav, 'nav-cta') ?>>Jetzt buchen</a>
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

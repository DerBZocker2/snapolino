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

function nav_link_class(string $target, string $active): string
{
    return $target === $active ? ' class="active"' : '';
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
    <a class="brand" href="/">Snapolino</a>
    <nav>
        <a href="/"<?= nav_link_class('home', $activeNav) ?>>Start</a>
        <a href="/buchen.php"<?= nav_link_class('buchen', $activeNav) ?>>Jetzt buchen</a>
        <a href="/konto.php"<?= nav_link_class('konto', $activeNav) ?>>Mein Konto</a>
        <a href="mailto:<?= htmlspecialchars($contactEmail, ENT_QUOTES) ?>">Kontakt</a>
    </nav>
</header>

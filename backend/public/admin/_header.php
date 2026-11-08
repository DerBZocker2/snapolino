<?php
declare(strict_types=1);

// Wird von jeder Admin-Seite (ausser login.php) am Anfang eingebunden.
// Erwartet, dass $pageTitle vorher gesetzt wurde.

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_login();

$pageTitle = $pageTitle ?? 'Snapolino Panel';
$currentPage = basename($_SERVER['SCRIPT_NAME']);

// Bookings-Tabelle existiert evtl. noch nicht (Migration 0002 nicht
// eingespielt) - Panel soll deswegen nicht komplett ausfallen.
try {
    $openBookings = (int) db()->query("SELECT COUNT(*) FROM bookings WHERE status = 'angefragt'")->fetchColumn();
    $unassignedBookings = (int) db()->query(
        "SELECT COUNT(*) FROM bookings WHERE status = 'angefragt' OR (status = 'bestaetigt' AND box_id IS NULL)"
    )->fetchColumn();
} catch (PDOException $e) {
    $openBookings = 0;
    $unassignedBookings = 0;
}

// Warteliste-Tabelle existiert evtl. noch nicht (Migration 0020 nicht
// eingespielt) - Panel soll deswegen nicht komplett ausfallen.
try {
    $waitingEntries = (int) db()->query('SELECT COUNT(*) FROM waitlist_entries WHERE notified_at IS NULL')->fetchColumn();
} catch (PDOException $e) {
    $waitingEntries = 0;
}

function nav_class(array $pages, string $current): string
{
    return in_array($current, $pages, true) ? 'active' : '';
}

// Schlichte, selbst gezeichnete Strich-Icons (keine externe Icon-Bibliothek/
// kein Lizenzthema, analog zur Begruendung bei den Unicode-Emoji-Stickern
// im Online-Designer) - ein Icon je Sidebar-Punkt, Outline-Stil (stroke
// statt fill, siehe .sidebar-nav a svg in style.css).
function nav_icon(string $name): string
{
    $paths = [
        'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>',
        'bookings' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 3v2h6V3"/><path d="M8 11h8M8 15h8M8 7h4"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><circle cx="17" cy="9" r="2.3"/><path d="M15.5 14.2c2.2.5 3.8 2.4 3.8 4.8"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'receipt' => '<path d="M6 3h12v18l-2-1.5-2 1.5-2-1.5-2 1.5-2-1.5-2 1.5V3z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
        'box' => '<path d="M3 8l9-5 9 5-9 5-9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
        'grid' => '<rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/>',
        'sparkle' => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3z"/>',
        'tag' => '<path d="M3 11.5V5a2 2 0 0 1 2-2h6.5L21 12.5 12.5 21 3 11.5z"/><circle cx="8" cy="8" r="1.4"/>',
        'percent' => '<circle cx="7" cy="7" r="2.3"/><circle cx="17" cy="17" r="2.3"/><path d="M19 5 5 19"/>',
        'star' => '<path d="m12 3 2.6 5.6 6.1.7-4.5 4.1 1.2 6-5.4-3-5.4 3 1.2-6-4.5-4.1 6.1-.7L12 3z"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 13a7.1 7.1 0 0 0 0-2l2-1.5-2-3.5-2.3.9a7 7 0 0 0-1.7-1L15 3h-4l-.4 2.4a7 7 0 0 0-1.7 1l-2.3-.9-2 3.5L6.6 11a7.1 7.1 0 0 0 0 2l-2 1.5 2 3.5 2.3-.9a7 7 0 0 0 1.7 1L10.5 21h4l.4-2.4a7 7 0 0 0 1.7-1l2.3.9 2-3.5L19.4 13z"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?> &ndash; Snapolino Panel</title>
    <link rel="icon" type="image/x-icon" href="<?= asset_url('../assets/favicon.ico', __DIR__ . '/../assets/favicon.ico') ?>">
    <link rel="stylesheet" href="<?= asset_url('assets/style.css', __DIR__ . '/assets/style.css') ?>">
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <img src="<?= asset_url('../assets/logo-icon.png', __DIR__ . '/../assets/logo-icon.png') ?>" alt="" width="26" height="26">
            Snapolino
        </div>
        <nav class="sidebar-nav">
            <a class="<?= nav_class(['dashboard.php'], $currentPage) ?>" href="dashboard.php">
                <?= nav_icon('home') ?> Übersicht
            </a>

            <div class="sidebar-group-label">Verkauf</div>
            <a class="<?= nav_class(['bookings.php', 'booking_detail.php'], $currentPage) ?>" href="bookings.php">
                <?= nav_icon('bookings') ?> Buchungen
                <?php if ($openBookings > 0): ?>
                    <span class="nav-badge"><?= $openBookings ?></span>
                <?php endif; ?>
            </a>
            <a class="<?= nav_class(['customers.php', 'customer_detail.php'], $currentPage) ?>" href="customers.php">
                <?= nav_icon('users') ?> Kunden
            </a>
            <a class="<?= nav_class(['calendar.php'], $currentPage) ?>" href="calendar.php">
                <?= nav_icon('calendar') ?> Kalender
            </a>
            <a class="<?= nav_class(['invoices.php'], $currentPage) ?>" href="invoices.php">
                <?= nav_icon('receipt') ?> Rechnungen
            </a>

            <div class="sidebar-group-label">Box &amp; Logistik</div>
            <a class="<?= nav_class(['boxes.php', 'box_layouts.php'], $currentPage) ?>" href="boxes.php">
                <?= nav_icon('box') ?> Boxen
                <?php if ($unassignedBookings > 0): ?>
                    <span class="nav-badge"><?= $unassignedBookings ?></span>
                <?php endif; ?>
            </a>
            <a class="<?= nav_class(['waitlist.php'], $currentPage) ?>" href="waitlist.php">
                <?= nav_icon('clock') ?> Warteliste
                <?php if ($waitingEntries > 0): ?>
                    <span class="nav-badge"><?= $waitingEntries ?></span>
                <?php endif; ?>
            </a>

            <div class="sidebar-group-label">Katalog</div>
            <a class="<?= nav_class(['layouts.php', 'layout_form.php'], $currentPage) ?>" href="layouts.php">
                <?= nav_icon('grid') ?> Layouts
            </a>
            <a class="<?= nav_class(['extras.php', 'extra_form.php'], $currentPage) ?>" href="extras.php">
                <?= nav_icon('sparkle') ?> Extras
            </a>

            <div class="sidebar-group-label">Marketing</div>
            <a class="<?= nav_class(['coupons.php', 'coupon_form.php'], $currentPage) ?>" href="coupons.php">
                <?= nav_icon('tag') ?> Gutscheine
            </a>
            <a class="<?= nav_class(['promotions.php', 'promotion_form.php'], $currentPage) ?>" href="promotions.php">
                <?= nav_icon('percent') ?> Rabattaktionen
            </a>
            <a class="<?= nav_class(['testimonials.php', 'testimonial_form.php'], $currentPage) ?>" href="testimonials.php">
                <?= nav_icon('star') ?> Bewertungen
            </a>

            <div class="sidebar-group-label">System</div>
            <a class="<?= nav_class(['settings.php'], $currentPage) ?>" href="settings.php">
                <?= nav_icon('gear') ?> Einstellungen
            </a>
        </nav>
        <div class="sidebar-footer">
            <a href="../">Zur Webseite</a>
            <a href="logout.php">Abmelden</a>
        </div>
    </aside>
    <main class="content">
        <div class="topbar">
            <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></h1>
            <form class="topbar-search" method="get" action="search.php">
                <?= nav_icon('search') ?>
                <input type="search" name="q" placeholder="Kunden, Buchungen durchsuchen…" value="<?= htmlspecialchars((string) ($_GET['q'] ?? ''), ENT_QUOTES) ?>">
            </form>
        </div>

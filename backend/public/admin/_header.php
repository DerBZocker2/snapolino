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
                <span class="nav-icon">📊</span> Übersicht
            </a>
            <a class="<?= nav_class(['bookings.php', 'booking_detail.php'], $currentPage) ?>" href="bookings.php">
                <span class="nav-icon">📅</span> Buchungen
                <?php if ($openBookings > 0): ?>
                    <span class="nav-badge"><?= $openBookings ?></span>
                <?php endif; ?>
            </a>
            <a class="<?= nav_class(['boxes.php', 'box_layouts.php'], $currentPage) ?>" href="boxes.php">
                <span class="nav-icon">📦</span> Boxen
                <?php if ($unassignedBookings > 0): ?>
                    <span class="nav-badge"><?= $unassignedBookings ?></span>
                <?php endif; ?>
            </a>
            <a class="<?= nav_class(['waitlist.php'], $currentPage) ?>" href="waitlist.php">
                <span class="nav-icon">⏳</span> Warteliste
                <?php if ($waitingEntries > 0): ?>
                    <span class="nav-badge"><?= $waitingEntries ?></span>
                <?php endif; ?>
            </a>
            <a class="<?= nav_class(['layouts.php', 'layout_form.php'], $currentPage) ?>" href="layouts.php">
                <span class="nav-icon">🖼️</span> Layouts
            </a>
            <a class="<?= nav_class(['extras.php', 'extra_form.php'], $currentPage) ?>" href="extras.php">
                <span class="nav-icon">✨</span> Extras
            </a>
            <a class="<?= nav_class(['coupons.php', 'coupon_form.php'], $currentPage) ?>" href="coupons.php">
                <span class="nav-icon">🎟️</span> Gutscheine
            </a>
            <a class="<?= nav_class(['testimonials.php', 'testimonial_form.php'], $currentPage) ?>" href="testimonials.php">
                <span class="nav-icon">⭐</span> Bewertungen
            </a>
            <a class="<?= nav_class(['settings.php'], $currentPage) ?>" href="settings.php">
                <span class="nav-icon">⚙️</span> Einstellungen
            </a>
        </nav>
        <div class="sidebar-footer">
            <a href="../">Zur Webseite</a>
            <a href="logout.php">Abmelden</a>
        </div>
    </aside>
    <main class="content">
        <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></h1>

<?php
declare(strict_types=1);

// Wird von jeder Admin-Seite (ausser login.php) am Anfang eingebunden.
// Erwartet, dass $pageTitle vorher gesetzt wurde.

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_login();

$pageTitle = $pageTitle ?? 'Snapolino Panel';
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?> &ndash; Snapolino Panel</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="brand">Snapolino Panel</div>
    <nav>
        <a href="dashboard.php">Uebersicht</a>
        <a href="boxes.php">Boxen</a>
        <a href="layouts.php">Layouts</a>
        <a href="logout.php">Abmelden</a>
    </nav>
</header>
<main class="content">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></h1>

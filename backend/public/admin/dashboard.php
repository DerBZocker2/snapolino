<?php
declare(strict_types=1);

$pageTitle = 'Uebersicht';
require __DIR__ . '/_header.php';

$boxCount = (int) db()->query('SELECT COUNT(*) FROM boxes')->fetchColumn();
$layoutCount = (int) db()->query('SELECT COUNT(*) FROM layouts')->fetchColumn();
?>
<div class="cards">
    <a class="card" href="boxes.php">
        <span class="card-number"><?= $boxCount ?></span>
        <span class="card-label">Boxen</span>
    </a>
    <a class="card" href="layouts.php">
        <span class="card-number"><?= $layoutCount ?></span>
        <span class="card-label">Layouts</span>
    </a>
</div>

<p>
    Neue Boxen unter <a href="boxes.php">Boxen</a> anlegen, Collagen-Vorlagen
    unter <a href="layouts.php">Layouts</a> pflegen. Bevor eine Box versendet
    wird, muss sie mit dem hier angezeigten Box-Key und API-Key in ihrer
    lokalen <code>box.ini</code> versorgt werden und einmalig online sein,
    um die Konfiguration und Rahmen-PNGs abzuholen.
</p>

<?php require __DIR__ . '/_footer.php'; ?>

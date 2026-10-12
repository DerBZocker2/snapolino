<?php
declare(strict_types=1);

// Eigene 404-Seite statt der unmarkierten Apache-Standardfehlerseite -
// ueber "ErrorDocument 404 /404" in .htaccess eingebunden. Rein statisch,
// keine Datenbankabfrage noetig.

require_once __DIR__ . '/../includes/functions.php';

http_response_code(404);

$pageTitle = 'Seite nicht gefunden';
$pageDescription = 'Diese Seite existiert nicht oder wurde verschoben.';
$pageNoindex = true;
require __DIR__ . '/_site_header.php';
?>

<section class="section" style="padding-top:60px;">
    <div class="panel-box success-box">
        <h2 style="margin-top:0;">🔍 Seite nicht gefunden</h2>
        <p class="muted">
            Die aufgerufene Seite gibt es nicht (mehr) oder der Link enthält einen Tippfehler.
        </p>
        <p style="margin-top:24px;">
            <a class="button" href="/">Zur Startseite</a>
        </p>
        <p class="muted" style="margin-top:16px;">
            Oder direkt <a href="/buchen">buchen</a>, in <a href="/faq">den häufigen Fragen</a>
            nachsehen, oder uns schreiben:
            <a href="mailto:<?= htmlspecialchars($contactEmail, ENT_QUOTES) ?>"><?= htmlspecialchars($contactEmail, ENT_QUOTES) ?></a>.
        </p>
    </div>
</section>

<?php require __DIR__ . '/_site_footer.php'; ?>

<?php
declare(strict_types=1);

// Ziel-Seite fuer den Stripe-Redirect nach einer Zusatzzahlung (siehe
// admin/booking_detail.php, create_addon_charge_checkout_session()). Reiner
// Hinweis fuers UI - die eigentliche Bestaetigung passiert wie bei jeder
// Stripe-Zahlung ausschliesslich per Webhook (stripe_webhook.php).

$pageTitle = 'Zahlung erfolgreich';
$activeNav = '';
require __DIR__ . '/_site_header.php';
?>

<section class="section legal-page">
    <div class="panel-box success-box">
        <h3>Danke für deine Zahlung!</h3>
        <p>Sie ist bei uns eingegangen. Eine Bestätigung findest du in Kürze per E-Mail.</p>
        <p class="muted" style="margin-top:16px;">
            Fragen? Schreib uns einfach an
            <a href="mailto:<?= htmlspecialchars($contactEmail, ENT_QUOTES) ?>"><?= htmlspecialchars($contactEmail, ENT_QUOTES) ?></a>.
        </p>
    </div>
</section>

<?php require __DIR__ . '/_site_footer.php'; ?>

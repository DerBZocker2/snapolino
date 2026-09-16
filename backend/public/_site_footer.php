<?php declare(strict_types=1); ?>
<footer class="site-footer">
    <div class="site-footer-links">
        <a href="/impressum.php">Impressum</a>
        <a href="/datenschutz.php">Datenschutz</a>
        <a href="/agb.php">AGB &amp; Widerrufsrecht</a>
    </div>
    <p>&copy; <?= date('Y') ?> Snapolino &middot; <a href="mailto:<?= htmlspecialchars($contactEmail, ENT_QUOTES) ?>"><?= htmlspecialchars($contactEmail, ENT_QUOTES) ?></a></p>
</footer>
</body>
</html>

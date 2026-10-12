<?php declare(strict_types=1); ?>
<footer class="site-footer">
    <div class="site-footer-links">
        <a href="/impressum">Impressum</a>
        <a href="/datenschutz">Datenschutz</a>
        <a href="/agb">AGB &amp; Widerrufsrecht</a>
    </div>
    <p>&copy; <?= date('Y') ?> Snapolino &middot; <a href="mailto:<?= htmlspecialchars($contactEmail, ENT_QUOTES) ?>"><?= htmlspecialchars($contactEmail, ENT_QUOTES) ?></a></p>
</footer>
</body>
</html>

<?php
declare(strict_types=1);

// Oeffentliche Online-Galerie einer Buchung - Aufruf ueber den unratbaren
// Link aus ensure_gallery_token()/gallery_url() (?token=...), kein
// Kundenkonto-Login noetig, damit auch Gaeste ohne eigenes Konto die Fotos
// ansehen/laden koennen. Bewusst im dunklen Design (anders als die uebrige,
// helle Webseite), siehe Nutzerwunsch.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$token = trim((string) ($_GET['token'] ?? ''));
$booking = null;

if ($token !== '') {
    $stmt = db()->prepare('SELECT * FROM bookings WHERE gallery_token = ?');
    $stmt->execute([$token]);
    $found = $stmt->fetch();
    if ($found && hash_equals($found['gallery_token'], $token)) {
        $booking = $found;
    }
}

if ($booking) {
    $stmt = db()->prepare('SELECT filename, kind FROM gallery_photos WHERE booking_id = ? ORDER BY kind DESC, filename');
    $stmt->execute([$booking['id']]);
    $photos = $stmt->fetchAll();
} else {
    $photos = [];
}

$eventDate = $booking ? (new DateTimeImmutable($booking['event_date']))->format('d.m.Y') : '';
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fotogalerie &ndash; Snapolino</title>
    <link rel="icon" type="image/x-icon" href="<?= asset_url('assets/favicon.ico', __DIR__ . '/assets/favicon.ico') ?>">
    <style>
        :root {
            --bg: #14121c;
            --panel: #1e1b2a;
            --border: #322d45;
            --text: #f1eefc;
            --muted: #9d97b8;
            --accent: #ff6f59;
            --accent2: #6c5ce7;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        header {
            padding: 20px 24px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid var(--border);
        }
        header img { width: 28px; height: 28px; }
        header .brand { font-weight: 700; font-size: 18px; }
        main { max-width: 1100px; margin: 0 auto; padding: 28px 20px 60px; }
        h1 { font-size: 26px; margin: 0 0 4px; }
        .sub { color: var(--muted); margin: 0 0 28px; }
        .empty {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            color: var(--muted);
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 14px;
        }
        .tile {
            position: relative;
            border-radius: 10px;
            overflow: hidden;
            background: var(--panel);
            border: 1px solid var(--border);
            cursor: pointer;
            aspect-ratio: 3 / 2;
        }
        .tile img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 0.2s ease;
        }
        .tile:hover img { transform: scale(1.05); }
        .tile .badge {
            position: absolute;
            top: 8px;
            left: 8px;
            background: rgba(108, 92, 231, 0.9);
            color: #fff;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 20px;
        }
        .tile .dl {
            position: absolute;
            bottom: 8px;
            right: 8px;
            background: rgba(20, 18, 28, 0.75);
            color: #fff;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 16px;
        }
        .tile .dl:hover { background: var(--accent); }
        .lightbox {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(10, 9, 15, 0.94);
            z-index: 100;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            padding: 20px;
        }
        .lightbox.open { display: flex; }
        .lightbox img {
            max-width: 92vw;
            max-height: 78vh;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
        }
        .lightbox-bar {
            margin-top: 18px;
            display: flex;
            gap: 14px;
            align-items: center;
        }
        .lightbox-bar a, .lightbox-bar button {
            background: var(--accent2);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 18px;
            font-size: 15px;
            text-decoration: none;
            cursor: pointer;
        }
        .lightbox-bar button.secondary { background: transparent; border: 1px solid var(--border); color: var(--text); }
        .lightbox-close {
            position: absolute;
            top: 18px;
            right: 24px;
            background: none;
            border: none;
            color: #fff;
            font-size: 30px;
            cursor: pointer;
        }
        .lightbox-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255, 255, 255, 0.08);
            border: none;
            color: #fff;
            font-size: 26px;
            width: 46px;
            height: 46px;
            border-radius: 50%;
            cursor: pointer;
        }
        .lightbox-nav.prev { left: 16px; }
        .lightbox-nav.next { right: 16px; }
    </style>
</head>
<body>
<header>
    <img src="<?= asset_url('assets/logo-icon.png', __DIR__ . '/assets/logo-icon.png') ?>" alt="">
    <span class="brand">Snapolino</span>
</header>
<main>
    <?php if (!$booking): ?>
        <div class="empty">
            <h1>Galerie nicht gefunden</h1>
            <p>Der Link ist ungültig oder abgelaufen. Bitte wende dich an die Person, die die Fotobox gebucht hat.</p>
        </div>
    <?php else: ?>
        <h1>Fotogalerie von <?= htmlspecialchars($booking['customer_name'], ENT_QUOTES) ?></h1>
        <p class="sub">Event vom <?= htmlspecialchars($eventDate, ENT_QUOTES) ?> &middot; <?= count($photos) ?> Foto(s)</p>

        <?php if (!$photos): ?>
            <div class="empty">
                <p>Es sind noch keine Fotos in der Galerie. Sie erscheinen automatisch, sobald die Fotobox nach der
                    Veranstaltung wieder mit dem Internet verbunden ist.</p>
            </div>
        <?php else: ?>
            <div class="grid" id="grid">
                <?php foreach ($photos as $i => $photo): ?>
                    <?php $url = 'gallery_photo.php?token=' . rawurlencode($token) . '&file=' . rawurlencode($photo['filename']); ?>
                    <div class="tile" data-index="<?= $i ?>" data-full="<?= htmlspecialchars($url, ENT_QUOTES) ?>">
                        <img src="<?= htmlspecialchars($url, ENT_QUOTES) ?>" alt="Foto" loading="lazy">
                        <?php if ($photo['kind'] === 'collage'): ?><span class="badge">Collage</span><?php endif; ?>
                        <a class="dl" href="<?= htmlspecialchars($url, ENT_QUOTES) ?>&download=1" onclick="event.stopPropagation()" title="Herunterladen">⬇</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>

<div class="lightbox" id="lightbox">
    <button class="lightbox-close" id="lb-close">&times;</button>
    <button class="lightbox-nav prev" id="lb-prev">&#8249;</button>
    <img id="lb-img" src="" alt="Foto in voller Größe">
    <button class="lightbox-nav next" id="lb-next">&#8250;</button>
    <div class="lightbox-bar">
        <a id="lb-download" href="#">Herunterladen</a>
        <button class="secondary" id="lb-close2">Schließen</button>
    </div>
</div>

<script>
(function () {
    var tiles = Array.prototype.slice.call(document.querySelectorAll('.tile'));
    if (!tiles.length) return;

    var lightbox = document.getElementById('lightbox');
    var img = document.getElementById('lb-img');
    var download = document.getElementById('lb-download');
    var current = 0;

    function show(index) {
        current = (index + tiles.length) % tiles.length;
        var url = tiles[current].getAttribute('data-full');
        img.src = url;
        download.href = url + '&download=1';
        lightbox.classList.add('open');
    }

    tiles.forEach(function (tile, i) {
        tile.addEventListener('click', function () { show(i); });
    });

    function close() { lightbox.classList.remove('open'); }

    document.getElementById('lb-close').addEventListener('click', close);
    document.getElementById('lb-close2').addEventListener('click', close);
    document.getElementById('lb-prev').addEventListener('click', function () { show(current - 1); });
    document.getElementById('lb-next').addEventListener('click', function () { show(current + 1); });
    lightbox.addEventListener('click', function (e) { if (e.target === lightbox) close(); });
    document.addEventListener('keydown', function (e) {
        if (!lightbox.classList.contains('open')) return;
        if (e.key === 'Escape') close();
        if (e.key === 'ArrowLeft') show(current - 1);
        if (e.key === 'ArrowRight') show(current + 1);
    });
})();
</script>
</body>
</html>

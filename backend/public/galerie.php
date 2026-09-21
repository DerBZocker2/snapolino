<?php
declare(strict_types=1);

// Oeffentliche Online-Galerie einer Buchung - zwei getrennte, unratbare
// Links (Migration 0018):
// - gallery_token (Verwalter-Link): sieht alle Fotos inkl. ausgeblendeter,
//   kann Fotos aus-/einblenden, sieht den Gaeste-Link zum Weitergeben.
// - gallery_guest_token (Gaeste-Link): read-only, zeigt nur nicht
//   ausgeblendete Fotos, kein Hinweis auf den Verwalter-Link.
// Kein Kundenkonto-Login noetig, der Token selbst ist der Zugriffsschutz
// (wie edit_token bei buchen.php) - Besitz des Links = Berechtigung, eine
// CSRF-Absicherung waere daher wirkungslos (wer den Token kennt, braucht
// kein CSRF). Bewusst im dunklen Design (anders als die uebrige, helle
// Webseite), siehe Nutzerwunsch.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Derselbe ?token=-Parameter wird gegen beide Spalten geprueft - ein
// Verwalter-Token matcht gallery_token, ein Gaeste-Token gallery_guest_token,
// nie beide gleichzeitig (verschiedene Zufallswerte).
function gallery_find_booking(string $token): array
{
    if ($token === '') {
        return [null, ''];
    }
    $stmt = db()->prepare('SELECT * FROM bookings WHERE gallery_token = ?');
    $stmt->execute([$token]);
    $found = $stmt->fetch();
    if ($found && hash_equals($found['gallery_token'], $token)) {
        return [$found, 'organizer'];
    }

    $stmt = db()->prepare('SELECT * FROM bookings WHERE gallery_guest_token = ?');
    $stmt->execute([$token]);
    $found = $stmt->fetch();
    if ($found && hash_equals((string) $found['gallery_guest_token'], $token)) {
        return [$found, 'guest'];
    }

    return [null, ''];
}

$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
[$booking, $role] = gallery_find_booking($token);

// Nur der Verwalter-Link darf Fotos aus-/einblenden.
if ($booking && $role === 'organizer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $photoId = (int) ($_POST['photo_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    if ($photoId > 0 && in_array($action, ['hide', 'show'], true)) {
        db()->prepare('UPDATE gallery_photos SET hidden = ? WHERE id = ? AND booking_id = ?')
            ->execute([$action === 'hide' ? 1 : 0, $photoId, $booking['id']]);
    }
    // Redirect statt direkt weiterzurendern (PRG-Pattern) - ein Neuladen der
    // Seite soll nicht denselben Toggle nochmal absenden.
    header('Location: galerie.php?token=' . rawurlencode($token));
    exit;
}

$photos = [];
$hiddenCount = 0;
if ($booking) {
    $stmt = db()->prepare('SELECT * FROM gallery_photos WHERE booking_id = ? ORDER BY kind DESC, filename');
    $stmt->execute([$booking['id']]);
    $allPhotos = $stmt->fetchAll();

    foreach ($allPhotos as $photo) {
        if ((int) $photo['hidden'] === 1) {
            $hiddenCount++;
            if ($role !== 'organizer') {
                continue;
            }
        }
        $photos[] = $photo;
    }
}

$eventDate = $booking ? (new DateTimeImmutable($booking['event_date']))->format('d.m.Y') : '';
$guestUrl = ($booking && $role === 'organizer')
    ? gallery_guest_url($booking['gallery_guest_token'] ?: ensure_gallery_guest_token((int) $booking['id']))
    : '';

$deletedAt = $booking['gallery_deleted_at'] ?? null;
$deletionDate = $booking ? gallery_deletion_date($booking['event_date']) : null;
$daysLeft = $deletionDate ? (int) (new DateTimeImmutable('today'))->diff($deletionDate)->format('%r%a') : 0;
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
        main { max-width: 1300px; margin: 0 auto; padding: 28px 20px 60px; }
        h1 { font-size: 26px; margin: 0 0 4px; }
        .sub { color: var(--muted); margin: 0 0 20px; }
        .empty {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            color: var(--muted);
        }
        .banner {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            font-size: 14px;
            color: var(--muted);
        }
        .banner.urgent { border-color: var(--accent); color: #ffd4cb; }
        .banner strong { color: var(--text); }
        .guest-link-box {
            background: var(--panel);
            border: 1px solid var(--accent2);
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 24px;
        }
        .guest-link-box h2 { margin: 0 0 6px; font-size: 16px; }
        .guest-link-box p { margin: 0 0 12px; color: var(--muted); font-size: 13px; }
        .guest-link-row { display: flex; gap: 8px; flex-wrap: wrap; }
        .guest-link-row input {
            flex: 1;
            min-width: 220px;
            background: #100e18;
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 13px;
        }
        .guest-link-row button, .guest-link-row a {
            background: var(--accent2);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }
        .tile {
            position: relative;
            border-radius: 12px;
            overflow: hidden;
            background: var(--panel);
            border: 1px solid var(--border);
            cursor: pointer;
            aspect-ratio: 3 / 2;
        }
        .tile.is-hidden { opacity: 0.4; }
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
            top: 10px;
            left: 10px;
            background: rgba(108, 92, 231, 0.9);
            color: #fff;
            font-size: 12px;
            padding: 3px 10px;
            border-radius: 20px;
        }
        .tile .badge.hidden-badge { background: rgba(0, 0, 0, 0.75); left: auto; right: 10px; }
        .tile .dl {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: rgba(20, 18, 28, 0.75);
            color: #fff;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 17px;
        }
        .tile .dl:hover { background: var(--accent); }
        .tile .toggle-form {
            position: absolute;
            bottom: 10px;
            left: 10px;
        }
        .tile .toggle-btn {
            background: rgba(20, 18, 28, 0.75);
            color: #fff;
            border: none;
            border-radius: 18px;
            padding: 8px 14px;
            font-size: 12px;
            cursor: pointer;
        }
        .tile .toggle-btn:hover { background: var(--accent2); }
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
    <?php elseif ($deletedAt): ?>
        <h1>Fotogalerie von <?= htmlspecialchars($booking['customer_name'], ENT_QUOTES) ?></h1>
        <p class="sub">Event vom <?= htmlspecialchars($eventDate, ENT_QUOTES) ?></p>
        <div class="empty">
            <p>Die Fotos dieser Veranstaltung wurden am
                <strong><?= htmlspecialchars((new DateTimeImmutable($deletedAt))->format('d.m.Y'), ENT_QUOTES) ?></strong>
                gemäß unserer <a href="/datenschutz.php" style="color:#fff;">Datenschutzerklärung</a> automatisch gelöscht.</p>
        </div>
    <?php else: ?>
        <h1>Fotogalerie von <?= htmlspecialchars($booking['customer_name'], ENT_QUOTES) ?></h1>
        <p class="sub">
            Event vom <?= htmlspecialchars($eventDate, ENT_QUOTES) ?> &middot; <?= count($photos) ?> Foto(s)
            <?php if ($role === 'organizer' && $hiddenCount > 0): ?>
                &middot; <?= $hiddenCount ?> ausgeblendet
            <?php endif; ?>
        </p>

        <div class="banner<?= $daysLeft <= 3 ? ' urgent' : '' ?>">
            <?php if ($daysLeft > 0): ?>
                🕓 Diese Fotos werden aus Datenschutzgründen automatisch am
                <strong><?= htmlspecialchars($deletionDate->format('d.m.Y'), ENT_QUOTES) ?></strong>
                gelöscht (noch <?= $daysLeft ?> Tag<?= $daysLeft === 1 ? '' : 'e' ?>) - lade dir gewünschte Fotos vorher herunter.
            <?php else: ?>
                🕓 Diese Fotos werden in Kürze aus Datenschutzgründen automatisch gelöscht - lade dir gewünschte Fotos jetzt herunter.
            <?php endif; ?>
        </div>

        <?php if ($role === 'organizer'): ?>
            <div class="guest-link-box">
                <h2>🔗 Link für eure Gäste</h2>
                <p>Diesen Link könnt ihr an alle Gäste weitergeben - er zeigt nur die Fotos, die du nicht
                    ausgeblendet hast, und erlaubt keine Änderungen.</p>
                <div class="guest-link-row">
                    <input type="text" readonly value="<?= htmlspecialchars($guestUrl, ENT_QUOTES) ?>" id="guest-url" onclick="this.select()">
                    <button type="button" id="copy-guest-url">Kopieren</button>
                    <a href="<?= htmlspecialchars($guestUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener">Öffnen</a>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$photos): ?>
            <div class="empty">
                <p>Es sind noch keine Fotos in der Galerie. Sie erscheinen automatisch, sobald die Fotobox nach der
                    Veranstaltung wieder mit dem Internet verbunden ist.</p>
            </div>
        <?php else: ?>
            <div class="grid" id="grid">
                <?php foreach ($photos as $i => $photo): ?>
                    <?php
                    $isHidden = (int) $photo['hidden'] === 1;
                    $url = 'gallery_photo.php?token=' . rawurlencode($token) . '&file=' . rawurlencode($photo['filename']);
                    ?>
                    <div class="tile<?= $isHidden ? ' is-hidden' : '' ?>" data-index="<?= $i ?>" data-full="<?= htmlspecialchars($url, ENT_QUOTES) ?>">
                        <img src="<?= htmlspecialchars($url, ENT_QUOTES) ?>" alt="Foto" loading="lazy">
                        <?php if ($photo['kind'] === 'collage'): ?><span class="badge">Collage</span><?php endif; ?>
                        <?php if ($isHidden): ?><span class="badge hidden-badge">Ausgeblendet</span><?php endif; ?>
                        <a class="dl" href="<?= htmlspecialchars($url, ENT_QUOTES) ?>&download=1" onclick="event.stopPropagation()" title="Herunterladen">⬇</a>
                        <?php if ($role === 'organizer'): ?>
                            <form class="toggle-form" method="post" action="galerie.php" onclick="event.stopPropagation()">
                                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
                                <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                                <input type="hidden" name="action" value="<?= $isHidden ? 'show' : 'hide' ?>">
                                <button type="submit" class="toggle-btn"><?= $isHidden ? 'Einblenden' : 'Ausblenden' ?></button>
                            </form>
                        <?php endif; ?>
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

    var copyBtn = document.getElementById('copy-guest-url');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var input = document.getElementById('guest-url');
            input.select();
            navigator.clipboard && navigator.clipboard.writeText(input.value).then(function () {
                copyBtn.textContent = 'Kopiert!';
                setTimeout(function () { copyBtn.textContent = 'Kopieren'; }, 1500);
            });
        });
    }
})();
</script>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$pageTitle = 'Fotobox buchen';
$errors = [];
$success = isset($_GET['danke']);

$name    = trim((string) ($_POST['customer_name'] ?? ''));
$email   = trim((string) ($_POST['customer_email'] ?? ''));
$phone   = trim((string) ($_POST['customer_phone'] ?? ''));
$address = trim((string) ($_POST['customer_address'] ?? ''));
$eventDate = trim((string) ($_POST['event_date'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));
$selectedLayoutIds = array_map('intval', $_POST['layout_ids'] ?? []);

$layouts = fetch_all_layouts();
$defaultLayout = null;
foreach ($layouts as $layout) {
    if ($layout['is_default']) {
        $defaultLayout = $layout;
        break;
    }
}
$extraLayouts = array_values(array_filter($layouts, static fn (array $l): bool => !$l['is_default']));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    // Honeypot: fuer Menschen unsichtbares Feld, Bots fuellen es oft aus.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        header('Location: buchen.php?danke=1');
        exit;
    }

    if ($name === '') {
        $errors[] = 'Bitte deinen Namen angeben.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Bitte eine gueltige E-Mail-Adresse angeben.';
    }
    if ($address === '') {
        $errors[] = 'Bitte eine Versandadresse angeben.';
    }

    $eventDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $eventDate) ?: null;
    if (!$eventDateObj) {
        $errors[] = 'Bitte ein gueltiges Eventdatum waehlen.';
    } elseif ($eventDateObj < new DateTimeImmutable('today')) {
        $errors[] = 'Das Eventdatum darf nicht in der Vergangenheit liegen.';
    } elseif (is_date_blocked($eventDate)) {
        $errors[] = 'Dieser Termin ist leider schon vergeben. Bitte ein anderes Datum waehlen.';
    }

    if (!$defaultLayout) {
        $errors[] = 'Es ist aktuell kein Standardlayout hinterlegt, bitte kontaktiere uns direkt.';
    }

    if (!$errors) {
        $layoutIds = array_unique(array_merge([(int) $defaultLayout['id']], $selectedLayoutIds));

        db()->beginTransaction();
        $stmt = db()->prepare(
            'INSERT INTO bookings (customer_name, customer_email, customer_phone, customer_address, event_date, message)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $email, $phone !== '' ? $phone : null, $address, $eventDate, $message !== '' ? $message : null]);
        $bookingId = (int) db()->lastInsertId();

        $stmt = db()->prepare('INSERT INTO booking_layouts (booking_id, layout_id) VALUES (?, ?)');
        foreach ($layoutIds as $layoutId) {
            $stmt->execute([$bookingId, $layoutId]);
        }
        db()->commit();

        header('Location: buchen.php?danke=1');
        exit;
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?> &ndash; Snapolino</title>
    <link rel="stylesheet" href="assets/site.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="/">Snapolino</a>
    <nav>
        <a href="mailto:info@snapolino.de">Kontakt</a>
        <a href="admin/login.php">Admin-Login</a>
    </nav>
</header>

<section class="section" style="padding-top:48px;">
    <h2>Fotobox buchen</h2>
    <p class="lead">Termin waehlen, Formular ausfuellen, wir melden uns mit einer Bestaetigung.</p>

    <?php if ($success): ?>
        <div class="panel-box success-box">
            <h3>Danke fuer deine Anfrage!</h3>
            <p>Wir pruefen die Verfuegbarkeit und melden uns zeitnah per E-Mail bei dir.</p>
        </div>
    <?php else: ?>

        <?php foreach ($errors as $err): ?>
            <p class="error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
        <?php endforeach; ?>

        <div class="booking-layout">
            <div class="panel-box">
                <h3>1. Termin waehlen</h3>
                <div id="calendar"></div>
                <p id="selected-date-label" class="muted">Noch kein Termin ausgewaehlt.</p>
            </div>

            <div class="panel-box">
                <h3>2. Deine Daten</h3>
                <form method="post" action="buchen.php" id="booking-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="event_date" id="event_date" value="<?= htmlspecialchars($eventDate, ENT_QUOTES) ?>">
                    <input type="text" name="website" class="honeypot" tabindex="-1" autocomplete="off">

                    <label>Name *
                        <input type="text" name="customer_name" required value="<?= htmlspecialchars($name, ENT_QUOTES) ?>">
                    </label>
                    <label>E-Mail *
                        <input type="email" name="customer_email" required value="<?= htmlspecialchars($email, ENT_QUOTES) ?>">
                    </label>
                    <label>Telefon
                        <input type="text" name="customer_phone" value="<?= htmlspecialchars($phone, ENT_QUOTES) ?>">
                    </label>
                    <label>Versandadresse (Straße, PLZ, Ort) *
                        <textarea name="customer_address" rows="3" required><?= htmlspecialchars($address, ENT_QUOTES) ?></textarea>
                    </label>

                    <?php if ($defaultLayout): ?>
                        <p><strong><?= htmlspecialchars($defaultLayout['name'], ENT_QUOTES) ?></strong> ist immer inklusive.</p>
                    <?php endif; ?>

                    <?php if ($extraLayouts): ?>
                        <label>Zusatzformate (optional, gegen Aufpreis)</label>
                        <?php foreach ($extraLayouts as $layout): ?>
                            <label class="checkbox">
                                <input type="checkbox" name="layout_ids[]" value="<?= (int) $layout['id'] ?>"
                                    <?= in_array((int) $layout['id'], $selectedLayoutIds, true) ? 'checked' : '' ?>>
                                <?= htmlspecialchars($layout['name'], ENT_QUOTES) ?>
                                (+<?= number_format($layout['surcharge_cents'] / 100, 2, ',', '.') ?> EUR)
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <label>Nachricht (optional)
                        <textarea name="message" rows="3"><?= htmlspecialchars($message, ENT_QUOTES) ?></textarea>
                    </label>

                    <button type="submit">Anfrage senden</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</section>

<footer class="site-footer">
    &copy; <?= date('Y') ?> Snapolino &middot; <a href="mailto:info@snapolino.de">info@snapolino.de</a>
</footer>

<script>
(function () {
    var eventDateInput = document.getElementById('event_date');
    var calendarEl = document.getElementById('calendar');
    var selectedLabel = document.getElementById('selected-date-label');
    if (!calendarEl) return;

    var monthNames = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    var viewYear = today.getFullYear();
    var viewMonth = today.getMonth();
    var blockedDates = {};
    var selected = eventDateInput.value || null;

    function toIso(y, m, d) {
        return y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
    }

    function render() {
        var first = new Date(viewYear, viewMonth, 1);
        var startWeekday = (first.getDay() + 6) % 7; // Montag = 0
        var daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();

        var html = '<div class="cal-header">'
            + '<button type="button" id="cal-prev">&larr;</button>'
            + '<span>' + monthNames[viewMonth] + ' ' + viewYear + '</span>'
            + '<button type="button" id="cal-next">&rarr;</button>'
            + '</div><div class="cal-grid">';

        ['Mo','Di','Mi','Do','Fr','Sa','So'].forEach(function (d) {
            html += '<div class="cal-weekday">' + d + '</div>';
        });

        for (var i = 0; i < startWeekday; i++) {
            html += '<div class="cal-day cal-empty"></div>';
        }

        for (var day = 1; day <= daysInMonth; day++) {
            var iso = toIso(viewYear, viewMonth, day);
            var dateObj = new Date(viewYear, viewMonth, day);
            var isPast = dateObj < today;
            var isBlocked = !!blockedDates[iso];
            var classes = ['cal-day'];
            if (isPast || isBlocked) classes.push('cal-disabled');
            if (iso === selected) classes.push('cal-selected');
            html += '<div class="' + classes.join(' ') + '" data-date="' + iso + '">' + day + '</div>';
        }

        calendarEl.innerHTML = html;

        document.getElementById('cal-prev').addEventListener('click', function () {
            viewMonth--; if (viewMonth < 0) { viewMonth = 11; viewYear--; }
            render();
        });
        document.getElementById('cal-next').addEventListener('click', function () {
            viewMonth++; if (viewMonth > 11) { viewMonth = 0; viewYear++; }
            render();
        });
        calendarEl.querySelectorAll('.cal-day:not(.cal-disabled):not(.cal-empty)').forEach(function (el) {
            el.addEventListener('click', function () {
                selected = el.getAttribute('data-date');
                eventDateInput.value = selected;
                var d = new Date(selected);
                selectedLabel.textContent = 'Gewählter Termin: ' + d.toLocaleDateString('de-DE');
                render();
            });
        });
    }

    fetch('booking_availability.php')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            (data.blocked || []).forEach(function (d) { blockedDates[d] = true; });
            render();
        })
        .catch(function () { render(); });

    if (selected) {
        var d = new Date(selected);
        selectedLabel.textContent = 'Gewählter Termin: ' + d.toLocaleDateString('de-DE');
    }
})();
</script>
</body>
</html>

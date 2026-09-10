<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Muss vor jeder Ausgabe passieren: csrf_field()/check_csrf() greifen auf die
// Session zu, und auf laengeren Seiten (z.B. Schritt 5 mit Zusammenfassung)
// wurden ohne das hier schon genug Bytes ausgegeben, dass PHP die Header
// vorher flushed - session_start() schlug dann stumm fehl und jedes Formular
// auf der Seite bekam ein neues, nicht mehr passendes CSRF-Token.
start_session();

$pageTitle = 'Fotobox buchen';
$errors = [];
$success = isset($_GET['danke']);
$step = $success ? 0 : max(1, min(5, (int) ($_GET['step'] ?? 1)));

function fetch_booking_by_token(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM bookings WHERE edit_token = ?');
    $stmt->execute([$token]);
    $booking = $stmt->fetch();
    return $booking ?: null;
}

function booking_layout_ids(int $bookingId): array
{
    $stmt = db()->prepare('SELECT layout_id FROM booking_layouts WHERE booking_id = ?');
    $stmt->execute([$bookingId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function booking_extra_selections(int $bookingId): array
{
    $stmt = db()->prepare('SELECT extra_id, quantity FROM booking_extras WHERE booking_id = ?');
    $stmt->execute([$bookingId]);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[(int) $row['extra_id']] = (int) $row['quantity'];
    }
    return $result;
}

function stepper_html(int $current): string
{
    $steps = [1 => 'Datum wählen', 2 => 'Reservieren', 3 => 'Design wählen', 4 => 'Extras', 5 => 'Zusammenfassung'];
    $html = '<div class="stepper">';
    foreach ($steps as $num => $label) {
        $state = $num < $current ? 'done' : ($num === $current ? 'active' : 'todo');
        $html .= '<div class="step ' . $state . '"><span class="step-circle">' . ($num < $current ? '✓' : $num) . '</span>'
            . '<span class="step-label">' . htmlspecialchars($label, ENT_QUOTES) . '</span></div>';
        if ($num < 5) {
            $html .= '<div class="step-line ' . ($num < $current ? 'done' : '') . '"></div>';
        }
    }
    return $html . '</div>';
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$booking = $step >= 3 ? fetch_booking_by_token($token) : null;

if ($step >= 3 && (!$booking || $booking['status'] !== 'reserviert')) {
    header('Location: buchen.php?step=1');
    exit;
}

$layouts = fetch_all_layouts();
$defaultLayout = null;
foreach ($layouts as $layout) {
    if ($layout['is_default']) {
        $defaultLayout = $layout;
        break;
    }
}
$extraLayouts = array_values(array_filter($layouts, static fn (array $l): bool => !$l['is_default']));
$activeExtras = fetch_active_extras();

// ---------- Schritt 2: Reservierung anlegen ----------
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        header('Location: buchen.php?danke=1');
        exit;
    }

    $firstName = trim((string) ($_POST['first_name'] ?? ''));
    $lastName = trim((string) ($_POST['last_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $eventDate = trim((string) ($_POST['event_date'] ?? ''));

    if ($firstName === '' || $lastName === '') {
        $errors[] = 'Bitte Vor- und Nachnamen angeben.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
    }
    $eventDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $eventDate) ?: null;
    if (!$eventDateObj) {
        $errors[] = 'Bitte zuerst einen Termin wählen.';
    } elseif ($eventDateObj < new DateTimeImmutable('today')) {
        $errors[] = 'Das Eventdatum darf nicht in der Vergangenheit liegen.';
    } elseif (is_date_blocked($eventDate)) {
        $errors[] = 'Dieser Termin ist leider inzwischen vergeben. Bitte ein anderes Datum wählen.';
    }
    if (!$defaultLayout) {
        $errors[] = 'Es ist aktuell kein Standardlayout hinterlegt, bitte kontaktiere uns direkt.';
    }

    if (!$errors) {
        $newToken = random_key(24);
        $stmt = db()->prepare(
            'INSERT INTO bookings (edit_token, customer_name, customer_email, event_date, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$newToken, trim($firstName . ' ' . $lastName), $email, $eventDate, 'reserviert']);
        $newBookingId = (int) db()->lastInsertId();

        $stmt = db()->prepare('INSERT INTO booking_layouts (booking_id, layout_id) VALUES (?, ?)');
        $stmt->execute([$newBookingId, (int) $defaultLayout['id']]);

        header('Location: buchen.php?step=3&token=' . urlencode($newToken));
        exit;
    }
}

// ---------- Schritt 3: Design speichern ----------
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $selectedLayoutIds = array_unique(array_merge(
        [(int) $defaultLayout['id']],
        array_map('intval', $_POST['layout_ids'] ?? [])
    ));

    db()->beginTransaction();
    db()->prepare('DELETE FROM booking_layouts WHERE booking_id = ?')->execute([$booking['id']]);
    $stmt = db()->prepare('INSERT INTO booking_layouts (booking_id, layout_id) VALUES (?, ?)');
    foreach ($selectedLayoutIds as $layoutId) {
        $stmt->execute([$booking['id'], $layoutId]);
    }
    db()->commit();

    header('Location: buchen.php?step=4&token=' . urlencode($token));
    exit;
}

// ---------- Schritt 4: Extras speichern ----------
if ($step === 4 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $quantities = $_POST['extra_qty'] ?? [];

    db()->beginTransaction();
    db()->prepare('DELETE FROM booking_extras WHERE booking_id = ?')->execute([$booking['id']]);
    $stmt = db()->prepare('INSERT INTO booking_extras (booking_id, extra_id, quantity) VALUES (?, ?, ?)');
    foreach ($activeExtras as $extra) {
        $qty = max(0, (int) ($quantities[$extra['id']] ?? 0));
        if ($extra['type'] === 'toggle') {
            $qty = $qty > 0 ? 1 : 0;
        }
        if ($qty > 0) {
            $stmt->execute([$booking['id'], (int) $extra['id'], $qty]);
        }
    }
    db()->commit();

    header('Location: buchen.php?step=5&token=' . urlencode($token));
    exit;
}

// ---------- Schritt 5: final abschicken ----------
if ($step === 5 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $phone = trim((string) ($_POST['customer_phone'] ?? ''));
    $address = trim((string) ($_POST['customer_address'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    $wantsQuote = isset($_POST['wants_quote']);

    if ($address === '') {
        $errors[] = 'Bitte eine Versandadresse angeben.';
    }

    if (!$errors) {
        $layoutIds = booking_layout_ids((int) $booking['id']);
        $extraSelections = booking_extra_selections((int) $booking['id']);
        $total = calc_booking_total($layoutIds, $extraSelections);

        $stmt = db()->prepare(
            "UPDATE bookings SET customer_phone = ?, customer_address = ?, message = ?,
             total_price_cents = ?, wants_quote = ?, status = 'angefragt' WHERE id = ?"
        );
        $stmt->execute([
            $phone !== '' ? $phone : null,
            $address,
            $message !== '' ? $message : null,
            $total,
            $wantsQuote ? 1 : 0,
            $booking['id'],
        ]);

        header('Location: buchen.php?danke=1');
        exit;
    }
}

// Daten fuer die Anzeige der aktuellen Schritte neu laden (nach evtl. POST oben).
if ($booking) {
    $stmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
    $stmt->execute([$booking['id']]);
    $booking = $stmt->fetch();
    $chosenLayoutIds = booking_layout_ids((int) $booking['id']);
    $chosenExtras = booking_extra_selections((int) $booking['id']);
} else {
    $chosenLayoutIds = [];
    $chosenExtras = [];
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

<section class="section" style="padding-top:40px;">
    <?php if ($success): ?>
        <h2>Fotobox buchen</h2>
        <div class="panel-box success-box">
            <h3>Danke für deine Anfrage!</h3>
            <p>Wir prüfen die Verfügbarkeit und melden uns zeitnah per E-Mail bei dir.</p>
        </div>
    <?php else: ?>
        <?= stepper_html($step) ?>

        <?php foreach ($errors as $err): ?>
            <p class="error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
        <?php endforeach; ?>

        <?php if ($step === 1): ?>
            <div class="panel-box" style="max-width:520px;margin:0 auto;">
                <h3>1. Termin wählen</h3>
                <div id="calendar"></div>
                <p id="selected-date-label" class="muted">Bitte einen freien Tag anklicken.</p>
            </div>

        <?php elseif ($step === 2): ?>
            <?php $eventDate = (string) ($_GET['date'] ?? $_POST['event_date'] ?? ''); ?>
            <div class="date-banner">
                📅 <?= htmlspecialchars((new DateTimeImmutable($eventDate))->format('d.m.Y'), ENT_QUOTES) ?>
                <a href="buchen.php?step=1">Ändern</a>
            </div>
            <div class="panel-box" style="max-width:480px;margin:0 auto;">
                <h3>Termin reservieren</h3>
                <p class="muted">Sichere dir deinen Wunschtermin. Die Reservierung ist <?= RESERVATION_HOLD_DAYS ?> Tage kostenlos und unverbindlich.</p>
                <p class="price-line">
                    <?= money_from_cents(base_price_cents()) ?>
                    <span class="muted"><?= htmlspecialchars(base_price_label(), ENT_QUOTES) ?></span>
                </p>
                <form method="post" action="buchen.php?step=2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="event_date" value="<?= htmlspecialchars($eventDate, ENT_QUOTES) ?>">
                    <input type="text" name="website" class="honeypot" tabindex="-1" autocomplete="off">
                    <div class="grid2">
                        <label>Vorname *<input type="text" name="first_name" required></label>
                        <label>Nachname *<input type="text" name="last_name" required></label>
                    </div>
                    <label>E-Mail-Adresse *<input type="email" name="email" required></label>
                    <button type="submit">Termin jetzt reservieren</button>
                    <p class="muted" style="text-align:center;">Keine Zahlungsdaten nötig. Wir senden dir eine E-Mail mit allen Details.</p>
                </form>
            </div>

        <?php elseif ($step === 3): ?>
            <?php
            $categories = array_values(array_unique(array_filter(array_map(
                static fn (array $l) => $l['category'] ?? null,
                $extraLayouts
            ))));
            sort($categories);
            $hasChoice = (bool) array_intersect($chosenLayoutIds, array_map(static fn ($l) => (int) $l['id'], $extraLayouts));
            ?>
            <div class="panel-box" style="max-width:760px;margin:0 auto;">
                <h3>Wähle dein <span class="accent-text">Design</span></h3>
                <p class="muted">Wie sollen deine Ausdrucke aussehen? Du kannst das später noch ändern.</p>

                <div class="design-options">
                    <div class="design-option active">
                        <div class="design-icon">🎨</div>
                        <strong>Fertige Vorlage</strong>
                        <span class="muted-text">Aus <?= count($extraLayouts) + 1 ?> Designs wählen</span>
                    </div>
                    <div class="design-option disabled">
                        <div class="design-icon">✏️</div>
                        <strong>Online-Designer</strong>
                        <span class="badge-soon">Bald verfügbar</span>
                    </div>
                    <div class="design-option disabled">
                        <div class="design-icon">📤</div>
                        <strong>Eigenes hochladen</strong>
                        <span class="badge-soon">Bald verfügbar</span>
                    </div>
                </div>

                <?php if (!$hasChoice): ?>
                    <div class="info-banner">
                        <span class="info-icon">ℹ️</span>
                        <div>
                            <strong>Standarddesign wird verwendet</strong>
                            <p>Ohne weitere Auswahl bekommst du unser
                                „<?= htmlspecialchars($defaultLayout['name'] ?? 'Standard', ENT_QUOTES) ?>"-Layout.
                                Wähle unten ein Zusatzformat, falls du mehr möchtest.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="post" action="buchen.php?step=3&token=<?= urlencode($token) ?>" id="design-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">

                    <?php if ($categories): ?>
                        <div class="category-tabs">
                            <button type="button" class="cat-tab active" data-cat="">Alle</button>
                            <?php foreach ($categories as $cat): ?>
                                <button type="button" class="cat-tab" data-cat="<?= htmlspecialchars($cat, ENT_QUOTES) ?>"><?= htmlspecialchars($cat, ENT_QUOTES) ?></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($extraLayouts): ?>
                        <div class="design-gallery">
                            <?php foreach ($extraLayouts as $layout): ?>
                                <label class="design-card" data-cat="<?= htmlspecialchars((string) ($layout['category'] ?? ''), ENT_QUOTES) ?>">
                                    <input type="checkbox" name="layout_ids[]" value="<?= (int) $layout['id'] ?>"
                                        <?= in_array((int) $layout['id'], $chosenLayoutIds, true) ? 'checked' : '' ?>>
                                    <img src="layout_preview.php?id=<?= (int) $layout['id'] ?>" alt="<?= htmlspecialchars($layout['name'], ENT_QUOTES) ?>" loading="lazy">
                                    <span class="design-card-name"><?= htmlspecialchars($layout['name'], ENT_QUOTES) ?></span>
                                    <span class="design-card-price">+<?= money_from_cents((int) $layout['surcharge_cents']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="muted">Aktuell nur das Standarddesign verfügbar.</p>
                    <?php endif; ?>

                    <button type="submit">Weiter</button>
                </form>
            </div>
            <script>
            (function () {
                var tabs = document.querySelectorAll('.cat-tab');
                var cards = document.querySelectorAll('.design-card');
                tabs.forEach(function (tab) {
                    tab.addEventListener('click', function () {
                        tabs.forEach(function (t) { t.classList.remove('active'); });
                        tab.classList.add('active');
                        var cat = tab.getAttribute('data-cat');
                        cards.forEach(function (card) {
                            card.style.display = (!cat || card.getAttribute('data-cat') === cat) ? '' : 'none';
                        });
                    });
                });
            })();
            </script>

        <?php elseif ($step === 4): ?>
            <?php
            $layoutSurcharge = 0;
            foreach ($layouts as $l) {
                if (in_array((int) $l['id'], $chosenLayoutIds, true)) {
                    $layoutSurcharge += (int) $l['surcharge_cents'];
                }
            }
            $baseTotal = base_price_cents() + $layoutSurcharge;
            ?>
            <div class="panel-box" style="max-width:600px;margin:0 auto;">
                <h3>Extras &amp; Upgrades</h3>
                <p class="muted">Extra hinzufügen oder entfernen - der Preis unten aktualisiert sich direkt.</p>
                <form method="post" action="buchen.php?step=4&token=<?= urlencode($token) ?>" id="extras-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
                    <?php foreach ($activeExtras as $extra): ?>
                        <?php $qty = $chosenExtras[$extra['id']] ?? 0; ?>
                        <div class="extra-row" data-price="<?= (int) $extra['price_cents'] ?>">
                            <div class="extra-info">
                                <span class="extra-icon"><?= htmlspecialchars((string) $extra['icon'], ENT_QUOTES) ?></span>
                                <div>
                                    <strong><?= htmlspecialchars($extra['name'], ENT_QUOTES) ?></strong>
                                    <?php if ($extra['description']): ?>
                                        <div class="muted"><?= htmlspecialchars($extra['description'], ENT_QUOTES) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="extra-control">
                                <span class="extra-price"><?= ($extra['price_cents'] >= 0 ? '+' : '') . money_from_cents((int) $extra['price_cents']) ?><?= $extra['unit_label'] ? ' / ' . htmlspecialchars($extra['unit_label'], ENT_QUOTES) : '' ?></span>
                                <?php if ($extra['type'] === 'quantity'): ?>
                                    <div class="qty-stepper">
                                        <button type="button" class="qty-minus">−</button>
                                        <input type="number" name="extra_qty[<?= (int) $extra['id'] ?>]" value="<?= (int) $qty ?>" min="0" <?= $extra['max_quantity'] ? 'max="' . (int) $extra['max_quantity'] . '"' : '' ?> readonly>
                                        <button type="button" class="qty-plus">+</button>
                                    </div>
                                <?php else: ?>
                                    <label class="switch">
                                        <input type="checkbox" name="extra_qty[<?= (int) $extra['id'] ?>]" value="1" <?= $qty > 0 ? 'checked' : '' ?>>
                                        <span class="slider"></span>
                                    </label>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="total-line">
                        <span>Gesamtpreis</span>
                        <strong id="total-price"><?= money_from_cents($baseTotal) ?></strong>
                    </div>
                    <button type="submit">Weiter</button>
                </form>
            </div>
            <script>
            (function () {
                var base = <?= $baseTotal ?>;
                var form = document.getElementById('extras-form');
                var totalEl = document.getElementById('total-price');

                function formatEuro(cents) {
                    return (cents / 100).toLocaleString('de-DE', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' EUR';
                }

                function recalc() {
                    var total = base;
                    form.querySelectorAll('.extra-row').forEach(function (row) {
                        var price = parseInt(row.getAttribute('data-price'), 10);
                        var checkbox = row.querySelector('input[type=checkbox]');
                        var numberInput = row.querySelector('input[type=number]');
                        if (checkbox) {
                            if (checkbox.checked) total += price;
                        } else if (numberInput) {
                            total += price * parseInt(numberInput.value || '0', 10);
                        }
                    });
                    totalEl.textContent = formatEuro(total);
                }

                form.addEventListener('change', recalc);
                form.querySelectorAll('.qty-plus').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var input = btn.parentElement.querySelector('input[type=number]');
                        var max = input.getAttribute('max');
                        var val = parseInt(input.value || '0', 10) + 1;
                        if (!max || val <= parseInt(max, 10)) { input.value = val; recalc(); }
                    });
                });
                form.querySelectorAll('.qty-minus').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var input = btn.parentElement.querySelector('input[type=number]');
                        var val = Math.max(0, parseInt(input.value || '0', 10) - 1);
                        input.value = val;
                        recalc();
                    });
                });
            })();
            </script>

        <?php elseif ($step === 5): ?>
            <?php
            $chosenLayoutRows = array_values(array_filter($layouts, static fn (array $l) => in_array((int) $l['id'], $chosenLayoutIds, true)));
            $layoutSurchargeSum = array_sum(array_map(static fn (array $l) => (int) $l['surcharge_cents'], $chosenLayoutRows));

            $chosenExtraRows = [];
            if ($chosenExtras) {
                $placeholders = implode(',', array_fill(0, count($chosenExtras), '?'));
                $stmt = db()->prepare("SELECT * FROM extras WHERE id IN ($placeholders)");
                $stmt->execute(array_keys($chosenExtras));
                $chosenExtraRows = $stmt->fetchAll();
            }
            $total = calc_booking_total($chosenLayoutIds, $chosenExtras);

            $event = new DateTimeImmutable($booking['event_date']);
            [$shipStart] = booking_block_range($booking['event_date']);
            $shipOut = new DateTimeImmutable($shipStart);
            $shipBack = $event->modify('+' . BOOKING_BUFFER_DAYS . ' days');
            ?>
            <div class="panel-box" style="max-width:640px;margin:0 auto;">
                <h3>Zusammenfassung</h3>
                <p class="muted">📅 <?= htmlspecialchars(german_weekday($event) . ', ' . $event->format('d.m.Y'), ENT_QUOTES) ?></p>

                <div class="summary-card">
                    <div class="summary-card-head">
                        <span>🎨 DESIGN</span>
                        <a href="buchen.php?step=3&token=<?= urlencode($token) ?>">Ändern</a>
                    </div>
                    <strong><?= htmlspecialchars($defaultLayout['name'] ?? 'Standard', ENT_QUOTES) ?></strong>
                    <?php foreach ($chosenLayoutRows as $l): ?>
                        <?php if (!$l['is_default']): ?>
                            <div><?= htmlspecialchars($l['name'], ENT_QUOTES) ?> (+<?= money_from_cents((int) $l['surcharge_cents']) ?>)</div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <div class="summary-card">
                    <div class="summary-card-head">
                        <span>✨ EXTRAS</span>
                        <a href="buchen.php?step=4&token=<?= urlencode($token) ?>">Ändern</a>
                    </div>
                    <?php if ($chosenExtraRows): ?>
                        <?php foreach ($chosenExtraRows as $extraRow): ?>
                            <?php $qty = $chosenExtras[$extraRow['id']]; ?>
                            <div>✓ <?= htmlspecialchars($extraRow['name'], ENT_QUOTES) ?><?= $qty > 1 ? ' (' . $qty . 'x)' : '' ?>
                                <span class="muted-text">(<?= $extraRow['price_cents'] >= 0 ? '+' : '' ?><?= money_from_cents((int) $extraRow['price_cents'] * $qty) ?>)</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="muted">Keine Extras gewählt</span>
                    <?php endif; ?>
                </div>

                <div class="summary-card">
                    <div class="summary-card-head"><span>🚚 VERSAND-ZEITPLAN (voraussichtlich)</span></div>
                    <ul class="timeline">
                        <li><strong>Versand an dich</strong> &mdash; ca. <?= htmlspecialchars($shipOut->format('d.m.Y'), ENT_QUOTES) ?></li>
                        <li><strong>Dein Event</strong> &mdash; <?= htmlspecialchars($event->format('d.m.Y'), ENT_QUOTES) ?></li>
                        <li><strong>Rücksendung</strong> &mdash; ca. <?= htmlspecialchars($shipBack->format('d.m.Y'), ENT_QUOTES) ?></li>
                    </ul>
                </div>

                <div class="price-box">
                    <h4>Preisübersicht</h4>
                    <div class="price-row"><span><?= htmlspecialchars(base_price_label() ?: 'Fotobox-Miete', ENT_QUOTES) ?></span><span><?= money_from_cents(base_price_cents()) ?></span></div>
                    <?php if ($layoutSurchargeSum > 0): ?>
                        <div class="price-row"><span>Zusatzformate</span><span>+<?= money_from_cents($layoutSurchargeSum) ?></span></div>
                    <?php endif; ?>
                    <?php foreach ($chosenExtraRows as $extraRow): ?>
                        <?php $qty = $chosenExtras[$extraRow['id']]; ?>
                        <div class="price-row"><span><?= htmlspecialchars($extraRow['name'], ENT_QUOTES) ?><?= $qty > 1 ? ' (' . $qty . 'x)' : '' ?></span>
                            <span><?= $extraRow['price_cents'] >= 0 ? '+' : '' ?><?= money_from_cents((int) $extraRow['price_cents'] * $qty) ?></span></div>
                    <?php endforeach; ?>
                    <div class="price-row total"><span>Gesamtpreis</span><span><?= money_from_cents($total) ?></span></div>
                </div>

                <form method="post" action="buchen.php?step=5&token=<?= urlencode($token) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
                    <label>Telefon<input type="text" name="customer_phone" value="<?= htmlspecialchars((string) $booking['customer_phone'], ENT_QUOTES) ?>"></label>
                    <label>Versandadresse (Straße, PLZ, Ort) *
                        <textarea name="customer_address" rows="3" required><?= htmlspecialchars((string) $booking['customer_address'], ENT_QUOTES) ?></textarea>
                    </label>
                    <label>Nachricht (optional)<textarea name="message" rows="3"><?= htmlspecialchars((string) $booking['message'], ENT_QUOTES) ?></textarea></label>
                    <label class="checkbox">
                        <input type="checkbox" name="wants_quote" <?= $booking['wants_quote'] ? 'checked' : '' ?>>
                        Ich benötige vorab ein schriftliches Angebot
                    </label>
                    <button type="submit">Anfrage jetzt verbindlich abschicken</button>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<footer class="site-footer">
    &copy; <?= date('Y') ?> Snapolino &middot; <a href="mailto:info@snapolino.de">info@snapolino.de</a>
</footer>

<?php if ($step === 1 && !$success): ?>
<script>
(function () {
    var calendarEl = document.getElementById('calendar');
    var selectedLabel = document.getElementById('selected-date-label');
    if (!calendarEl) return;

    var monthNames = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    var viewYear = today.getFullYear();
    var viewMonth = today.getMonth();
    var blockedDates = {};

    function toIso(y, m, d) {
        return y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
    }

    function render() {
        var first = new Date(viewYear, viewMonth, 1);
        var startWeekday = (first.getDay() + 6) % 7;
        var daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();

        var html = '<div class="cal-header">'
            + '<button type="button" id="cal-prev">&larr;</button>'
            + '<span>' + monthNames[viewMonth] + ' ' + viewYear + '</span>'
            + '<button type="button" id="cal-next">&rarr;</button>'
            + '</div><div class="cal-grid">';

        ['Mo','Di','Mi','Do','Fr','Sa','So'].forEach(function (d) {
            html += '<div class="cal-weekday">' + d + '</div>';
        });
        for (var i = 0; i < startWeekday; i++) html += '<div class="cal-day cal-empty"></div>';

        for (var day = 1; day <= daysInMonth; day++) {
            var iso = toIso(viewYear, viewMonth, day);
            var dateObj = new Date(viewYear, viewMonth, day);
            var isPast = dateObj < today;
            var isBlocked = !!blockedDates[iso];
            var classes = ['cal-day'];
            if (isPast || isBlocked) classes.push('cal-disabled');
            html += '<div class="' + classes.join(' ') + '" data-date="' + iso + '">' + day + '</div>';
        }

        calendarEl.innerHTML = html;
        document.getElementById('cal-prev').addEventListener('click', function () {
            viewMonth--; if (viewMonth < 0) { viewMonth = 11; viewYear--; } render();
        });
        document.getElementById('cal-next').addEventListener('click', function () {
            viewMonth++; if (viewMonth > 11) { viewMonth = 0; viewYear++; } render();
        });
        calendarEl.querySelectorAll('.cal-day:not(.cal-disabled):not(.cal-empty)').forEach(function (el) {
            el.addEventListener('click', function () {
                window.location.href = 'buchen.php?step=2&date=' + el.getAttribute('data-date');
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
})();
</script>
<?php endif; ?>
</body>
</html>

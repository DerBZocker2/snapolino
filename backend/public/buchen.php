<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stripe.php';

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
    $designMode = (string) ($_POST['mode'] ?? 'gallery');

    if ($designMode === 'upload') {
        $uploadError = validate_custom_design_upload($_FILES['custom_design_file'] ?? null);
        if ($uploadError !== null) {
            $errors[] = $uploadError;
        } else {
            $slotInfo = detect_transparent_slots($_FILES['custom_design_file']['tmp_name']);
            if (!$slotInfo) {
                $errors[] = 'In der Datei wurden keine transparenten Bereiche für Fotos gefunden. Bitte ein PNG mit transparenten Fotoflächen hochladen.';
            } else {
                save_custom_layout_for_booking(
                    (int) $booking['id'], $_FILES['custom_design_file']['tmp_name'],
                    $slotInfo['slots'], $slotInfo['width'], $slotInfo['height'], 'Eigenes Design (Upload)'
                );
                header('Location: buchen.php?step=4&token=' . urlencode($token));
                exit;
            }
        }
    } elseif ($designMode === 'designer') {
        $dataUrl = (string) ($_POST['custom_design_data'] ?? '');
        $baseLayout = fetch_layout_with_slots((int) ($_POST['base_layout_id'] ?? 0));
        $prefix = 'data:image/png;base64,';

        if (!$baseLayout || strncmp($dataUrl, $prefix, strlen($prefix)) !== 0) {
            $errors[] = 'Design konnte nicht gespeichert werden. Bitte erneut versuchen.';
        } else {
            $binary = base64_decode(substr($dataUrl, strlen($prefix)), true);
            $tmpPath = tempnam(sys_get_temp_dir(), 'snapdesign');
            file_put_contents($tmpPath, (string) $binary);
            $info = @getimagesize($tmpPath);
            if (!$info || $info[2] !== IMAGETYPE_PNG) {
                $errors[] = 'Design konnte nicht gespeichert werden. Bitte erneut versuchen.';
            } else {
                $slots = validate_custom_slots(
                    $_POST['custom_slots'] ?? null,
                    (int) $baseLayout['canvas_width'],
                    (int) $baseLayout['canvas_height']
                ) ?? $baseLayout['slots'];

                save_custom_layout_for_booking(
                    (int) $booking['id'], $tmpPath, $slots,
                    (int) $baseLayout['canvas_width'], (int) $baseLayout['canvas_height'],
                    'Eigenes Design (Online-Designer)'
                );
                unlink($tmpPath);
                header('Location: buchen.php?step=4&token=' . urlencode($token));
                exit;
            }
            unlink($tmpPath);
        }
    } else {
        $selectedLayoutIds = array_unique(array_merge(
            [(int) $defaultLayout['id']],
            array_map('intval', $_POST['layout_ids'] ?? [])
        ));

        db()->beginTransaction();
        delete_custom_layouts_for_booking((int) $booking['id']);
        db()->prepare('DELETE FROM booking_layouts WHERE booking_id = ?')->execute([$booking['id']]);
        $stmt = db()->prepare('INSERT INTO booking_layouts (booking_id, layout_id) VALUES (?, ?)');
        foreach ($selectedLayoutIds as $layoutId) {
            $stmt->execute([$booking['id'], $layoutId]);
        }
        db()->commit();

        header('Location: buchen.php?step=4&token=' . urlencode($token));
        exit;
    }
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
    $formAction = (string) ($_POST['form_action'] ?? 'submit');
    $layoutIds = booking_layout_ids((int) $booking['id']);
    $extraSelections = booking_extra_selections((int) $booking['id']);

    if ($formAction === 'apply_coupon' || $formAction === 'remove_coupon') {
        $couponCode = $formAction === 'remove_coupon' ? '' : strtoupper(trim((string) ($_POST['coupon_code'] ?? '')));
        $pricing = calc_booking_pricing($layoutIds, $extraSelections, $couponCode !== '' ? $couponCode : null);

        if ($couponCode !== '' && !$pricing['coupon']) {
            $errors[] = 'Dieser Gutscheincode ist ungültig oder abgelaufen.';
        } else {
            db()->prepare('UPDATE bookings SET coupon_code = ?, discount_cents = ?, total_price_cents = ? WHERE id = ?')
                ->execute([$couponCode !== '' ? $couponCode : null, $pricing['discount_cents'], $pricing['total'], $booking['id']]);
        }
    } else {
        $phone = trim((string) ($_POST['customer_phone'] ?? ''));
        $street = trim((string) ($_POST['customer_street'] ?? ''));
        $zip = trim((string) ($_POST['customer_zip'] ?? ''));
        $city = trim((string) ($_POST['customer_city'] ?? ''));
        $company = trim((string) ($_POST['customer_company'] ?? ''));
        $invoiceToCompany = isset($_POST['invoice_to_company']);
        $message = trim((string) ($_POST['message'] ?? ''));
        $wantsQuote = isset($_POST['wants_quote']);

        if ($street === '' || $zip === '' || $city === '') {
            $errors[] = 'Bitte Straße, PLZ und Ort angeben.';
        }
        if ($invoiceToCompany && $company === '') {
            $errors[] = 'Bitte einen Firmennamen für die Rechnung angeben.';
        }

        if (!$errors) {
            $pricing = calc_booking_pricing($layoutIds, $extraSelections, (string) ($booking['coupon_code'] ?? '') ?: null);

            $stmt = db()->prepare(
                'UPDATE bookings SET customer_phone = ?, customer_street = ?, customer_zip = ?, customer_city = ?,
                 customer_company = ?, invoice_to_company = ?, message = ?, discount_cents = ?,
                 total_price_cents = ?, wants_quote = ? WHERE id = ?'
            );
            $stmt->execute([
                $phone !== '' ? $phone : null,
                $street,
                $zip,
                $city,
                $company !== '' ? $company : null,
                $invoiceToCompany ? 1 : 0,
                $message !== '' ? $message : null,
                $pricing['discount_cents'],
                $pricing['total'],
                $wantsQuote ? 1 : 0,
                $booking['id'],
            ]);

            if ($wantsQuote) {
                // Wer erst ein schriftliches Angebot moechte, zahlt nicht sofort -
                // normale Anfrage, Admin bearbeitet sie im Panel wie bisher.
                db()->prepare("UPDATE bookings SET status = 'angefragt' WHERE id = ?")->execute([$booking['id']]);
                header('Location: buchen.php?danke=1');
                exit;
            }

            $baseUrl = rtrim((string) backend_config()['base_url'], '/');
            $successUrl = $baseUrl . '/buchen.php?danke=1&paid=1';
            $cancelUrl = $baseUrl . '/buchen.php?step=5&token=' . urlencode($token);

            $session = create_stripe_checkout_session($booking, booking_stripe_line_items((int) $booking['id']), $successUrl, $cancelUrl);

            if (!$session || empty($session['url'])) {
                $errors[] = 'Die Zahlung konnte gerade nicht gestartet werden. Bitte versuche es gleich nochmal oder schreib uns kurz.';
            } else {
                db()->prepare('UPDATE bookings SET stripe_session_id = ? WHERE id = ?')->execute([$session['id'], $booking['id']]);
                header('Location: ' . $session['url']);
                exit;
            }
        }
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
    <link rel="stylesheet" href="<?= asset_url('assets/site.css', __DIR__ . '/assets/site.css') ?>">
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
            <?php if (isset($_GET['paid'])): ?>
                <h3>Danke für deine Zahlung!</h3>
                <p>Deine Fotobox ist fest gebucht. Die Buchungsbestätigung mit Rechnung schicken wir dir gerade per E-Mail zu.</p>
            <?php else: ?>
                <h3>Danke für deine Anfrage!</h3>
                <p>Wir prüfen die Verfügbarkeit und melden uns zeitnah per E-Mail bei dir.</p>
            <?php endif; ?>
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

            $customLayout = null;
            foreach ($chosenLayoutIds as $clId) {
                if ($clId === (int) $defaultLayout['id']) {
                    continue;
                }
                $maybe = fetch_layout_with_slots($clId);
                if ($maybe && $maybe['is_custom']) {
                    $customLayout = $maybe;
                    break;
                }
            }
            $startPanel = $customLayout ? 'upload' : 'gallery';

            $designerLayouts = array_merge([$defaultLayout], $extraLayouts);
            $designerLayoutsJson = json_encode(array_map(static function (array $l) {
                return [
                    'id' => (int) $l['id'],
                    'name' => $l['name'],
                    'canvas_width' => (int) $l['canvas_width'],
                    'canvas_height' => (int) $l['canvas_height'],
                    'slots' => array_map(static fn ($s) => [
                        'x' => (int) $s['x'], 'y' => (int) $s['y'],
                        'width' => (int) $s['width'], 'height' => (int) $s['height'],
                    ], fetch_layout_with_slots((int) $l['id'])['slots']),
                ];
            }, $designerLayouts), JSON_UNESCAPED_UNICODE);
            ?>
            <div class="panel-box" style="max-width:760px;margin:0 auto;">
                <h3>Wähle dein <span class="accent-text">Design</span></h3>
                <p class="muted">Wie sollen deine Ausdrucke aussehen? Du kannst das später noch ändern.</p>

                <div class="design-options">
                    <div class="design-option <?= $startPanel === 'gallery' ? 'active' : '' ?>" data-panel="gallery">
                        <div class="design-icon">🎨</div>
                        <strong>Fertige Vorlage</strong>
                        <span class="muted-text">Aus <?= count($extraLayouts) + 1 ?> Designs wählen</span>
                    </div>
                    <div class="design-option" data-panel="designer">
                        <div class="design-icon">✏️</div>
                        <strong>Online-Designer</strong>
                        <span class="muted-text">Farbe, Muster &amp; Text selbst gestalten</span>
                    </div>
                    <div class="design-option <?= $startPanel === 'upload' ? 'active' : '' ?>" data-panel="upload">
                        <div class="design-icon">📤</div>
                        <strong>Eigenes hochladen</strong>
                        <span class="muted-text">PNG mit transparenten Fotoflächen</span>
                    </div>
                </div>

                <?php if (!$hasChoice && !$customLayout): ?>
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

                <?php foreach ($errors as $err): ?>
                    <p class="error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
                <?php endforeach; ?>

                <!-- Panel 1: Fertige Vorlage -->
                <div class="design-panel" id="panel-gallery" <?= $startPanel === 'gallery' ? '' : 'hidden' ?>>
                    <form method="post" action="buchen.php?step=3&token=<?= urlencode($token) ?>" id="design-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
                        <input type="hidden" name="mode" value="gallery">

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
                                        <button type="button" class="design-card-customize" data-customize="<?= (int) $layout['id'] ?>">Anpassen</button>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="muted">Aktuell nur das Standarddesign verfügbar.</p>
                        <?php endif; ?>

                        <button type="submit">Weiter</button>
                    </form>
                </div>

                <!-- Panel 2: Online-Designer -->
                <div class="design-panel" id="panel-designer" hidden>
                    <p class="muted">Wähle Farben, ein Muster und optional einen Text. Die Fotoflächen (gestrichelt) kannst du direkt im Bild per Maus verschieben.</p>
                    <div class="designer-layout">
                        <canvas id="designer-canvas" width="600" height="400"></canvas>
                        <div class="designer-controls">
                            <label>Basis-Layout
                                <select id="designer-base">
                                    <?php foreach ($designerLayouts as $l): ?>
                                        <option value="<?= (int) $l['id'] ?>"><?= htmlspecialchars($l['name'], ENT_QUOTES) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Hintergrundfarbe <input type="color" id="designer-bg" value="#fdf6ec"></label>
                            <label>Akzentfarbe <input type="color" id="designer-accent" value="#ff6f59"></label>
                            <label>Muster
                                <select id="designer-pattern">
                                    <option value="none">Keins</option>
                                    <option value="confetti">Konfetti</option>
                                    <option value="stripes">Streifen</option>
                                </select>
                            </label>
                            <label>Text (optional)<input type="text" id="designer-text" maxlength="40" placeholder="z.B. Julia &amp; Tom"></label>
                            <button type="button" id="designer-reset" class="button-secondary">Fotoflächen zurücksetzen</button>
                            <button type="button" id="designer-save">Design übernehmen</button>
                        </div>
                    </div>
                    <form method="post" action="buchen.php?step=3&token=<?= urlencode($token) ?>" id="designer-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
                        <input type="hidden" name="mode" value="designer">
                        <input type="hidden" name="base_layout_id" id="designer-base-id">
                        <input type="hidden" name="custom_slots" id="designer-slots">
                        <input type="hidden" name="custom_design_data" id="designer-data">
                    </form>
                </div>

                <!-- Panel 3: Eigenes hochladen -->
                <div class="design-panel" id="panel-upload" <?= $startPanel === 'upload' ? '' : 'hidden' ?>>
                    <?php if ($customLayout): ?>
                        <div class="info-banner">
                            <span class="info-icon">✅</span>
                            <div>
                                <strong>Eigenes Design gespeichert</strong>
                                <p><?= (int) $customLayout['slot_count'] ?> Fotoflächen erkannt. Du kannst es unten ersetzen.</p>
                            </div>
                        </div>
                        <img src="layout_preview.php?id=<?= (int) $customLayout['id'] ?>" alt="Eigenes Design" style="max-width:280px;border:1px solid var(--border);border-radius:8px;">
                    <?php endif; ?>
                    <p class="muted">Lade ein fertiges PNG hoch (Querformat, Seitenverhältnis ca. 3:2). Die Bereiche, die du transparent gelassen hast, werden automatisch als Fotoflächen erkannt.</p>
                    <form method="post" action="buchen.php?step=3&token=<?= urlencode($token) ?>" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
                        <input type="hidden" name="mode" value="upload">
                        <label>PNG-Datei<input type="file" name="custom_design_file" accept="image/png" required></label>
                        <button type="submit"><?= $customLayout ? 'Design ersetzen' : 'Hochladen' ?></button>
                    </form>
                </div>
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

                var options = document.querySelectorAll('.design-option');
                var panels = { gallery: document.getElementById('panel-gallery'), designer: document.getElementById('panel-designer'), upload: document.getElementById('panel-upload') };
                function showPanel(name) {
                    options.forEach(function (o) { o.classList.toggle('active', o.getAttribute('data-panel') === name); });
                    Object.keys(panels).forEach(function (key) { panels[key].hidden = (key !== name); });
                }
                options.forEach(function (opt) {
                    opt.addEventListener('click', function () { showPanel(opt.getAttribute('data-panel')); });
                });

                var layouts = <?= $designerLayoutsJson ?>;
                var canvas = document.getElementById('designer-canvas');
                var ctx = canvas.getContext('2d');
                var baseSelect = document.getElementById('designer-base');
                var bgInput = document.getElementById('designer-bg');
                var accentInput = document.getElementById('designer-accent');
                var patternSelect = document.getElementById('designer-pattern');
                var textInput = document.getElementById('designer-text');

                function layoutById(id) {
                    for (var i = 0; i < layouts.length; i++) {
                        if (layouts[i].id === id) { return layouts[i]; }
                    }
                    return layouts[0];
                }

                function currentLayout() { return layoutById(parseInt(baseSelect.value, 10)); }

                var customSlots = null;
                var HANDLE_RADIUS = 8;
                var MIN_SLOT_SIZE = 60;
                function resetSlots() {
                    customSlots = currentLayout().slots.map(function (s) {
                        return { x: s.x, y: s.y, width: s.width, height: s.height };
                    });
                }

                function drawDesign(targetCtx, w, h, layout, slots, scale, showOutlines) {
                    targetCtx.clearRect(0, 0, w, h);
                    targetCtx.fillStyle = bgInput.value;
                    targetCtx.fillRect(0, 0, w, h);

                    var accent = accentInput.value;
                    var pattern = patternSelect.value;
                    if (pattern === 'confetti') {
                        var rng = 12345;
                        function rand() { rng = (rng * 1103515245 + 12345) & 0x7fffffff; return (rng % 1000) / 1000; }
                        for (var i = 0; i < 140; i++) {
                            targetCtx.fillStyle = (i % 3 === 0) ? accent : (i % 3 === 1 ? '#ffffff' : bgInput.value);
                            var rx = rand() * w, ry = rand() * h, rr = 2 * scale + rand() * 3 * scale;
                            targetCtx.beginPath();
                            targetCtx.arc(rx, ry, rr, 0, Math.PI * 2);
                            targetCtx.fill();
                        }
                    } else if (pattern === 'stripes') {
                        targetCtx.strokeStyle = accent;
                        targetCtx.lineWidth = 6 * scale;
                        for (var sx = -h; sx < w; sx += 24 * scale) {
                            targetCtx.beginPath();
                            targetCtx.moveTo(sx, 0);
                            targetCtx.lineTo(sx + h, h);
                            targetCtx.stroke();
                        }
                    }

                    targetCtx.strokeStyle = accent;
                    targetCtx.lineWidth = 3 * scale;
                    targetCtx.strokeRect(10 * scale, 10 * scale, w - 20 * scale, h - 20 * scale);

                    var text = textInput.value.trim();
                    if (text) {
                        targetCtx.fillStyle = accent;
                        targetCtx.font = 'bold ' + Math.round(18 * scale) + 'px "Segoe UI", Arial, sans-serif';
                        targetCtx.textAlign = 'center';
                        targetCtx.textBaseline = 'middle';
                        targetCtx.fillText(text, w / 2, h - 20 * scale);
                    }

                    // Foto-Slots ausschneiden (transparent), damit die Kamera-Bilder durchscheinen.
                    targetCtx.save();
                    targetCtx.globalCompositeOperation = 'destination-out';
                    slots.forEach(function (s) {
                        targetCtx.fillRect(s.x * scale, s.y * scale, s.width * scale, s.height * scale);
                    });
                    targetCtx.restore();

                    if (showOutlines) {
                        targetCtx.save();
                        targetCtx.strokeStyle = 'rgba(108,92,231,0.8)';
                        targetCtx.lineWidth = 2;
                        targetCtx.setLineDash([6, 4]);
                        slots.forEach(function (s) {
                            targetCtx.strokeRect(s.x * scale, s.y * scale, s.width * scale, s.height * scale);
                        });
                        targetCtx.restore();

                        // Rundlicher Ziehpunkt unten rechts an jeder Fotoflaeche zum Skalieren.
                        targetCtx.save();
                        targetCtx.fillStyle = '#6c5ce7';
                        targetCtx.strokeStyle = '#fff';
                        targetCtx.lineWidth = 2;
                        slots.forEach(function (s) {
                            targetCtx.beginPath();
                            targetCtx.arc((s.x + s.width) * scale, (s.y + s.height) * scale, HANDLE_RADIUS, 0, Math.PI * 2);
                            targetCtx.fill();
                            targetCtx.stroke();
                        });
                        targetCtx.restore();
                    }
                }

                function refreshPreview() {
                    var layout = currentLayout();
                    var scale = canvas.width / layout.canvas_width;
                    drawDesign(ctx, canvas.width, canvas.height, layout, customSlots, scale, true);
                }

                [bgInput, accentInput, patternSelect, textInput].forEach(function (el) {
                    el.addEventListener('input', refreshPreview);
                    el.addEventListener('change', refreshPreview);
                });
                baseSelect.addEventListener('change', function () {
                    resetSlots();
                    refreshPreview();
                });
                resetSlots();
                refreshPreview();

                document.getElementById('designer-reset').addEventListener('click', function () {
                    resetSlots();
                    refreshPreview();
                });

                // Fotoflaechen im Vorschau-Canvas per Maus verschieben und am
                // Ziehpunkt unten rechts in der Groesse anpassen.
                var drag = null;
                var resize = null;
                function canvasPoint(ev) {
                    var rect = canvas.getBoundingClientRect();
                    return {
                        x: (ev.clientX - rect.left) * (canvas.width / rect.width),
                        y: (ev.clientY - rect.top) * (canvas.height / rect.height),
                    };
                }
                function slotAtHandle(p, scale) {
                    for (var i = customSlots.length - 1; i >= 0; i--) {
                        var s = customSlots[i];
                        var hx = (s.x + s.width) * scale, hy = (s.y + s.height) * scale;
                        if (Math.hypot(p.x - hx, p.y - hy) <= HANDLE_RADIUS + 4) {
                            return i;
                        }
                    }
                    return -1;
                }
                function slotAtPoint(p, scale) {
                    for (var i = customSlots.length - 1; i >= 0; i--) {
                        var s = customSlots[i];
                        var sx = s.x * scale, sy = s.y * scale, sw = s.width * scale, sh = s.height * scale;
                        if (p.x >= sx && p.x <= sx + sw && p.y >= sy && p.y <= sy + sh) {
                            return i;
                        }
                    }
                    return -1;
                }
                canvas.addEventListener('mousedown', function (ev) {
                    var layout = currentLayout();
                    var scale = canvas.width / layout.canvas_width;
                    var p = canvasPoint(ev);

                    var handleIdx = slotAtHandle(p, scale);
                    if (handleIdx !== -1) {
                        var hs = customSlots[handleIdx];
                        resize = { index: handleIdx, startX: p.x, startY: p.y, origW: hs.width, origH: hs.height };
                        return;
                    }

                    var moveIdx = slotAtPoint(p, scale);
                    if (moveIdx !== -1) {
                        var ms = customSlots[moveIdx];
                        drag = { index: moveIdx, startX: p.x, startY: p.y, origX: ms.x, origY: ms.y };
                    }
                });
                canvas.addEventListener('mousemove', function (ev) {
                    var layout = currentLayout();
                    var scale = canvas.width / layout.canvas_width;
                    var p = canvasPoint(ev);

                    if (resize) {
                        var s = customSlots[resize.index];
                        var dw = (p.x - resize.startX) / scale;
                        var dh = (p.y - resize.startY) / scale;
                        s.width = Math.max(MIN_SLOT_SIZE, Math.min(layout.canvas_width - s.x, resize.origW + dw));
                        s.height = Math.max(MIN_SLOT_SIZE, Math.min(layout.canvas_height - s.y, resize.origH + dh));
                        refreshPreview();
                        return;
                    }
                    if (drag) {
                        var d = customSlots[drag.index];
                        var dx = (p.x - drag.startX) / scale;
                        var dy = (p.y - drag.startY) / scale;
                        d.x = Math.max(0, Math.min(layout.canvas_width - d.width, drag.origX + dx));
                        d.y = Math.max(0, Math.min(layout.canvas_height - d.height, drag.origY + dy));
                        refreshPreview();
                        return;
                    }

                    if (slotAtHandle(p, scale) !== -1) {
                        canvas.style.cursor = 'nwse-resize';
                    } else if (slotAtPoint(p, scale) !== -1) {
                        canvas.style.cursor = 'grab';
                    } else {
                        canvas.style.cursor = 'default';
                    }
                });
                document.addEventListener('mouseup', function () { drag = null; resize = null; });

                document.querySelectorAll('[data-customize]').forEach(function (btn) {
                    btn.addEventListener('click', function (ev) {
                        ev.preventDefault();
                        baseSelect.value = btn.getAttribute('data-customize');
                        resetSlots();
                        refreshPreview();
                        showPanel('designer');
                    });
                });

                document.getElementById('designer-save').addEventListener('click', function () {
                    var layout = currentLayout();
                    var full = document.createElement('canvas');
                    full.width = layout.canvas_width;
                    full.height = layout.canvas_height;
                    drawDesign(full.getContext('2d'), full.width, full.height, layout, customSlots, 1, false);
                    document.getElementById('designer-base-id').value = layout.id;
                    document.getElementById('designer-slots').value = JSON.stringify(customSlots);
                    document.getElementById('designer-data').value = full.toDataURL('image/png');
                    document.getElementById('designer-form').submit();
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
            $pricing = calc_booking_pricing($chosenLayoutIds, $chosenExtras, (string) ($booking['coupon_code'] ?? '') ?: null);
            $discount = $pricing['discount_cents'];
            $total = $pricing['total'];

            $event = new DateTimeImmutable($booking['event_date']);
            [$shipStart] = booking_block_range($booking['event_date']);
            $shipOut = new DateTimeImmutable($shipStart);
            $shipBack = $event->modify('+' . BOOKING_BUFFER_DAYS . ' days');
            ?>
            <div class="summary-layout">
                <div class="panel-box">
                    <h3>Rechnungsadresse</h3>
                    <p class="muted">Die Rechnung geht an diese Adresse.</p>

                    <form method="post" action="buchen.php?step=5&token=<?= urlencode($token) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
                        <input type="hidden" name="form_action" value="submit">

                        <label>Telefon<input type="text" name="customer_phone" value="<?= htmlspecialchars((string) $booking['customer_phone'], ENT_QUOTES) ?>"></label>
                        <label>Straße und Hausnummer *
                            <input type="text" name="customer_street" required value="<?= htmlspecialchars((string) $booking['customer_street'], ENT_QUOTES) ?>">
                        </label>
                        <div class="grid2">
                            <label>PLZ *<input type="text" name="customer_zip" required value="<?= htmlspecialchars((string) $booking['customer_zip'], ENT_QUOTES) ?>"></label>
                            <label>Ort *<input type="text" name="customer_city" required value="<?= htmlspecialchars((string) $booking['customer_city'], ENT_QUOTES) ?>"></label>
                        </div>

                        <label class="checkbox">
                            <input type="checkbox" name="invoice_to_company" id="invoice-company-checkbox" <?= $booking['invoice_to_company'] ? 'checked' : '' ?>>
                            Rechnung auf Firma ausstellen
                        </label>
                        <div id="company-name-field" <?= $booking['invoice_to_company'] ? '' : 'hidden' ?>>
                            <label>Firmenname
                                <input type="text" name="customer_company" value="<?= htmlspecialchars((string) $booking['customer_company'], ENT_QUOTES) ?>">
                            </label>
                        </div>

                        <label>Nachricht (optional)<textarea name="message" rows="3"><?= htmlspecialchars((string) $booking['message'], ENT_QUOTES) ?></textarea></label>
                        <label class="checkbox">
                            <input type="checkbox" name="wants_quote" id="wants-quote-checkbox" <?= $booking['wants_quote'] ? 'checked' : '' ?>>
                            Ich möchte vorab nur ein schriftliches Angebot (noch nicht bezahlen)
                        </label>

                        <button type="submit" id="submit-booking-btn" class="btn-gradient">
                            <?= $booking['wants_quote'] ? 'Angebot anfordern' : 'Weiter zur Zahlung' ?>
                        </button>
                        <p class="muted" style="text-align:center;font-size:12px;">Sichere Zahlung über Stripe · Kreditkarte, Klarna &amp; mehr</p>
                    </form>
                </div>

                <div class="summary-sidebar">
                    <div class="panel-box">
                        <h3>Deine Buchung</h3>
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

                        <form method="post" action="buchen.php?step=5&token=<?= urlencode($token) ?>" class="coupon-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
                            <?php if ($booking['coupon_code']): ?>
                                <input type="hidden" name="form_action" value="remove_coupon">
                                <div class="coupon-row">
                                    <span class="muted">Gutschein <strong><?= htmlspecialchars($booking['coupon_code'], ENT_QUOTES) ?></strong> aktiv</span>
                                    <button type="submit" class="button-secondary">Entfernen</button>
                                </div>
                            <?php else: ?>
                                <input type="hidden" name="form_action" value="apply_coupon">
                                <div class="coupon-row">
                                    <input type="text" name="coupon_code" placeholder="Gutscheincode eingeben">
                                    <button type="submit" class="button-secondary">Einlösen</button>
                                </div>
                            <?php endif; ?>
                        </form>

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
                            <?php if ($discount > 0): ?>
                                <div class="price-row" style="color:#1f9d55;"><span>Rabatt<?= $booking['coupon_code'] ? ' (' . htmlspecialchars($booking['coupon_code'], ENT_QUOTES) . ')' : '' ?></span><span>&minus;<?= money_from_cents($discount) ?></span></div>
                            <?php endif; ?>
                            <div class="price-row total"><span>Gesamtpreis</span><span><?= money_from_cents($total) ?></span></div>
                        </div>
                    </div>
                </div>
            </div>
            <script>
            (function () {
                var cb = document.getElementById('wants-quote-checkbox');
                var btn = document.getElementById('submit-booking-btn');
                var payLabel = 'Weiter zur Zahlung';
                var quoteLabel = 'Angebot anfordern';
                cb.addEventListener('change', function () {
                    btn.textContent = cb.checked ? quoteLabel : payLabel;
                });

                var companyCb = document.getElementById('invoice-company-checkbox');
                var companyField = document.getElementById('company-name-field');
                companyCb.addEventListener('change', function () {
                    companyField.hidden = !companyCb.checked;
                });
            })();
            </script>
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

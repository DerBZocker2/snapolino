<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/customer_auth.php';
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
    $steps = [1 => 'Datum wählen', 2 => 'Kontaktdaten', 3 => 'Design wählen', 4 => 'Extras', 5 => 'Zusammenfassung'];
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

if ($step >= 3 && !$booking) {
    header('Location: buchen.php?step=1');
    exit;
}

// Buchungen, die abschliessend bestaetigt sind (bezahlt oder Admin hat eine
// Angebots-Buchung manuell bestaetigt) sowie abgelehnte/stornierte lassen
// sich nicht mehr aendern - ausser ein Admin hat die Bearbeitung fuer genau
// diese eine Buchung ausdruecklich wieder freigeschaltet (siehe
// booking_detail.php). Ein POST auf eine gesperrte Buchung wird abgelehnt,
// statt die Aenderung stillschweigend zu speichern.
$bookingLocked = $step >= 3 && !booking_customer_editable($booking);
if ($bookingLocked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(403);
    exit('Diese Buchung ist bereits abgeschlossen und kann nicht mehr geändert werden.');
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

// Maximal 3 Zusatzformate pro Buchung waehlbar (das Standardlayout kommt
// immer automatisch dazu) - clientseitig deaktiviert JS weitere
// Checkboxen, hier serverseitig zusaetzlich als Schutz vor manuellem POST.
const MAX_EXTRA_LAYOUTS = 3;

// ---------- Schritt 2: Buchungsanfrage anlegen ----------
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
        $accountId = find_or_create_customer_account($email);
        $stmt = db()->prepare(
            'INSERT INTO bookings (edit_token, customer_name, customer_email, customer_account_id, event_date, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$newToken, trim($firstName . ' ' . $lastName), $email, $accountId, $eventDate, 'angefragt']);
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
        // array_slice kappt still auf MAX_EXTRA_LAYOUTS, statt einen Fehler zu
        // zeigen - normalerweise verhindert das JS im Formular schon, dass
        // mehr angehakt werden, das hier ist nur die serverseitige Absicherung
        // gegen einen manuellen POST.
        $extraLayoutIds = array_slice(array_map('intval', $_POST['layout_ids'] ?? []), 0, MAX_EXTRA_LAYOUTS);
        $selectedLayoutIds = array_unique(array_merge([(int) $defaultLayout['id']], $extraLayoutIds));

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
        $pricing = calc_booking_pricing(
            $layoutIds,
            $extraSelections,
            $couponCode !== '' ? $couponCode : null,
            (string) $booking['customer_email'],
            (int) $booking['id']
        );

        if ($couponCode !== '' && !$pricing['coupon']) {
            $errors[] = 'Dieser Gutscheincode ist ungültig oder abgelaufen.';
        } else {
            db()->prepare('UPDATE bookings SET coupon_code = ?, discount_cents = ?, returning_discount_cents = ?, total_price_cents = ? WHERE id = ?')
                ->execute([$couponCode !== '' ? $couponCode : null, $pricing['discount_cents'], $pricing['returning_discount_cents'], $pricing['total'], $booking['id']]);
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
        $agbAccepted = isset($_POST['agb_accepted']);

        if ($street === '' || $zip === '' || $city === '') {
            $errors[] = 'Bitte Straße, PLZ und Ort angeben.';
        }
        if ($invoiceToCompany && $company === '') {
            $errors[] = 'Bitte einen Firmennamen für die Rechnung angeben.';
        }
        if (!$agbAccepted) {
            $errors[] = 'Bitte bestätige, dass du die AGB und die Datenschutzerklärung gelesen hast.';
        }

        if (!$errors) {
            $pricing = calc_booking_pricing(
                $layoutIds,
                $extraSelections,
                (string) ($booking['coupon_code'] ?? '') ?: null,
                (string) $booking['customer_email'],
                (int) $booking['id']
            );

            $stmt = db()->prepare(
                'UPDATE bookings SET customer_phone = ?, customer_street = ?, customer_zip = ?, customer_city = ?,
                 customer_company = ?, invoice_to_company = ?, message = ?, discount_cents = ?,
                 returning_discount_cents = ?, total_price_cents = ?, wants_quote = ?, agb_accepted_at = ? WHERE id = ?'
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
                $pricing['returning_discount_cents'],
                $pricing['total'],
                $wantsQuote ? 1 : 0,
                // Zeitstempel als Nachweis der Zustimmung, nicht ueberschreiben,
                // falls das Formular (z.B. nach einem abgebrochenen
                // Stripe-Checkout) erneut abgeschickt wird.
                $booking['agb_accepted_at'] ?? date('Y-m-d H:i:s'),
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
$activeNav = 'buchen';
require __DIR__ . '/_site_header.php';
?>

<section class="section" style="padding-top:40px;">
    <?php if ($success): ?>
        <h2>Fotobox buchen</h2>
        <div class="panel-box success-box">
            <?php if (isset($_GET['paid'])): ?>
                <h3>🎉 Danke für deine Zahlung!</h3>
                <p>Deine Fotobox ist fest gebucht. Die Buchungsbestätigung mit Rechnung schicken wir
                    dir gerade per E-Mail zu (bitte auch im Spam-Ordner nachsehen). Alle weiteren
                    Details zu Versand, Aufstellung und Rücksendung bekommst du rechtzeitig vor
                    deinem Event von uns.</p>
            <?php else: ?>
                <h3>🎉 Danke für deine Anfrage!</h3>
                <p>Wir prüfen die Verfügbarkeit und melden uns zeitnah per E-Mail bei dir – meist
                    innerhalb eines Werktags. Du musst bis dahin nichts weiter tun.</p>
            <?php endif; ?>
            <p class="muted" style="margin-top:16px;">
                Fragen in der Zwischenzeit? Schreib uns einfach an
                <a href="mailto:<?= htmlspecialchars($contactEmail, ENT_QUOTES) ?>"><?= htmlspecialchars($contactEmail, ENT_QUOTES) ?></a>.
            </p>
        </div>
    <?php elseif ($bookingLocked): ?>
        <h2>Fotobox buchen</h2>
        <div class="panel-box" style="max-width:520px;margin:0 auto;text-align:center;">
            <h3>Diese Buchung ist bereits abgeschlossen</h3>
            <p class="muted">
                Sie kann nicht mehr geändert werden. In deinem
                <a href="konto.php">Konto</a> kannst du sie dir jederzeit ansehen. Falls doch noch
                etwas geändert werden muss, melde dich einfach bei uns:
                <a href="mailto:<?= htmlspecialchars($contactEmail, ENT_QUOTES) ?>"><?= htmlspecialchars($contactEmail, ENT_QUOTES) ?></a>.
            </p>
        </div>
    <?php else: ?>
        <?= stepper_html($step) ?>

        <?php foreach ($errors as $err): ?>
            <p class="error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
        <?php endforeach; ?>

        <?php if ($step === 1): ?>
            <div class="panel-box" style="max-width:520px;margin:0 auto;">
                <h3>Wähle deinen Wunschtermin</h3>
                <p class="muted">Bereits vergebene Tage sind ausgegraut - such dir einfach einen freien Tag aus.</p>
                <div id="calendar"></div>
                <p id="selected-date-label" class="muted">Tippe auf einen freien Tag, um fortzufahren.</p>
                <p class="muted">Dein Wunschtermin ist schon vergeben? Tippe ihn trotzdem an oder
                    <a href="warteliste.php">trag dich auf die Warteliste ein</a> - wir melden uns automatisch,
                    sobald der Tag wieder frei wird.</p>
            </div>

        <?php elseif ($step === 2): ?>
            <?php $eventDate = (string) ($_GET['date'] ?? $_POST['event_date'] ?? ''); ?>
            <div class="date-banner">
                📅 <?= htmlspecialchars((new DateTimeImmutable($eventDate))->format('d.m.Y'), ENT_QUOTES) ?>
                <a href="buchen.php?step=1">Ändern</a>
            </div>
            <div class="panel-box" style="max-width:480px;margin:0 auto;">
                <h3>Deinen Termin anfragen</h3>
                <p class="muted">Gib deine Kontaktdaten ein, um deinen Wunschtermin anzufragen - im nächsten Schritt wählst du Design und Extras.</p>
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
                    <button type="submit">Jetzt anfragen</button>
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
                            <p class="muted" style="margin-top:0;">Bis zu <?= MAX_EXTRA_LAYOUTS ?> Zusatzformate wählbar - alle kostenlos, außer den 1- und 2-Bilder-Formaten.</p>
                            <div class="design-gallery">
                                <?php foreach ($extraLayouts as $layout): ?>
                                    <label class="design-card" data-cat="<?= htmlspecialchars((string) ($layout['category'] ?? ''), ENT_QUOTES) ?>">
                                        <span class="design-card-badge">✓ Ausgewählt</span>
                                        <input type="checkbox" name="layout_ids[]" value="<?= (int) $layout['id'] ?>"
                                            <?= in_array((int) $layout['id'], $chosenLayoutIds, true) ? 'checked' : '' ?>>
                                        <img src="layout_preview.php?id=<?= (int) $layout['id'] ?>" alt="<?= htmlspecialchars($layout['name'], ENT_QUOTES) ?>" loading="lazy">
                                        <span class="design-card-name"><?= htmlspecialchars($layout['name'], ENT_QUOTES) ?></span>
                                        <span class="design-card-price"><?= $layout['surcharge_cents'] > 0 ? '+' . money_from_cents((int) $layout['surcharge_cents']) : 'kostenlos' ?></span>
                                        <button type="button" class="design-card-customize" data-customize="<?= (int) $layout['id'] ?>">Anpassen</button>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="muted">Aktuell nur das Standarddesign verfügbar.</p>
                        <?php endif; ?>

                        <button type="submit">Weiter zu den Extras</button>
                    </form>
                </div>

                <!-- Panel 2: Online-Designer -->
                <div class="design-panel" id="panel-designer" hidden>
                    <p class="muted">Wähle Farben und ein Muster, füge beliebig viele Texte und Sticker/Emojis hinzu und ziehe sie direkt im Bild an die gewünschte Stelle. Die Fotoflächen (gestrichelt) lassen sich ebenso per Maus verschieben und in der Größe anpassen.</p>
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
                            <div class="designer-elements">
                                <div class="designer-elements-head">
                                    <span>Text &amp; Sticker</span>
                                    <div class="designer-elements-actions">
                                        <button type="button" id="designer-add-text" class="button-secondary">+ Text</button>
                                        <button type="button" id="designer-add-sticker" class="button-secondary">+ Sticker</button>
                                    </div>
                                </div>
                                <div id="designer-sticker-picker" class="designer-sticker-picker" hidden></div>
                                <div id="designer-elements-list" class="designer-elements-list"></div>
                            </div>
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

                var maxExtraLayouts = <?= MAX_EXTRA_LAYOUTS ?>;
                var layoutCheckboxes = document.querySelectorAll('.design-card input[type=checkbox]');
                function updateLayoutCheckboxLimit() {
                    var checkedCount = 0;
                    layoutCheckboxes.forEach(function (cb) { if (cb.checked) checkedCount++; });
                    layoutCheckboxes.forEach(function (cb) {
                        cb.disabled = !cb.checked && checkedCount >= maxExtraLayouts;
                        cb.closest('.design-card').classList.toggle('design-card-disabled', cb.disabled);
                    });
                }
                layoutCheckboxes.forEach(function (cb) { cb.addEventListener('change', updateLayoutCheckboxLimit); });
                updateLayoutCheckboxLimit();

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
                var elementsList = document.getElementById('designer-elements-list');
                var stickerPicker = document.getElementById('designer-sticker-picker');
                var STICKERS = ['🎉', '🎊', '🎈', '🥳', '🍾', '🥂', '❤️', '💍', '👰', '🤵', '🎂', '🌟', '✨', '🎶', '🕺', '💃', '📸', '😄', '👍', '🌸', '☀️', '❄️', '🎄'];

                function layoutById(id) {
                    for (var i = 0; i < layouts.length; i++) {
                        if (layouts[i].id === id) { return layouts[i]; }
                    }
                    return layouts[0];
                }

                function currentLayout() { return layoutById(parseInt(baseSelect.value, 10)); }

                var customSlots = null;
                // Text-/Sticker-Elemente bleiben beim Wechsel des Basis-Layouts oder
                // beim Zuruecksetzen der Fotoflaechen bewusst erhalten (alle Layouts
                // teilen sich dieselbe Leinwandgroesse, siehe schema.sql) - nur ein
                // explizites Entfernen in der Liste loescht ein Element.
                var elements = [];
                var HANDLE_RADIUS = 8;
                var MIN_SLOT_SIZE = 60;
                var ELEMENT_HANDLE_RADIUS = 9;
                function resetSlots() {
                    var layout = currentLayout();
                    customSlots = layout.slots.map(function (s) {
                        return { x: s.x, y: s.y, width: s.width, height: s.height };
                    });
                }

                function elementFont(el, scale) {
                    var size = Math.max(1, Math.round(el.size * scale));
                    return el.type === 'sticker'
                        ? size + 'px "Segoe UI Emoji", "Noto Color Emoji", sans-serif'
                        : 'bold ' + size + 'px "Segoe UI", Arial, sans-serif';
                }

                function elementBounds(el, scale) {
                    ctx.font = elementFont(el, scale);
                    var w = ctx.measureText(el.content || '').width;
                    var h = el.size * scale * 1.2;
                    var cx = el.x * scale, cy = el.y * scale;
                    return { cx: cx, cy: cy, left: cx - w / 2, right: cx + w / 2, top: cy - h / 2, bottom: cy + h / 2 };
                }

                function elementAtPoint(p, scale) {
                    for (var i = elements.length - 1; i >= 0; i--) {
                        var b = elementBounds(elements[i], scale);
                        if (p.x >= b.left && p.x <= b.right && p.y >= b.top && p.y <= b.bottom) {
                            return i;
                        }
                    }
                    return -1;
                }

                function renderElementsList() {
                    elementsList.innerHTML = '';
                    elements.forEach(function (el, idx) {
                        var row = document.createElement('div');
                        row.className = 'designer-element-row';

                        if (el.type === 'text') {
                            var textField = document.createElement('input');
                            textField.type = 'text';
                            textField.maxLength = 40;
                            textField.value = el.content;
                            textField.addEventListener('input', function () {
                                el.content = textField.value;
                                refreshPreview();
                            });
                            row.appendChild(textField);

                            var colorField = document.createElement('input');
                            colorField.type = 'color';
                            colorField.value = el.color;
                            colorField.addEventListener('input', function () {
                                el.color = colorField.value;
                                refreshPreview();
                            });
                            row.appendChild(colorField);
                        } else {
                            var emojiSpan = document.createElement('span');
                            emojiSpan.className = 'designer-element-emoji';
                            emojiSpan.textContent = el.content;
                            row.appendChild(emojiSpan);
                        }

                        var sizeField = document.createElement('input');
                        sizeField.type = 'range';
                        sizeField.min = el.type === 'sticker' ? 40 : 16;
                        sizeField.max = el.type === 'sticker' ? 300 : 160;
                        sizeField.value = el.size;
                        sizeField.title = 'Größe';
                        sizeField.addEventListener('input', function () {
                            el.size = parseInt(sizeField.value, 10);
                            refreshPreview();
                        });
                        row.appendChild(sizeField);

                        var removeBtn = document.createElement('button');
                        removeBtn.type = 'button';
                        removeBtn.className = 'designer-element-remove';
                        removeBtn.textContent = '×';
                        removeBtn.title = 'Entfernen';
                        removeBtn.addEventListener('click', function () {
                            elements.splice(idx, 1);
                            renderElementsList();
                            refreshPreview();
                        });
                        row.appendChild(removeBtn);

                        elementsList.appendChild(row);
                    });
                }

                document.getElementById('designer-add-text').addEventListener('click', function () {
                    var layout = currentLayout();
                    elements.push({
                        type: 'text',
                        content: 'Dein Text',
                        color: accentInput.value,
                        size: 60,
                        x: layout.canvas_width / 2,
                        y: layout.canvas_height - 20,
                    });
                    renderElementsList();
                    refreshPreview();
                });

                document.getElementById('designer-add-sticker').addEventListener('click', function () {
                    stickerPicker.hidden = !stickerPicker.hidden;
                });

                STICKERS.forEach(function (emoji) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.textContent = emoji;
                    btn.addEventListener('click', function () {
                        var layout = currentLayout();
                        elements.push({
                            type: 'sticker',
                            content: emoji,
                            size: 120,
                            x: layout.canvas_width / 2,
                            y: layout.canvas_height / 2,
                        });
                        stickerPicker.hidden = true;
                        renderElementsList();
                        refreshPreview();
                    });
                    stickerPicker.appendChild(btn);
                });

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

                    targetCtx.textAlign = 'center';
                    targetCtx.textBaseline = 'middle';
                    elements.forEach(function (el) {
                        if (!el.content) { return; }
                        targetCtx.fillStyle = el.type === 'sticker' ? '#000000' : el.color;
                        targetCtx.font = elementFont(el, scale);
                        targetCtx.fillText(el.content, el.x * scale, el.y * scale);
                    });

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

                        // Ziehpunkt an jedem Text-/Sticker-Element - frei im Bild
                        // verschiebbar statt fest positioniert.
                        targetCtx.save();
                        targetCtx.fillStyle = '#17c3b2';
                        targetCtx.strokeStyle = '#fff';
                        targetCtx.lineWidth = 2;
                        elements.forEach(function (el) {
                            var b = elementBounds(el, scale);
                            targetCtx.beginPath();
                            targetCtx.arc(b.cx, b.cy, ELEMENT_HANDLE_RADIUS, 0, Math.PI * 2);
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

                [bgInput, accentInput, patternSelect].forEach(function (el) {
                    el.addEventListener('input', refreshPreview);
                    el.addEventListener('change', refreshPreview);
                });
                baseSelect.addEventListener('change', function () {
                    resetSlots();
                    refreshPreview();
                });
                resetSlots();
                renderElementsList();
                refreshPreview();

                document.getElementById('designer-reset').addEventListener('click', function () {
                    resetSlots();
                    refreshPreview();
                });

                // Fotoflaechen im Vorschau-Canvas per Maus verschieben und am
                // Ziehpunkt unten rechts in der Groesse anpassen, Text-/Sticker-
                // Elemente ebenso frei verschieben (siehe elements).
                var drag = null;
                var resize = null;
                var elementDrag = null;
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

                    var elIdx = elementAtPoint(p, scale);
                    if (elIdx !== -1) {
                        var el = elements[elIdx];
                        elementDrag = { index: elIdx, startX: p.x, startY: p.y, origX: el.x, origY: el.y };
                        return;
                    }

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

                    if (elementDrag) {
                        var el = elements[elementDrag.index];
                        var dex = (p.x - elementDrag.startX) / scale;
                        var dey = (p.y - elementDrag.startY) / scale;
                        el.x = Math.max(0, Math.min(layout.canvas_width, elementDrag.origX + dex));
                        el.y = Math.max(0, Math.min(layout.canvas_height, elementDrag.origY + dey));
                        refreshPreview();
                        return;
                    }
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
                    } else if (elementAtPoint(p, scale) !== -1 || slotAtPoint(p, scale) !== -1) {
                        canvas.style.cursor = 'grab';
                    } else {
                        canvas.style.cursor = 'default';
                    }
                });
                document.addEventListener('mouseup', function () { drag = null; resize = null; elementDrag = null; });

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
                <h3>Möchtest du dein Erlebnis aufwerten?</h3>
                <p class="muted">Ganz nach Wunsch dazubuchen oder einfach weiter - der Preis unten aktualisiert sich direkt.</p>
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
                    <button type="submit">Weiter zur Zusammenfassung</button>
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
            // Ueber fetch_layout_with_slots() statt $layouts holen, weil
            // $layouts (fetch_all_layouts()) eigene Designs (is_custom=1)
            // nicht enthaelt - die sollen in der Uebersicht aber sichtbar
            // sein, falls per Online-Designer/Upload eines gewaehlt wurde.
            $chosenLayoutRows = array_values(array_filter(array_map(
                static fn (int $lid) => fetch_layout_with_slots($lid),
                $chosenLayoutIds
            )));
            $layoutSurchargeSum = array_sum(array_map(static fn (array $l) => (int) $l['surcharge_cents'], $chosenLayoutRows));

            $chosenExtraRows = [];
            if ($chosenExtras) {
                $placeholders = implode(',', array_fill(0, count($chosenExtras), '?'));
                $stmt = db()->prepare("SELECT * FROM extras WHERE id IN ($placeholders)");
                $stmt->execute(array_keys($chosenExtras));
                $chosenExtraRows = $stmt->fetchAll();
            }
            $pricing = calc_booking_pricing(
                $chosenLayoutIds,
                $chosenExtras,
                (string) ($booking['coupon_code'] ?? '') ?: null,
                (string) $booking['customer_email'],
                (int) $booking['id']
            );
            $discount = $pricing['discount_cents'];
            $returningDiscount = $pricing['returning_discount_cents'];
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
                            <div class="address-autocomplete">
                                <input type="text" name="customer_street" id="customer_street" autocomplete="off" required value="<?= htmlspecialchars((string) $booking['customer_street'], ENT_QUOTES) ?>">
                                <div id="address-suggestions" class="address-suggestions" hidden></div>
                            </div>
                        </label>
                        <p class="muted" style="margin-top:-10px;font-size:12px;">Adresse eintippen und aus den Vorschlägen wählen - PLZ und Ort werden automatisch ausgefüllt. Geht auch ohne, dann bitte von Hand ausfüllen.</p>
                        <div class="grid2">
                            <label>PLZ *<input type="text" name="customer_zip" id="customer_zip" required value="<?= htmlspecialchars((string) $booking['customer_zip'], ENT_QUOTES) ?>"></label>
                            <label>Ort *<input type="text" name="customer_city" id="customer_city" required value="<?= htmlspecialchars((string) $booking['customer_city'], ENT_QUOTES) ?>"></label>
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
                        <label class="checkbox">
                            <input type="checkbox" name="agb_accepted" id="agb-checkbox" required <?= $booking['agb_accepted_at'] ? 'checked' : '' ?>>
                            Ich habe die <a href="/agb.php" target="_blank" rel="noopener">AGB</a> und die
                            <a href="/datenschutz.php" target="_blank" rel="noopener">Datenschutzerklärung</a>
                            gelesen und akzeptiere sie. *
                        </label>

                        <button type="submit" id="submit-booking-btn" class="btn-gradient">
                            <?= $booking['wants_quote'] ? 'Angebot anfordern' : 'Weiter zur Zahlung' ?>
                        </button>
                        <p class="muted" style="text-align:center;font-size:12px;">Sichere Zahlung über Stripe · Kreditkarte, Klarna &amp; mehr</p>
                    </form>
                </div>

                <div class="summary-sidebar">
                    <div class="panel-box">
                        <h3>Fast geschafft! Hier deine Übersicht</h3>
                        <p class="muted">📅 <?= htmlspecialchars(german_weekday($event) . ', ' . $event->format('d.m.Y'), ENT_QUOTES) ?></p>

                        <div class="summary-card">
                            <div class="summary-card-head">
                                <span>🎨 Design</span>
                                <a href="buchen.php?step=3&token=<?= urlencode($token) ?>">Ändern</a>
                            </div>
                            <?php foreach ($chosenLayoutRows as $l): ?>
                                <div class="summary-card-thumb">
                                    <img src="layout_preview.php?id=<?= (int) $l['id'] ?>" alt="<?= htmlspecialchars($l['name'], ENT_QUOTES) ?>" loading="lazy">
                                    <div>
                                        <strong><?= htmlspecialchars($l['name'], ENT_QUOTES) ?></strong>
                                        <?php if (!$l['is_default']): ?>
                                            <div class="muted-text"><?= $l['surcharge_cents'] > 0 ? '+' . money_from_cents((int) $l['surcharge_cents']) : 'kostenlos' ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="summary-card">
                            <div class="summary-card-head">
                                <span>✨ Extras</span>
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
                            <div class="summary-card-head"><span>🚚 Versand-Zeitplan (voraussichtlich)</span></div>
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
                            <?php if ($returningDiscount > 0): ?>
                                <div class="price-row" style="color:#1f9d55;"><span>Stammkundenrabatt</span><span>&minus;<?= money_from_cents($returningDiscount) ?></span></div>
                                <p class="muted" style="font-size:12px;margin:-4px 0 8px;">Automatisch, weil du schon einmal bei uns gebucht hast - danke, dass du wiederkommst!</p>
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

                // Adress-Autocomplete ueber Photon (komoot, oeffentliche
                // OpenStreetMap-Suche, siehe datenschutz.php) - rein
                // optionale Hilfe, bei Fehler/Timeout bleiben die drei
                // Felder ganz normal von Hand ausfuellbar.
                var streetInput = document.getElementById('customer_street');
                var zipInput = document.getElementById('customer_zip');
                var cityInput = document.getElementById('customer_city');
                var suggestionsBox = document.getElementById('address-suggestions');
                var debounceTimer = null;
                var activeController = null;

                function hideSuggestions() {
                    suggestionsBox.hidden = true;
                    suggestionsBox.innerHTML = '';
                }

                function showSuggestions(features) {
                    suggestionsBox.innerHTML = '';
                    var shown = 0;
                    features.forEach(function (feature) {
                        var p = feature.properties || {};
                        if (p.countrycode !== 'DE' || !p.postcode || !p.city || !p.street) {
                            return;
                        }
                        var streetLine = p.street + (p.housenumber ? ' ' + p.housenumber : '');
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'address-suggestion';
                        btn.textContent = streetLine + ', ' + p.postcode + ' ' + p.city;
                        btn.addEventListener('click', function () {
                            streetInput.value = streetLine;
                            zipInput.value = p.postcode;
                            cityInput.value = p.city;
                            hideSuggestions();
                        });
                        suggestionsBox.appendChild(btn);
                        shown++;
                    });
                    suggestionsBox.hidden = shown === 0;
                }

                streetInput.addEventListener('input', function () {
                    var query = streetInput.value.trim();
                    clearTimeout(debounceTimer);
                    if (query.length < 4) {
                        hideSuggestions();
                        return;
                    }
                    debounceTimer = setTimeout(function () {
                        if (activeController) {
                            activeController.abort();
                        }
                        activeController = ('AbortController' in window) ? new AbortController() : null;
                        fetch('https://photon.komoot.io/api/?q=' + encodeURIComponent(query) + '&lang=de&limit=8', {
                            signal: activeController ? activeController.signal : undefined,
                        })
                            .then(function (r) { return r.json(); })
                            .then(function (data) { showSuggestions(data.features || []); })
                            .catch(function () { /* Dienst nicht erreichbar - Felder bleiben manuell ausfuellbar */ });
                    }, 350);
                });

                document.addEventListener('click', function (ev) {
                    if (ev.target !== streetInput && !suggestionsBox.contains(ev.target)) {
                        hideSuggestions();
                    }
                });
            })();
            </script>
        <?php endif; ?>
    <?php endif; ?>
</section>

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
            if (isBlocked && !isPast) classes.push('cal-blocked');
            html += '<div class="' + classes.join(' ') + '" data-date="' + iso + '" title="'
                + (isBlocked && !isPast ? 'Bereits vergeben - auf Warteliste eintragen' : '') + '">' + day + '</div>';
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
        calendarEl.querySelectorAll('.cal-day.cal-blocked').forEach(function (el) {
            el.addEventListener('click', function () {
                window.location.href = 'warteliste.php?date=' + el.getAttribute('data-date');
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
<?php require __DIR__ . '/_site_footer.php'; ?>

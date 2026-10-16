<?php
declare(strict_types=1);

$pageTitle = 'Neue Buchung';
require __DIR__ . '/_header.php';
require_once __DIR__ . '/../../includes/stripe.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/customer_auth.php';

// Legt eine Buchung direkt im Panel an (z.B. telefonische/persoenliche
// Anfrage) - unabhaengig vom oeffentlichen Buchungsassistenten
// (buchen.php) und ohne dessen Mindestvorlauf (booking_min_lead_days()):
// der Admin kennt den Kontext und soll hier nicht ausgebremst werden.
// Zweistufig wie die Aenderung einer bestehenden Buchung in
// booking_detail.php: erst Zusammenfassung mit berechnetem Preis
// anzeigen ("review"), dann erst auf einen der beiden Knoepfe hin
// tatsaechlich anlegen ("create") - je nachdem entweder sofort kostenfrei
// bestaetigt (assign_box_and_confirm(), wie bei einer bezahlten Buchung,
// nur ohne Zahlung) oder ein Stripe-Zahlungslink per Mail verschickt
// (dieselbe Checkout-Session-Mechanik wie buchen.php Schritt 5 -
// stripe_webhook.php bestaetigt sie anhand der booking_id-Metadata ganz
// normal, kein Sonderfall noetig).

$allLayouts = fetch_all_layouts();
$defaultLayout = null;
foreach ($allLayouts as $l) {
    if ($l['is_default']) {
        $defaultLayout = $l;
        break;
    }
}
$activeExtras = fetch_active_extras();

$errors = [];
$review = null;

$firstName = trim((string) ($_POST['first_name'] ?? ''));
$lastName = trim((string) ($_POST['last_name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$eventDate = trim((string) ($_POST['event_date'] ?? ''));
$couponCode = strtoupper(trim((string) ($_POST['coupon_code'] ?? '')));
$layoutIds = array_map('intval', $_POST['layout_ids'] ?? ($defaultLayout ? [(int) $defaultLayout['id']] : []));
$extraSelections = [];
foreach ($activeExtras as $extra) {
    $qty = max(0, (int) ($_POST['extra_qty'][$extra['id']] ?? 0));
    if ($extra['type'] === 'toggle') {
        $qty = $qty > 0 ? 1 : 0;
    } elseif ($extra['max_quantity']) {
        $qty = min($qty, (int) $extra['max_quantity']);
    }
    if ($qty > 0) {
        $extraSelections[(int) $extra['id']] = $qty;
    }
}

$formAction = (string) ($_POST['form_action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($formAction === 'review' || $formAction === 'create')) {
    check_csrf();

    if ($firstName === '' || $lastName === '') {
        $errors[] = 'Bitte Vor- und Nachnamen angeben.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
    }
    $eventDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $eventDate) ?: null;
    if (!$eventDateObj) {
        $errors[] = 'Bitte ein gültiges Eventdatum angeben.';
    }
    if (!$layoutIds || !$defaultLayout) {
        $errors[] = 'Bitte mindestens ein Layout auswählen.';
    }

    if (!$errors) {
        $pricing = calc_booking_pricing($layoutIds, $extraSelections, $couponCode ?: null, $email);

        if ($formAction === 'review') {
            $review = [
                'pricing' => $pricing,
                'date_blocked' => is_date_blocked($eventDate),
            ];
        } else {
            // form_action === 'create': Werte nochmal serverseitig aus dem
            // POST berechnen statt einem eingeschleusten Hidden-Field zu
            // vertrauen - $pricing oben ist bereits die vertrauenswuerdige
            // Quelle.
            $mode = (string) ($_POST['mode'] ?? '');
            if (!in_array($mode, ['free', 'payment_link'], true)) {
                $errors[] = 'Bitte eine der beiden Optionen unten wählen.';
            } else {
                $accountId = find_or_create_customer_account($email);
                $editToken = random_key(24);
                $customerPin = generate_customer_pin();
                $customerName = trim($firstName . ' ' . $lastName);

                db()->beginTransaction();
                $stmt = db()->prepare(
                    'INSERT INTO bookings
                     (edit_token, customer_pin, customer_name, customer_email, customer_account_id, customer_phone,
                      event_date, status, coupon_code, discount_cents, returning_discount_cents,
                      promotion_discount_cents, applied_promotions_json, total_price_cents, admin_note)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $editToken,
                    $customerPin,
                    $customerName,
                    $email,
                    $accountId,
                    $phone !== '' ? $phone : null,
                    $eventDate,
                    'angefragt',
                    $pricing['coupon'] ? $couponCode : null,
                    $mode === 'free' ? 0 : $pricing['discount_cents'],
                    $mode === 'free' ? 0 : $pricing['returning_discount_cents'],
                    $mode === 'free' ? 0 : $pricing['promotion_discount_cents'],
                    $mode === 'free' ? null : applied_promotions_json($pricing),
                    $mode === 'free' ? 0 : $pricing['total'],
                    'Von einem Admin direkt im Panel angelegt.',
                ]);
                $newBookingId = (int) db()->lastInsertId();

                $ins = db()->prepare('INSERT INTO booking_layouts (booking_id, layout_id) VALUES (?, ?)');
                foreach ($layoutIds as $layoutId) {
                    $ins->execute([$newBookingId, $layoutId]);
                }
                $ins = db()->prepare('INSERT INTO booking_extras (booking_id, extra_id, quantity) VALUES (?, ?, ?)');
                foreach ($extraSelections as $extraId => $qty) {
                    $ins->execute([$newBookingId, $extraId, $qty]);
                }
                db()->commit();

                $stmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
                $stmt->execute([$newBookingId]);
                $newBooking = $stmt->fetch();

                if ($mode === 'free') {
                    assign_box_and_confirm($newBookingId);
                    try {
                        send_admin_confirmed_booking_email($newBooking);
                    } catch (Throwable $e) {
                        error_log('Bestaetigungsmail (kostenlos) fehlgeschlagen fuer Buchung #' . $newBookingId . ': ' . $e->getMessage());
                    }
                    header('Location: booking_detail.php?id=' . $newBookingId . '&neu_kostenlos=1');
                    exit;
                }

                // mode === 'payment_link': Buchung bleibt "angefragt", bis
                // Stripe die Zahlung per Webhook bestaetigt - dieselbe
                // Mechanik wie beim oeffentlichen Buchungsassistenten.
                $baseUrl = rtrim((string) backend_config()['base_url'], '/');
                $session = create_stripe_checkout_session(
                    $newBooking,
                    booking_stripe_line_items($newBookingId),
                    $baseUrl . '/buchen?danke=1&paid=1',
                    $baseUrl . '/'
                );

                if ($session && !empty($session['url']) && !empty($session['id'])) {
                    db()->prepare('UPDATE bookings SET stripe_session_id = ? WHERE id = ?')
                        ->execute([$session['id'], $newBookingId]);
                    try {
                        send_booking_payment_link_email($newBooking, $session['url']);
                    } catch (Throwable $e) {
                        error_log('Zahlungslink-Mail fehlgeschlagen fuer Buchung #' . $newBookingId . ': ' . $e->getMessage());
                    }
                    header('Location: booking_detail.php?id=' . $newBookingId . '&neu_zahlungslink=1');
                    exit;
                }

                // Stripe-Fehler (z.B. stripe_secret_key fehlt) - die Buchung
                // existiert bereits, der Admin muss den Link nur von Hand
                // nachholen (z.B. nach Beheben der Konfiguration erneut
                // versuchen) statt alles zu verlieren.
                header('Location: booking_detail.php?id=' . $newBookingId . '&zahlungslink_fehler=1');
                exit;
            }
        }
    }
}
?>

<section class="panel">
    <?php foreach ($errors as $err): ?>
        <p class="error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
    <?php endforeach; ?>

    <?php if ($review): ?>
        <h2>Zusammenfassung</h2>
        <?php if ($review['date_blocked']): ?>
            <p class="error">Achtung: Für dieses Datum existiert bereits eine andere aktive Buchung (angefragt
                oder bestätigt) - bitte prüfen, bevor du fortfährst.</p>
        <?php endif; ?>
        <p><strong><?= htmlspecialchars(trim($firstName . ' ' . $lastName), ENT_QUOTES) ?></strong>
            (<?= htmlspecialchars($email, ENT_QUOTES) ?><?= $phone !== '' ? ', ' . htmlspecialchars($phone, ENT_QUOTES) : '' ?>)
            &middot; Event am <?= htmlspecialchars((new DateTimeImmutable($eventDate))->format('d.m.Y'), ENT_QUOTES) ?></p>

        <table style="max-width:420px;">
            <tr><td>Zwischensumme</td><td class="nowrap"><?= money_from_cents($review['pricing']['subtotal']) ?></td></tr>
            <?php if ($review['pricing']['discount_cents'] > 0): ?>
                <tr><td>Gutschein<?= $couponCode !== '' ? ' (' . htmlspecialchars($couponCode, ENT_QUOTES) . ')' : '' ?></td>
                    <td class="nowrap">&minus;<?= money_from_cents($review['pricing']['discount_cents']) ?></td></tr>
            <?php endif; ?>
            <?php if ($review['pricing']['returning_discount_cents'] > 0): ?>
                <tr><td>Stammkundenrabatt</td><td class="nowrap">&minus;<?= money_from_cents($review['pricing']['returning_discount_cents']) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($review['pricing']['promotions'] as $promotion): ?>
                <tr><td>Rabattaktion: <?= htmlspecialchars($promotion['name'], ENT_QUOTES) ?></td>
                    <td class="nowrap">&minus;<?= money_from_cents($promotion['discount_cents']) ?></td></tr>
            <?php endforeach; ?>
            <tr><td><strong>Gesamtpreis</strong></td><td class="nowrap"><strong><?= money_from_cents($review['pricing']['total']) ?></strong></td></tr>
        </table>

        <form method="post" action="booking_new.php" style="margin-top:20px;">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="create">
            <input type="hidden" name="first_name" value="<?= htmlspecialchars($firstName, ENT_QUOTES) ?>">
            <input type="hidden" name="last_name" value="<?= htmlspecialchars($lastName, ENT_QUOTES) ?>">
            <input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES) ?>">
            <input type="hidden" name="phone" value="<?= htmlspecialchars($phone, ENT_QUOTES) ?>">
            <input type="hidden" name="event_date" value="<?= htmlspecialchars($eventDate, ENT_QUOTES) ?>">
            <input type="hidden" name="coupon_code" value="<?= htmlspecialchars($couponCode, ENT_QUOTES) ?>">
            <?php foreach ($layoutIds as $lid): ?>
                <input type="hidden" name="layout_ids[]" value="<?= (int) $lid ?>">
            <?php endforeach; ?>
            <?php foreach ($extraSelections as $extraId => $qty): ?>
                <input type="hidden" name="extra_qty[<?= (int) $extraId ?>]" value="<?= (int) $qty ?>">
            <?php endforeach; ?>

            <p class="muted-text">Wie soll die Buchung angelegt werden?</p>
            <div class="inline-form">
                <button type="submit" name="mode" value="payment_link">Zahlungslink per Mail senden</button>
                <button type="submit" name="mode" value="free" class="button-secondary">Kostenlos bestätigen (keine Zahlung nötig)</button>
                <a href="booking_new.php" class="button-secondary">Abbrechen</a>
            </div>
        </form>
    <?php else: ?>
        <h2>Neue Buchung anlegen</h2>
        <p class="muted-text">Fuer eine telefonische oder persoenliche Anfrage - unabhaengig vom oeffentlichen
            Buchungsassistenten, kein Mindestvorlauf.</p>

        <form method="post" action="booking_new.php">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="review">

            <div class="grid3">
                <label>Vorname *<input type="text" name="first_name" value="<?= htmlspecialchars($firstName, ENT_QUOTES) ?>" required></label>
                <label>Nachname *<input type="text" name="last_name" value="<?= htmlspecialchars($lastName, ENT_QUOTES) ?>" required></label>
            </div>
            <div class="grid3">
                <label>E-Mail *<input type="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES) ?>" required></label>
                <label>Telefon (optional)<input type="text" name="phone" value="<?= htmlspecialchars($phone, ENT_QUOTES) ?>"></label>
            </div>
            <label>Eventdatum *<input type="date" name="event_date" value="<?= htmlspecialchars($eventDate, ENT_QUOTES) ?>" required></label>
            <label>Gutscheincode (optional)<input type="text" name="coupon_code" value="<?= htmlspecialchars($couponCode, ENT_QUOTES) ?>" style="text-transform:uppercase;"></label>

            <h3 style="margin-top:20px;">Layouts</h3>
            <div class="grid3">
                <?php foreach ($allLayouts as $layout): ?>
                    <label class="checkbox">
                        <input type="checkbox" name="layout_ids[]" value="<?= (int) $layout['id'] ?>"
                            <?= in_array((int) $layout['id'], $layoutIds, true) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($layout['name'], ENT_QUOTES) ?>
                        <?= $layout['surcharge_cents'] > 0 ? ' (+' . money_from_cents((int) $layout['surcharge_cents']) . ')' : '' ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <h3 style="margin-top:20px;">Extras</h3>
            <?php foreach ($activeExtras as $extra): ?>
                <?php $qty = $extraSelections[$extra['id']] ?? 0; ?>
                <label class="checkbox">
                    <?php if ($extra['type'] === 'toggle'): ?>
                        <input type="checkbox" name="extra_qty[<?= (int) $extra['id'] ?>]" value="1" <?= $qty > 0 ? 'checked' : '' ?>>
                    <?php else: ?>
                        <input type="number" name="extra_qty[<?= (int) $extra['id'] ?>]" value="<?= (int) $qty ?>" min="0"
                            <?= $extra['max_quantity'] ? 'max="' . (int) $extra['max_quantity'] . '"' : '' ?>
                            style="width:70px;display:inline-block;">
                    <?php endif; ?>
                    <?= htmlspecialchars((string) $extra['icon'], ENT_QUOTES) ?>
                    <?= htmlspecialchars($extra['name'], ENT_QUOTES) ?>
                    (<?= ($extra['price_cents'] >= 0 ? '+' : '') . money_from_cents((int) $extra['price_cents']) ?><?= $extra['unit_label'] ? ' / ' . htmlspecialchars($extra['unit_label'], ENT_QUOTES) : '' ?>)
                </label>
            <?php endforeach; ?>

            <button type="submit" style="margin-top:20px;">Weiter</button>
        </form>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

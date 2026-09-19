<?php
declare(strict_types=1);

$pageTitle = 'Buchungsdetails';
require __DIR__ . '/_header.php';
require_once __DIR__ . '/../../includes/stripe.php';
require_once __DIR__ . '/../../includes/mailer.php';

$bookingId = (int) ($_GET['id'] ?? $_POST['booking_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    http_response_code(404);
    echo '<p>Buchung nicht gefunden.</p>';
    require __DIR__ . '/_footer.php';
    exit;
}

$editError = '';
// Ungespeicherter Bearbeitungsversuch, dessen Preis ueber dem bisherigen
// liegt und der deshalb erst bestaetigt werden muss (siehe unten) - haelt
// die vorgeschlagenen Werte fuers erneute Anzeigen/Absenden des Formulars.
$pendingEdit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string) ($_POST['form_action'] ?? 'save_note');

    if ($action === 'save_note') {
        $note = trim((string) ($_POST['admin_note'] ?? ''));
        db()->prepare('UPDATE bookings SET admin_note = ? WHERE id = ?')
            ->execute([$note !== '' ? $note : null, $bookingId]);
        header('Location: booking_detail.php?id=' . $bookingId);
        exit;
    }

    if ($action === 'send_gallery_email') {
        $galleryToken = ensure_gallery_token($bookingId);
        send_gallery_email($booking, gallery_url($galleryToken));
        header('Location: booking_detail.php?id=' . $bookingId . '&galerie_mail=1');
        exit;
    }

    if ($action === 'toggle_unlock') {
        $newValue = (int) $booking['edit_unlocked_by_admin'] === 1 ? 0 : 1;
        db()->prepare('UPDATE bookings SET edit_unlocked_by_admin = ? WHERE id = ?')
            ->execute([$newValue, $bookingId]);
        header('Location: booking_detail.php?id=' . $bookingId);
        exit;
    }

    if ($action === 'edit_booking' || $action === 'resolve_addon') {
        $newEventDate = trim((string) ($_POST['event_date'] ?? $booking['event_date']));
        $newLayoutIds = array_map('intval', $_POST['layout_ids'] ?? []);
        $activeExtras = fetch_active_extras();
        $newExtraSelections = [];
        foreach ($activeExtras as $extra) {
            $qty = max(0, (int) ($_POST['extra_qty'][$extra['id']] ?? 0));
            if ($extra['type'] === 'toggle') {
                $qty = $qty > 0 ? 1 : 0;
            } elseif ($extra['max_quantity']) {
                $qty = min($qty, (int) $extra['max_quantity']);
            }
            if ($qty > 0) {
                $newExtraSelections[(int) $extra['id']] = $qty;
            }
        }

        $eventDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $newEventDate) ?: null;
        if (!$eventDateObj) {
            $editError = 'Ungültiges Eventdatum.';
        } elseif (!$newLayoutIds) {
            $editError = 'Bitte mindestens ein Layout auswählen.';
        } else {
            $pricing = calc_booking_pricing($newLayoutIds, $newExtraSelections, (string) ($booking['coupon_code'] ?? '') ?: null);
            $currentTotal = (int) ($booking['total_price_cents'] ?? 0);
            $delta = $pricing['total'] - $currentTotal;
            // Nur bei einer bereits abschliessend bestaetigten Buchung
            // (bezahlt oder manuell bestaetigte Angebots-Buchung) ist "mehr
            // als urspruenglich gebucht/bezahlt" ueberhaupt ein sinnvoller
            // Begriff - waehrend die Buchung noch laeuft, ist ohnehin noch
            // nichts final.
            $needsConfirmation = $booking['status'] === 'bestaetigt' && $delta > 0;

            if ($needsConfirmation && $action === 'edit_booking') {
                $pendingEdit = [
                    'event_date' => $newEventDate,
                    'layout_ids' => $newLayoutIds,
                    'extra_selections' => $newExtraSelections,
                    'delta' => $delta,
                    'new_total' => $pricing['total'],
                ];
            } else {
                $resolution = (string) ($_POST['resolution'] ?? '');

                if ($action === 'resolve_addon' && $resolution === 'charge' && $delta > 0) {
                    // Wichtig: die Aenderung wird hier bewusst NOCH NICHT auf die
                    // Buchung angewendet - sonst waere sie schon aktiv (und auf der
                    // Box sichtbar), bevor der Kunde ueberhaupt bezahlt hat. Sie
                    // landet nur als "pending_changes_json" bei der Zusatzzahlung
                    // und wird erst von mark_addon_charge_paid() (Stripe-Webhook)
                    // uebernommen.
                    $description = 'Nachträgliche Änderung an deiner Buchung vom ' . $booking['event_date'];
                    $pendingChangesJson = json_encode([
                        'event_date' => $newEventDate,
                        'layout_ids' => $newLayoutIds,
                        'extra_selections' => $newExtraSelections,
                        'total_price_cents' => $pricing['total'],
                        'discount_cents' => $pricing['discount_cents'],
                    ]);
                    db()->prepare(
                        'INSERT INTO booking_addon_charges (booking_id, description, amount_cents, pending_changes_json) VALUES (?, ?, ?, ?)'
                    )->execute([$bookingId, $description, $delta, $pendingChangesJson]);
                    $addonChargeId = (int) db()->lastInsertId();

                    $baseUrl = rtrim((string) backend_config()['base_url'], '/');
                    $session = create_addon_charge_checkout_session(
                        $booking,
                        $addonChargeId,
                        $description,
                        $delta,
                        $baseUrl . '/zahlung_erfolgreich.php',
                        $baseUrl . '/admin/booking_detail.php?id=' . $bookingId
                    );

                    if ($session && !empty($session['url']) && !empty($session['id'])) {
                        db()->prepare('UPDATE booking_addon_charges SET stripe_session_id = ? WHERE id = ?')
                            ->execute([$session['id'], $addonChargeId]);
                        send_addon_charge_payment_link_email($booking, $description, $delta, $session['url']);
                    } else {
                        error_log('Zusatzzahlung: Stripe-Session konnte nicht erstellt werden (Buchung #' . $bookingId . ')');
                    }
                } else {
                    db()->beginTransaction();
                    db()->prepare('UPDATE bookings SET event_date = ?, total_price_cents = ?, discount_cents = ? WHERE id = ?')
                        ->execute([$newEventDate, $pricing['total'], $pricing['discount_cents'], $bookingId]);

                    db()->prepare('DELETE FROM booking_layouts WHERE booking_id = ?')->execute([$bookingId]);
                    $ins = db()->prepare('INSERT INTO booking_layouts (booking_id, layout_id) VALUES (?, ?)');
                    foreach ($newLayoutIds as $layoutId) {
                        $ins->execute([$bookingId, $layoutId]);
                    }

                    db()->prepare('DELETE FROM booking_extras WHERE booking_id = ?')->execute([$bookingId]);
                    $ins = db()->prepare('INSERT INTO booking_extras (booking_id, extra_id, quantity) VALUES (?, ?, ?)');
                    foreach ($newExtraSelections as $extraId => $qty) {
                        $ins->execute([$bookingId, $extraId, $qty]);
                    }
                    db()->commit();

                    sync_booking_to_box($bookingId);
                }

                header('Location: booking_detail.php?id=' . $bookingId . '&gespeichert=1');
                exit;
            }
        }
    }
}

$stmt = db()->prepare(
    'SELECT l.id, l.name, l.surcharge_cents, l.is_custom FROM booking_layouts bl
     INNER JOIN layouts l ON l.id = bl.layout_id WHERE bl.booking_id = ?'
);
$stmt->execute([$bookingId]);
$currentLayouts = $stmt->fetchAll();
$currentLayoutIds = array_map(static fn (array $l) => (int) $l['id'], $currentLayouts);
$customLayout = null;
foreach ($currentLayouts as $l) {
    if ($l['is_custom']) {
        $customLayout = $l;
        break;
    }
}

$currentExtras = booking_extra_selections($bookingId);
$stmt = db()->prepare(
    'SELECT e.name, e.icon, e.unit_label, e.price_cents, be.quantity FROM booking_extras be
     INNER JOIN extras e ON e.id = be.extra_id WHERE be.booking_id = ?'
);
$stmt->execute([$bookingId]);
$extras = $stmt->fetchAll();

$allLayouts = fetch_all_layouts();
$activeExtras = fetch_active_extras();

$boxName = null;
if ($booking['box_id']) {
    $stmt = db()->prepare('SELECT name FROM boxes WHERE id = ?');
    $stmt->execute([$booking['box_id']]);
    $boxName = $stmt->fetchColumn() ?: null;
}

$stmt = db()->prepare('SELECT * FROM booking_addon_charges WHERE booking_id = ? ORDER BY created_at DESC');
$stmt->execute([$bookingId]);
$addonCharges = $stmt->fetchAll();

$stmt = db()->prepare('SELECT COUNT(*) FROM gallery_photos WHERE booking_id = ?');
$stmt->execute([$bookingId]);
$galleryPhotoCount = (int) $stmt->fetchColumn();

// Fuer die erneute Anzeige des Formulars nach einer abgebrochenen/noch zu
// bestaetigenden Aenderung die vorgeschlagenen statt der gespeicherten
// Werte verwenden.
$formEventDate = $pendingEdit['event_date'] ?? $booking['event_date'];
$formLayoutIds = $pendingEdit['layout_ids'] ?? $currentLayoutIds;
$formExtraSelections = $pendingEdit['extra_selections'] ?? $currentExtras;
?>

<p><a href="bookings.php">&larr; zurück zu den Buchungen</a></p>

<?php if (isset($_GET['gespeichert'])): ?>
    <p class="badge">Gespeichert</p>
<?php endif; ?>
<?php if (isset($_GET['galerie_mail'])): ?>
    <p class="badge">Galerie-Link per Mail verschickt</p>
<?php endif; ?>

<section class="panel">
    <h2>
        <?= htmlspecialchars($booking['customer_name'], ENT_QUOTES) ?>
        <span class="status-pill status-<?= htmlspecialchars($booking['status'], ENT_QUOTES) ?>">
            <?= htmlspecialchars(booking_status_label($booking['status']), ENT_QUOTES) ?>
        </span>
    </h2>
    <table class="key-table">
        <tr><th>Eventdatum</th><td><?= htmlspecialchars($booking['event_date'], ENT_QUOTES) ?></td></tr>
        <tr><th>E-Mail</th><td><a href="mailto:<?= htmlspecialchars($booking['customer_email'], ENT_QUOTES) ?>"><?= htmlspecialchars($booking['customer_email'], ENT_QUOTES) ?></a></td></tr>
        <tr><th>Telefon</th><td><?= htmlspecialchars((string) $booking['customer_phone'], ENT_QUOTES) ?: '—' ?></td></tr>
        <tr><th>Versandadresse</th><td>
            <?php $addressLines = booking_address_lines($booking); ?>
            <?= $addressLines ? htmlspecialchars(implode("\n", $addressLines), ENT_QUOTES) : '—' ?>
            <?php if ($booking['invoice_to_company'] && $booking['customer_company']): ?>
                <br><span class="muted-text">Rechnung auf Firma: <?= htmlspecialchars((string) $booking['customer_company'], ENT_QUOTES) ?></span>
            <?php endif; ?>
        </td></tr>
        <tr><th>Gewünschte Layouts</th><td>
            <?php foreach ($currentLayouts as $layout): ?>
                <?= htmlspecialchars($layout['name'], ENT_QUOTES) ?>
                <?php if ($layout['surcharge_cents']): ?>(+<?= money_from_cents((int) $layout['surcharge_cents']) ?>)<?php endif; ?>
                <?php if ($layout['is_custom']): ?>
                    <a href="layout_form.php?id=<?= (int) $layout['id'] ?>" class="button-secondary" style="padding:2px 8px;font-size:11px;">Rahmen ansehen/anpassen</a>
                <?php endif; ?>
                <br>
            <?php endforeach; ?>
        </td></tr>
        <tr><th>Extras</th><td>
            <?php if (!$extras): ?>
                —
            <?php else: ?>
                <?php foreach ($extras as $extra): ?>
                    <?= htmlspecialchars((string) $extra['icon'], ENT_QUOTES) ?>
                    <?= htmlspecialchars($extra['name'], ENT_QUOTES) ?>
                    <?php if ((int) $extra['quantity'] > 1): ?>
                        (<?= (int) $extra['quantity'] ?><?= $extra['unit_label'] ? ' ' . htmlspecialchars($extra['unit_label'], ENT_QUOTES) : '' ?>)
                    <?php endif; ?>
                    – <?= money_from_cents((int) $extra['price_cents'] * (int) $extra['quantity']) ?><br>
                <?php endforeach; ?>
            <?php endif; ?>
        </td></tr>
        <?php if ($booking['coupon_code']): ?>
            <tr><th>Gutschein</th><td><?= htmlspecialchars($booking['coupon_code'], ENT_QUOTES) ?> (&minus;<?= money_from_cents((int) $booking['discount_cents']) ?>)</td></tr>
        <?php endif; ?>
        <tr><th>Gesamtpreis</th><td>
            <?= $booking['total_price_cents'] !== null ? '<strong>' . money_from_cents((int) $booking['total_price_cents']) . '</strong>' : '— (Buchung noch nicht abgeschlossen)' ?>
        </td></tr>
        <?php if ($booking['invoice_number'] || $booking['stripe_invoice_hosted_url']): ?>
            <tr><th>Rechnung</th><td>
                <?php if ($booking['invoice_number']): ?>
                    <?= htmlspecialchars($booking['invoice_number'], ENT_QUOTES) ?>
                    <span class="muted-text">(eigene Rechnungsnummer, historisch - seit Migration 0015 stellt nur noch Stripe die Rechnung aus)</span>
                <?php endif; ?>
                <?php if ($booking['stripe_invoice_hosted_url']): ?>
                    · <a href="<?= htmlspecialchars($booking['stripe_invoice_hosted_url'], ENT_QUOTES) ?>" target="_blank" rel="noopener">bei Stripe ansehen</a>
                <?php endif; ?>
                <?php if ($booking['stripe_invoice_pdf_url']): ?>
                    · <a href="<?= htmlspecialchars($booking['stripe_invoice_pdf_url'], ENT_QUOTES) ?>" target="_blank" rel="noopener">PDF</a>
                <?php endif; ?>
            </td></tr>
        <?php endif; ?>
        <?php if ($booking['cancelled_at']): ?>
            <tr><th>Storniert am</th><td>
                <?= htmlspecialchars($booking['cancelled_at'], ENT_QUOTES) ?>
                <?php if ($booking['stripe_refund_id']): ?>
                    · <span class="badge">Zahlung über Stripe zurückerstattet</span>
                <?php elseif ($booking['paid_at']): ?>
                    · <span class="badge badge-warning">Rückerstattung fehlgeschlagen - bitte im Stripe-Dashboard prüfen</span>
                <?php endif; ?>
                <?php if ($booking['stripe_credit_note_pdf_url']): ?>
                    · <a href="<?= htmlspecialchars($booking['stripe_credit_note_pdf_url'], ENT_QUOTES) ?>" target="_blank" rel="noopener">Stornorechnung (PDF)</a>
                <?php endif; ?>
            </td></tr>
        <?php endif; ?>
        <tr><th>Schriftliches Angebot gewünscht</th><td><?= $booking['wants_quote'] ? 'Ja' : 'Nein' ?></td></tr>
        <tr><th>AGB &amp; Datenschutz akzeptiert</th><td>
            <?= $booking['agb_accepted_at'] ? htmlspecialchars($booking['agb_accepted_at'], ENT_QUOTES) : '—' ?>
        </td></tr>
        <tr><th>Nachricht</th><td><?= $booking['message'] ? nl2br(htmlspecialchars($booking['message'], ENT_QUOTES)) : '—' ?></td></tr>
        <tr><th>Zugeordnete Box</th><td><?= $boxName ? htmlspecialchars($boxName, ENT_QUOTES) : '—' ?></td></tr>
        <tr><th>Angefragt am</th><td><?= htmlspecialchars($booking['created_at'], ENT_QUOTES) ?></td></tr>
        <tr><th>Bearbeitung für Kunde freigeschaltet</th><td>
            <?= (int) $booking['edit_unlocked_by_admin'] === 1 ? 'Ja' : 'Nein' ?>
            <?php if ($booking['status'] === 'bestaetigt'): ?>
                <form method="post" action="booking_detail.php" style="display:inline;margin-left:8px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                    <input type="hidden" name="form_action" value="toggle_unlock">
                    <button type="submit" class="button-secondary" style="padding:4px 10px;font-size:12px;">
                        <?= (int) $booking['edit_unlocked_by_admin'] === 1 ? 'Sperren' : 'Für Kunde freischalten' ?>
                    </button>
                </form>
                <p class="muted-text" style="margin-top:4px;">Erlaubt dem Kunden im eigenen Konto trotz
                    abgeschlossener Buchung nochmal Design/Extras/Adresse zu ändern, z.B. wenn etwas
                    falsch war.</p>
            <?php endif; ?>
        </td></tr>
    </table>
</section>

<?php if ($addonCharges): ?>
    <section class="panel">
        <h2>Zusatzzahlungen</h2>
        <table class="key-table">
            <?php foreach ($addonCharges as $charge): ?>
                <tr>
                    <th><?= htmlspecialchars($charge['description'], ENT_QUOTES) ?></th>
                    <td>
                        <?= money_from_cents((int) $charge['amount_cents']) ?>
                        <?php if ($charge['paid_at']): ?>
                            <span class="badge">Bezahlt am <?= htmlspecialchars($charge['paid_at'], ENT_QUOTES) ?> – Änderung übernommen</span>
                            <?php if ($charge['stripe_invoice_hosted_url']): ?>
                                · <a href="<?= htmlspecialchars($charge['stripe_invoice_hosted_url'], ENT_QUOTES) ?>" target="_blank" rel="noopener">Rechnung bei Stripe</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="muted-text">Zahlung ausstehend – die Änderung wird erst nach Zahlungseingang auf die Buchung übernommen.</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </section>
<?php endif; ?>

<?php if ($booking['status'] === 'bestaetigt'): ?>
    <section class="panel">
        <h2>Online-Galerie</h2>
        <?php if ($galleryPhotoCount === 0): ?>
            <p class="muted-text">Noch keine Fotos hochgeladen. Das passiert automatisch, sobald die Fotobox nach
                der Veranstaltung wieder mit dem Internet verbunden ist.</p>
        <?php else: ?>
            <p>
                <?= $galleryPhotoCount ?> Foto(s) hochgeladen &middot;
                <a href="<?= htmlspecialchars(gallery_url($booking['gallery_token'] ?: ensure_gallery_token($bookingId)), ENT_QUOTES) ?>" target="_blank" rel="noopener">Galerie ansehen</a>
            </p>
            <form method="post" action="booking_detail.php" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                <input type="hidden" name="form_action" value="send_gallery_email">
                <button type="submit" class="button-secondary">Link per Mail an Kunde senden</button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($pendingEdit): ?>
    <section class="panel">
        <h2>⚠️ Nicht ursprünglich gebucht</h2>
        <p>Die vorgeschlagene Änderung erhöht den Gesamtpreis um
            <strong><?= money_from_cents($pendingEdit['delta']) ?></strong>
            (neuer Gesamtpreis: <?= money_from_cents($pendingEdit['new_total']) ?>) - mehr, als der Kunde
            bisher gebucht bzw. bezahlt hat. Wie möchtest du vorgehen?</p>
        <form method="post" action="booking_detail.php">
            <?= csrf_field() ?>
            <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
            <input type="hidden" name="form_action" value="resolve_addon">
            <input type="hidden" name="event_date" value="<?= htmlspecialchars($pendingEdit['event_date'], ENT_QUOTES) ?>">
            <?php foreach ($pendingEdit['layout_ids'] as $lid): ?>
                <input type="hidden" name="layout_ids[]" value="<?= (int) $lid ?>">
            <?php endforeach; ?>
            <?php foreach ($pendingEdit['extra_selections'] as $extraId => $qty): ?>
                <input type="hidden" name="extra_qty[<?= (int) $extraId ?>]" value="<?= (int) $qty ?>">
            <?php endforeach; ?>
            <div class="inline-form">
                <button type="submit" name="resolution" value="free">Kostenlos übernehmen</button>
                <button type="submit" name="resolution" value="charge">Zahlungslink an Kunde senden</button>
                <a href="booking_detail.php?id=<?= (int) $booking['id'] ?>" class="button-secondary">Abbrechen</a>
            </div>
        </form>
    </section>
<?php else: ?>
    <section class="panel">
        <h2>Buchung bearbeiten</h2>
        <?php if ($editError !== ''): ?>
            <p class="error"><?= htmlspecialchars($editError, ENT_QUOTES) ?></p>
        <?php endif; ?>
        <form method="post" action="booking_detail.php">
            <?= csrf_field() ?>
            <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
            <input type="hidden" name="form_action" value="edit_booking">

            <label>Eventdatum
                <input type="date" name="event_date" value="<?= htmlspecialchars($formEventDate, ENT_QUOTES) ?>" required>
            </label>

            <h3 style="margin-top:20px;">Layouts</h3>
            <?php if ($customLayout): ?>
                <p class="muted-text">Individuelles Design des Kunden bleibt unabhängig von der Auswahl
                    unten erhalten (<a href="layout_form.php?id=<?= (int) $customLayout['id'] ?>">ansehen/anpassen</a>).</p>
                <input type="hidden" name="layout_ids[]" value="<?= (int) $customLayout['id'] ?>">
            <?php endif; ?>
            <div class="grid3">
                <?php foreach ($allLayouts as $layout): ?>
                    <label class="checkbox">
                        <input type="checkbox" name="layout_ids[]" value="<?= (int) $layout['id'] ?>"
                            <?= in_array((int) $layout['id'], $formLayoutIds, true) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($layout['name'], ENT_QUOTES) ?>
                        <?= $layout['surcharge_cents'] > 0 ? ' (+' . money_from_cents((int) $layout['surcharge_cents']) . ')' : '' ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <h3 style="margin-top:20px;">Extras</h3>
            <?php foreach ($activeExtras as $extra): ?>
                <?php $qty = $formExtraSelections[$extra['id']] ?? 0; ?>
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

            <button type="submit" style="margin-top:16px;">Änderungen speichern</button>
        </form>
    </section>
<?php endif; ?>

<section class="panel">
    <h2>Interne Notiz</h2>
    <form method="post" action="booking_detail.php">
        <?= csrf_field() ?>
        <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
        <input type="hidden" name="form_action" value="save_note">
        <textarea name="admin_note" rows="4"><?= htmlspecialchars((string) $booking['admin_note'], ENT_QUOTES) ?></textarea>
        <button type="submit" style="margin-top:10px;">Notiz speichern</button>
    </form>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

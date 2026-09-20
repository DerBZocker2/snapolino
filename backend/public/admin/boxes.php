<?php
declare(strict_types=1);

$pageTitle = 'Boxen';
require __DIR__ . '/_header.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'unassign') {
    check_csrf();

    $bookingId = (int) ($_POST['booking_id'] ?? 0);
    $boxId = (int) ($_POST['box_id'] ?? 0);

    // Status bleibt 'bestaetigt' (die Buchung ist ja ggf. schon bezahlt),
    // nur die Box-Zuordnung wird entfernt - die Buchung taucht danach
    // wieder unter "Buchungen ohne Box" auf.
    $stmt = db()->prepare('UPDATE bookings SET box_id = NULL WHERE id = ? AND box_id = ?');
    $stmt->execute([$bookingId, $boxId]);

    // Ohne diesen Aufruf wuerde die Box beim naechsten Preflight-Check
    // (?since=) einen 304 bekommen und weiter die alte Buchung anzeigen.
    bump_box_version($boxId);

    // Die Box kommt hier typischerweise gerade vom Kunden zurueck - alle
    // Wartungs-Haekchen zuruecksetzen, damit vor der naechsten Vermietung
    // erneut geprueft wird (siehe "Wartungs-Checkliste" in CLAUDE.md).
    reset_box_maintenance_checks($boxId);

    header('Location: boxes.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'add_maintenance_item') {
    check_csrf();
    $itemName = trim((string) ($_POST['item_name'] ?? ''));
    if ($itemName !== '') {
        $nextSort = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM maintenance_checklist_items')->fetchColumn();
        db()->prepare('INSERT INTO maintenance_checklist_items (name, sort_order) VALUES (?, ?)')
            ->execute([$itemName, $nextSort]);
    }
    header('Location: boxes.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'delete_maintenance_item') {
    check_csrf();
    db()->prepare('DELETE FROM maintenance_checklist_items WHERE id = ?')->execute([(int) ($_POST['item_id'] ?? 0)]);
    header('Location: boxes.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $name = trim((string) ($_POST['name'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));

    if ($name === '') {
        $error = 'Bitte einen Namen für die Box angeben.';
    } else {
        $boxKey = random_key(8);
        $apiKey = random_key(24);

        db()->beginTransaction();
        $stmt = db()->prepare(
            'INSERT INTO boxes (box_key, api_key, name, note) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$boxKey, $apiKey, $name, $note !== '' ? $note : null]);
        $boxId = (int) db()->lastInsertId();

        // Standard-Layout automatisch zuordnen, damit jede Box sofort
        // die 4er-Collage ausliefern kann.
        $stmt = db()->prepare('SELECT id FROM layouts WHERE is_default = 1 ORDER BY id LIMIT 1');
        $stmt->execute();
        $defaultLayoutId = $stmt->fetchColumn();
        if ($defaultLayoutId !== false) {
            $stmt = db()->prepare(
                'INSERT INTO box_layouts (box_id, layout_id, sort_order) VALUES (?, ?, 0)'
            );
            $stmt->execute([$boxId, (int) $defaultLayoutId]);
        }
        db()->commit();

        header('Location: box_layouts.php?id=' . $boxId);
        exit;
    }
}

$boxes = db()->query('SELECT * FROM boxes ORDER BY created_at DESC')->fetchAll();

// Fuer jede Box dieselbe "naechste bestaetigte Buchung"-Auswahl wie
// api.php, damit die Karte hier zeigt, was die Box beim naechsten Sync
// tatsaechlich bekommt.
$currentBookingStmt = db()->prepare(
    "SELECT id, customer_name, event_date FROM bookings
     WHERE box_id = ? AND status = 'bestaetigt' AND event_date >= CURDATE()
     ORDER BY event_date ASC LIMIT 1"
);
foreach ($boxes as &$box) {
    $currentBookingStmt->execute([$box['id']]);
    $box['current_booking'] = $currentBookingStmt->fetch() ?: null;
    $box['maintenance_status'] = box_maintenance_status((int) $box['id']);
}
unset($box);

$maintenanceItems = fetch_maintenance_items();

// Buchungen ohne Box: frische Anfragen sowie bereits bestaetigte
// Buchungen, denen (z.B. bei 0 oder mehreren Boxen) noch keine Box
// automatisch zugewiesen werden konnte.
$pendingBookings = db()->query(
    "SELECT * FROM bookings
     WHERE status = 'angefragt' OR (status = 'bestaetigt' AND box_id IS NULL)
     ORDER BY event_date"
)->fetchAll();

$layoutStmt = db()->prepare(
    'SELECT l.name FROM booking_layouts bl INNER JOIN layouts l ON l.id = bl.layout_id WHERE bl.booking_id = ?'
);
?>

<section class="panel">
    <h2>Neue Box anlegen</h2>
    <?php if ($error !== ''): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <form method="post" action="boxes.php" class="inline-form">
        <?= csrf_field() ?>
        <label>Name
            <input type="text" name="name" required placeholder="z.B. Box 3 - Hochzeit Müller">
        </label>
        <label>Notiz
            <input type="text" name="note" placeholder="optional">
        </label>
        <button type="submit">Anlegen</button>
    </form>
</section>

<section class="panel">
    <h2>Buchungen ohne Box</h2>
    <p class="muted-text">Karte auf eine Box weiter unten ziehen, um sie zuzuordnen - die Buchung
        wird dabei bestätigt, und die Box bekommt Kundendaten und gebuchte Layouts beim nächsten Sync.</p>

    <div class="booking-chip-list">
        <?php foreach ($pendingBookings as $booking): ?>
            <?php
            $layoutStmt->execute([$booking['id']]);
            $layoutNames = $layoutStmt->fetchAll(PDO::FETCH_COLUMN);
            ?>
            <div class="booking-chip" draggable="true" data-booking-id="<?= (int) $booking['id'] ?>">
                <div class="booking-chip-name"><?= htmlspecialchars($booking['customer_name'], ENT_QUOTES) ?></div>
                <div class="booking-chip-meta">
                    📅 <?= htmlspecialchars($booking['event_date'], ENT_QUOTES) ?>
                    <span class="status-pill status-<?= htmlspecialchars($booking['status'], ENT_QUOTES) ?>">
                        <?= htmlspecialchars(booking_status_label($booking['status']), ENT_QUOTES) ?>
                    </span>
                </div>
                <?php if ($layoutNames): ?>
                    <div class="booking-chip-meta"><?= htmlspecialchars(implode(', ', $layoutNames), ENT_QUOTES) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (!$pendingBookings): ?>
            <p class="muted-text">Alle Buchungen sind einer Box zugeordnet.</p>
        <?php endif; ?>
    </div>
</section>

<section class="panel">
    <h2>Boxen</h2>
    <?php if (!$boxes): ?>
        <p class="muted-text">Noch keine Box angelegt.</p>
    <?php endif; ?>
    <div class="box-grid">
        <?php foreach ($boxes as $box): ?>
            <div class="box-card">
                <div class="box-card-head">
                    <h3><?= htmlspecialchars($box['name'], ENT_QUOTES) ?></h3>
                    <span class="muted-text">v<?= (int) $box['config_version'] ?></span>
                </div>
                <div class="box-card-drop" data-box-id="<?= (int) $box['id'] ?>">
                    <?php if ($box['current_booking']): ?>
                        <div class="box-card-booking">
                            <strong><?= htmlspecialchars($box['current_booking']['customer_name'], ENT_QUOTES) ?></strong>
                            <span class="muted-text">📅 <?= htmlspecialchars($box['current_booking']['event_date'], ENT_QUOTES) ?></span>
                            <form method="post" action="boxes.php" class="box-card-unassign"
                                  onsubmit="return confirm('Zuordnung wirklich aufheben? Die Box zeigt danach keine Buchung mehr an.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="unassign">
                                <input type="hidden" name="booking_id" value="<?= (int) $box['current_booking']['id'] ?>">
                                <input type="hidden" name="box_id" value="<?= (int) $box['id'] ?>">
                                <button type="submit" class="button-secondary">Zuordnung aufheben</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="box-card-empty">Buchung hierher ziehen</div>
                    <?php endif; ?>
                </div>
                <?php if ($box['note']): ?>
                    <p class="muted-text"><?= htmlspecialchars($box['note'], ENT_QUOTES) ?></p>
                <?php endif; ?>
                <?php if ($maintenanceItems): ?>
                    <?php
                    $doneCount = count(array_filter($box['maintenance_status'], static fn (array $s) => $s['checked_at'] !== null));
                    $totalCount = count($box['maintenance_status']);
                    ?>
                    <details class="box-maintenance">
                        <summary>Wartung
                            <span class="badge <?= $doneCount === $totalCount ? '' : 'badge-warning' ?>"><?= $doneCount ?>/<?= $totalCount ?></span>
                        </summary>
                        <ul class="maintenance-checklist" data-box-id="<?= (int) $box['id'] ?>">
                            <?php foreach ($box['maintenance_status'] as $item): ?>
                                <li>
                                    <label class="checkbox">
                                        <input type="checkbox" data-item-id="<?= (int) $item['id'] ?>" <?= $item['checked_at'] !== null ? 'checked' : '' ?>>
                                        <?= htmlspecialchars($item['name'], ENT_QUOTES) ?>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
                <div class="box-card-actions">
                    <a href="box_layouts.php?id=<?= (int) $box['id'] ?>">Layouts &amp; Zugang</a>
                    <form method="post" action="box_delete.php" onsubmit="return confirm('Box wirklich löschen?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $box['id'] ?>">
                        <button type="submit" class="danger">Löschen</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="panel">
    <h2>Wartungs-Checkliste verwalten</h2>
    <p class="muted-text">Diese Punkte erscheinen bei jeder Box unter "Wartung" - werden beim Aufheben einer
        Buchungs-Zuordnung automatisch fuer diese Box zurueckgesetzt.</p>
    <form method="post" action="boxes.php" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="add_maintenance_item">
        <label>Neuer Punkt
            <input type="text" name="item_name" required placeholder="z.B. Objektiv gereinigt">
        </label>
        <button type="submit">Hinzufügen</button>
    </form>
    <?php if ($maintenanceItems): ?>
        <ul class="maintenance-item-list">
            <?php foreach ($maintenanceItems as $item): ?>
                <li>
                    <?= htmlspecialchars($item['name'], ENT_QUOTES) ?>
                    <form method="post" action="boxes.php" style="display:inline;" onsubmit="return confirm('Wartungspunkt wirklich löschen? Der Erledigt-Status bei allen Boxen geht dabei verloren.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_action" value="delete_maintenance_item">
                        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                        <button type="submit" class="danger">Löschen</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="muted-text">Noch keine Wartungspunkte angelegt.</p>
    <?php endif; ?>
</section>

<script>
(function () {
    var csrfToken = <?= json_encode(csrf_token()) ?>;

    document.querySelectorAll('.maintenance-checklist').forEach(function (list) {
        var boxId = list.dataset.boxId;
        // Badge (z.B. "3/6") direkt aktualisieren statt die Seite neu zu
        // laden - ein Reload wuerde das <details> wieder zuklappen, was beim
        // Abhaken mehrerer Punkte hintereinander staendig im Weg waere.
        var badge = list.closest('.box-maintenance').querySelector('summary .badge');
        var total = list.querySelectorAll('input[type=checkbox]').length;

        function updateBadge() {
            var done = list.querySelectorAll('input[type=checkbox]:checked').length;
            badge.textContent = done + '/' + total;
            badge.classList.toggle('badge-warning', done !== total);
        }

        list.querySelectorAll('input[type=checkbox]').forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                var wasChecked = !checkbox.checked;
                var body = 'box_id=' + encodeURIComponent(boxId)
                    + '&item_id=' + encodeURIComponent(checkbox.dataset.itemId)
                    + '&checked=' + (checkbox.checked ? '1' : '0')
                    + '&csrf_token=' + encodeURIComponent(csrfToken);

                fetch('toggle_maintenance_check.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body,
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.ok) {
                            updateBadge();
                        } else {
                            alert(data.error || 'Speichern fehlgeschlagen');
                            checkbox.checked = wasChecked;
                        }
                    })
                    .catch(function () {
                        alert('Speichern fehlgeschlagen (Netzwerkfehler)');
                        checkbox.checked = wasChecked;
                    });
            });
        });
    });

    document.querySelectorAll('.booking-chip').forEach(function (chip) {
        chip.addEventListener('dragstart', function (e) {
            e.dataTransfer.setData('text/plain', chip.dataset.bookingId);
            e.dataTransfer.effectAllowed = 'move';
            chip.classList.add('dragging');
        });
        chip.addEventListener('dragend', function () {
            chip.classList.remove('dragging');
        });
    });

    document.querySelectorAll('.box-card-drop').forEach(function (dropZone) {
        dropZone.addEventListener('dragover', function (e) {
            e.preventDefault();
            dropZone.classList.add('drag-over');
        });
        dropZone.addEventListener('dragleave', function () {
            dropZone.classList.remove('drag-over');
        });
        dropZone.addEventListener('drop', function (e) {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
            var bookingId = e.dataTransfer.getData('text/plain');
            var boxId = dropZone.dataset.boxId;
            if (!bookingId || !boxId) {
                return;
            }

            var body = 'booking_id=' + encodeURIComponent(bookingId)
                + '&box_id=' + encodeURIComponent(boxId)
                + '&csrf_token=' + encodeURIComponent(csrfToken);

            fetch('assign_box.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body,
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.ok) {
                        location.reload();
                    } else {
                        alert(data.error || 'Zuordnung fehlgeschlagen');
                    }
                })
                .catch(function () {
                    alert('Zuordnung fehlgeschlagen (Netzwerkfehler)');
                });
        });
    });
})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>

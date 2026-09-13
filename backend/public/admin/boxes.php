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
}
unset($box);

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

<script>
(function () {
    var csrfToken = <?= json_encode(csrf_token()) ?>;

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

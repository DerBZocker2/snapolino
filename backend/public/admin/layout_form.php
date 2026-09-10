<?php
declare(strict_types=1);

$pageTitle = 'Layout';
require __DIR__ . '/_header.php';

$layoutId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$layout = null;
$slots = [];

if ($layoutId > 0) {
    $layout = fetch_layout_with_slots($layoutId);
    if (!$layout) {
        http_response_code(404);
        echo '<p>Layout nicht gefunden.</p>';
        require __DIR__ . '/_footer.php';
        exit;
    }
    $slots = $layout['slots'];
}

$errors = [];

// Formularwerte: aus POST bei erneuter Anzeige nach Fehler, sonst aus DB
// bzw. sinnvollen Standardwerten fuer ein neues Layout.
$name          = (string) ($_POST['name'] ?? $layout['name'] ?? '');
$category      = trim((string) ($_POST['category'] ?? $layout['category'] ?? ''));
$slotCount     = (int) ($_POST['slot_count'] ?? $layout['slot_count'] ?? 4);
$canvasWidth   = (int) ($_POST['canvas_width'] ?? $layout['canvas_width'] ?? 1800);
$canvasHeight  = (int) ($_POST['canvas_height'] ?? $layout['canvas_height'] ?? 1200);
$isDefault     = isset($_POST['is_default']) ? true : (bool) ($layout['is_default'] ?? false);
$surchargeEuro = isset($_POST['surcharge_euro'])
    ? (string) $_POST['surcharge_euro']
    : ($layout ? number_format($layout['surcharge_cents'] / 100, 2, '.', '') : '0.00');

$postedSlots = $_POST['slots'] ?? null;
if (is_array($postedSlots)) {
    $slotRows = array_values($postedSlots);
} elseif ($slots) {
    $slotRows = array_map(
        static fn (array $s): array => ['x' => $s['x'], 'y' => $s['y'], 'width' => $s['width'], 'height' => $s['height']],
        $slots
    );
} else {
    $slotRows = [];
}

// "Anzahl aktualisieren" hat gedrueckt: nur Zeilenanzahl anpassen, nicht speichern.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resize'])) {
    check_csrf();
    $slotCount = max(1, min(20, $slotCount));
    $slotRows = array_slice($slotRows, 0, $slotCount);
    // Neue Zeilen bekommen eine sichtbare Startgroesse/-position statt 0x0,
    // damit man sie im visuellen Editor sofort sehen und verschieben kann.
    $defaultW = max(50, intdiv($canvasWidth, 3));
    $defaultH = max(50, intdiv($canvasHeight, 3));
    while (count($slotRows) < $slotCount) {
        $i = count($slotRows);
        $offset = ($i % 5) * 30;
        $slotRows[] = [
            'x' => min(max(0, $canvasWidth - $defaultW), $offset),
            'y' => min(max(0, $canvasHeight - $defaultH), $offset),
            'width' => $defaultW,
            'height' => $defaultH,
        ];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    check_csrf();

    if ($name === '') {
        $errors[] = 'Bitte einen Namen angeben.';
    }
    if ($canvasWidth <= 0 || $canvasHeight <= 0) {
        $errors[] = 'Leinwandgroesse muss groesser als 0 sein.';
    }
    if ($slotCount < 1 || $slotCount !== count($slotRows)) {
        $errors[] = 'Anzahl Slots stimmt nicht mit den Koordinatenzeilen ueberein. Bitte "Anzahl aktualisieren" nutzen.';
    }
    if (!is_numeric($surchargeEuro) || (float) $surchargeEuro < 0) {
        $errors[] = 'Aufpreis muss eine Zahl >= 0 sein.';
    }

    $frameFile = $layout['frame_file'] ?? null;
    $uploadedFile = $_FILES['frame'] ?? null;

    if ($uploadedFile && $uploadedFile['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Fehler beim Hochladen der Rahmen-Datei.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($uploadedFile['tmp_name']);
            $imageInfo = @getimagesize($uploadedFile['tmp_name']);
            if ($mime !== 'image/png' || $imageInfo === false || $imageInfo[2] !== IMAGETYPE_PNG) {
                $errors[] = 'Die Rahmen-Datei muss eine echte PNG-Datei sein.';
            } elseif ($uploadedFile['size'] > 15 * 1024 * 1024) {
                $errors[] = 'Die Rahmen-Datei darf maximal 15 MB gross sein.';
            } else {
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name)) ?: 'layout';
                $newFrameFile = trim($slug, '_') . '_' . bin2hex(random_bytes(4)) . '.png';
                $cfg = backend_config();
                $destination = rtrim($cfg['storage_dir'], '/') . '/' . $newFrameFile;
                if (!move_uploaded_file($uploadedFile['tmp_name'], $destination)) {
                    $errors[] = 'Rahmen-Datei konnte nicht gespeichert werden.';
                } else {
                    $oldFrameFile = $frameFile;
                    $frameFile = $newFrameFile;
                    // Alte Datei entfernen, falls kein anderes Layout sie mehr nutzt.
                    if ($oldFrameFile) {
                        $stmt = db()->prepare('SELECT COUNT(*) FROM layouts WHERE frame_file = ? AND id != ?');
                        $stmt->execute([$oldFrameFile, $layoutId]);
                        if ((int) $stmt->fetchColumn() === 0) {
                            $oldPath = rtrim($cfg['storage_dir'], '/') . '/' . $oldFrameFile;
                            if (is_file($oldPath)) {
                                unlink($oldPath);
                            }
                        }
                    }
                }
            }
        }
    } elseif (!$frameFile) {
        $errors[] = 'Bitte eine Rahmen-PNG hochladen.';
    }

    if (!$errors) {
        $surchargeCents = (int) round(((float) $surchargeEuro) * 100);

        db()->beginTransaction();

        if ($isDefault) {
            db()->exec('UPDATE layouts SET is_default = 0');
        }

        if ($layoutId > 0) {
            $stmt = db()->prepare(
                'UPDATE layouts SET name = ?, category = ?, slot_count = ?, canvas_width = ?, canvas_height = ?,
                 frame_file = ?, is_default = ?, surcharge_cents = ? WHERE id = ?'
            );
            $stmt->execute([$name, $category ?: null, $slotCount, $canvasWidth, $canvasHeight, $frameFile, $isDefault ? 1 : 0, $surchargeCents, $layoutId]);
        } else {
            $stmt = db()->prepare(
                'INSERT INTO layouts (name, category, slot_count, canvas_width, canvas_height, frame_file, is_default, surcharge_cents)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $category ?: null, $slotCount, $canvasWidth, $canvasHeight, $frameFile, $isDefault ? 1 : 0, $surchargeCents]);
            $layoutId = (int) db()->lastInsertId();
        }

        db()->prepare('DELETE FROM layout_slots WHERE layout_id = ?')->execute([$layoutId]);
        $ins = db()->prepare(
            'INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($slotRows as $index => $row) {
            $ins->execute([
                $layoutId,
                $index,
                (int) $row['x'],
                (int) $row['y'],
                (int) $row['width'],
                (int) $row['height'],
            ]);
        }

        bump_boxes_for_layout($layoutId);
        db()->commit();

        header('Location: layouts.php');
        exit;
    }
}

if (!$slotRows) {
    $slotRows = array_fill(0, $slotCount, ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0]);
}
?>

<section class="panel">
    <?php foreach ($errors as $err): ?>
        <p class="error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
    <?php endforeach; ?>

    <form method="post" action="layout_form.php" enctype="multipart/form-data" id="layout-form">
        <?= csrf_field() ?>
        <?php if ($layoutId > 0): ?>
            <input type="hidden" name="id" value="<?= $layoutId ?>">
        <?php endif; ?>

        <div class="grid3">
            <label>Name
                <input type="text" name="name" required value="<?= htmlspecialchars($name, ENT_QUOTES) ?>">
            </label>
            <label>Kategorie (fuer die Galerie-Filter, z.B. "Hochzeit")
                <input type="text" name="category" value="<?= htmlspecialchars($category, ENT_QUOTES) ?>">
            </label>
        </div>

        <div class="grid3">
            <label>Leinwandbreite (px)
                <input type="number" name="canvas_width" min="1" required value="<?= $canvasWidth ?>">
            </label>
            <label>Leinwandhoehe (px)
                <input type="number" name="canvas_height" min="1" required value="<?= $canvasHeight ?>">
            </label>
            <label>Aufpreis (EUR)
                <input type="text" name="surcharge_euro" required value="<?= htmlspecialchars($surchargeEuro, ENT_QUOTES) ?>">
            </label>
        </div>

        <label class="checkbox">
            <input type="checkbox" name="is_default" <?= $isDefault ? 'checked' : '' ?>>
            Dies ist das kostenlose Standard-Layout (immer inklusive)
        </label>

        <label>Rahmen-PNG <?= $layoutId > 0 ? '(leer lassen = bisherige Datei behalten)' : '' ?>
            <input type="file" name="frame" accept="image/png">
        </label>
        <?php if ($layout && $layout['frame_file']): ?>
            <p>Aktuelle Datei: <code><?= htmlspecialchars($layout['frame_file'], ENT_QUOTES) ?></code></p>
        <?php endif; ?>

        <h2>Foto-Slots</h2>
        <p>Fotoflächen direkt auf dem Rahmen per Maus verschieben und an der
            Ecke unten rechts in der Größe ziehen - die Zahlen unten aktualisieren
            sich automatisch mit (und lassen sich für die exakte Positionierung
            auch direkt eintippen).</p>

        <div class="inline-form">
            <label>Anzahl Slots
                <input type="number" name="slot_count" id="slot_count" min="1" max="20" value="<?= count($slotRows) ?>">
            </label>
            <button type="submit" name="resize" value="1" formnovalidate>Anzahl aktualisieren</button>
        </div>

        <div id="slot-editor-wrap" style="margin:16px 0;">
            <div id="slot-editor" style="position:relative;width:100%;max-width:700px;background:repeating-conic-gradient(#f0edf9 0% 25%, #ffffff 0% 50%) 0 0/20px 20px;border:1px solid var(--border);border-radius:8px;overflow:hidden;">
                <img id="slot-editor-bg" alt="" style="display:block;width:100%;height:auto;">
            </div>
        </div>

        <table id="slots-table">
            <thead>
            <tr><th>#</th><th>x</th><th>y</th><th>Breite</th><th>Hoehe</th></tr>
            </thead>
            <tbody>
            <?php foreach ($slotRows as $i => $row): ?>
                <tr>
                    <td><?= $i ?></td>
                    <td><input type="number" class="slot-input" data-slot="<?= $i ?>" data-field="x" name="slots[<?= $i ?>][x]" value="<?= (int) $row['x'] ?>"></td>
                    <td><input type="number" class="slot-input" data-slot="<?= $i ?>" data-field="y" name="slots[<?= $i ?>][y]" value="<?= (int) $row['y'] ?>"></td>
                    <td><input type="number" class="slot-input" data-slot="<?= $i ?>" data-field="width" name="slots[<?= $i ?>][width]" value="<?= (int) $row['width'] ?>"></td>
                    <td><input type="number" class="slot-input" data-slot="<?= $i ?>" data-field="height" name="slots[<?= $i ?>][height]" value="<?= (int) $row['height'] ?>"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <button type="submit" name="save" value="1">Layout speichern</button>
        <a href="layouts.php" class="button-secondary">Abbrechen</a>
    </form>
</section>

<script>
(function () {
    var wrap = document.getElementById('slot-editor');
    var bgImg = document.getElementById('slot-editor-bg');
    var canvasWInput = document.querySelector('input[name="canvas_width"]');
    var canvasHInput = document.querySelector('input[name="canvas_height"]');
    var fileInput = document.querySelector('input[name="frame"]');
    var slotCount = <?= (int) count($slotRows) ?>;
    var layoutId = <?= $layoutId > 0 ? (int) $layoutId : 'null' ?>;
    var boxes = [];

    function canvasW() { return Math.max(1, parseInt(canvasWInput.value, 10) || 1800); }
    function canvasH() { return Math.max(1, parseInt(canvasHInput.value, 10) || 1200); }
    function scale() { return wrap.clientWidth / canvasW(); }

    function getInput(i, field) {
        return document.querySelector('.slot-input[data-slot="' + i + '"][data-field="' + field + '"]');
    }

    function layoutBoxes() {
        var s = scale();
        boxes.forEach(function (b) {
            var i = b.index;
            b.el.style.left = (parseInt(getInput(i, 'x').value, 10) || 0) * s + 'px';
            b.el.style.top = (parseInt(getInput(i, 'y').value, 10) || 0) * s + 'px';
            b.el.style.width = (parseInt(getInput(i, 'width').value, 10) || 0) * s + 'px';
            b.el.style.height = (parseInt(getInput(i, 'height').value, 10) || 0) * s + 'px';
        });
    }

    function buildBoxes() {
        boxes.forEach(function (b) { b.el.remove(); });
        boxes = [];
        var colors = ['255,111,89', '108,92,231', '23,195,178', '255,196,61', '255,92,141'];
        for (var i = 0; i < slotCount; i++) {
            if (!getInput(i, 'x')) continue;
            var color = colors[i % colors.length];
            var el = document.createElement('div');
            el.className = 'slot-box';
            el.style.cssText = 'position:absolute;border:2px solid rgba(' + color + ',0.9);background:rgba(' + color + ',0.18);cursor:move;box-sizing:border-box;';
            var label = document.createElement('span');
            label.textContent = (i + 1);
            label.style.cssText = 'position:absolute;top:2px;left:4px;font:bold 12px sans-serif;color:rgba(' + color + ',1);';
            el.appendChild(label);
            var handle = document.createElement('div');
            handle.className = 'slot-resize-handle';
            handle.style.cssText = 'position:absolute;right:-6px;bottom:-6px;width:14px;height:14px;background:rgba(' + color + ',1);border:2px solid #fff;border-radius:50%;cursor:nwse-resize;';
            el.appendChild(handle);
            wrap.appendChild(el);
            boxes.push({ index: i, el: el, handle: handle });
            attachDrag(boxes[boxes.length - 1]);
        }
        layoutBoxes();
    }

    function attachDrag(box) {
        var i = box.index;
        box.el.addEventListener('mousedown', function (ev) {
            if (ev.target === box.handle) return;
            ev.preventDefault();
            var s = scale();
            var startX = ev.clientX, startY = ev.clientY;
            var origX = parseInt(getInput(i, 'x').value, 10) || 0;
            var origY = parseInt(getInput(i, 'y').value, 10) || 0;
            function onMove(e) {
                var dx = (e.clientX - startX) / s, dy = (e.clientY - startY) / s;
                var w = parseInt(getInput(i, 'width').value, 10) || 0;
                var h = parseInt(getInput(i, 'height').value, 10) || 0;
                var nx = Math.max(0, Math.min(canvasW() - w, origX + dx));
                var ny = Math.max(0, Math.min(canvasH() - h, origY + dy));
                getInput(i, 'x').value = Math.round(nx);
                getInput(i, 'y').value = Math.round(ny);
                layoutBoxes();
            }
            function onUp() {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });

        box.handle.addEventListener('mousedown', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            var s = scale();
            var startX = ev.clientX, startY = ev.clientY;
            var origW = parseInt(getInput(i, 'width').value, 10) || 0;
            var origH = parseInt(getInput(i, 'height').value, 10) || 0;
            var x = parseInt(getInput(i, 'x').value, 10) || 0;
            var y = parseInt(getInput(i, 'y').value, 10) || 0;
            function onMove(e) {
                var dw = (e.clientX - startX) / s, dh = (e.clientY - startY) / s;
                var nw = Math.max(10, Math.min(canvasW() - x, origW + dw));
                var nh = Math.max(10, Math.min(canvasH() - y, origH + dh));
                getInput(i, 'width').value = Math.round(nw);
                getInput(i, 'height').value = Math.round(nh);
                layoutBoxes();
            }
            function onUp() {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });
    }

    document.querySelectorAll('.slot-input').forEach(function (input) {
        input.addEventListener('input', layoutBoxes);
    });
    window.addEventListener('resize', layoutBoxes);

    bgImg.addEventListener('load', layoutBoxes);
    if (fileInput) {
        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files[0]) {
                bgImg.src = URL.createObjectURL(fileInput.files[0]);
            }
        });
    }
    if (layoutId) {
        bgImg.src = '../layout_preview.php?id=' + layoutId + '&t=' + Date.now();
    } else {
        // Kein Bild vorhanden: feste Hoehe passend zum Seitenverhaeltnis reservieren.
        bgImg.style.aspectRatio = canvasW() + ' / ' + canvasH();
    }

    buildBoxes();
})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>

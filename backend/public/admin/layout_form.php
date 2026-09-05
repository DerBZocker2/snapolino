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
    $slotRows = array_pad(array_slice($slotRows, 0, $slotCount), $slotCount, ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0]);
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
                'UPDATE layouts SET name = ?, slot_count = ?, canvas_width = ?, canvas_height = ?,
                 frame_file = ?, is_default = ?, surcharge_cents = ? WHERE id = ?'
            );
            $stmt->execute([$name, $slotCount, $canvasWidth, $canvasHeight, $frameFile, $isDefault ? 1 : 0, $surchargeCents, $layoutId]);
        } else {
            $stmt = db()->prepare(
                'INSERT INTO layouts (name, slot_count, canvas_width, canvas_height, frame_file, is_default, surcharge_cents)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $slotCount, $canvasWidth, $canvasHeight, $frameFile, $isDefault ? 1 : 0, $surchargeCents]);
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

        <label>Name
            <input type="text" name="name" required value="<?= htmlspecialchars($name, ENT_QUOTES) ?>">
        </label>

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
        <p>Koordinaten in Pixeln, bezogen auf die Leinwandgroesse oben. Kein
            visueller Editor &ndash; bitte anhand der Rahmen-PNG von Hand eintragen.</p>

        <div class="inline-form">
            <label>Anzahl Slots
                <input type="number" name="slot_count" id="slot_count" min="1" max="20" value="<?= count($slotRows) ?>">
            </label>
            <button type="submit" name="resize" value="1" formnovalidate>Anzahl aktualisieren</button>
        </div>

        <table id="slots-table">
            <thead>
            <tr><th>#</th><th>x</th><th>y</th><th>Breite</th><th>Hoehe</th></tr>
            </thead>
            <tbody>
            <?php foreach ($slotRows as $i => $row): ?>
                <tr>
                    <td><?= $i ?></td>
                    <td><input type="number" name="slots[<?= $i ?>][x]" value="<?= (int) $row['x'] ?>"></td>
                    <td><input type="number" name="slots[<?= $i ?>][y]" value="<?= (int) $row['y'] ?>"></td>
                    <td><input type="number" name="slots[<?= $i ?>][width]" value="<?= (int) $row['width'] ?>"></td>
                    <td><input type="number" name="slots[<?= $i ?>][height]" value="<?= (int) $row['height'] ?>"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <button type="submit" name="save" value="1">Layout speichern</button>
        <a href="layouts.php" class="button-secondary">Abbrechen</a>
    </form>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

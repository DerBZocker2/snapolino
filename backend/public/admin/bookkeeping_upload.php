<?php
declare(strict_types=1);

$pageTitle = 'Beleg hochladen';
require __DIR__ . '/_header.php';
require_once __DIR__ . '/../../includes/bookkeeping.php';

$errors = [];
$storageDir = bookkeeping_storage_dir();
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0750, true);
}

// Schritt 2 abgebrochen - verwaistes Beleg-Dokument aus Schritt 1 wieder
// entfernen, statt es ungenutzt liegen zu lassen.
if (isset($_POST['cancel'], $_POST['stored_filename'])) {
    check_csrf();
    $path = $storageDir . '/' . basename((string) $_POST['stored_filename']);
    if (is_file($path)) {
        unlink($path);
    }
    header('Location: bookkeeping_upload.php');
    exit;
}

$step = 1;
$guessed = [
    'direction' => 'ausgabe',
    'document_date' => date('Y-m-d'),
    'counterparty' => '',
    'description' => '',
    'category' => '',
    'amount' => '',
    'stored_filename' => '',
    'original_filename' => '',
    'mime_type' => '',
    'extracted_text' => '',
];

// Schritt 1: Datei hochladen, bestmoeglich auslesen, Formular vorausfuellen.
if (isset($_POST['upload'])) {
    check_csrf();
    $file = $_FILES['document'] ?? null;
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Bitte eine Datei auswaehlen.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Fehler beim Hochladen der Datei.';
    } elseif ($file['size'] > 15 * 1024 * 1024) {
        $errors[] = 'Die Datei darf maximal 15 MB gross sein.';
    } else {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
        if (!isset($allowed[$mime])) {
            $errors[] = 'Nur PDF-, JPG- oder PNG-Dateien werden unterstuetzt.';
        } else {
            $storedFilename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
            $destination = $storageDir . '/' . $storedFilename;
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                $errors[] = 'Datei konnte nicht gespeichert werden.';
            } else {
                $text = bookkeeping_extract_text($destination, $mime);
                $guessedAmount = bookkeeping_guess_amount_cents($text);
                $guessed = [
                    'direction' => 'ausgabe',
                    'document_date' => bookkeeping_guess_date($text) ?? date('Y-m-d'),
                    'counterparty' => bookkeeping_guess_counterparty($text) ?? '',
                    'description' => '',
                    'category' => '',
                    'amount' => $guessedAmount !== null ? number_format($guessedAmount / 100, 2, ',', '') : '',
                    'stored_filename' => $storedFilename,
                    'original_filename' => $file['name'],
                    'mime_type' => $mime,
                    'extracted_text' => $text,
                ];
                $step = 2;
            }
        }
    }
}

// Schritt 2: tatsaechliches Speichern - erst hier entsteht der
// Buchhaltungseintrag, nie automatisch nur aufgrund der Vorausfuellung.
if (isset($_POST['confirm'])) {
    check_csrf();
    $direction = in_array($_POST['direction'] ?? '', ['einnahme', 'ausgabe'], true) ? $_POST['direction'] : 'ausgabe';
    $documentDate = (string) ($_POST['document_date'] ?? '');
    $counterparty = trim((string) ($_POST['counterparty'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    $amountRaw = trim((string) ($_POST['amount'] ?? ''));
    $storedFilename = (string) ($_POST['stored_filename'] ?? '');
    $originalFilename = (string) ($_POST['original_filename'] ?? '');
    $mimeType = (string) ($_POST['mime_type'] ?? '');
    $extractedText = (string) ($_POST['extracted_text'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $documentDate)) {
        $errors[] = 'Bitte ein gueltiges Datum angeben.';
    }
    $amountCents = null;
    if ($amountRaw === '' || !preg_match('/^\d+(,\d{1,2})?$/', $amountRaw)) {
        $errors[] = 'Bitte einen gueltigen Betrag angeben (z.B. 19,99).';
    } else {
        $amountCents = bookkeeping_amount_to_cents($amountRaw);
    }

    if ($errors) {
        $step = 2;
        $guessed = [
            'direction' => $direction, 'document_date' => $documentDate, 'counterparty' => $counterparty,
            'description' => $description, 'category' => $category, 'amount' => $amountRaw,
            'stored_filename' => $storedFilename, 'original_filename' => $originalFilename,
            'mime_type' => $mimeType, 'extracted_text' => $extractedText,
        ];
    } else {
        $stmt = db()->prepare(
            'INSERT INTO bookkeeping_entries
                (direction, document_date, counterparty, description, category, amount_cents,
                 original_filename, stored_filename, mime_type, extracted_text)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $direction, $documentDate, $counterparty ?: null, $description ?: null, $category ?: null,
            $amountCents, $originalFilename ?: null, $storedFilename ?: null, $mimeType ?: null,
            $extractedText ?: null,
        ]);
        header('Location: bookkeeping.php?year=' . (int) substr($documentDate, 0, 4));
        exit;
    }
}
?>

<section class="panel">
    <?php foreach ($errors as $error): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endforeach; ?>

    <?php if ($step === 1): ?>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <label>Beleg (PDF, JPG oder PNG, max. 15 MB)
                <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png" required>
            </label>
            <p class="muted-text">
                Textbasierte PDFs werden automatisch ausgelesen und füllen das nächste
                Formular vor (Betrag, Datum, Absender) - Bilder nur, falls auf dem Server
                eine Texterkennung installiert ist. In jedem Fall prüfst du die Angaben im
                nächsten Schritt noch einmal, bevor etwas gespeichert wird.
            </p>
            <button type="submit" name="upload" value="1">Weiter</button>
        </form>
    <?php else: ?>
        <?php if (!$guessed['extracted_text']): ?>
            <p class="muted-text">
                Keine automatische Texterkennung möglich (keine Textebene im PDF gefunden,
                oder keine Texterkennung installiert) - bitte die Felder von Hand ausfüllen.
            </p>
        <?php else: ?>
            <p class="muted-text">
                Die Felder unten sind nur ein <strong>Vorschlag</strong> aus dem erkannten Text -
                bei unterschiedlichen Rechnungslayouts (Amazon, Behörden, Lieferanten...) kann
                das danebenliegen. Bitte jedes Feld gegen den unten einsehbaren erkannten Text
                prüfen und korrigieren, bevor du speicherst.
            </p>
            <details style="margin-bottom:16px;">
                <summary>Erkannten Text anzeigen (zum Abgleichen)</summary>
                <pre style="white-space:pre-wrap;max-height:300px;overflow:auto;background:var(--bg-subtle,#f5f5f7);padding:12px;border-radius:8px;font-size:13px;"><?= htmlspecialchars($guessed['extracted_text'], ENT_QUOTES) ?></pre>
            </details>
        <?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="stored_filename" value="<?= htmlspecialchars($guessed['stored_filename'], ENT_QUOTES) ?>">
            <input type="hidden" name="original_filename" value="<?= htmlspecialchars($guessed['original_filename'], ENT_QUOTES) ?>">
            <input type="hidden" name="mime_type" value="<?= htmlspecialchars($guessed['mime_type'], ENT_QUOTES) ?>">
            <input type="hidden" name="extracted_text" value="<?= htmlspecialchars($guessed['extracted_text'], ENT_QUOTES) ?>">

            <label>Richtung
                <select name="direction">
                    <option value="ausgabe" <?= $guessed['direction'] === 'ausgabe' ? 'selected' : '' ?>>Ausgabe (Eingangsrechnung - ich habe bezahlt)</option>
                    <option value="einnahme" <?= $guessed['direction'] === 'einnahme' ? 'selected' : '' ?>>Einnahme (Ausgangsrechnung - ich wurde bezahlt)</option>
                </select>
            </label>
            <label>Datum
                <input type="date" name="document_date" value="<?= htmlspecialchars($guessed['document_date'], ENT_QUOTES) ?>" required>
            </label>
            <label>Von/An (Firma, Person)
                <input type="text" name="counterparty" value="<?= htmlspecialchars($guessed['counterparty'], ENT_QUOTES) ?>" maxlength="150">
            </label>
            <label>Beschreibung
                <input type="text" name="description" value="<?= htmlspecialchars($guessed['description'], ENT_QUOTES) ?>" maxlength="255" placeholder="z.B. Fotopapier Selphy CP1500">
            </label>
            <label>Kategorie (optional)
                <input type="text" name="category" value="<?= htmlspecialchars($guessed['category'], ENT_QUOTES) ?>" maxlength="80" placeholder="z.B. Verbrauchsmaterial">
            </label>
            <label>Betrag in EUR (brutto)
                <input type="text" name="amount" value="<?= htmlspecialchars($guessed['amount'], ENT_QUOTES) ?>" placeholder="z.B. 19,99" required>
            </label>

            <div class="actions-row">
                <button type="submit" name="confirm" value="1">Speichern</button>
                <button type="submit" name="cancel" value="1" class="button-secondary">Abbrechen</button>
            </div>
        </form>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/_footer.php'; ?>

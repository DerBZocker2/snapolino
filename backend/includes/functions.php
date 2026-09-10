<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function random_key(int $bytes = 20): string
{
    return bin2hex(random_bytes($bytes));
}

// Erhoeht die config_version einer einzelnen Box, z.B. nach Aenderung
// der zugeordneten Layouts.
function bump_box_version(int $boxId): void
{
    $stmt = db()->prepare('UPDATE boxes SET config_version = config_version + 1 WHERE id = ?');
    $stmt->execute([$boxId]);
}

// Erhoeht die config_version aller Boxen, denen dieses Layout zugeordnet
// ist, z.B. nach Bearbeitung von Rahmen oder Slot-Koordinaten.
function bump_boxes_for_layout(int $layoutId): void
{
    $stmt = db()->prepare(
        'UPDATE boxes SET config_version = config_version + 1
         WHERE id IN (SELECT box_id FROM box_layouts WHERE layout_id = ?)'
    );
    $stmt->execute([$layoutId]);
}

function money_from_cents(int $cents): string
{
    return number_format($cents / 100, 2, ',', '.') . ' EUR';
}

// DateTime::format('l') liefert immer englische Wochentagsnamen, unabhaengig
// von setlocale() - deshalb hier per Hand uebersetzt statt ueber Locale.
function german_weekday(DateTimeInterface $date): string
{
    $names = [
        'Monday' => 'Montag', 'Tuesday' => 'Dienstag', 'Wednesday' => 'Mittwoch',
        'Thursday' => 'Donnerstag', 'Friday' => 'Freitag', 'Saturday' => 'Samstag',
        'Sunday' => 'Sonntag',
    ];

    return $names[$date->format('l')] ?? $date->format('l');
}

// Laedt ein Layout inkl. seiner Slot-Koordinaten (sortiert nach slot_index).
function fetch_layout_with_slots(int $layoutId): ?array
{
    $stmt = db()->prepare('SELECT * FROM layouts WHERE id = ?');
    $stmt->execute([$layoutId]);
    $layout = $stmt->fetch();
    if (!$layout) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM layout_slots WHERE layout_id = ? ORDER BY slot_index');
    $stmt->execute([$layoutId]);
    $layout['slots'] = $stmt->fetchAll();
    return $layout;
}

// Kundendesigns (Upload/Online-Designer, is_custom=1) sind nur der jeweiligen
// Buchung zugeordnet und sollen nicht in der oeffentlichen Galerie oder im
// allgemeinen Panel bei anderen Kunden auftauchen.
function fetch_all_layouts(bool $includeCustom = false): array
{
    $sql = 'SELECT * FROM layouts';
    if (!$includeCustom) {
        $sql .= ' WHERE is_custom = 0';
    }
    $sql .= ' ORDER BY is_default DESC, name';

    return db()->query($sql)->fetchAll();
}

// ---------- Eigene Kundendesigns (Upload/Online-Designer) ----------

// Prueft eine hochgeladene Datei aus $_FILES auf Groesse/Format. Liefert
// null wenn alles passt, sonst eine deutsche Fehlermeldung fuers Formular.
function validate_custom_design_upload(?array $file): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 'Bitte eine Datei auswählen.';
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return 'Die Datei ist zu groß fuer den Server (siehe upload_max_filesize in php.ini).';
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'Der Upload ist fehlgeschlagen. Bitte die Datei erneut auswählen.';
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        return 'Die Datei ist zu groß (maximal 10 MB).';
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || $info[2] !== IMAGETYPE_PNG) {
        return 'Bitte eine PNG-Datei hochladen.';
    }
    $ratio = $info[0] / $info[1];
    if ($ratio < 1.35 || $ratio > 1.7) {
        return 'Das Bild sollte im Querformat mit Seitenverhaeltnis 3:2 sein (wie ' . $info[0] . '×' . $info[1] . ' passt nicht), damit es zum 10x15cm-Ausdruck passt.';
    }
    return null;
}

// Sucht in einer PNG-Datei zusammenhaengende transparente Bereiche - das
// sind die Fotoflaechen, die der Kunde beim Gestalten seines Rahmens frei
// gelassen hat. Arbeitet auf einem verkleinerten Raster (Performance) und
// skaliert die gefundenen Rechtecke danach wieder auf die echte Aufloesung
// hoch. Liefert null, wenn keine brauchbaren Bereiche gefunden wurden.
function detect_transparent_slots(string $path, int $maxSlots = 12): ?array
{
    $info = @getimagesize($path);
    if (!$info || $info[2] !== IMAGETYPE_PNG) {
        return null;
    }
    [$width, $height] = $info;

    $img = @imagecreatefrompng($path);
    if (!$img) {
        return null;
    }
    imagepalettetotruecolor($img);
    imagealphablending($img, false);
    imagesavealpha($img, true);

    $stride = max(1, (int) ceil(max($width, $height) / 400));
    $gw = (int) ceil($width / $stride);
    $gh = (int) ceil($height / $stride);

    $transparent = array_fill(0, $gw * $gh, false);
    for ($gy = 0; $gy < $gh; $gy++) {
        $py = min($gy * $stride, $height - 1);
        for ($gx = 0; $gx < $gw; $gx++) {
            $px = min($gx * $stride, $width - 1);
            $rgba = imagecolorat($img, $px, $py);
            $alpha = ($rgba >> 24) & 0x7F; // GD: 0 = deckend, 127 = komplett transparent
            $transparent[$gy * $gw + $gx] = $alpha >= 100;
        }
    }
    imagedestroy($img);

    $visited = array_fill(0, $gw * $gh, false);
    $components = [];
    for ($sy = 0; $sy < $gh; $sy++) {
        for ($sx = 0; $sx < $gw; $sx++) {
            $startIdx = $sy * $gw + $sx;
            if (!$transparent[$startIdx] || $visited[$startIdx]) {
                continue;
            }

            $stack = [[$sx, $sy]];
            $visited[$startIdx] = true;
            $minX = $maxX = $sx;
            $minY = $maxY = $sy;
            $area = 0;

            while ($stack) {
                [$cx, $cy] = array_pop($stack);
                $area++;
                $minX = min($minX, $cx);
                $maxX = max($maxX, $cx);
                $minY = min($minY, $cy);
                $maxY = max($maxY, $cy);

                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                    $nx = $cx + $dx;
                    $ny = $cy + $dy;
                    if ($nx < 0 || $nx >= $gw || $ny < 0 || $ny >= $gh) {
                        continue;
                    }
                    $nidx = $ny * $gw + $nx;
                    if ($transparent[$nidx] && !$visited[$nidx]) {
                        $visited[$nidx] = true;
                        $stack[] = [$nx, $ny];
                    }
                }
            }

            if ($area < 12) {
                continue; // Rauschen/Anti-Aliasing-Reste ignorieren
            }
            $components[] = [
                'x' => (int) round($minX * $stride),
                'y' => (int) round($minY * $stride),
                'width' => (int) round(($maxX - $minX + 1) * $stride),
                'height' => (int) round(($maxY - $minY + 1) * $stride),
                'area' => $area,
            ];
        }
    }

    if (!$components) {
        return null;
    }

    usort($components, static fn (array $a, array $b) => $b['area'] <=> $a['area']);
    $components = array_slice($components, 0, $maxSlots);

    $bucket = max(1, (int) round($height * 0.04));
    usort($components, static function (array $a, array $b) use ($bucket) {
        return intdiv((int) $a['y'], $bucket) <=> intdiv((int) $b['y'], $bucket) ?: $a['x'] <=> $b['x'];
    });

    return ['width' => $width, 'height' => $height, 'slots' => array_values($components)];
}

// Loescht alle eigenen Kundendesigns (Upload/Online-Designer) einer Buchung
// samt Rahmen-Datei - genutzt sowohl beim Ersetzen durch ein neues eigenes
// Design als auch beim Wechsel zurueck auf die Fertige-Vorlage-Galerie,
// damit keine Karteileichen in storage/frames uebrig bleiben.
function delete_custom_layouts_for_booking(int $bookingId): void
{
    $cfg = backend_config();
    $storageDir = rtrim($cfg['storage_dir'], '/');

    $stmt = db()->prepare(
        "SELECT l.id, l.frame_file FROM booking_layouts bl
         INNER JOIN layouts l ON l.id = bl.layout_id
         WHERE bl.booking_id = ? AND l.is_custom = 1"
    );
    $stmt->execute([$bookingId]);
    foreach ($stmt->fetchAll() as $row) {
        db()->prepare('DELETE FROM layouts WHERE id = ?')->execute([(int) $row['id']]);
        $file = $storageDir . '/' . basename((string) $row['frame_file']);
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

// Legt aus einer fertigen PNG-Datei (Upload oder vom Online-Designer
// exportiert) ein neues Layout an, direkt einer Buchung zugeordnet. Ein
// vorheriges eigenes Design derselben Buchung wird ersetzt statt
// angehaeuft (samt Datei), damit beim mehrfachen Ausprobieren keine
// Karteileichen in storage/frames uebrig bleiben.
function save_custom_layout_for_booking(int $bookingId, string $sourcePath, array $slots, int $canvasWidth, int $canvasHeight, string $name): int
{
    $cfg = backend_config();
    $storageDir = rtrim($cfg['storage_dir'], '/');
    $frameFile = 'custom_' . random_key(16) . '.png';

    if (!copy($sourcePath, $storageDir . '/' . $frameFile)) {
        throw new RuntimeException('Konnte Design nicht speichern.');
    }

    db()->beginTransaction();

    delete_custom_layouts_for_booking($bookingId);

    $stmt = db()->prepare(
        'INSERT INTO layouts (name, slot_count, canvas_width, canvas_height, frame_file, is_default, is_custom, surcharge_cents)
         VALUES (?, ?, ?, ?, ?, 0, 1, 0)'
    );
    $stmt->execute([$name, count($slots), $canvasWidth, $canvasHeight, $frameFile]);
    $layoutId = (int) db()->lastInsertId();

    $slotStmt = db()->prepare('INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height) VALUES (?, ?, ?, ?, ?, ?)');
    foreach (array_values($slots) as $i => $slot) {
        $slotStmt->execute([$layoutId, $i, (int) $slot['x'], (int) $slot['y'], (int) $slot['width'], (int) $slot['height']]);
    }

    db()->prepare('INSERT INTO booking_layouts (booking_id, layout_id) VALUES (?, ?)')->execute([$bookingId, $layoutId]);

    db()->commit();

    return $layoutId;
}

// ---------- Buchungen ----------

// Eine Box ist ab Versand bis Rueckversand blockiert, nicht nur am
// Eventtag selbst. Fester Puffer vor und nach dem Eventdatum.
const BOOKING_BUFFER_DAYS = 3;

// Solange eine Reservierung nicht durch den ganzen Assistenten bis
// "angefragt" gelaufen ist, faellt sie nach dieser Frist wieder aus dem
// Kalender - ganz ohne Cronjob, einfach beim Abfragen der Sperrtage
// ignoriert (siehe fetch_blocked_dates).
const RESERVATION_HOLD_DAYS = 14;

const BOOKING_STATUSES = ['reserviert', 'angefragt', 'bestaetigt', 'abgelehnt', 'storniert'];

const BOOKING_STATUS_LABELS = [
    'reserviert' => 'Reserviert (unvollständig)',
    'angefragt'  => 'Angefragt',
    'bestaetigt' => 'Bestätigt',
    'abgelehnt'  => 'Abgelehnt',
    'storniert'  => 'Storniert',
];

function booking_status_label(string $status): string
{
    return BOOKING_STATUS_LABELS[$status] ?? $status;
}

// Blockierter Zeitraum (inkl. Versand-Puffer) fuer ein Eventdatum.
function booking_block_range(string $eventDate): array
{
    $event = new DateTimeImmutable($eventDate);
    return [
        $event->modify('-' . BOOKING_BUFFER_DAYS . ' days')->format('Y-m-d'),
        $event->modify('+' . BOOKING_BUFFER_DAYS . ' days')->format('Y-m-d'),
    ];
}

// Alle Tage, die aktuell blockiert sind (inkl. Puffer): vollstaendige
// Anfragen und bestaetigte Buchungen halten den Termin dauerhaft, eine
// blosse Reservierung (Schritt 2 des Assistenten) nur fuer
// RESERVATION_HOLD_DAYS - danach ist sie einfach verfallen.
function fetch_blocked_dates(): array
{
    $stmt = db()->prepare(
        "SELECT event_date FROM bookings
         WHERE status IN ('angefragt', 'bestaetigt')
            OR (status = 'reserviert' AND created_at >= ?)"
    );
    $stmt->execute([
        (new DateTimeImmutable('-' . RESERVATION_HOLD_DAYS . ' days'))->format('Y-m-d H:i:s'),
    ]);

    $blocked = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $eventDate) {
        [$start, $end] = booking_block_range($eventDate);
        $cursor = new DateTimeImmutable($start);
        $endDate = new DateTimeImmutable($end);
        while ($cursor <= $endDate) {
            $blocked[$cursor->format('Y-m-d')] = true;
            $cursor = $cursor->modify('+1 day');
        }
    }
    return array_keys($blocked);
}

function is_date_blocked(string $eventDate): bool
{
    return in_array($eventDate, fetch_blocked_dates(), true);
}

// ---------- Einstellungen ----------

function get_setting(string $name, ?string $default = null): ?string
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE name = ?');
    $stmt->execute([$name]);
    $value = $stmt->fetchColumn();
    return $value !== false ? $value : $default;
}

function set_setting(string $name, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );
    $stmt->execute([$name, $value]);
}

function base_price_cents(): int
{
    return (int) get_setting('base_price_cents', '0');
}

function base_price_label(): string
{
    return (string) get_setting('base_price_label', '');
}

// ---------- Extras ----------

function fetch_active_extras(): array
{
    return db()->query('SELECT * FROM extras WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();
}

function fetch_all_extras(): array
{
    return db()->query('SELECT * FROM extras ORDER BY sort_order, name')->fetchAll();
}

// Gesamtpreis: Basispreis + Aufpreis der gewuenschten Layouts + gewaehlte
// Extras (Menge * Preis, Extra-Preise duerfen negativ sein). Wird beim
// finalen Absenden serverseitig neu berechnet, nie der Client-Wert
// uebernommen. $extraSelections: [extra_id => quantity].
function calc_booking_total(array $layoutIds, array $extraSelections): int
{
    $total = base_price_cents();

    if ($layoutIds) {
        $placeholders = implode(',', array_fill(0, count($layoutIds), '?'));
        $stmt = db()->prepare("SELECT COALESCE(SUM(surcharge_cents), 0) FROM layouts WHERE id IN ($placeholders)");
        $stmt->execute(array_map('intval', $layoutIds));
        $total += (int) $stmt->fetchColumn();
    }

    foreach ($extraSelections as $extraId => $quantity) {
        $stmt = db()->prepare('SELECT price_cents FROM extras WHERE id = ? AND is_active = 1');
        $stmt->execute([(int) $extraId]);
        $price = $stmt->fetchColumn();
        if ($price !== false) {
            $total += (int) $price * max(1, (int) $quantity);
        }
    }

    return max(0, $total);
}

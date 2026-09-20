<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
// Fuer send_referral_reward_email() in reward_referral_owner_if_applicable()
// unten - assign_box_and_confirm() (und damit dieser Pfad) wird auch vom
// Admin-Panel (assign_box.php) aufgerufen, das mailer.php sonst nicht laedt.
require_once __DIR__ . '/mailer.php';

function random_key(int $bytes = 20): string
{
    return bin2hex(random_bytes($bytes));
}

// Haengt einen Versions-Query-Parameter an eine Asset-URL (CSS/JS), der sich
// bei jeder Aenderung der Datei automatisch mitaendert. Ohne das liefern
// CDNs/Browser (z.B. Cloudflares Standard-Edge-Cache fuer .css/.js) nach
// einem Deploy oft tagelang die alte Version aus, waehrend .php-Seiten
// laengst aktuell sind - sichtbar an einem kaputt wirkenden Layout mit
// neuer HTML-Struktur, aber altem Stylesheet.
function asset_url(string $urlPath, string $fsPath): string
{
    $version = is_file($fsPath) ? (string) filemtime($fsPath) : '0';

    return $urlPath . '?v=' . $version;
}

// Bestaetigt eine Buchung und weist ihr eine Box zu (bei genau einer Box
// automatisch, sonst muss $boxId uebergeben werden). Ueberfuehrt die
// gewuenschten Layouts nach box_layouts und erhoeht die config_version -
// gemeinsam genutzt vom Admin-Panel (manuelles Bestaetigen) und dem
// Stripe-Webhook (automatisches Bestaetigen nach Zahlung). Gibt false
// zurueck, wenn keine eindeutige Box bestimmt werden konnte.
function assign_box_and_confirm(int $bookingId, int $boxId = 0): bool
{
    if ($boxId <= 0) {
        $boxes = db()->query('SELECT id FROM boxes ORDER BY name')->fetchAll();
        if (count($boxes) !== 1) {
            return false;
        }
        $boxId = (int) $boxes[0]['id'];
    }

    $stmt = db()->prepare('SELECT status, coupon_code FROM bookings WHERE id = ?');
    $stmt->execute([$bookingId]);
    $before = $stmt->fetch();

    db()->beginTransaction();

    db()->prepare("UPDATE bookings SET status = 'bestaetigt', box_id = ? WHERE id = ?")
        ->execute([$boxId, $bookingId]);

    $stmt = db()->prepare('SELECT layout_id FROM booking_layouts WHERE booking_id = ?');
    $stmt->execute([$bookingId]);
    $layoutIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $ins = db()->prepare('INSERT IGNORE INTO box_layouts (box_id, layout_id, sort_order) VALUES (?, ?, 0)');
    foreach ($layoutIds as $layoutId) {
        $ins->execute([$boxId, (int) $layoutId]);
    }

    // Gutschein-Einloesung erst zaehlen, wenn die Buchung wirklich bestaetigt
    // wird (bezahlt oder Admin bestaetigt eine Angebots-Buchung) - nicht
    // schon beim blossen Eintippen im Assistenten, und nur einmal pro Buchung.
    $firstConfirmation = $before && $before['status'] !== 'bestaetigt';
    if ($firstConfirmation && !empty($before['coupon_code'])) {
        db()->prepare('UPDATE coupons SET redemption_count = redemption_count + 1 WHERE code = ?')
            ->execute([$before['coupon_code']]);
    }

    bump_box_version($boxId);
    db()->commit();

    // Empfehlungsprogramm: eigenen Empfehlungscode anlegen und, falls diese
    // Buchung selbst mit einem fremden Empfehlungscode bezahlt hat, die
    // werbende Person belohnen. Erst nach dem Commit (Mailversand ist ein
    // Netzwerkaufruf, soll keine offene Transaktion blockieren), und nur bei
    // der allerersten Bestaetigung dieser Buchung.
    if ($firstConfirmation) {
        ensure_referral_coupon_for_booking($bookingId);
        if (!empty($before['coupon_code'])) {
            reward_referral_owner_if_applicable($bookingId, (string) $before['coupon_code']);
        }
    }

    return true;
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

// Nach einer nachtraeglichen Admin-Aenderung an einer bereits einer Box
// zugeordneten Buchung (siehe admin/booking_detail.php): neu gewuenschte
// Layouts nach box_layouts uebertragen (wie beim urspruenglichen
// Bestaetigen, siehe assign_box_and_confirm()) und die config_version in
// jedem Fall erhoehen, damit auch reine Extra-/Datumsaenderungen (die
// api.php dynamisch mitliefert, aber am ?since-Preflight vorbei stumpf
// gecacht bleiben wuerden) beim naechsten Sync tatsaechlich ankommen.
function sync_booking_to_box(int $bookingId): void
{
    $stmt = db()->prepare('SELECT box_id FROM bookings WHERE id = ?');
    $stmt->execute([$bookingId]);
    $boxId = (int) $stmt->fetchColumn();
    if ($boxId <= 0) {
        return;
    }

    $stmt = db()->prepare('SELECT layout_id FROM booking_layouts WHERE booking_id = ?');
    $stmt->execute([$bookingId]);
    $layoutIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $ins = db()->prepare('INSERT IGNORE INTO box_layouts (box_id, layout_id, sort_order) VALUES (?, ?, 0)');
    foreach ($layoutIds as $layoutId) {
        $ins->execute([$boxId, (int) $layoutId]);
    }

    bump_box_version($boxId);
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

// Prueft vom Online-Designer eingesandte Slot-Positionen (nachdem der
// Kunde die Fotoflaechen im Editor verschoben hat). Liefert normalisierte
// Slots oder null, wenn die Daten nicht plausibel sind (z.B. manipuliert) -
// der Aufrufer faellt dann auf die Basis-Layout-Slots zurueck.
function validate_custom_slots(mixed $raw, int $canvasWidth, int $canvasHeight): ?array
{
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $slots = json_decode($raw, true);
    if (!is_array($slots) || !$slots) {
        return null;
    }

    $result = [];
    foreach ($slots as $slot) {
        if (!is_array($slot)) {
            return null;
        }
        foreach (['x', 'y', 'width', 'height'] as $field) {
            if (!isset($slot[$field]) || !is_numeric($slot[$field])) {
                return null;
            }
        }
        $x = (int) round((float) $slot['x']);
        $y = (int) round((float) $slot['y']);
        $width = (int) round((float) $slot['width']);
        $height = (int) round((float) $slot['height']);

        if ($width <= 0 || $height <= 0 || $x < 0 || $y < 0 || $x + $width > $canvasWidth || $y + $height > $canvasHeight) {
            return null;
        }

        $result[] = ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height];
    }

    return $result;
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

// ---------- Online-Galerie ----------

// Ordner fuer hochgeladene Event-Fotos (siehe upload_photo.php/gallery_photo.php).
// Eigener Konfigurationsschluessel, mit Fallback auf einen Ordner neben
// storage_dir - so funktioniert es auch, solange config.php auf dem Server
// noch nicht um gallery_storage_dir ergaenzt wurde.
function gallery_storage_dir(): string
{
    $cfg = backend_config();
    if (!empty($cfg['gallery_storage_dir'])) {
        return rtrim($cfg['gallery_storage_dir'], '/');
    }
    return rtrim(dirname(rtrim($cfg['storage_dir'], '/')), '/') . '/gallery';
}

// Erzeugt beim ersten Foto-Upload einer Buchung einmalig zwei unratbare
// Tokens fuer die oeffentliche Galerie (siehe galerie.php) - analog zu
// edit_token bei buchen.php. gallery_token ist der Verwalter-Link (Fotos
// ausblenden, sieht auch ausgeblendete Fotos und den Gaeste-Link),
// gallery_guest_token der separate, read-only Link zum Weitergeben an
// Gaeste. Bereits vorhandene Tokens bleiben unveraendert, damit einmal
// verschickte Links (Mail an den Kunden, ggf. von ihm weitergeleitet)
// weiter gelten. Gibt den Verwalter-Token zurueck.
function ensure_gallery_token(int $bookingId): string
{
    $stmt = db()->prepare('SELECT gallery_token, gallery_guest_token FROM bookings WHERE id = ?');
    $stmt->execute([$bookingId]);
    $row = $stmt->fetch();
    $token = (string) ($row['gallery_token'] ?? '');
    $guestToken = (string) ($row['gallery_guest_token'] ?? '');
    if ($token !== '' && $guestToken !== '') {
        return $token;
    }

    $token = $token !== '' ? $token : random_key(24);
    $guestToken = $guestToken !== '' ? $guestToken : random_key(24);
    db()->prepare('UPDATE bookings SET gallery_token = ?, gallery_guest_token = ? WHERE id = ?')
        ->execute([$token, $guestToken, $bookingId]);
    return $token;
}

// Absicherung fuer den (in der Praxis seltenen) Fall, dass eine Buchung
// noch einen Verwalter- aber keinen Gaeste-Token hat (z.B. vor Migration
// 0018 bereits erste Fotos hochgeladen). Ruft dafuer ensure_gallery_token()
// auf, statt die Erzeugungslogik zu duplizieren.
function ensure_gallery_guest_token(int $bookingId): string
{
    $stmt = db()->prepare('SELECT gallery_guest_token FROM bookings WHERE id = ?');
    $stmt->execute([$bookingId]);
    $token = (string) $stmt->fetchColumn();
    if ($token !== '') {
        return $token;
    }

    ensure_gallery_token($bookingId);
    $stmt->execute([$bookingId]);
    return (string) $stmt->fetchColumn();
}

function gallery_url(string $galleryToken): string
{
    $cfg = backend_config();
    return rtrim($cfg['base_url'], '/') . '/galerie.php?token=' . rawurlencode($galleryToken);
}

function gallery_guest_url(string $guestToken): string
{
    $cfg = backend_config();
    return rtrim($cfg['base_url'], '/') . '/galerie.php?token=' . rawurlencode($guestToken);
}

// Tage NACH DEM EVENTDATUM, nach denen bin/purge_expired_galleries.php die
// Galerie-Fotos einer Buchung endgueltig loescht (DSGVO), im Panel unter
// Einstellungen editierbar.
function gallery_retention_days(): int
{
    return max(1, (int) get_setting('gallery_retention_days', '30'));
}

function gallery_deletion_date(string $eventDate): DateTimeImmutable
{
    return (new DateTimeImmutable($eventDate))->modify('+' . gallery_retention_days() . ' days');
}

// Tage VOR DEM EVENTDATUM, ab denen bin/send_event_reminders.php die
// automatische Erinnerungsmail verschickt, im Panel unter Einstellungen
// editierbar.
function reminder_days_before_event(): int
{
    return max(0, (int) get_setting('reminder_days_before_event', '7'));
}

// ---------- Buchungen ----------

// Eine Box ist ab Versand bis Rueckversand blockiert, nicht nur am
// Eventtag selbst. Fester Puffer vor und nach dem Eventdatum.
const BOOKING_BUFFER_DAYS = 3;

const BOOKING_STATUSES = ['angefragt', 'bestaetigt', 'abgelehnt', 'storniert'];

const BOOKING_STATUS_LABELS = [
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

// Alle Tage, die aktuell blockiert sind (inkl. Puffer): jede echte
// Buchungsanfrage haelt den Termin dauerhaft, bis ein Admin sie ablehnt
// oder storniert - es gibt keine unverbindliche, automatisch verfallende
// Zwischenstufe mehr (nur eine Box, da lohnt sich Cronjob-freies Verfallen
// nicht - der Admin sichtet Anfragen ohnehin von Hand).
function fetch_blocked_dates(): array
{
    $stmt = db()->query("SELECT event_date FROM bookings WHERE status IN ('angefragt', 'bestaetigt')");

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

// ---------- Warteliste ----------

// Wird aufgerufen, nachdem eine Buchung eine bestimmte Belegung nicht mehr
// blockiert (Admin lehnt eine Anfrage ab oder storniert, siehe
// admin/bookings.php und payments.php::cancel_booking()). Prueft jeden Tag
// im zuvor durch diese Buchung blockierten Zeitraum (inkl. Versand-Puffer,
// siehe booking_block_range()) und benachrichtigt die Warteliste fuer jeden
// Tag, der nach der Freigabe tatsaechlich wieder frei ist (eine andere,
// weiterhin aktive Buchung koennte denselben Tag ueberlappend blockieren).
function notify_waitlist_for_freed_range(string $eventDate): void
{
    [$start, $end] = booking_block_range($eventDate);
    $cursor = new DateTimeImmutable($start);
    $endDate = new DateTimeImmutable($end);
    while ($cursor <= $endDate) {
        $day = $cursor->format('Y-m-d');
        if (!is_date_blocked($day)) {
            notify_waitlist_for_date($day);
        }
        $cursor = $cursor->modify('+1 day');
    }
}

function notify_waitlist_for_date(string $day): void
{
    $stmt = db()->prepare('SELECT * FROM waitlist_entries WHERE event_date = ? AND notified_at IS NULL');
    $stmt->execute([$day]);
    foreach ($stmt->fetchAll() as $entry) {
        if (send_waitlist_slot_free_email($entry)) {
            db()->prepare('UPDATE waitlist_entries SET notified_at = NOW() WHERE id = ?')->execute([$entry['id']]);
        }
    }
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

function booking_layout_ids(int $bookingId): array
{
    $stmt = db()->prepare('SELECT layout_id FROM booking_layouts WHERE booking_id = ?');
    $stmt->execute([$bookingId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// [extra_id => quantity]
function booking_extra_selections(int $bookingId): array
{
    $stmt = db()->prepare('SELECT extra_id, quantity FROM booking_extras WHERE booking_id = ?');
    $stmt->execute([$bookingId]);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[(int) $row['extra_id']] = (int) $row['quantity'];
    }
    return $result;
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

// Itemisierte Positionen einer Buchung (Basispreis, Layout-Aufpreise,
// Extras) - Grundlage sowohl fuer die Rechnung als auch fuer die
// Stripe-Checkout-Zeilen. Preise koennen negativ sein (Rabatt-Extras).
function booking_invoice_items(int $bookingId): array
{
    $items = [[
        'name' => base_price_label() ?: 'Fotobox-Miete',
        'unit_amount_cents' => base_price_cents(),
        'quantity' => 1,
    ]];

    $layoutIds = booking_layout_ids($bookingId);
    if ($layoutIds) {
        $placeholders = implode(',', array_fill(0, count($layoutIds), '?'));
        $stmt = db()->prepare("SELECT name, surcharge_cents FROM layouts WHERE id IN ($placeholders) AND surcharge_cents <> 0");
        $stmt->execute(array_map('intval', $layoutIds));
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'name' => 'Zusatzformat: ' . $row['name'],
                'unit_amount_cents' => (int) $row['surcharge_cents'],
                'quantity' => 1,
            ];
        }
    }

    $extraSelections = booking_extra_selections($bookingId);
    if ($extraSelections) {
        $placeholders = implode(',', array_fill(0, count($extraSelections), '?'));
        $stmt = db()->prepare("SELECT id, name, price_cents FROM extras WHERE id IN ($placeholders)");
        $stmt->execute(array_map('intval', array_keys($extraSelections)));
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'name' => $row['name'],
                'unit_amount_cents' => (int) $row['price_cents'],
                'quantity' => max(1, $extraSelections[(int) $row['id']]),
            ];
        }
    }

    $stmt = db()->prepare('SELECT coupon_code, discount_cents FROM bookings WHERE id = ?');
    $stmt->execute([$bookingId]);
    $couponRow = $stmt->fetch();
    if ($couponRow && (int) $couponRow['discount_cents'] > 0) {
        $items[] = [
            'name' => 'Rabatt' . ($couponRow['coupon_code'] ? ' (' . $couponRow['coupon_code'] . ')' : ''),
            'unit_amount_cents' => -(int) $couponRow['discount_cents'],
            'quantity' => 1,
        ];
    }

    return $items;
}

// Stripe erlaubt keine negativen Line-Item-Betraege (z.B. beim
// Rabatt-Extra "Ohne Druck"). Positive Posten bleiben erhalten, ein
// eventueller Rabatt wird vom groessten Posten abgezogen (der dabei auf
// Menge 1 kollabiert, um Rundung zu vermeiden) - die Summe entspricht
// danach exakt calc_booking_total().
function booking_stripe_line_items(int $bookingId): array
{
    $items = booking_invoice_items($bookingId);
    $positive = [];
    $discount = 0;
    foreach ($items as $item) {
        $lineTotal = $item['unit_amount_cents'] * $item['quantity'];
        if ($lineTotal >= 0) {
            $positive[] = $item;
        } else {
            $discount += -$lineTotal;
        }
    }

    if ($discount > 0 && $positive) {
        usort($positive, static fn (array $a, array $b) =>
            ($b['unit_amount_cents'] * $b['quantity']) <=> ($a['unit_amount_cents'] * $a['quantity']));
        foreach ($positive as &$item) {
            if ($discount <= 0) {
                break;
            }
            $lineTotal = $item['unit_amount_cents'] * $item['quantity'];
            $reduceBy = min($discount, $lineTotal);
            $item['unit_amount_cents'] = $lineTotal - $reduceBy;
            $item['quantity'] = 1;
            $discount -= $reduceBy;
        }
        unset($item);
    }

    return array_values(array_map(static fn (array $i) => [
        'name' => $i['name'],
        'amount_cents' => max(0, $i['unit_amount_cents']),
        'quantity' => $i['quantity'],
    ], array_filter($positive, static fn (array $i) => $i['unit_amount_cents'] > 0)));
}

// Adresszeilen fuer Rechnung/Admin-Ansicht, leere Felder werden ausgelassen.
function booking_address_lines(array $booking): array
{
    $lines = [];
    if (!empty($booking['customer_street'])) {
        $lines[] = (string) $booking['customer_street'];
    }
    $zipCity = trim((string) ($booking['customer_zip'] ?? '') . ' ' . (string) ($booking['customer_city'] ?? ''));
    if ($zipCity !== '') {
        $lines[] = $zipCity;
    }
    return $lines;
}

// ---------- Gutscheine ----------

// Liefert den Gutschein nur, wenn er aktuell einloesbar ist (aktiv, nicht
// abgelaufen, Kontingent nicht ausgeschoepft) - sonst null, unabhaengig
// davon, ob der Code ueberhaupt existiert (kein Unterschied fuer den Kunden
// zwischen "falscher Code" und "abgelaufener Code" noetig).
function find_active_coupon(string $code): ?array
{
    if ($code === '') {
        return null;
    }
    $stmt = db()->prepare(
        "SELECT * FROM coupons WHERE code = ? AND is_active = 1
         AND (valid_until IS NULL OR valid_until >= CURDATE())
         AND (max_redemptions IS NULL OR redemption_count < max_redemptions)"
    );
    $stmt->execute([strtoupper($code)]);
    return $stmt->fetch() ?: null;
}

function coupon_discount_cents(array $coupon, int $subtotalCents): int
{
    if ($coupon['discount_type'] === 'percent') {
        $discount = (int) round($subtotalCents * ((int) $coupon['discount_value'] / 100));
    } else {
        $discount = (int) $coupon['discount_value'];
    }
    return max(0, min($discount, $subtotalCents));
}

// Rechnet Zwischensumme, Rabatt und Endsumme fuer eine Buchung aus - Basis
// fuer sowohl das Einloesen im Assistenten als auch das finale Abschicken
// (Schritt 5), damit beide exakt denselben Betrag ermitteln.
function calc_booking_pricing(array $layoutIds, array $extraSelections, ?string $couponCode): array
{
    $subtotal = calc_booking_total($layoutIds, $extraSelections);
    $coupon = $couponCode ? find_active_coupon($couponCode) : null;
    $discount = $coupon ? coupon_discount_cents($coupon, $subtotal) : 0;

    return [
        'subtotal' => $subtotal,
        'coupon' => $coupon,
        'discount_cents' => $discount,
        'total' => max(0, $subtotal - $discount),
    ];
}

// ---------- Empfehlungsprogramm ----------

// Rabatt (Prozent) fuer die geworbene Person, die einen persoenlichen
// Empfehlungscode einloest.
function referral_discount_percent(): int
{
    return max(0, (int) get_setting('referral_discount_percent', '10'));
}

// Belohnung (Cent, als neuer Einmal-Gutschein) fuer die werbende Person,
// sobald eine mit ihrem Code geworbene Buchung bestaetigt wird.
function referral_reward_cents(): int
{
    return max(0, (int) get_setting('referral_reward_cents', '1500'));
}

// Erzeugt einen im Panel eindeutigen Gutscheincode mit gegebenem Praefix
// (z.B. "EMPFEHLUNG" oder "DANKE") plus 5 zufaelligen Grossbuchstaben/Ziffern.
function generate_unique_coupon_code(string $prefix): string
{
    $stmt = db()->prepare('SELECT 1 FROM coupons WHERE code = ?');
    do {
        $code = strtoupper($prefix) . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));
        $stmt->execute([$code]);
    } while ($stmt->fetchColumn() !== false);
    return $code;
}

// Legt bei der ersten Bestaetigung einer Buchung (siehe assign_box_and_confirm())
// deren persoenlichen Empfehlungscode an, falls noch keiner existiert, und
// gibt ihn zurueck - unbegrenzt oft einloesbar (mehrere Freunde koennen
// denselben Code nutzen), verfaellt nicht automatisch.
function ensure_referral_coupon_for_booking(int $bookingId): string
{
    $stmt = db()->prepare('SELECT code FROM coupons WHERE referral_owner_booking_id = ?');
    $stmt->execute([$bookingId]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        return (string) $existing;
    }

    $code = generate_unique_coupon_code('EMPFEHLUNG');
    db()->prepare(
        'INSERT INTO coupons (code, discount_type, discount_value, is_active, referral_owner_booking_id)
         VALUES (?, ?, ?, 1, ?)'
    )->execute([$code, 'percent', referral_discount_percent(), $bookingId]);

    return $code;
}

function get_referral_coupon_for_booking(int $bookingId): ?array
{
    $stmt = db()->prepare('SELECT * FROM coupons WHERE referral_owner_booking_id = ?');
    $stmt->execute([$bookingId]);
    return $stmt->fetch() ?: null;
}

// Wird aufgerufen, wenn eine Buchung, die selbst einen Gutscheincode
// eingeloest hat, zum ersten Mal bestaetigt wird (siehe assign_box_and_confirm()).
// War dieser Code ein persoenlicher Empfehlungscode einer anderen Buchung
// (referral_owner_booking_id), bekommt die werbende Person automatisch einen
// neuen, einmaligen Belohnungsgutschein per Mail - referral_reward_sent_at
// auf der geworbenen Buchung verhindert eine doppelte Belohnung. Ein
// Selbst-Werben mit der eigenen E-Mail-Adresse wird nicht belohnt.
function reward_referral_owner_if_applicable(int $referredBookingId, string $usedCouponCode): void
{
    $stmt = db()->prepare('SELECT referral_reward_sent_at, customer_email FROM bookings WHERE id = ?');
    $stmt->execute([$referredBookingId]);
    $referredBooking = $stmt->fetch();
    if (!$referredBooking || $referredBooking['referral_reward_sent_at'] !== null) {
        return;
    }

    $stmt = db()->prepare('SELECT * FROM coupons WHERE code = ? AND referral_owner_booking_id IS NOT NULL');
    $stmt->execute([strtoupper($usedCouponCode)]);
    $usedCoupon = $stmt->fetch();
    if (!$usedCoupon) {
        return;
    }

    $stmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
    $stmt->execute([(int) $usedCoupon['referral_owner_booking_id']]);
    $referrerBooking = $stmt->fetch();
    if (!$referrerBooking) {
        return;
    }

    if (strcasecmp((string) $referrerBooking['customer_email'], (string) $referredBooking['customer_email']) === 0) {
        db()->prepare('UPDATE bookings SET referral_reward_sent_at = NOW() WHERE id = ?')->execute([$referredBookingId]);
        return;
    }

    $rewardCode = generate_unique_coupon_code('DANKE');
    $rewardCents = referral_reward_cents();
    db()->prepare(
        'INSERT INTO coupons (code, discount_type, discount_value, max_redemptions, is_active)
         VALUES (?, ?, ?, 1, 1)'
    )->execute([$rewardCode, 'fixed', $rewardCents]);

    send_referral_reward_email($referrerBooking, $rewardCode, $rewardCents);
    db()->prepare('UPDATE bookings SET referral_reward_sent_at = NOW() WHERE id = ?')->execute([$referredBookingId]);
}

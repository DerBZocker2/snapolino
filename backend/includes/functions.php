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

function fetch_all_layouts(): array
{
    return db()->query('SELECT * FROM layouts ORDER BY is_default DESC, name')->fetchAll();
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

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

// Alle Tage, die aktuell durch bestaetigte Buchungen blockiert sind
// (inkl. Puffer). Nur "bestaetigt" blockiert den Kalender - eine blosse
// Anfrage reserviert noch nichts, das entscheidet der Admin.
function fetch_blocked_dates(): array
{
    $stmt = db()->query("SELECT event_date FROM bookings WHERE status = 'bestaetigt'");
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

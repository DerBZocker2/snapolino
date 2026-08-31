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

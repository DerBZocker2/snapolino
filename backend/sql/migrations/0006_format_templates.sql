-- Migration 0006: drei zusaetzliche Rahmen-Formate mit unterschiedlicher
-- Fotoanzahl (1 grosses Bild, 2 nebeneinander, 3 nebeneinander) - anders als
-- die bisherigen Presets NICHT mit der Standard-4er-Slot-Geometrie, jedes
-- Format hat seine eigenen Slot-Koordinaten (siehe unten).
-- Auf einer bereits laufenden Installation von Hand ausfuehren:
--   mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0006_format_templates.sql

INSERT INTO layouts (name, category, slot_count, canvas_width, canvas_height, frame_file, is_default, surcharge_cents)
SELECT * FROM (
    SELECT '1 Bild (Vollformat)'    AS name, 'Format' AS category, 1 AS slot_count, 1800 AS canvas_width, 1200 AS canvas_height, 'preset_format_1bild.png'    AS frame_file, 0 AS is_default, 300 AS surcharge_cents
    UNION ALL SELECT '2 Bilder nebeneinander', 'Format', 2, 1800, 1200, 'preset_format_2bilder.png', 0, 300
    UNION ALL SELECT '3 Bilder nebeneinander', 'Format', 3, 1800, 1200, 'preset_format_3bilder.png', 0, 300
) AS preset
WHERE NOT EXISTS (SELECT 1 FROM layouts WHERE layouts.name = preset.name);

INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT l.id, s.slot_index, s.x, s.y, s.width, s.height
FROM layouts l
CROSS JOIN (
    SELECT 0 AS slot_index, 40 AS x, 40 AS y, 1720 AS width, 1120 AS height
) AS s
WHERE l.name = '1 Bild (Vollformat)'
AND NOT EXISTS (SELECT 1 FROM layout_slots ls WHERE ls.layout_id = l.id);

INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT l.id, s.slot_index, s.x, s.y, s.width, s.height
FROM layouts l
CROSS JOIN (
    SELECT 0 AS slot_index, 40  AS x, 40 AS y, 850 AS width, 1120 AS height
    UNION ALL SELECT 1, 910, 40, 850, 1120
) AS s
WHERE l.name = '2 Bilder nebeneinander'
AND NOT EXISTS (SELECT 1 FROM layout_slots ls WHERE ls.layout_id = l.id);

INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT l.id, s.slot_index, s.x, s.y, s.width, s.height
FROM layouts l
CROSS JOIN (
    SELECT 0 AS slot_index, 40   AS x, 40 AS y, 560 AS width, 1120 AS height
    UNION ALL SELECT 1, 620, 40, 560, 1120
    UNION ALL SELECT 2, 1200, 40, 560, 1120
) AS s
WHERE l.name = '3 Bilder nebeneinander'
AND NOT EXISTS (SELECT 1 FROM layout_slots ls WHERE ls.layout_id = l.id);

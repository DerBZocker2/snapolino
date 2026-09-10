-- Migration 0004: 18 mitgelieferte Preset-Designs (Rahmen-PNGs liegen unter
-- storage/frames/preset_*.png, siehe .gitignore-Ausnahme) plus ein echtes
-- Design fuer das bisher nur als Platzhalter existierende Standardlayout.
-- Alle teilen sich die Slot-Koordinaten des Standardlayouts, sind also ohne
-- weiteres Zutun im Panel einsatzbereit. Idempotent: kann gefahrlos mehrfach
-- ausgefuehrt werden, ueberschreibt keine bereits vorhandenen Zeilen.
-- Auf einer bereits laufenden Installation von Hand ausfuehren:
--   mysql -u snapolino -p snapolino < backend/sql/migrations/0004_preset_designs.sql

ALTER TABLE layouts
    ADD COLUMN IF NOT EXISTS is_custom TINYINT(1) NOT NULL DEFAULT 0 AFTER is_default;

UPDATE layouts SET frame_file = 'preset_standard.png'
WHERE name = 'Standard 4er-Collage' AND is_default = 1 AND frame_file != 'preset_standard.png';

INSERT INTO layouts (name, category, slot_count, canvas_width, canvas_height, frame_file, is_default, surcharge_cents)
SELECT * FROM (
    SELECT 'Hochzeit Elegant'      AS name, 'Hochzeit'    AS category, 4 AS slot_count, 1800 AS canvas_width, 1200 AS canvas_height, 'preset_hochzeit_elegant.png'      AS frame_file, 0 AS is_default, 300 AS surcharge_cents
    UNION ALL SELECT 'Hochzeit Rustikal',     'Hochzeit',    4, 1800, 1200, 'preset_hochzeit_rustikal.png',      0, 300
    UNION ALL SELECT 'Hochzeit Modern',       'Hochzeit',    4, 1800, 1200, 'preset_hochzeit_modern.png',        0, 300
    UNION ALL SELECT 'Geburtstag Bunt',       'Geburtstag',  4, 1800, 1200, 'preset_geburtstag_bunt.png',        0, 0
    UNION ALL SELECT 'Geburtstag Kids',       'Geburtstag',  4, 1800, 1200, 'preset_geburtstag_kids.png',        0, 0
    UNION ALL SELECT 'Geburtstag Glamour',    'Geburtstag',  4, 1800, 1200, 'preset_geburtstag_glamour.png',     0, 0
    UNION ALL SELECT 'Business Klassisch',    'Business',    4, 1800, 1200, 'preset_business_klassisch.png',     0, 300
    UNION ALL SELECT 'Business Modern',       'Business',    4, 1800, 1200, 'preset_business_modern.png',        0, 300
    UNION ALL SELECT 'Silvester Party',       'Party',       4, 1800, 1200, 'preset_silvester.png',              0, 0
    UNION ALL SELECT 'Regenbogen',            'Party',       4, 1800, 1200, 'preset_regenbogen.png',             0, 0
    UNION ALL SELECT 'Sommerfest',            'Sommer',      4, 1800, 1200, 'preset_sommerfest.png',             0, 0
    UNION ALL SELECT 'Gartenparty',           'Sommer',      4, 1800, 1200, 'preset_gartenparty.png',            0, 0
    UNION ALL SELECT 'Babyparty Blau',        'Baby',        4, 1800, 1200, 'preset_baby_boy.png',               0, 0
    UNION ALL SELECT 'Babyparty Rosa',        'Baby',        4, 1800, 1200, 'preset_baby_girl.png',              0, 0
    UNION ALL SELECT 'Weihnachten Klassisch', 'Weihnachten', 4, 1800, 1200, 'preset_weihnachten_klassisch.png',  0, 0
    UNION ALL SELECT 'Weihnachten Elegant',   'Weihnachten', 4, 1800, 1200, 'preset_weihnachten_elegant.png',    0, 0
    UNION ALL SELECT 'Modern Schwarz',        'Neutral',     4, 1800, 1200, 'preset_neutral_schwarz.png',        0, 0
    UNION ALL SELECT 'Modern Weiss',          'Neutral',     4, 1800, 1200, 'preset_neutral_weiss.png',          0, 0
) AS preset
WHERE NOT EXISTS (SELECT 1 FROM layouts WHERE layouts.name = preset.name);

INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT l.id, s.slot_index, s.x, s.y, s.width, s.height
FROM layouts l
CROSS JOIN (
    SELECT 0 AS slot_index, 40  AS x, 40  AS y, 850 AS width, 550 AS height
    UNION ALL SELECT 1, 910, 40,  850, 550
    UNION ALL SELECT 2, 40,  610, 850, 550
    UNION ALL SELECT 3, 910, 610, 850, 550
) AS s
WHERE l.name IN (
    'Hochzeit Elegant', 'Hochzeit Rustikal', 'Hochzeit Modern',
    'Geburtstag Bunt', 'Geburtstag Kids', 'Geburtstag Glamour',
    'Business Klassisch', 'Business Modern',
    'Silvester Party', 'Regenbogen', 'Sommerfest', 'Gartenparty',
    'Babyparty Blau', 'Babyparty Rosa',
    'Weihnachten Klassisch', 'Weihnachten Elegant',
    'Modern Schwarz', 'Modern Weiss'
)
AND NOT EXISTS (SELECT 1 FROM layout_slots ls WHERE ls.layout_id = l.id);

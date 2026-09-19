-- Ueberarbeitete Preset-Rahmen (siehe backend/tools/generate_presets.py):
-- huebschere, abgerundete Fotoflaechen mit Passepartout-Rand statt eckiger
-- Vollfarbflaechen (die vorherige Version hatte an den Ecken zudem farbige
-- Deko-Punkte, die auf einem dunklen Testfoto wie ein Darstellungsfehler
-- aussahen), dazu vier unterschiedliche Foto-Anordnungen statt immer
-- desselben 2x2-Rasters sowie einige Designs mit Text/Icons passend zum
-- jeweiligen Anlass. Dateien tragen ein "_v2"-Suffix (statt den Namen
-- wiederzuverwenden), damit bereits synchronisierte Boxen die neuen Rahmen
-- automatisch nachladen - cloudsync.py auf der Box erkennt einen bereits
-- lokal vorhandenen Dateinamen sonst faelschlich als "aktuell" und wuerde nie
-- neu herunterladen.

UPDATE layouts SET frame_file = 'preset_standard_v2.png' WHERE name = 'Standard 4er-Collage';
UPDATE layouts SET frame_file = 'preset_hochzeit_elegant_v2.png' WHERE name = 'Hochzeit Elegant';
UPDATE layouts SET frame_file = 'preset_hochzeit_rustikal_v2.png' WHERE name = 'Hochzeit Rustikal';
UPDATE layouts SET frame_file = 'preset_hochzeit_modern_v2.png' WHERE name = 'Hochzeit Modern';
UPDATE layouts SET frame_file = 'preset_geburtstag_bunt_v2.png' WHERE name = 'Geburtstag Bunt';
UPDATE layouts SET frame_file = 'preset_geburtstag_kids_v2.png' WHERE name = 'Geburtstag Kids';
UPDATE layouts SET frame_file = 'preset_geburtstag_glamour_v2.png' WHERE name = 'Geburtstag Glamour';
UPDATE layouts SET frame_file = 'preset_business_klassisch_v2.png' WHERE name = 'Business Klassisch';
UPDATE layouts SET frame_file = 'preset_business_modern_v2.png' WHERE name = 'Business Modern';
UPDATE layouts SET frame_file = 'preset_silvester_v2.png' WHERE name = 'Silvester Party';
UPDATE layouts SET frame_file = 'preset_regenbogen_v2.png' WHERE name = 'Regenbogen';
UPDATE layouts SET frame_file = 'preset_sommerfest_v2.png' WHERE name = 'Sommerfest';
UPDATE layouts SET frame_file = 'preset_gartenparty_v2.png' WHERE name = 'Gartenparty';
UPDATE layouts SET frame_file = 'preset_baby_boy_v2.png' WHERE name = 'Babyparty Blau';
UPDATE layouts SET frame_file = 'preset_baby_girl_v2.png' WHERE name = 'Babyparty Rosa';
UPDATE layouts SET frame_file = 'preset_weihnachten_klassisch_v2.png' WHERE name = 'Weihnachten Klassisch';
UPDATE layouts SET frame_file = 'preset_weihnachten_elegant_v2.png' WHERE name = 'Weihnachten Elegant';
UPDATE layouts SET frame_file = 'preset_neutral_schwarz_v2.png' WHERE name = 'Modern Schwarz';
UPDATE layouts SET frame_file = 'preset_neutral_weiss_v2.png' WHERE name = 'Modern Weiss';
UPDATE layouts SET frame_file = 'preset_format_1bild_v2.png' WHERE name = '1 Bild (Vollformat)';
UPDATE layouts SET frame_file = 'preset_format_2bilder_v2.png' WHERE name = '2 Bilder nebeneinander';
UPDATE layouts SET frame_file = 'preset_format_3bilder_v2.png' WHERE name = '3 Bilder nebeneinander';

-- Alte, geteilte Slot-Koordinaten je Layout ersetzen (jede Anordnung hat
-- jetzt ihre eigene Geometrie statt dass sich alle 4-Bilder-Layouts
-- dieselben Koordinaten teilen).

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = 'Standard 4er-Collage';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 835 AS width, 535 AS height
    UNION ALL
    SELECT 1 AS slot_index, 915 AS x, 50 AS y, 835 AS width, 535 AS height
    UNION ALL
    SELECT 2 AS slot_index, 50 AS x, 615 AS y, 835 AS width, 535 AS height
    UNION ALL
    SELECT 3 AS slot_index, 915 AS x, 615 AS y, 835 AS width, 535 AS height
) AS s
WHERE layouts.name = 'Standard 4er-Collage';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = 'Hochzeit Elegant';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 1700 AS width, 650 AS height
    UNION ALL
    SELECT 1 AS slot_index, 50 AS x, 790 AS y, 553 AS width, 360 AS height
    UNION ALL
    SELECT 2 AS slot_index, 623 AS x, 790 AS y, 553 AS width, 360 AS height
    UNION ALL
    SELECT 3 AS slot_index, 1196 AS x, 790 AS y, 554 AS width, 360 AS height
) AS s
WHERE layouts.name = 'Hochzeit Elegant';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = 'Hochzeit Rustikal';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 950 AS width, 1050 AS height
    UNION ALL
    SELECT 1 AS slot_index, 1030 AS x, 50 AS y, 720 AS width, 337 AS height
    UNION ALL
    SELECT 2 AS slot_index, 1030 AS x, 407 AS y, 720 AS width, 337 AS height
    UNION ALL
    SELECT 3 AS slot_index, 1030 AS x, 764 AS y, 720 AS width, 336 AS height
) AS s
WHERE layouts.name = 'Hochzeit Rustikal';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = 'Hochzeit Modern';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 1700 AS width, 650 AS height
    UNION ALL
    SELECT 1 AS slot_index, 50 AS x, 790 AS y, 553 AS width, 360 AS height
    UNION ALL
    SELECT 2 AS slot_index, 623 AS x, 790 AS y, 553 AS width, 360 AS height
    UNION ALL
    SELECT 3 AS slot_index, 1196 AS x, 790 AS y, 554 AS width, 360 AS height
) AS s
WHERE layouts.name = 'Hochzeit Modern';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = 'Geburtstag Bunt';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 40 AS x, 170 AS y, 415 AS width, 990 AS height
    UNION ALL
    SELECT 1 AS slot_index, 475 AS x, 170 AS y, 415 AS width, 990 AS height
    UNION ALL
    SELECT 2 AS slot_index, 910 AS x, 170 AS y, 415 AS width, 990 AS height
    UNION ALL
    SELECT 3 AS slot_index, 1345 AS x, 170 AS y, 415 AS width, 990 AS height
) AS s
WHERE layouts.name = 'Geburtstag Bunt';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = 'Geburtstag Kids';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 950 AS width, 1050 AS height
    UNION ALL
    SELECT 1 AS slot_index, 1030 AS x, 50 AS y, 720 AS width, 337 AS height
    UNION ALL
    SELECT 2 AS slot_index, 1030 AS x, 407 AS y, 720 AS width, 337 AS height
    UNION ALL
    SELECT 3 AS slot_index, 1030 AS x, 764 AS y, 720 AS width, 336 AS height
) AS s
WHERE layouts.name = 'Geburtstag Kids';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = 'Geburtstag Glamour';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 1700 AS width, 650 AS height
    UNION ALL
    SELECT 1 AS slot_index, 50 AS x, 790 AS y, 553 AS width, 360 AS height
    UNION ALL
    SELECT 2 AS slot_index, 623 AS x, 790 AS y, 553 AS width, 360 AS height
    UNION ALL
    SELECT 3 AS slot_index, 1196 AS x, 790 AS y, 554 AS width, 360 AS height
) AS s
WHERE layouts.name = 'Geburtstag Glamour';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = 'Business Klassisch';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 835 AS width, 535 AS height
    UNION ALL
    SELECT 1 AS slot_index, 915 AS x, 50 AS y, 835 AS width, 535 AS height
    UNION ALL
    SELECT 2 AS slot_index, 50 AS x, 615 AS y, 835 AS width, 535 AS height
    UNION ALL
    SELECT 3 AS slot_index, 915 AS x, 615 AS y, 835 AS width, 535 AS height
) AS s
WHERE layouts.name = 'Business Klassisch';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = 'Business Modern';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 1700 AS width, 650 AS height
    UNION ALL
    SELECT 1 AS slot_index, 50 AS x, 790 AS y, 553 AS width, 360 AS height
    UNION ALL
    SELECT 2 AS slot_index, 623 AS x, 790 AS y, 553 AS width, 360 AS height
    UNION ALL
    SELECT 3 AS slot_index, 1196 AS x, 790 AS y, 554 AS width, 360 AS height
) AS s
WHERE layouts.name = 'Business Modern';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = 'Silvester Party';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 40 AS x, 170 AS y, 415 AS width, 990 AS height
    UNION ALL
    SELECT 1 AS slot_index, 475 AS x, 170 AS y, 415 AS width, 990 AS height
    UNION ALL
    SELECT 2 AS slot_index, 910 AS x, 170 AS y, 415 AS width, 990 AS height
    UNION ALL
    SELECT 3 AS slot_index, 1345 AS x, 170 AS y, 415 AS width, 990 AS height
) AS s
WHERE layouts.name = 'Silvester Party';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = 'Regenbogen';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 40 AS x, 170 AS y, 415 AS width, 990 AS height
    UNION ALL
    SELECT 1 AS slot_index, 475 AS x, 170 AS y, 415 AS width, 990 AS height
    UNION ALL
    SELECT 2 AS slot_index, 910 AS x, 170 AS y, 415 AS width, 990 AS height
    UNION ALL
    SELECT 3 AS slot_index, 1345 AS x, 170 AS y, 415 AS width, 990 AS height
) AS s
WHERE layouts.name = 'Regenbogen';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = 'Sommerfest';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 40 AS x, 170 AS y, 415 AS width, 990 AS height
    UNION ALL
    SELECT 1 AS slot_index, 475 AS x, 170 AS y, 415 AS width, 990 AS height
    UNION ALL
    SELECT 2 AS slot_index, 910 AS x, 170 AS y, 415 AS width, 990 AS height
    UNION ALL
    SELECT 3 AS slot_index, 1345 AS x, 170 AS y, 415 AS width, 990 AS height
) AS s
WHERE layouts.name = 'Sommerfest';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = 'Gartenparty';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 950 AS width, 1050 AS height
    UNION ALL
    SELECT 1 AS slot_index, 1030 AS x, 50 AS y, 720 AS width, 337 AS height
    UNION ALL
    SELECT 2 AS slot_index, 1030 AS x, 407 AS y, 720 AS width, 337 AS height
    UNION ALL
    SELECT 3 AS slot_index, 1030 AS x, 764 AS y, 720 AS width, 336 AS height
) AS s
WHERE layouts.name = 'Gartenparty';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = 'Babyparty Blau';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 950 AS width, 1050 AS height
    UNION ALL
    SELECT 1 AS slot_index, 1030 AS x, 50 AS y, 720 AS width, 337 AS height
    UNION ALL
    SELECT 2 AS slot_index, 1030 AS x, 407 AS y, 720 AS width, 337 AS height
    UNION ALL
    SELECT 3 AS slot_index, 1030 AS x, 764 AS y, 720 AS width, 336 AS height
) AS s
WHERE layouts.name = 'Babyparty Blau';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = 'Babyparty Rosa';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 950 AS width, 1050 AS height
    UNION ALL
    SELECT 1 AS slot_index, 1030 AS x, 50 AS y, 720 AS width, 337 AS height
    UNION ALL
    SELECT 2 AS slot_index, 1030 AS x, 407 AS y, 720 AS width, 337 AS height
    UNION ALL
    SELECT 3 AS slot_index, 1030 AS x, 764 AS y, 720 AS width, 336 AS height
) AS s
WHERE layouts.name = 'Babyparty Rosa';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = 'Weihnachten Klassisch';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 835 AS width, 535 AS height
    UNION ALL
    SELECT 1 AS slot_index, 915 AS x, 50 AS y, 835 AS width, 535 AS height
    UNION ALL
    SELECT 2 AS slot_index, 50 AS x, 615 AS y, 835 AS width, 535 AS height
    UNION ALL
    SELECT 3 AS slot_index, 915 AS x, 615 AS y, 835 AS width, 535 AS height
) AS s
WHERE layouts.name = 'Weihnachten Klassisch';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = 'Weihnachten Elegant';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 1700 AS width, 650 AS height
    UNION ALL
    SELECT 1 AS slot_index, 50 AS x, 790 AS y, 553 AS width, 360 AS height
    UNION ALL
    SELECT 2 AS slot_index, 623 AS x, 790 AS y, 553 AS width, 360 AS height
    UNION ALL
    SELECT 3 AS slot_index, 1196 AS x, 790 AS y, 554 AS width, 360 AS height
) AS s
WHERE layouts.name = 'Weihnachten Elegant';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = 'Modern Schwarz';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 835 AS width, 535 AS height
    UNION ALL
    SELECT 1 AS slot_index, 915 AS x, 50 AS y, 835 AS width, 535 AS height
    UNION ALL
    SELECT 2 AS slot_index, 50 AS x, 615 AS y, 835 AS width, 535 AS height
    UNION ALL
    SELECT 3 AS slot_index, 915 AS x, 615 AS y, 835 AS width, 535 AS height
) AS s
WHERE layouts.name = 'Modern Schwarz';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = 'Modern Weiss';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 835 AS width, 535 AS height
    UNION ALL
    SELECT 1 AS slot_index, 915 AS x, 50 AS y, 835 AS width, 535 AS height
    UNION ALL
    SELECT 2 AS slot_index, 50 AS x, 615 AS y, 835 AS width, 535 AS height
    UNION ALL
    SELECT 3 AS slot_index, 915 AS x, 615 AS y, 835 AS width, 535 AS height
) AS s
WHERE layouts.name = 'Modern Weiss';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = '1 Bild (Vollformat)';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 60 AS x, 60 AS y, 1680 AS width, 940 AS height
) AS s
WHERE layouts.name = '1 Bild (Vollformat)';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = '2 Bilder nebeneinander';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 897 AS width, 1100 AS height
    UNION ALL
    SELECT 1 AS slot_index, 1017 AS x, 50 AS y, 733 AS width, 1100 AS height
) AS s
WHERE layouts.name = '2 Bilder nebeneinander';

DELETE ls FROM layout_slots ls INNER JOIN layouts l ON l.id = ls.layout_id WHERE l.name = '3 Bilder nebeneinander';
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 90 AS y, 553 AS width, 1020 AS height
    UNION ALL
    SELECT 1 AS slot_index, 623 AS x, 90 AS y, 553 AS width, 1020 AS height
    UNION ALL
    SELECT 2 AS slot_index, 1196 AS x, 90 AS y, 554 AS width, 1020 AS height
) AS s
WHERE layouts.name = '3 Bilder nebeneinander';

-- Boxen, denen eines der ueberarbeiteten Layouts zugeordnet ist, muessen die
-- neuen Rahmen/Koordinaten beim naechsten Sync abholen (analog zu
-- bump_boxes_for_layout() in includes/functions.php).
UPDATE boxes SET config_version = config_version + 1
WHERE id IN (
    SELECT DISTINCT bl.box_id FROM box_layouts bl
    INNER JOIN layouts l ON l.id = bl.layout_id
    WHERE l.name IN ('Standard 4er-Collage', 'Hochzeit Elegant', 'Hochzeit Rustikal', 'Hochzeit Modern', 'Geburtstag Bunt', 'Geburtstag Kids', 'Geburtstag Glamour', 'Business Klassisch', 'Business Modern', 'Silvester Party', 'Regenbogen', 'Sommerfest', 'Gartenparty', 'Babyparty Blau', 'Babyparty Rosa', 'Weihnachten Klassisch', 'Weihnachten Elegant', 'Modern Schwarz', 'Modern Weiss', '1 Bild (Vollformat)', '2 Bilder nebeneinander', '3 Bilder nebeneinander')
);

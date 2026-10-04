-- Alle Layouts sind jetzt grundsaetzlich kostenlos - das Admin-Formular
-- (layout_form.php) bietet keinen Aufpreis mehr an, zusaetzliche Kosten
-- gehoeren stattdessen zu den Extras. Bestehende Aufpreise (bisher 1 Bild/
-- 2 Bilder) werden dafuer auf 0 zurueckgesetzt.
UPDATE layouts SET surcharge_cents = 0 WHERE surcharge_cents > 0;

-- Die drei Format-Rahmen (1/2/3 Bilder) hatten bisher ein Herz-Icon/
-- "Snapolino"-Schriftzug bzw. eine Filmstreifen-Perforation als Verzierung
-- (siehe tools/generate_presets.py) - jetzt bewusst schlicht ohne jede
-- Dekoration, da sie reiner Fotoanzahl-Zuschnitt ohne eigenes Design sein
-- sollen. Neues Versions-Suffix (_v3 statt _v2), damit bereits
-- synchronisierte Boxen die neue, schlichte Grafik tatsaechlich herunterladen
-- (siehe "Buchungssystem" in CLAUDE.md zum Versions-Suffix-Mechanismus).
UPDATE layouts SET frame_file = 'preset_format_1bild_v3.png' WHERE name = '1 Bild (Vollformat)';
UPDATE layouts SET frame_file = 'preset_format_2bilder_v3.png' WHERE name = '2 Bilder nebeneinander';
UPDATE layouts SET frame_file = 'preset_format_3bilder_v3.png' WHERE name = '3 Bilder nebeneinander';

UPDATE boxes SET config_version = config_version + 1
WHERE id IN (
    SELECT DISTINCT bl.box_id FROM box_layouts bl
    INNER JOIN layouts l ON l.id = bl.layout_id
    WHERE l.name IN ('1 Bild (Vollformat)', '2 Bilder nebeneinander', '3 Bilder nebeneinander')
);

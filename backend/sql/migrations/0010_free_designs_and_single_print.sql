-- Migration 0010: Designs kostenlos ausser 1-/2-Bild-Format, Einzeldruck-Extra
-- repariert (siehe CLAUDE.md, backend/README.md "Schnittstelle fuer die Box").
-- Auf einer bereits laufenden Installation von Hand ausfuehren:
--   mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0010_free_designs_and_single_print.sql

-- Alle Layouts kostenlos, ausser den 1- und 2-Bilder-Formaten. Bleibt pro
-- Layout im Panel unter Layouts weiterhin einzeln editierbar, das hier
-- setzt nur den Ausgangswert.
UPDATE layouts SET surcharge_cents = 0 WHERE slot_count NOT IN (1, 2);

-- "Mehrfachdruck" traf nie den Namen, den main.py sucht (fruehere Konstante
-- EXTRA_MULTI_COPY = "mehrfachabzug"), und ein Extra "Einzelne Bilder
-- drucken" (EXTRA_INDIVIDUAL_PRINTS) wurde nie geseedet - das Feature lief
-- also nie. main.py macht daraus jetzt ein einziges Extra: einfach anhaken,
-- am Ende der Session ein Foto auswaehlen und bis zu 3x einzeln
-- ausdrucken (kein zweites Extra fuer die Menge noetig, MAX_INDIVIDUAL_PRINT_COPIES
-- in main.py). Preis bleibt unveraendert, im Panel unter Extras anpassbar.
UPDATE extras
SET name = 'Einzelne Bilder drucken',
    description = 'Am Ende ein Foto auswaehlen und zusaetzlich bis zu 3x einzeln ausdrucken lassen',
    type = 'toggle',
    unit_label = NULL
WHERE name = 'Mehrfachdruck';

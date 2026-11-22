-- Preis-Pakete statt eines einzelnen Basispreises (settings.base_price_cents/
-- base_price_label) - Kunde waehlt genau ein Paket im Buchungsassistenten,
-- siehe "Pakete" in CLAUDE.md. package_extras markiert, welche Extras in
-- einem Paket kostenlos inklusive sind (z.B. "Online-Galerie" im Premium-
-- Paket) - dasselbe Extra bleibt fuer Basic weiterhin einzeln dazubuchbar.
-- Bestehende Buchungen bleiben bewusst package_id = NULL (kein
-- rueckwirkendes Zuordnen) - Preisberechnung/Anzeige faellt fuer diese
-- weiterhin auf den alten globalen Basispreis zurueck.
CREATE TABLE IF NOT EXISTS packages (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(100) NOT NULL,
    description      VARCHAR(255) NULL,
    price_cents      INT UNSIGNED NOT NULL,
    -- Rein informativer Hinweistext fuers Verpacken (z.B. "2x Fotopapier +
    -- 2x Farbband mitschicken") - keine Bestandsverwaltung, nur eine
    -- Packliste fuer den Admin, sichtbar in booking_detail.php.
    supplies_note    VARCHAR(255) NULL,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    sort_order       INT UNSIGNED NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS package_extras (
    package_id INT UNSIGNED NOT NULL,
    extra_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (package_id, extra_id),
    FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
    FOREIGN KEY (extra_id) REFERENCES extras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE bookings
    ADD COLUMN package_id INT UNSIGNED NULL AFTER total_price_cents,
    ADD FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL;

-- Drei Beispielpakete, Basic uebernimmt den bisherigen Basispreis -
-- Name/Preis/Inhalt ueber das Panel unter Pakete frei anpassbar.
INSERT INTO packages (name, description, price_cents, supplies_note, sort_order)
SELECT
    'Basic',
    'Fotobox mit Sofortdruck - die Collage wird einmal gedruckt.',
    COALESCE((SELECT CAST(value AS UNSIGNED) FROM settings WHERE name = 'base_price_cents'), 21900),
    '1x Fotopapier (108 Blatt) + 1x Farbband',
    10;

INSERT INTO packages (name, description, price_cents, supplies_note, sort_order) VALUES
    ('Premium', 'Wie Basic, zusätzlich die Online-Galerie zum Nachbestellen/Teilen der Fotos nach dem Event.', 26900, '1x Fotopapier (108 Blatt) + 1x Farbband', 20),
    ('Deluxe', 'Wie Premium, zusätzlich dürfen Gäste ihr Foto am Ende noch bis zu 3x extra ausdrucken lassen.', 32900, '2x Fotopapier (108 Blatt) + 2x Farbband', 30);

-- Online-Galerie in Premium und Deluxe kostenlos inkludieren (bleibt fuer
-- Basic ein separat zubuchbares Extra), "Einzelne Bilder drucken" nur in
-- Deluxe - jeweils nur falls der Extra-Seed aus schema.sql existiert.
INSERT INTO package_extras (package_id, extra_id)
SELECT p.id, e.id FROM packages p, extras e
WHERE p.name IN ('Premium', 'Deluxe') AND e.name = 'Online-Galerie';

INSERT INTO package_extras (package_id, extra_id)
SELECT p.id, e.id FROM packages p, extras e
WHERE p.name = 'Deluxe' AND e.name = 'Einzelne Bilder drucken';

-- Snapolino Cloud-Backend
-- Datenbankschema fuer Boxen, Layouts und die Zuordnung zwischen beiden.
-- Zeichensatz durchgehend utf8mb4, damit Umlaute in Namen keine Probleme machen.

CREATE TABLE IF NOT EXISTS admins (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS boxes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    box_key         VARCHAR(64)  NOT NULL UNIQUE,
    api_key         VARCHAR(64)  NOT NULL UNIQUE,
    admin_pin       VARCHAR(20)  NULL,
    name            VARCHAR(100) NOT NULL,
    note            TEXT NULL,
    config_version  INT UNSIGNED NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ein Layout beschreibt eine Collage-Vorlage: Rahmen-PNG, Leinwandgroesse
-- in Pixeln und die Anzahl der Foto-Slots (Slots stehen in layout_slots).
CREATE TABLE IF NOT EXISTS layouts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    category        VARCHAR(50) NULL,
    slot_count      TINYINT UNSIGNED NOT NULL DEFAULT 4,
    canvas_width    INT UNSIGNED NOT NULL,
    canvas_height   INT UNSIGNED NOT NULL,
    frame_file      VARCHAR(150) NOT NULL,
    is_default      TINYINT(1) NOT NULL DEFAULT 0,
    is_custom       TINYINT(1) NOT NULL DEFAULT 0,
    surcharge_cents INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Position und Groesse jedes einzelnen Foto-Slots auf der Leinwand.
CREATE TABLE IF NOT EXISTS layout_slots (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    layout_id   INT UNSIGNED NOT NULL,
    slot_index  TINYINT UNSIGNED NOT NULL,
    x           INT UNSIGNED NOT NULL,
    y           INT UNSIGNED NOT NULL,
    width       INT UNSIGNED NOT NULL,
    height      INT UNSIGNED NOT NULL,
    FOREIGN KEY (layout_id) REFERENCES layouts(id) ON DELETE CASCADE,
    UNIQUE KEY unique_slot (layout_id, slot_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Welche Layouts eine Box ausliefern darf. Das Standard-Layout (4er-Collage)
-- wird beim Anlegen einer Box automatisch zugeordnet, dazugebuchte Formate
-- kommen als weitere Zeilen dazu.
CREATE TABLE IF NOT EXISTS box_layouts (
    box_id      INT UNSIGNED NOT NULL,
    layout_id   INT UNSIGNED NOT NULL,
    sort_order  INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (box_id, layout_id),
    FOREIGN KEY (box_id) REFERENCES boxes(id) ON DELETE CASCADE,
    FOREIGN KEY (layout_id) REFERENCES layouts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Buchungen von der oeffentlichen Webseite, entstehen als "reserviert"
-- (Schritt 2 des Assistenten: nur Name/E-Mail/Datum, haelt den Termin),
-- werden zu "angefragt" sobald der Assistent komplett durchlaufen ist.
-- box_id bleibt leer, bis ein Admin die Anfrage bestaetigt und eine
-- physische Box zuordnet.
CREATE TABLE IF NOT EXISTS bookings (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    edit_token             VARCHAR(64) NULL UNIQUE,
    stripe_session_id      VARCHAR(255) NULL UNIQUE,
    stripe_payment_intent  VARCHAR(255) NULL,
    customer_name          VARCHAR(120) NOT NULL,
    customer_email         VARCHAR(190) NOT NULL,
    customer_phone         VARCHAR(40) NULL,
    customer_street        VARCHAR(150) NULL,
    customer_zip           VARCHAR(10) NULL,
    customer_city          VARCHAR(100) NULL,
    customer_company       VARCHAR(150) NULL,
    invoice_to_company     TINYINT(1) NOT NULL DEFAULT 0,
    coupon_code            VARCHAR(50) NULL,
    discount_cents         INT UNSIGNED NOT NULL DEFAULT 0,
    event_date             DATE NOT NULL,
    box_id                 INT UNSIGNED NULL,
    status                 VARCHAR(20) NOT NULL DEFAULT 'reserviert',
    message                TEXT NULL,
    admin_note             TEXT NULL,
    total_price_cents      INT UNSIGNED NULL,
    wants_quote            TINYINT(1) NOT NULL DEFAULT 0,
    paid_at                DATETIME NULL,
    invoice_number         VARCHAR(30) NULL UNIQUE,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (box_id) REFERENCES boxes(id) ON DELETE SET NULL,
    INDEX idx_event_date (event_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Gutscheincodes, im Panel unter "Gutscheine" angelegt. redemption_count
-- wird erst erhoeht, wenn eine Buchung tatsaechlich bestaetigt wird
-- (bezahlt oder Admin bestaetigt eine Angebots-Buchung), nicht schon beim
-- blossen Eingeben/Einloesen im Assistenten.
CREATE TABLE IF NOT EXISTS coupons (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code              VARCHAR(50) NOT NULL UNIQUE,
    discount_type     ENUM('percent', 'fixed') NOT NULL,
    discount_value    INT UNSIGNED NOT NULL,
    max_redemptions   INT UNSIGNED NULL,
    redemption_count  INT UNSIGNED NOT NULL DEFAULT 0,
    valid_until       DATE NULL,
    is_active         TINYINT(1) NOT NULL DEFAULT 1,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Atomarer Zaehler fuer fortlaufende Rechnungsnummern (ein Zaehler pro Jahr,
-- Format wird in PHP zu z.B. "2026-0001" zusammengesetzt).
CREATE TABLE IF NOT EXISTS invoice_counters (
    year        INT UNSIGNED PRIMARY KEY,
    next_number INT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Vom Kunden bei der Buchung gewuenschte Layouts (Standard ist immer dabei,
-- weitere Formate optional gegen Aufpreis - der Aufpreis-Betrag steht schon
-- in layouts.surcharge_cents, hier wird nur die Auswahl festgehalten).
CREATE TABLE IF NOT EXISTS booking_layouts (
    booking_id INT UNSIGNED NOT NULL,
    layout_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (booking_id, layout_id),
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (layout_id) REFERENCES layouts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin-verwaltete Zusatzoptionen fuer die Buchung (Preis darf negativ
-- sein, z.B. "Ohne Druck" als Rabatt-Option).
CREATE TABLE IF NOT EXISTS extras (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL,
    description  VARCHAR(255) NULL,
    icon         VARCHAR(10) NULL,
    price_cents  INT NOT NULL DEFAULT 0,
    type         VARCHAR(10) NOT NULL DEFAULT 'toggle',   -- 'toggle' oder 'quantity'
    unit_label   VARCHAR(30) NULL,                        -- z.B. "Tag" bei quantity
    max_quantity INT UNSIGNED NULL,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    sort_order   INT UNSIGNED NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS booking_extras (
    booking_id INT UNSIGNED NOT NULL,
    extra_id   INT UNSIGNED NOT NULL,
    quantity   INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (booking_id, extra_id),
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (extra_id) REFERENCES extras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Einfache Key-Value-Einstellungen, aktuell fuer den Basispreis.
CREATE TABLE IF NOT EXISTS settings (
    name  VARCHAR(60) PRIMARY KEY,
    value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (name, value) VALUES
    ('base_price_cents', '21900'),
    ('base_price_label', 'Basic · Mit Druck-Flatrate'),
    ('business_name', 'Bitte im Panel unter Einstellungen ausfuellen'),
    ('business_address', 'Straße Hausnummer\nPLZ Ort'),
    ('business_tax_note', 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.');

-- Beispiel-Extras zum Start, im Panel unter "Extras" frei anpassbar/loeschbar.
INSERT INTO extras (name, description, icon, price_cents, type, unit_label, sort_order) VALUES
    ('Express-Versand', 'Lieferung am naechsten Werktag - ideal fuer kurzfristige Events', '⚡', 2900, 'toggle', NULL, 10),
    ('Vollformat-Drucke', 'Fotos rahmenlos auf das gesamte Papier gedruckt - keine Collage', '🖼️', 7500, 'toggle', NULL, 20),
    ('Mehrfachdruck', 'Bis zu 3 Abzuege pro Foto - jeder Gast bekommt sein eigenes Exemplar', '📄', 5000, 'toggle', NULL, 30),
    ('Extra-Tage', 'Laenger mieten, gestaffelt pro Tag', '📅', 8000, 'quantity', 'Tag', 40),
    ('Online-Galerie', 'Online verfuegbar sobald die Fotobox zurueck ist - nicht live waehrend der Party', '🌐', 2000, 'toggle', NULL, 50),
    ('Live aufs Smartphone', 'Fotos direkt aufs Handy per QR-Code - sofort teilbar', '📱', 2900, 'toggle', NULL, 60),
    ('Ohne Druck', 'Fotobox ohne Druckfunktion - guenstiger, falls nur digitale Fotos gewuenscht sind', '♻️', -5000, 'toggle', NULL, 70);

-- Standardlayout: 4 Bilder als 2x2-Collage auf 10x15cm quer (1800x1200 px,
-- ca. 300dpi). Alle Presets unten teilen sich dieselben Slot-Koordinaten,
-- damit sie ohne weiteres Zutun im Panel sofort einsatzbereit sind - nur
-- der Rahmen (frame_file) unterscheidet sich. Die PNGs liegen als
-- mitgelieferte Design-Vorlagen unter storage/frames/preset_*.png (siehe
-- .gitignore-Ausnahme), echte Kunden-Uploads bleiben weiterhin ignoriert.
INSERT INTO layouts (name, category, slot_count, canvas_width, canvas_height, frame_file, is_default, surcharge_cents) VALUES
    ('Standard 4er-Collage',   NULL,          4, 1800, 1200, 'preset_standard.png',            1, 0),
    ('Hochzeit Elegant',       'Hochzeit',    4, 1800, 1200, 'preset_hochzeit_elegant.png',    0, 300),
    ('Hochzeit Rustikal',      'Hochzeit',    4, 1800, 1200, 'preset_hochzeit_rustikal.png',   0, 300),
    ('Hochzeit Modern',        'Hochzeit',    4, 1800, 1200, 'preset_hochzeit_modern.png',     0, 300),
    ('Geburtstag Bunt',        'Geburtstag',  4, 1800, 1200, 'preset_geburtstag_bunt.png',     0, 0),
    ('Geburtstag Kids',        'Geburtstag',  4, 1800, 1200, 'preset_geburtstag_kids.png',     0, 0),
    ('Geburtstag Glamour',     'Geburtstag',  4, 1800, 1200, 'preset_geburtstag_glamour.png',  0, 0),
    ('Business Klassisch',     'Business',    4, 1800, 1200, 'preset_business_klassisch.png',  0, 300),
    ('Business Modern',        'Business',    4, 1800, 1200, 'preset_business_modern.png',     0, 300),
    ('Silvester Party',        'Party',       4, 1800, 1200, 'preset_silvester.png',           0, 0),
    ('Regenbogen',             'Party',       4, 1800, 1200, 'preset_regenbogen.png',          0, 0),
    ('Sommerfest',             'Sommer',      4, 1800, 1200, 'preset_sommerfest.png',          0, 0),
    ('Gartenparty',            'Sommer',      4, 1800, 1200, 'preset_gartenparty.png',         0, 0),
    ('Babyparty Blau',         'Baby',        4, 1800, 1200, 'preset_baby_boy.png',            0, 0),
    ('Babyparty Rosa',         'Baby',        4, 1800, 1200, 'preset_baby_girl.png',           0, 0),
    ('Weihnachten Klassisch',  'Weihnachten', 4, 1800, 1200, 'preset_weihnachten_klassisch.png', 0, 0),
    ('Weihnachten Elegant',    'Weihnachten', 4, 1800, 1200, 'preset_weihnachten_elegant.png', 0, 0),
    ('Modern Schwarz',         'Neutral',     4, 1800, 1200, 'preset_neutral_schwarz.png',     0, 0),
    ('Modern Weiss',           'Neutral',     4, 1800, 1200, 'preset_neutral_weiss.png',       0, 0);

INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height
FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 40  AS x, 40  AS y, 850 AS width, 550 AS height
    UNION ALL SELECT 1, 910, 40,  850, 550
    UNION ALL SELECT 2, 40,  610, 850, 550
    UNION ALL SELECT 3, 910, 610, 850, 550
) AS s;

-- Zusatzformate mit anderer Fotoanzahl statt der Standard-4er-Collage -
-- jeweils eigene Slot-Geometrie, siehe migrations/0006_format_templates.sql.
INSERT INTO layouts (name, category, slot_count, canvas_width, canvas_height, frame_file, is_default, surcharge_cents) VALUES
    ('1 Bild (Vollformat)',    'Format', 1, 1800, 1200, 'preset_format_1bild.png',    0, 300),
    ('2 Bilder nebeneinander', 'Format', 2, 1800, 1200, 'preset_format_2bilder.png', 0, 300),
    ('3 Bilder nebeneinander', 'Format', 3, 1800, 1200, 'preset_format_3bilder.png', 0, 300);

INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, 0, 40, 40, 1720, 1120 FROM layouts WHERE name = '1 Bild (Vollformat)';

INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height
FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 40 AS x, 40 AS y, 850 AS width, 1120 AS height
    UNION ALL SELECT 1, 910, 40, 850, 1120
) AS s
WHERE layouts.name = '2 Bilder nebeneinander';

INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height
FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 40   AS x, 40 AS y, 560 AS width, 1120 AS height
    UNION ALL SELECT 1, 620, 40, 560, 1120
    UNION ALL SELECT 2, 1200, 40, 560, 1120
) AS s
WHERE layouts.name = '3 Bilder nebeneinander';

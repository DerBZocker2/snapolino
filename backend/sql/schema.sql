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
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    edit_token        VARCHAR(64) NULL UNIQUE,
    customer_name     VARCHAR(120) NOT NULL,
    customer_email    VARCHAR(190) NOT NULL,
    customer_phone    VARCHAR(40) NULL,
    customer_address  TEXT NULL,
    event_date        DATE NOT NULL,
    box_id            INT UNSIGNED NULL,
    status            VARCHAR(20) NOT NULL DEFAULT 'reserviert',
    message           TEXT NULL,
    admin_note        TEXT NULL,
    total_price_cents INT UNSIGNED NULL,
    wants_quote       TINYINT(1) NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (box_id) REFERENCES boxes(id) ON DELETE SET NULL,
    INDEX idx_event_date (event_date),
    INDEX idx_status (status)
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
    ('base_price_label', 'Basic · Mit Druck-Flatrate');

-- Beispiel-Extras zum Start, im Panel unter "Extras" frei anpassbar/loeschbar.
INSERT INTO extras (name, description, icon, price_cents, type, unit_label, sort_order) VALUES
    ('Express-Versand', 'Lieferung am naechsten Werktag - ideal fuer kurzfristige Events', '⚡', 2900, 'toggle', NULL, 10),
    ('Vollformat-Drucke', 'Fotos rahmenlos auf das gesamte Papier gedruckt - keine Collage', '🖼️', 7500, 'toggle', NULL, 20),
    ('Mehrfachdruck', 'Bis zu 3 Abzuege pro Foto - jeder Gast bekommt sein eigenes Exemplar', '📄', 5000, 'toggle', NULL, 30),
    ('Extra-Tage', 'Laenger mieten, gestaffelt pro Tag', '📅', 8000, 'quantity', 'Tag', 40),
    ('Online-Galerie', 'Online verfuegbar sobald die Fotobox zurueck ist - nicht live waehrend der Party', '🌐', 2000, 'toggle', NULL, 50),
    ('Live aufs Smartphone', 'Fotos direkt aufs Handy per QR-Code - sofort teilbar', '📱', 2900, 'toggle', NULL, 60),
    ('Ohne Druck', 'Fotobox ohne Druckfunktion - guenstiger, falls nur digitale Fotos gewuenscht sind', '♻️', -5000, 'toggle', NULL, 70);

-- Beispiel-Standardlayout: 4 Bilder als 2x2-Collage auf 10x15cm quer
-- (1800x1200 px, ca. 300dpi). Koordinaten sind nur ein Platzhalter und
-- sollten im Panel an die tatsaechliche Rahmen-PNG angepasst werden.
INSERT INTO layouts (name, slot_count, canvas_width, canvas_height, frame_file, is_default, surcharge_cents)
VALUES ('Standard 4er-Collage', 4, 1800, 1200, 'standard_4er.png', 1, 0);

INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height) VALUES
    (LAST_INSERT_ID(), 0, 40,  40, 850, 550),
    (LAST_INSERT_ID(), 1, 910, 40, 850, 550),
    (LAST_INSERT_ID(), 2, 40,  610, 850, 550),
    (LAST_INSERT_ID(), 3, 910, 610, 850, 550);

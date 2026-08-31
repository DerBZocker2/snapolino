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

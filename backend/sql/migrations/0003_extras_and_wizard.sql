-- Migration 0003: mehrstufiger Buchungs-Assistent (Reservierung -> Design
-- -> Extras -> Zusammenfassung), admin-verwaltete Extras und Preise.
-- Auf einer bereits laufenden Installation von Hand ausfuehren:
--   mysql -u snapolino -p snapolino < backend/sql/migrations/0003_extras_and_wizard.sql

ALTER TABLE bookings
    MODIFY customer_address TEXT NULL,
    ADD COLUMN edit_token VARCHAR(64) NULL UNIQUE AFTER id,
    ADD COLUMN total_price_cents INT UNSIGNED NULL AFTER admin_note,
    ADD COLUMN wants_quote TINYINT(1) NOT NULL DEFAULT 0 AFTER total_price_cents;

ALTER TABLE layouts
    ADD COLUMN category VARCHAR(50) NULL AFTER name;

-- Admin-verwaltete Zusatzoptionen fuer die Buchung (Preis darf negativ sein,
-- z.B. "Ohne Druck" als Rabatt-Option).
CREATE TABLE IF NOT EXISTS extras (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    icon        VARCHAR(10) NULL,
    price_cents INT NOT NULL DEFAULT 0,
    type        VARCHAR(10) NOT NULL DEFAULT 'toggle',   -- 'toggle' oder 'quantity'
    unit_label  VARCHAR(30) NULL,                        -- z.B. "Tag" bei quantity
    max_quantity INT UNSIGNED NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  INT UNSIGNED NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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

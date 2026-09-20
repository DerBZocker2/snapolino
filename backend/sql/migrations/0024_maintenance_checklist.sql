-- Wartungs-Checkliste im Panel zwischen Vermietungen: eine gemeinsame Liste
-- von Wartungspunkten (im Panel unter Boxen verwaltbar), deren Erledigung
-- pro Box einzeln getrackt wird. Hebt ein Admin die Buchungs-Zuordnung
-- einer Box auf (siehe boxes.php, "Zuordnung aufheben" - haeufig der
-- Moment, in dem die Box vom Kunden zurueckkommt), werden alle Haekchen
-- dieser Box automatisch zurueckgesetzt, damit vor der naechsten
-- Vermietung erneut geprueft wird.
CREATE TABLE IF NOT EXISTS maintenance_checklist_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    sort_order  INT UNSIGNED NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS box_maintenance_checks (
    box_id      INT UNSIGNED NOT NULL,
    item_id     INT UNSIGNED NOT NULL,
    checked_at  DATETIME NULL,
    PRIMARY KEY (box_id, item_id),
    FOREIGN KEY (box_id) REFERENCES boxes(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES maintenance_checklist_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO maintenance_checklist_items (name, sort_order) VALUES
    ('Gehäuse und Bildschirm gereinigt', 10),
    ('Druckerpapier und Farbband kontrolliert', 20),
    ('Kamera-Fokus und Bildqualität getestet', 30),
    ('Kabel und Akkus kontrolliert', 40),
    ('USB-Sticks/Zubehör vollständig und zurückgelegt', 50),
    ('Testfoto gedruckt und geprüft', 60);

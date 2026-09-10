-- Migration 0002: Buchungssystem.
-- Auf einer bereits laufenden Installation von Hand ausfuehren:
--   mysql -u snapolino -p snapolino < backend/sql/migrations/0002_bookings.sql
-- Nutzt ueberall IF NOT EXISTS, ist also gefahrlos auch bei einer
-- Neuinstallation (schema.sql enthaelt dieselben Tabellen bereits).

CREATE TABLE IF NOT EXISTS bookings (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_name    VARCHAR(120) NOT NULL,
    customer_email   VARCHAR(190) NOT NULL,
    customer_phone   VARCHAR(40) NULL,
    customer_address TEXT NOT NULL,
    event_date       DATE NOT NULL,
    box_id           INT UNSIGNED NULL,
    status           VARCHAR(20) NOT NULL DEFAULT 'angefragt',
    message          TEXT NULL,
    admin_note       TEXT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (box_id) REFERENCES boxes(id) ON DELETE SET NULL,
    INDEX idx_event_date (event_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Vom Kunden bei der Buchung gewuenschte Layouts (Standard ist immer dabei,
-- weitere Formate optional gegen Aufpreis - Aufpreis-Betrag steht schon in
-- layouts.surcharge_cents, hier wird nur die Auswahl festgehalten).
CREATE TABLE IF NOT EXISTS booking_layouts (
    booking_id INT UNSIGNED NOT NULL,
    layout_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (booking_id, layout_id),
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (layout_id) REFERENCES layouts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Warteliste fuer bereits ausgebuchte Termine: Interessenten tragen sich
-- fuer einen konkreten Wunschtermin ein (oeffentlich unter warteliste.php,
-- auch direkt per Klick auf einen ausgegrauten Tag im Buchungskalender
-- erreichbar). Wird der Termin durch eine Ablehnung/Stornierung wieder
-- frei, benachrichtigt notify_waitlist_for_freed_range() (functions.php)
-- automatisch alle noch nicht benachrichtigten Eintraege dieses Tages per
-- Mail (send_waitlist_slot_free_email()) und setzt notified_at.
CREATE TABLE IF NOT EXISTS waitlist_entries (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_date     DATE NOT NULL,
    customer_name  VARCHAR(120) NOT NULL,
    customer_email VARCHAR(190) NOT NULL,
    customer_phone VARCHAR(40) NULL,
    note           TEXT NULL,
    notified_at    DATETIME NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_waitlist_event_date (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

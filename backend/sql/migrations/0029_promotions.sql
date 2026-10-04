-- Rabattaktionen: anders als Gutscheine (coupons) kein Code noetig, sondern
-- automatisch angewendet, solange sie aktiv und im Gueltigkeitszeitraum
-- sind - entweder auf die gesamte Buchung (scope "all") oder nur auf
-- bestimmte Extras (scope "extras", siehe promotion_extras). Siehe
-- fetch_active_promotions()/promotion_discount_for_selection() in
-- includes/functions.php.
CREATE TABLE IF NOT EXISTS promotions (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(100) NOT NULL,
    discount_type  ENUM('percent', 'fixed') NOT NULL,
    discount_value INT UNSIGNED NOT NULL,
    scope          ENUM('all', 'extras') NOT NULL DEFAULT 'all',
    valid_from     DATE NULL,
    valid_until    DATE NULL,
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS promotion_extras (
    promotion_id INT UNSIGNED NOT NULL,
    extra_id     INT UNSIGNED NOT NULL,
    PRIMARY KEY (promotion_id, extra_id),
    FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
    FOREIGN KEY (extra_id) REFERENCES extras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Wie bei coupon_code/discount_cents wird der zum Buchungszeitpunkt
-- tatsaechlich gewaehrte Rabattaktions-Betrag auf der Buchung eingefroren
-- (eine Aktion kann spaeter enden/geaendert werden, ohne die Rechnung
-- rueckwirkend zu veraendern). applied_promotions_json haelt Name+Betrag
-- jeder angewendeten Aktion fest, damit booking_invoice_items() benannte
-- Rechnungszeilen erzeugen kann, ohne die (evtl. inzwischen abgelaufene)
-- Aktion erneut nachschlagen zu muessen.
ALTER TABLE bookings
    ADD COLUMN promotion_discount_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER returning_discount_cents,
    ADD COLUMN applied_promotions_json TEXT NULL AFTER promotion_discount_cents;

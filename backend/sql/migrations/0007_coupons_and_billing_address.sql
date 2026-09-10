-- Migration 0007: Gutscheincodes + strukturierte Rechnungsadresse statt
-- einem einzelnen Freitext-Adressfeld (Schritt 5 im Buchungsassistenten).
-- Auf einer bereits laufenden Installation von Hand ausfuehren:
--   mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0007_coupons_and_billing_address.sql

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

ALTER TABLE bookings
    ADD COLUMN customer_street  VARCHAR(150) NULL AFTER customer_address,
    ADD COLUMN customer_zip     VARCHAR(10)  NULL AFTER customer_street,
    ADD COLUMN customer_city    VARCHAR(100) NULL AFTER customer_zip,
    ADD COLUMN customer_company VARCHAR(150) NULL AFTER customer_city,
    ADD COLUMN invoice_to_company TINYINT(1) NOT NULL DEFAULT 0 AFTER customer_company,
    ADD COLUMN coupon_code      VARCHAR(50)  NULL AFTER invoice_to_company,
    ADD COLUMN discount_cents   INT UNSIGNED NOT NULL DEFAULT 0 AFTER coupon_code;

-- Bisherige Freitextadresse bestmoeglich in die neue Strassenzeile
-- uebernehmen (PLZ/Ort blieben darin unstrukturiert und muessten von Hand
-- nachgetragen werden, betrifft nur Alt-Buchungen aus der Testphase).
UPDATE bookings SET customer_street = customer_address
WHERE customer_address IS NOT NULL AND customer_address <> '' AND customer_street IS NULL;

ALTER TABLE bookings DROP COLUMN customer_address;

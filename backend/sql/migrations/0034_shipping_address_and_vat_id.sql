-- Getrennte Lieferanschrift (wohin die Box geschickt wird) von der
-- Rechnungsanschrift (customer_street/zip/city - war bisher immer
-- identisch), sowie eine optionale USt-IdNr. fuer Firmenrechnungen.
-- Siehe "Rechnungsadresse" in CLAUDE.md und buchen.php Schritt 5.
ALTER TABLE bookings
    ADD COLUMN ship_to_different_address TINYINT(1) NOT NULL DEFAULT 0 AFTER invoice_to_company,
    ADD COLUMN billing_vat_id VARCHAR(20) NULL AFTER customer_company,
    ADD COLUMN shipping_street VARCHAR(150) NULL AFTER ship_to_different_address,
    ADD COLUMN shipping_zip VARCHAR(10) NULL AFTER shipping_street,
    ADD COLUMN shipping_city VARCHAR(100) NULL AFTER shipping_zip;

-- Kundenverwaltung mit Kundennummer (admin/customers.php): customer_accounts
-- bekommt eine fortlaufende, menschenlesbare Kundennummer sowie Name/
-- Telefon/interne Notiz als eigene Felder, statt nur als Login-Mechanismus
-- fuer konto.php zu dienen - analog zur "Kunden"-Funktion in Shopify.
ALTER TABLE customer_accounts
    ADD COLUMN customer_number VARCHAR(20) NULL UNIQUE AFTER id,
    ADD COLUMN name VARCHAR(120) NULL AFTER email,
    ADD COLUMN phone VARCHAR(40) NULL AFTER name,
    ADD COLUMN notes TEXT NULL AFTER phone;

-- Fortlaufende Kundennummer aus der vorhandenen ID ableiten (K-00001 usw.) -
-- kollisionsfrei, da id bereits eindeutig und auto-increment ist.
UPDATE customer_accounts
SET customer_number = CONCAT('K-', LPAD(id, 5, '0'))
WHERE customer_number IS NULL;

-- Name/Telefon bei bereits bestehenden Konten aus der jeweils neuesten
-- verknuepften Buchung uebernehmen, damit die Kundenliste nicht leer
-- startet - neue Konten bekommen das direkt bei der Erstellung gesetzt
-- (siehe find_or_create_customer_account() in includes/customer_auth.php).
UPDATE customer_accounts ca
INNER JOIN (
    SELECT b.customer_account_id, b.customer_name, b.customer_phone
    FROM bookings b
    INNER JOIN (
        SELECT customer_account_id, MAX(created_at) AS max_created
        FROM bookings
        WHERE customer_account_id IS NOT NULL
        GROUP BY customer_account_id
    ) latest
        ON latest.customer_account_id = b.customer_account_id
       AND latest.max_created = b.created_at
) b ON b.customer_account_id = ca.id
SET ca.name = b.customer_name,
    ca.phone = b.customer_phone
WHERE ca.name IS NULL;

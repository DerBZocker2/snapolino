-- Migration 0011: Kundenkonten (E-Mail + Code, kein Passwort), Freischaltung
-- von Buchungen fuer nachtraegliche Kundenbearbeitung, Zusatzkosten fuer
-- Admin-Aenderungen, die nicht urspruenglich gebucht/bezahlt wurden.
-- Auf einer bereits laufenden Installation von Hand ausfuehren:
--   mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0011_customer_accounts.sql

CREATE TABLE IF NOT EXISTS customer_accounts (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email                  VARCHAR(190) NOT NULL UNIQUE,
    login_code             VARCHAR(10) NULL,
    login_code_expires_at  DATETIME NULL,
    login_code_attempts    INT UNSIGNED NOT NULL DEFAULT 0,
    login_code_sent_at     DATETIME NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE bookings
    ADD COLUMN customer_account_id INT UNSIGNED NULL AFTER customer_email,
    ADD COLUMN edit_unlocked_by_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER agb_accepted_at;

ALTER TABLE bookings
    ADD CONSTRAINT fk_bookings_customer_account
    FOREIGN KEY (customer_account_id) REFERENCES customer_accounts(id) ON DELETE SET NULL;

-- Bestehende Buchungen nachtraeglich mit einem Konto verknuepfen, damit sie
-- nach einem Login unter derselben E-Mail-Adresse ebenfalls auftauchen -
-- ein Konto pro E-Mail-Adresse.
INSERT IGNORE INTO customer_accounts (email)
SELECT DISTINCT customer_email FROM bookings
WHERE customer_email IS NOT NULL AND customer_email <> '';

UPDATE bookings b
INNER JOIN customer_accounts ca ON ca.email = b.customer_email
SET b.customer_account_id = ca.id
WHERE b.customer_account_id IS NULL;

-- Zusatzkosten, die ein Admin nachtraeglich fuer nicht urspruenglich
-- gebuchte Aenderungen anfordert (statt sie kostenlos zu uebernehmen) -
-- eigene, kleine Stripe-Checkout-Session pro Nachforderung, unabhaengig
-- von der Haupt-Session der Buchung selbst.
CREATE TABLE IF NOT EXISTS booking_addon_charges (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id         INT UNSIGNED NOT NULL,
    description        VARCHAR(255) NOT NULL,
    amount_cents       INT UNSIGNED NOT NULL,
    stripe_session_id  VARCHAR(255) NULL,
    paid_at            DATETIME NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

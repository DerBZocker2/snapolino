-- Einfache Buchhaltung fuer die EUER (Einnahmenueberschussrechnung) des
-- Einzelunternehmens selbst - unabhaengig von den Kundenbuchungen/-
-- rechnungen (die laufen weiterhin ueber Stripe, siehe admin/invoices.php).
-- Deckt vor allem Eingangsrechnungen ab (Einkaeufe: Druckerpapier,
-- Farbband, Technik, Hosting...), die nie durch Stripe laufen, sowie
-- seltene Ausgangsrechnungen ausserhalb des Buchungsflusses.
CREATE TABLE IF NOT EXISTS bookkeeping_entries (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    direction         ENUM('einnahme', 'ausgabe') NOT NULL,
    document_date     DATE NOT NULL,
    counterparty      VARCHAR(150) NULL,
    description       VARCHAR(255) NULL,
    category          VARCHAR(80) NULL,
    amount_cents      INT NOT NULL,
    original_filename VARCHAR(255) NULL,
    stored_filename   VARCHAR(150) NULL,
    mime_type         VARCHAR(100) NULL,
    extracted_text    MEDIUMTEXT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_document_date (document_date),
    INDEX idx_direction (direction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

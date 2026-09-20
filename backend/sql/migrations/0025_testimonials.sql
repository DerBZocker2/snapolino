-- Kundenbewertungen fuer die Startseite (Panel unter Buchungen &gt;
-- Bewertungen verwaltbar). Bewusst ohne Seed-Daten - es werden nur echte,
-- vom Admin eingetragene Kundenstimmen angezeigt (z.B. Antworten auf die
-- automatische Bewertungsanfrage, siehe bin/send_review_requests.php),
-- index.php zeigt den Abschnitt nur, wenn mindestens eine aktive
-- Bewertung existiert.
CREATE TABLE IF NOT EXISTS testimonials (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_name  VARCHAR(100) NOT NULL,
    event_type     VARCHAR(100) NULL,
    rating         TINYINT UNSIGNED NOT NULL DEFAULT 5,
    quote          TEXT NOT NULL,
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    sort_order     INT UNSIGNED NOT NULL DEFAULT 0,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

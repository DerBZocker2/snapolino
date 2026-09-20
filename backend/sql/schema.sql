-- Snapolino Cloud-Backend
-- Datenbankschema fuer Boxen, Layouts und die Zuordnung zwischen beiden.
-- Zeichensatz durchgehend utf8mb4, damit Umlaute in Namen keine Probleme machen.

CREATE TABLE IF NOT EXISTS admins (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS boxes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    box_key         VARCHAR(64)  NOT NULL UNIQUE,
    api_key         VARCHAR(64)  NOT NULL UNIQUE,
    admin_pin       VARCHAR(20)  NULL,
    name            VARCHAR(100) NOT NULL,
    note            TEXT NULL,
    config_version  INT UNSIGNED NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ein Layout beschreibt eine Collage-Vorlage: Rahmen-PNG, Leinwandgroesse
-- in Pixeln und die Anzahl der Foto-Slots (Slots stehen in layout_slots).
CREATE TABLE IF NOT EXISTS layouts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    category        VARCHAR(50) NULL,
    slot_count      TINYINT UNSIGNED NOT NULL DEFAULT 4,
    canvas_width    INT UNSIGNED NOT NULL,
    canvas_height   INT UNSIGNED NOT NULL,
    frame_file      VARCHAR(150) NOT NULL,
    is_default      TINYINT(1) NOT NULL DEFAULT 0,
    is_custom       TINYINT(1) NOT NULL DEFAULT 0,
    surcharge_cents INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Position und Groesse jedes einzelnen Foto-Slots auf der Leinwand.
CREATE TABLE IF NOT EXISTS layout_slots (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    layout_id   INT UNSIGNED NOT NULL,
    slot_index  TINYINT UNSIGNED NOT NULL,
    x           INT UNSIGNED NOT NULL,
    y           INT UNSIGNED NOT NULL,
    width       INT UNSIGNED NOT NULL,
    height      INT UNSIGNED NOT NULL,
    FOREIGN KEY (layout_id) REFERENCES layouts(id) ON DELETE CASCADE,
    UNIQUE KEY unique_slot (layout_id, slot_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Welche Layouts eine Box ausliefern darf. Das Standard-Layout (4er-Collage)
-- wird beim Anlegen einer Box automatisch zugeordnet, dazugebuchte Formate
-- kommen als weitere Zeilen dazu.
CREATE TABLE IF NOT EXISTS box_layouts (
    box_id      INT UNSIGNED NOT NULL,
    layout_id   INT UNSIGNED NOT NULL,
    sort_order  INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (box_id, layout_id),
    FOREIGN KEY (box_id) REFERENCES boxes(id) ON DELETE CASCADE,
    FOREIGN KEY (layout_id) REFERENCES layouts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Wartungs-Checkliste zwischen Vermietungen (Migration 0024) - eine
-- gemeinsame, im Panel unter Boxen verwaltbare Liste von Wartungspunkten,
-- box_maintenance_checks trackt die Erledigung je Box. Wird beim Aufheben
-- einer Buchungs-Zuordnung (boxes.php) automatisch zurueckgesetzt.
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

-- Kundenkonto pro E-Mail-Adresse, Login per zeitlich begrenztem Code statt
-- Passwort (siehe includes/customer_auth.php). Wird automatisch beim
-- Anlegen einer Reservierung erstellt/wiederverwendet.
CREATE TABLE IF NOT EXISTS customer_accounts (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email                  VARCHAR(190) NOT NULL UNIQUE,
    login_code             VARCHAR(10) NULL,
    login_code_expires_at  DATETIME NULL,
    login_code_attempts    INT UNSIGNED NOT NULL DEFAULT 0,
    login_code_sent_at     DATETIME NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Buchungen von der oeffentlichen Webseite, entstehen bereits ab Schritt 2
-- des Assistenten (Name/E-Mail/Datum) direkt als "angefragt" und blockieren
-- den Kalender sofort - es gibt keine unverbindliche Zwischenstufe, da nur
-- eine Box existiert. box_id bleibt leer, bis ein Admin die Anfrage
-- bestaetigt und eine physische Box zuordnet.
CREATE TABLE IF NOT EXISTS bookings (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    edit_token             VARCHAR(64) NULL UNIQUE,
    -- Erzeugt beim ersten Foto-Upload aus der Cloud-Galerie (Migration 0016,
    -- siehe gallery_photos unten), nicht schon bei der Buchung selbst.
    -- gallery_token ist der Verwalter-Link (Fotos ausblenden, Gaeste-Link
    -- einsehen), gallery_guest_token der separate, read-only Link zum
    -- Weitergeben an Gaeste (Migration 0018). gallery_deleted_at wird
    -- gesetzt, sobald bin/purge_expired_galleries.php die Fotos dieser
    -- Buchung geloescht hat (DSGVO-Aufbewahrungsfrist, siehe Einstellung
    -- gallery_retention_days).
    gallery_token          VARCHAR(64) NULL UNIQUE,
    gallery_guest_token    VARCHAR(64) NULL UNIQUE,
    gallery_deleted_at     DATETIME NULL,
    -- Gesetzt von bin/send_event_reminders.php, sobald die automatische
    -- Erinnerungsmail vor dem Event verschickt wurde (Migration 0019).
    reminder_sent_at       DATETIME NULL,
    -- Verhindert eine doppelte Empfehlungspraemie, falls diese Buchung
    -- (die selbst einen Empfehlungscode genutzt hat) mehrfach bestaetigt
    -- wird (Migration 0021, siehe reward_referral_owner_if_applicable()).
    referral_reward_sent_at DATETIME NULL,
    -- Gesetzt von bin/send_review_requests.php, sobald die automatische
    -- Bewertungsanfrage nach dem Event verschickt wurde (Migration 0022).
    review_requested_at   DATETIME NULL,
    stripe_session_id      VARCHAR(255) NULL UNIQUE,
    stripe_payment_intent  VARCHAR(255) NULL,
    customer_name          VARCHAR(120) NOT NULL,
    customer_email         VARCHAR(190) NOT NULL,
    customer_account_id    INT UNSIGNED NULL,
    customer_phone         VARCHAR(40) NULL,
    customer_street        VARCHAR(150) NULL,
    customer_zip           VARCHAR(10) NULL,
    customer_city          VARCHAR(100) NULL,
    customer_company       VARCHAR(150) NULL,
    invoice_to_company     TINYINT(1) NOT NULL DEFAULT 0,
    coupon_code            VARCHAR(50) NULL,
    discount_cents         INT UNSIGNED NOT NULL DEFAULT 0,
    -- Automatischer Stammkundenrabatt (Migration 0023), zusaetzlich zu
    -- discount_cents (Gutschein) - siehe is_returning_customer() in
    -- includes/functions.php.
    returning_discount_cents INT UNSIGNED NOT NULL DEFAULT 0,
    event_date             DATE NOT NULL,
    box_id                 INT UNSIGNED NULL,
    status                 VARCHAR(20) NOT NULL DEFAULT 'angefragt',
    message                TEXT NULL,
    admin_note             TEXT NULL,
    total_price_cents      INT UNSIGNED NULL,
    wants_quote            TINYINT(1) NOT NULL DEFAULT 0,
    agb_accepted_at        DATETIME NULL,
    edit_unlocked_by_admin TINYINT(1) NOT NULL DEFAULT 0,
    paid_at                DATETIME NULL,
    cancelled_at           DATETIME NULL,
    -- invoice_number ist nur noch fuer vor Migration 0015 bezahlte
    -- Buchungen gesetzt (historische eigene Rechnungsnummer/PDF) - seitdem
    -- ist ausschliesslich die bei Stripe gehostete Rechnung massgeblich
    -- (siehe mark_booking_paid() in includes/payments.php).
    invoice_number         VARCHAR(30) NULL UNIQUE,
    -- Stripe erstellt automatisch eine eigene, bei Stripe gehostete
    -- Rechnung (siehe Migration 0013). Bei einer Stornierung durch den
    -- Admin (cancel_booking()) kommen Rueckerstattung und Stornorechnung
    -- (Stripe Credit Note) dazu.
    stripe_invoice_id         VARCHAR(255) NULL,
    stripe_invoice_pdf_url    VARCHAR(500) NULL,
    stripe_invoice_hosted_url VARCHAR(500) NULL,
    stripe_refund_id          VARCHAR(255) NULL,
    stripe_credit_note_id     VARCHAR(255) NULL,
    stripe_credit_note_pdf_url VARCHAR(500) NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (box_id) REFERENCES boxes(id) ON DELETE SET NULL,
    FOREIGN KEY (customer_account_id) REFERENCES customer_accounts(id) ON DELETE SET NULL,
    INDEX idx_event_date (event_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Zusatzkosten, die ein Admin nachtraeglich fuer nicht urspruenglich
-- gebuchte Aenderungen anfordert (statt sie kostenlos zu uebernehmen) -
-- eigene, kleine Stripe-Checkout-Session pro Nachforderung, unabhaengig
-- von der Haupt-Session der Buchung selbst.
-- pending_changes_json haelt Eventdatum/Layouts/Extras/neuen Gesamtpreis der
-- vom Admin vorgeschlagenen Aenderung, solange sie noch nicht bezahlt ist -
-- erst mark_addon_charge_paid() wendet sie auf die Buchung an (siehe
-- Migration 0012).
CREATE TABLE IF NOT EXISTS booking_addon_charges (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id            INT UNSIGNED NOT NULL,
    description           VARCHAR(255) NOT NULL,
    amount_cents          INT UNSIGNED NOT NULL,
    pending_changes_json  TEXT NULL,
    stripe_session_id     VARCHAR(255) NULL,
    paid_at               DATETIME NULL,
    -- Zusatzzahlungen bekommen ebenfalls eine eigene, bei Stripe gehostete
    -- Rechnung (siehe Migration 0013) - vorher gab es dafuer gar keine.
    stripe_invoice_id         VARCHAR(255) NULL,
    stripe_invoice_pdf_url    VARCHAR(500) NULL,
    stripe_invoice_hosted_url VARCHAR(500) NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Warteliste fuer bereits ausgebuchte Termine (Migration 0020) - siehe
-- notify_waitlist_for_freed_range() in includes/functions.php.
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

-- Gutscheincodes, im Panel unter "Gutscheine" angelegt. redemption_count
-- wird erst erhoeht, wenn eine Buchung tatsaechlich bestaetigt wird
-- (bezahlt oder Admin bestaetigt eine Angebots-Buchung), nicht schon beim
-- blossen Eingeben/Einloesen im Assistenten.
CREATE TABLE IF NOT EXISTS coupons (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code              VARCHAR(50) NOT NULL UNIQUE,
    discount_type     ENUM('percent', 'fixed') NOT NULL,
    discount_value    INT UNSIGNED NOT NULL,
    max_redemptions   INT UNSIGNED NULL,
    redemption_count  INT UNSIGNED NOT NULL DEFAULT 0,
    valid_until       DATE NULL,
    is_active         TINYINT(1) NOT NULL DEFAULT 1,
    -- Gesetzt, wenn dieser Code der persoenliche Empfehlungscode einer
    -- Buchung ist (Migration 0021) - siehe ensure_referral_coupon_for_booking()
    -- in includes/functions.php.
    referral_owner_booking_id INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (referral_owner_booking_id) REFERENCES bookings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Atomarer Zaehler fuer fortlaufende Rechnungsnummern (ein Zaehler pro Jahr,
-- Format wird in PHP zu z.B. "2026-0001" zusammengesetzt).
CREATE TABLE IF NOT EXISTS invoice_counters (
    year        INT UNSIGNED PRIMARY KEY,
    next_number INT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Vom Kunden bei der Buchung gewuenschte Layouts (Standard ist immer dabei,
-- weitere Formate optional gegen Aufpreis - der Aufpreis-Betrag steht schon
-- in layouts.surcharge_cents, hier wird nur die Auswahl festgehalten).
CREATE TABLE IF NOT EXISTS booking_layouts (
    booking_id INT UNSIGNED NOT NULL,
    layout_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (booking_id, layout_id),
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (layout_id) REFERENCES layouts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin-verwaltete Zusatzoptionen fuer die Buchung (Preis darf negativ
-- sein, z.B. "Ohne Druck" als Rabatt-Option).
CREATE TABLE IF NOT EXISTS extras (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL,
    description  VARCHAR(255) NULL,
    icon         VARCHAR(10) NULL,
    price_cents  INT NOT NULL DEFAULT 0,
    type         VARCHAR(10) NOT NULL DEFAULT 'toggle',   -- 'toggle' oder 'quantity'
    unit_label   VARCHAR(30) NULL,                        -- z.B. "Tag" bei quantity
    max_quantity INT UNSIGNED NULL,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    sort_order   INT UNSIGNED NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kundenbewertungen fuer die Startseite (Migration 0025), im Panel unter
-- Bewertungen verwaltbar - bewusst ohne Seed-Daten, index.php zeigt den
-- Abschnitt nur bei mindestens einer aktiven Bewertung.
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

-- Automatisch aus der Fotobox hochgeladene Fotos/Collagen dieser Buchung -
-- Grundlage der oeffentlichen Online-Galerie (galerie.php, Migration 0016).
CREATE TABLE IF NOT EXISTS gallery_photos (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id  INT UNSIGNED NOT NULL,
    filename    VARCHAR(150) NOT NULL,
    kind        VARCHAR(10) NOT NULL DEFAULT 'foto',
    -- Vom Organisator (Verwalter-Link) ausgeblendet - nur die Gaeste-Ansicht
    -- blendet es aus, der Organisator sieht es weiterhin (zum Wieder-Einblenden).
    hidden      TINYINT(1) NOT NULL DEFAULT 0,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_booking_filename (booking_id, filename),
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS booking_extras (
    booking_id INT UNSIGNED NOT NULL,
    extra_id   INT UNSIGNED NOT NULL,
    quantity   INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (booking_id, extra_id),
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (extra_id) REFERENCES extras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Einfache Key-Value-Einstellungen, aktuell fuer den Basispreis.
CREATE TABLE IF NOT EXISTS settings (
    name  VARCHAR(60) PRIMARY KEY,
    value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (name, value) VALUES
    ('base_price_cents', '21900'),
    ('base_price_label', 'Basic · Mit Druck-Flatrate'),
    ('business_name', 'Bitte im Panel unter Einstellungen ausfuellen'),
    ('business_address', 'Straße Hausnummer\nPLZ Ort'),
    ('business_tax_note', 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.'),
    ('business_email', 'info@snapolino.de'),
    ('business_phone', ''),
    -- Tage NACH DEM EVENTDATUM, nach denen bin/purge_expired_galleries.php
    -- die Galerie-Fotos einer Buchung endgueltig loescht (DSGVO).
    ('gallery_retention_days', '30'),
    -- Tage VOR DEM EVENTDATUM, ab denen bin/send_event_reminders.php die
    -- automatische Erinnerungsmail verschickt.
    ('reminder_days_before_event', '7'),
    -- Empfehlungsprogramm: Rabatt fuer die geworbene Person (Prozent) und
    -- Belohnung fuer die werbende Person (Cent), siehe "Empfehlungsprogramm"
    -- in CLAUDE.md.
    ('referral_discount_percent', '10'),
    ('referral_reward_cents', '1500'),
    -- Tage NACH DEM EVENTDATUM, ab denen bin/send_review_requests.php die
    -- automatische Bewertungsanfrage verschickt, sowie der Link zur
    -- Google-Bewertungsseite (leer = kein Bewertungslink-Knopf in der Mail).
    ('review_request_days_after_event', '3'),
    ('google_review_url', ''),
    -- Automatischer Rabatt (Prozent) fuer wiederkehrende Kunden (per
    -- E-Mail-Adresse erkannt, siehe is_returning_customer()).
    ('returning_customer_discount_percent', '10');

-- Beispiel-Extras zum Start, im Panel unter "Extras" frei anpassbar/loeschbar.
INSERT INTO extras (name, description, icon, price_cents, type, unit_label, sort_order) VALUES
    ('Express-Versand', 'Lieferung am naechsten Werktag - ideal fuer kurzfristige Events', '⚡', 2900, 'toggle', NULL, 10),
    ('Vollformat-Drucke', 'Fotos rahmenlos auf das gesamte Papier gedruckt - keine Collage', '🖼️', 7500, 'toggle', NULL, 20),
    ('Einzelne Bilder drucken', 'Am Ende ein Foto auswaehlen und zusaetzlich bis zu 3x einzeln ausdrucken lassen', '📄', 5000, 'toggle', NULL, 30),
    ('Extra-Tage', 'Laenger mieten, gestaffelt pro Tag', '📅', 8000, 'quantity', 'Tag', 40),
    ('Online-Galerie', 'Online verfuegbar sobald die Fotobox zurueck ist - nicht live waehrend der Party', '🌐', 2000, 'toggle', NULL, 50),
    ('Live aufs Smartphone', 'Fotos direkt aufs Handy per QR-Code - sofort teilbar', '📱', 2900, 'toggle', NULL, 60),
    ('Ohne Druck', 'Fotobox ohne Druckfunktion - guenstiger, falls nur digitale Fotos gewuenscht sind', '♻️', -5000, 'toggle', NULL, 70);

-- Standardlayout: 4 Bilder als 2x2-Collage auf 10x15cm quer (1800x1200 px,
-- ca. 300dpi). Vier verschiedene Foto-Anordnungen (grid/hero/strip/stack,
-- siehe backend/tools/generate_presets.py) statt eines einzigen geteilten
-- 2x2-Rasters - jede Anordnung hat ihre eigene Slot-Geometrie, nur Layouts
-- derselben Anordnung teilen sich Koordinaten. Die PNGs liegen als
-- mitgelieferte Design-Vorlagen unter storage/frames/preset_*.png (siehe
-- .gitignore-Ausnahme), echte Kunden-Uploads bleiben weiterhin ignoriert.
INSERT INTO layouts (name, category, slot_count, canvas_width, canvas_height, frame_file, is_default, surcharge_cents) VALUES
    ('Standard 4er-Collage', NULL, 4, 1800, 1200, 'preset_standard_v2.png', 1, 0),
    ('Business Klassisch', 'Business', 4, 1800, 1200, 'preset_business_klassisch_v2.png', 0, 0),
    ('Weihnachten Klassisch', 'Weihnachten', 4, 1800, 1200, 'preset_weihnachten_klassisch_v2.png', 0, 0),
    ('Modern Schwarz', 'Neutral', 4, 1800, 1200, 'preset_neutral_schwarz_v2.png', 0, 0),
    ('Modern Weiss', 'Neutral', 4, 1800, 1200, 'preset_neutral_weiss_v2.png', 0, 0),
    ('Hochzeit Elegant', 'Hochzeit', 4, 1800, 1200, 'preset_hochzeit_elegant_v2.png', 0, 0),
    ('Hochzeit Modern', 'Hochzeit', 4, 1800, 1200, 'preset_hochzeit_modern_v2.png', 0, 0),
    ('Geburtstag Glamour', 'Geburtstag', 4, 1800, 1200, 'preset_geburtstag_glamour_v2.png', 0, 0),
    ('Business Modern', 'Business', 4, 1800, 1200, 'preset_business_modern_v2.png', 0, 0),
    ('Weihnachten Elegant', 'Weihnachten', 4, 1800, 1200, 'preset_weihnachten_elegant_v2.png', 0, 0),
    ('Geburtstag Bunt', 'Geburtstag', 4, 1800, 1200, 'preset_geburtstag_bunt_v2.png', 0, 0),
    ('Silvester Party', 'Party', 4, 1800, 1200, 'preset_silvester_v2.png', 0, 0),
    ('Regenbogen', 'Party', 4, 1800, 1200, 'preset_regenbogen_v2.png', 0, 0),
    ('Sommerfest', 'Sommer', 4, 1800, 1200, 'preset_sommerfest_v2.png', 0, 0),
    ('Hochzeit Rustikal', 'Hochzeit', 4, 1800, 1200, 'preset_hochzeit_rustikal_v2.png', 0, 0),
    ('Geburtstag Kids', 'Geburtstag', 4, 1800, 1200, 'preset_geburtstag_kids_v2.png', 0, 0),
    ('Gartenparty', 'Sommer', 4, 1800, 1200, 'preset_gartenparty_v2.png', 0, 0),
    ('Babyparty Blau', 'Baby', 4, 1800, 1200, 'preset_baby_boy_v2.png', 0, 0),
    ('Babyparty Rosa', 'Baby', 4, 1800, 1200, 'preset_baby_girl_v2.png', 0, 0);

-- Anordnung "grid":
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height
FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 835 AS width, 535 AS height
    UNION ALL SELECT 1, 915, 50, 835, 535
    UNION ALL SELECT 2, 50, 615, 835, 535
    UNION ALL SELECT 3, 915, 615, 835, 535
) AS s
WHERE layouts.name IN ('Standard 4er-Collage', 'Business Klassisch', 'Weihnachten Klassisch', 'Modern Schwarz', 'Modern Weiss');

-- Anordnung "hero":
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height
FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 1700 AS width, 650 AS height
    UNION ALL SELECT 1, 50, 790, 553, 360
    UNION ALL SELECT 2, 623, 790, 553, 360
    UNION ALL SELECT 3, 1196, 790, 554, 360
) AS s
WHERE layouts.name IN ('Hochzeit Elegant', 'Hochzeit Modern', 'Geburtstag Glamour', 'Business Modern', 'Weihnachten Elegant');

-- Anordnung "strip":
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height
FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 40 AS x, 170 AS y, 415 AS width, 990 AS height
    UNION ALL SELECT 1, 475, 170, 415, 990
    UNION ALL SELECT 2, 910, 170, 415, 990
    UNION ALL SELECT 3, 1345, 170, 415, 990
) AS s
WHERE layouts.name IN ('Geburtstag Bunt', 'Silvester Party', 'Regenbogen', 'Sommerfest');

-- Anordnung "stack":
INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height
FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 950 AS width, 1050 AS height
    UNION ALL SELECT 1, 1030, 50, 720, 337
    UNION ALL SELECT 2, 1030, 407, 720, 337
    UNION ALL SELECT 3, 1030, 764, 720, 336
) AS s
WHERE layouts.name IN ('Hochzeit Rustikal', 'Geburtstag Kids', 'Gartenparty', 'Babyparty Blau', 'Babyparty Rosa');

-- Zusatzformate mit anderer Fotoanzahl statt der Standard-4er-Collage -
-- jeweils eigene Slot-Geometrie.
INSERT INTO layouts (name, category, slot_count, canvas_width, canvas_height, frame_file, is_default, surcharge_cents) VALUES
    ('1 Bild (Vollformat)', 'Format', 1, 1800, 1200, 'preset_format_1bild_v2.png', 0, 300),
    ('2 Bilder nebeneinander', 'Format', 2, 1800, 1200, 'preset_format_2bilder_v2.png', 0, 300),
    ('3 Bilder nebeneinander', 'Format', 3, 1800, 1200, 'preset_format_3bilder_v2.png', 0, 0);

INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, 0, 60, 60, 1680, 940 FROM layouts WHERE name = '1 Bild (Vollformat)';

INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height
FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 50 AS y, 897 AS width, 1100 AS height
    UNION ALL SELECT 1, 1017, 50, 733, 1100
) AS s
WHERE layouts.name = '2 Bilder nebeneinander';

INSERT INTO layout_slots (layout_id, slot_index, x, y, width, height)
SELECT id, s.slot_index, s.x, s.y, s.width, s.height
FROM layouts
CROSS JOIN (
    SELECT 0 AS slot_index, 50 AS x, 90 AS y, 553 AS width, 1020 AS height
    UNION ALL SELECT 1, 623, 90, 553, 1020
    UNION ALL SELECT 2, 1196, 90, 554, 1020
) AS s
WHERE layouts.name = '3 Bilder nebeneinander';


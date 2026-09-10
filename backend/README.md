# Snapolino Cloud-Backend

PHP 8 + MySQL. Panel zum Anlegen von Layouts (Rahmen-PNG, Leinwandgroesse,
Slot-Koordinaten) und zur Zuordnung von Layouts zu Boxen. Die Box selbst
holt sich diese Konfiguration nur im Vorbereitungsmodus vor dem Versand ab
(`api.php`) und laeuft danach komplett offline weiter.

## Verzeichnisse

```
backend/
  sql/schema.sql          Datenbankschema fuer Neuinstallationen
  sql/migrations/          Einzelne Aenderungen zum Nachziehen auf bestehenden DBs
  includes/                PHP-Code, der NICHT direkt aus dem Web erreichbar sein darf
    config.php.example      Vorlage fuer die Zugangsdaten
    config.php               (nicht im Repo, siehe Einrichtung unten)
  bin/
    create_admin.php        CLI-Skript zum Anlegen/Aendern eines Admin-Logins
    lib/fpdf/                FPDF (Rechnungs-PDF), manuell eingebunden
    lib/PHPMailer/           PHPMailer (SMTP-Mailversand), manuell eingebunden
    stripe.php               Rohe Stripe-API-Anbindung per cURL (kein SDK)
    payments.php             mark_booking_paid(): Buchung bestaetigen, Rechnung + Mail ausloesen
    invoice.php              Rechnungs-PDF erzeugen (FPDF)
    mailer.php               Bestaetigungsmail mit Rechnung verschicken (PHPMailer/SMTP)
  storage/
    frames/                  Rahmen-PNGs: preset_*.png sind mitgelieferte
                             Design-Vorlagen (im Repo), alles andere sind
                             echte Uploads/Kundendesigns (nicht im Repo)
    invoices/                Erzeugte Rechnungs-PDFs (personenbezogen, nicht im Repo)
  public/                   Docroot fuer den Webserver
    index.php                Oeffentliche Startseite
    buchen.php               Oeffentlicher Buchungsassistent (5 Schritte)
    booking_availability.php JSON-Endpunkt: blockierte Tage fuer den Kalender
    layout_preview.php       Oeffentliche Vorschau-PNG fuer die Design-Galerie
    stripe_webhook.php       Nimmt Stripe-Zahlungsbestaetigungen entgegen (signaturgeprueft)
    api.php                  Konfigurations-Endpunkt fuer die Box
    frame.php                Liefert eine Rahmen-PNG aus (API-Key, fuer die Box)
    admin/                   Verwaltungs-Panel (Login-geschuetzt, Sidebar-Layout)
      bookings.php             Buchungsanfragen ablehnen/stornieren/Box zuweisen
      booking_detail.php       Details, Extras, Gesamtpreis + interne Notiz
      layout_form.php          Layout anlegen/bearbeiten inkl. visuellem Drag-Slot-Editor
      extras.php, extra_form.php   Zusatzoptionen verwalten (Preis, Ein/Aus/Menge)
      coupons.php, coupon_form.php Gutscheincodes verwalten (Prozent/Festbetrag, Ablauf, Kontingent)
      settings.php             Basispreis, Beschriftung, Rechnungsdaten
```

**Wichtig:** Das Document Root des vhosts muss auf `backend/public` zeigen,
nicht auf `backend/`. So sind `includes/`, `sql/`, `storage/` und `bin/`
grundsaetzlich nicht ueber HTTP erreichbar.

Fuer die konkrete Einrichtung auf dem Raspberry Pi (Apache, MariaDB,
Cloudflare-DNS/TLS) siehe `deploy/RASPBERRY_PI.md`.

## Einrichtung

**PHP-Erweiterungen:** `gd` (Design-Vorschauen, automatische Slot-Erkennung
beim Upload), `curl` (Stripe-API), `mbstring` (Rechnungs-PDF, Umlaute).
Bei den meisten Debian/Raspbian-PHP-Paketen schon dabei, notfalls
nachinstallieren:
```bash
sudo apt install php-gd php-curl php-mbstring && sudo systemctl reload apache2
```

1. Datenbank anlegen und Schema importieren:
   ```
   mysql -u root -p -e "CREATE DATABASE snapolino CHARACTER SET utf8mb4"
   mysql --default-character-set=utf8mb4 -u root -p snapolino < sql/schema.sql
   ```
2. `includes/config.php.example` nach `includes/config.php` kopieren und
   Zugangsdaten sowie `base_url` (die spaeter oeffentlich erreichbare
   Domain, z.B. `https://snapolino.de`) eintragen.
3. Ersten Admin-Zugang anlegen:
   ```
   php bin/create_admin.php mein_benutzername "sicheres passwort"
   ```
4. Docroot des Webservers auf `backend/public` zeigen lassen, HTTPS
   erzwingen (die API gibt echte Zugangsdaten als Header/Query zurueck).
5. Panel unter `https://snapolino.de/admin/` aufrufen und einloggen.

## Ablauf beim Anlegen einer Box

1. Im Panel unter **Boxen** eine neue Box anlegen. Dabei werden
   automatisch ein `box_key` und ein `api_key` erzeugt und das
   Standard-Layout (4er-Collage) zugeordnet.
2. Unter **Layouts & Zugang** der Box zusaetzliche Formate ankreuzen,
   falls der Kunde bei der Buchung dazugebucht hat (Aufpreis wird dort
   angezeigt).
3. `box_key` und `api_key` in die `box.ini` der jeweiligen Box eintragen.
4. Box einmalig mit Internetverbindung starten, damit `cloudsync.py`
   Konfiguration und Rahmen-PNGs abholt (Preflight vor dem Versand).
   Danach funktioniert die Box komplett offline; ein erneuter Abgleich
   ist nur noetig, wenn sich Layouts oder deren Zuordnung geaendert haben.

## Buchungssystem

Kunden buchen oeffentlich unter `/buchen.php`, ein 5-Schritte-Assistent:

1. **Datum waehlen** - Kalender zeigt nur wirklich blockierte Tage (siehe
   unten), inkl. `BOOKING_BUFFER_DAYS` Puffer vor/nach dem Event fuer Hin-
   und Ruecksand.
2. **Reservieren** - Name/E-Mail legen eine Zeile in `bookings` mit Status
   `reserviert` an (das Standard-Layout landet direkt in `booking_layouts`)
   und vergeben einen `edit_token`, mit dem der Assistent die Buchung ueber
   alle weiteren Schritte hinweg wiederfindet (Token steht in der URL, keine
   PHP-Session noetig - der Kunde kann die Seite also schliessen und mit dem
   Link aus der Bestaetigungsmail spaeter weitermachen).
3. **Design waehlen** - drei Wege, alle speichern das Ergebnis in
   `booking_layouts`:
   - **Fertige Vorlage**: nach Kategorie filterbare Galerie der Layouts aus
     der Datenbank (Vorschaubilder ueber `layout_preview.php`, oeffentlich
     ohne API-Key). 18 mitgelieferte Themen-Presets plus Standard (siehe
     `sql/migrations/0004_preset_designs.sql`) sowie 3 Formate mit
     abweichender Fotoanzahl - 1 Bild, 2 und 3 nebeneinander statt der
     4er-Collage (Kategorie "Format", siehe
     `sql/migrations/0006_format_templates.sql`). Jede Karte hat einen
     "Anpassen"-Knopf, der dieselbe Vorlage mit ihrer Slot-Geometrie in den
     Online-Designer laedt.
   - **Online-Designer**: Canvas-Editor (Hintergrund-/Akzentfarbe, Muster,
     optionaler Text), rendert clientseitig eine PNG mit der Slot-Geometrie
     eines gewaehlten Basis-Layouts (die Foto-Slots werden per
     `globalCompositeOperation = 'destination-out'` transparent
     ausgeschnitten). Die Fotoflaechen selbst lassen sich direkt im Canvas
     per Maus verschieben (gestrichelter Umriss zur Orientierung, "Zuruecksetzen"
     stellt die Ausgangsposition wieder her) - die neuen Koordinaten werden
     als JSON mit abgeschickt (`custom_slots`, serverseitig via
     `validate_custom_slots()` geprueft, faellt bei verdaechtigen Werten auf
     die Basis-Geometrie zurueck).
   - **Eigenes hochladen**: PNG mit transparenten Fotoflaechen hochladen.
     `detect_transparent_slots()` (includes/functions.php) erkennt die
     zusammenhaengenden transparenten Bereiche per Connected-Component-
     Analyse (auf einem verkleinerten Raster fuer Performance) und legt
     daraus automatisch die `layout_slots` an - keine manuelle
     Slot-Konfiguration noetig.

   Beide eigenen Wege legen ein Layout mit `is_custom=1` an
   (`save_custom_layout_for_booking()`), das nur dieser Buchung zugeordnet
   ist: weder in der oeffentlichen Galerie noch im allgemeinen Panel bei
   anderen Kunden sichtbar (`fetch_all_layouts()` filtert das standardmaessig
   raus). Ein zweiter Versuch ersetzt das vorherige eigene Design samt Datei
   statt es anzuhaeufen.
4. **Extras** - admin-verwaltete Zusatzoptionen (`extras`-Tabelle), je nach
   Typ als Ein/Aus-Schalter oder mit Mengenauswahl, Preis kann auch negativ
   sein (Rabatt, z.B. "Ohne Druck").
5. **Zusammenfassung** - strukturierte Rechnungsadresse (Strasse/PLZ/Ort,
   optional Firma), Versand-Zeitplan, Gutscheincode-Einloesung und
   Preisuebersicht (`calc_booking_pricing()`, kombiniert `calc_booking_total()`
   mit einem eingeloesten Gutschein). Zwei Wege zum Abschluss:
   - **Jetzt bezahlen** (Standardfall): erstellt eine Stripe Checkout
     Session (`create_stripe_checkout_session()`) und leitet zur von Stripe
     gehosteten Kassenseite weiter - keine Kartendaten beruehren den
     eigenen Server. Erst der **Webhook** `stripe_webhook.php` (Event
     `checkout.session.completed`, Signatur per `verify_stripe_webhook()`
     geprueft) bestaetigt die Buchung endgueltig
     (`payments.php::mark_booking_paid()`): Status `bestaetigt`, Box
     automatisch zugewiesen (wenn eindeutig moeglich), Rechnungsnummer
     vergeben, Rechnungs-PDF erzeugt und per Mail verschickt. Der Redirect
     des Browsers zurueck auf die Erfolgsseite ist nur fuers UI gedacht und
     bestaetigt selbst nichts.
   - **"Ich möchte vorab nur ein schriftliches Angebot"** (Checkbox): keine
     Zahlung, Status wird wie bisher `angefragt`, Admin bearbeitet die
     Anfrage im Panel von Hand.

**Gutscheine** (Panel unter **Gutscheine**, `coupons`-Tabelle): Prozent- oder
Festbetrag-Rabatt, optional mit Ablaufdatum und maximaler Einloesungszahl.
Der Code wird in Schritt 5 gegen `find_active_coupon()` geprueft und landet
mit dem berechneten Rabatt (`coupon_discount_cents()`) auf der Buchung
(`coupon_code`/`discount_cents`) - `redemption_count` wird aber bewusst
*nicht* schon beim Einloesen im Assistenten erhoeht, sondern erst wenn die
Buchung tatsaechlich `bestaetigt` wird (bezahlt oder Admin bestaetigt eine
Angebots-Buchung, beides laeuft durch `assign_box_and_confirm()`) - ein
abgebrochener Checkout verbraucht damit kein Kontingent.

Eine `reserviert`-Buchung, die **nicht** innerhalb von `RESERVATION_HOLD_DAYS`
(14 Tage) zu `angefragt` wird, blockiert den Kalender danach nicht mehr
(`fetch_blocked_dates()` prueft das per Zeitfenster, kein Cron noetig).

Im Panel unter **Buchungen**:
- **Ablehnen**/**Stornieren** setzen den Status, eine stornierte oder
  abgelehnte Buchung blockiert den Kalender nicht mehr.
- Die Liste zeigt den Gesamtpreis (leer, solange die Buchung noch bei
  `reserviert` haengt), das Detail zusaetzlich die gewaehlten Extras und ob
  ein schriftliches Angebot gewuenscht wurde.
- Eine `angefragt`-Buchung wird nicht mehr hier bestaetigt, sondern unter
  **Boxen** per Drag & Drop einer Box zugeordnet (siehe unten) - das
  bestaetigt sie gleichzeitig.

Im Panel unter **Boxen**: Buchungen, die noch keiner Box zugeordnet sind
(`angefragt`, oder `bestaetigt` mit `box_id IS NULL`), erscheinen dort als
Karten zum Ziehen; auf eine Box-Karte fallen gelassen ruft das per Fetch
`assign_box.php` auf, das dieselbe `assign_box_and_confirm()` aufruft, die
auch bei Zahlungseingang automatisch laeuft. Sie setzt die Buchung auf
`bestaetigt`, traegt alle gewuenschten Layouts in `box_layouts` dieser Box
ein und erhoeht deren `config_version` - die Box muss also vor dem Versand
einmal online sein, um Kundendaten, Layouts und Extras (siehe api.php)
abzuholen. Fuer bezahlte Buchungen laeuft dieselbe Zuordnung automatisch,
wenn genau eine Box existiert; bei 0 oder mehreren Boxen bleibt `box_id`
leer und die Buchung taucht ebenfalls als Karte zum Zuordnen auf.

Serverseitig wird das Eventdatum beim Absenden nochmal gegen blockierte
Tage geprueft (nicht nur im Kalender per JavaScript), damit das nicht per
manuellem POST umgangen werden kann.

**Wichtig fuer eigene Aenderungen an `buchen.php`:** `start_session()` wird
ganz am Anfang der Datei aufgerufen, vor jeder HTML-Ausgabe. Wuerde die
Session erst spaeter (z.B. beim ersten `csrf_field()` mitten im Template)
gestartet, kann PHP bei laengeren Seiten (wie der Zusammenfassung) die
Header schon automatisch geflushed haben, bevor der Session-Cookie gesetzt
wird - `session_start()` schlaegt dann still fehl und jedes Formular auf
der Seite bekommt ein neues CSRF-Token, das nicht mehr zum vorher
ausgelieferten passt ("Ungueltiges Formular"-Fehler beim Absenden).

## Zahlung (Stripe) und Rechnungen einrichten

Ohne die folgenden Schritte funktioniert der "Jetzt bezahlen"-Button
nicht (zeigt eine Fehlermeldung) und es werden keine Bestaetigungsmails
verschickt - die Buchung selbst geht dabei nicht verloren, der Kunde kann
es erneut versuchen bzw. stattdessen "nur ein schriftliches Angebot"
anfragen.

1. **Stripe-Konto** anlegen auf https://dashboard.stripe.com/register
   (echtes Geschaeftskonto mit Bankverbindung fuer den Live-Modus - zum
   Testen reicht der Test-Modus ohne echte Kontodaten).
2. **API-Key**: Dashboard -> **Developers -> API keys** -> "Secret key"
   kopieren (`sk_live_...` bzw. `sk_test_...` zum Testen) nach
   `includes/config.php` unter `stripe_secret_key`.
3. **Webhook einrichten**: Dashboard -> **Developers -> Webhooks -> Add
   endpoint**.
   - Endpoint-URL: `https://snapolino.de/stripe_webhook.php`
   - Event: `checkout.session.completed` auswaehlen (reicht allein aus).
   - Nach dem Anlegen das "Signing secret" (`whsec_...`) kopieren nach
     `includes/config.php` unter `stripe_webhook_secret`.
4. **SMTP-Zugang** des eigenen Postfachs (z.B. bei mc-host24.de, wo
   `info@snapolino.de` liegt) in `includes/config.php` eintragen:
   `smtp_host`, `smtp_port` (587 fuer STARTTLS, 465 fuer implizites TLS),
   `smtp_user`, `smtp_pass`, `smtp_from_email`.
5. **Rechnungsdaten** im Panel unter **Einstellungen** ausfuellen (Name/
   Firma, Anschrift, steuerlicher Hinweis) - erscheinen auf jeder
   erzeugten Rechnungs-PDF. Voreingestellt ist der Kleinunternehmer-Hinweis
   nach § 19 UStG; bei Regelbesteuerung hier den Text anpassen und ggf.
   Umsatzsteuer-ID ergaenzen.

Zum Testen: Stripe im Test-Modus lassen (Kreditkartennummer
`4242 4242 4242 4242`, beliebiges zukuenftiges Datum/CVC) und mit der
[Stripe CLI](https://stripe.com/docs/stripe-cli) `stripe listen --forward-to
https://snapolino.de/stripe_webhook.php` laufen lassen, falls Webhooks
lokal statt gegen die echte Domain getestet werden sollen.

## Schnittstelle fuer die Box

### `GET /api.php?box=<box_key>`

Header: `X-API-Key: <api_key>`

Optionaler Parameter `?since=<config_version>`: stimmt der Wert mit der
aktuellen Version ueberein, antwortet der Server mit `304 Not Modified`
ohne Body (billiger Preflight-Check, ob sich ueberhaupt etwas geaendert hat).

Antwort (Auszug):

```json
{
  "box_key": "...",
  "box_name": "Box 3 - Hochzeit Mueller",
  "admin_pin": "1234",
  "config_version": 4,
  "layouts": [
    {
      "id": 1,
      "name": "Standard 4er-Collage",
      "slot_count": 4,
      "is_default": true,
      "surcharge_cents": 0,
      "canvas_width": 1800,
      "canvas_height": 1200,
      "frame_file": "standard_4er_ab12cd34.png",
      "frame_url": "https://snapolino.de/frame.php?file=standard_4er_ab12cd34.png",
      "slots": [
        {"index": 0, "x": 40, "y": 40, "width": 850, "height": 550}
      ]
    }
  ],
  "booking": {"customer_name": "Julia Mueller", "event_date": "2026-10-03"},
  "extras": [
    {"name": "Einzelne Bilder drucken", "quantity": 1},
    {"name": "Mehrfachabzug", "quantity": 3}
  ]
}
```

`admin_pin` ist der auf der Box lokal ohne Internet nutzbare PIN-Schutz
fuers On-Box-Admin-Menue (Panel unter **Boxen → Layouts & Zugang**, leer =
kein Schutz). `booking`/`extras` gehoeren zur naechsten bestaetigten
Buchung dieser Box (`event_date >= CURDATE()`, sonst `null`/`[]`) - die Box
matcht Extra-Namen fest gegen "Einzelne Bilder drucken"/"Mehrfachabzug"
(siehe `main.py::extras_flags()`), ein Umbenennen dieser beiden Extras im
Panel wuerde die Zuordnung also brechen.

### `GET /frame.php?file=<name>.png`

Header: `X-API-Key: <api_key>`

Liefert die Rahmen-PNG aus, aber nur wenn die anfragende Box tatsaechlich
ein Layout zugeordnet hat, das genau diese Datei referenziert.

## Jede Aenderung erhoeht `config_version`

- Aendert sich die Layout-Zuordnung einer Box, wird deren `config_version`
  um eins erhoeht.
- Wird ein Layout selbst bearbeitet (Name, Rahmen, Leinwandgroesse,
  Slots) oder geloescht, erhoehen sich die `config_version` aller Boxen,
  denen dieses Layout zugeordnet ist bzw. war.

So kann `cloudsync.py` auf der Box mit `?since=<lokal gespeicherte Version>`
guenstig pruefen, ob ein neuer Abgleich noetig ist.

## Bestehende Installation aktualisieren

Neue Tabellen kommen nicht automatisch per `git pull` in die laufende
Datenbank (siehe `deploy/RASPBERRY_PI.md`). Der Reihe nach einspielen -
**immer mit `--default-character-set=utf8mb4`**, sonst schlaegt das
Einfuegen der Emoji-Icons bei den Beispiel-Extras mit "Incorrect string
value" fehl (der mysql-Client verhandelt sonst oft eine schmalere
Verbindungs-Kodierung, unabhaengig vom Tabellen-Charset):

```bash
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0002_bookings.sql
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0003_extras_and_wizard.sql
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0004_preset_designs.sql
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0005_payments_and_invoices.sql
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0006_format_templates.sql
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0007_coupons_and_billing_address.sql
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0008_box_admin_pin_and_booking_sync.sql
```

Migration 0003 ergaenzt `bookings` um `edit_token`, `total_price_cents` und
`wants_quote`, macht `customer_address` optional (erst ab Schritt 5
Pflicht), fuegt `layouts.category` hinzu und legt `extras`,
`booking_extras` sowie `settings` (inkl. Basispreis) neu an - mit den
gleichen Beispiel-Extras, die `schema.sql` auch bei einer Neuinstallation
seedet.

Migration 0004 fuegt `layouts.is_custom` hinzu, ersetzt das bisher nur als
Platzhalter existierende Standarddesign durch ein echtes und legt 18
mitgelieferte Preset-Designs an (idempotent - ueberschreibt keine
bestehenden Zeilen mit demselben Namen, z.B. eigene Admin-Layouts).

Migration 0005 ergaenzt `bookings` um Stripe-/Rechnungsfelder
(`stripe_session_id`, `stripe_payment_intent`, `paid_at`,
`invoice_number`), legt die Tabelle `invoice_counters` an (fortlaufende
Rechnungsnummern) und seedet Platzhalter-Rechnungsdaten in `settings` -
unbedingt danach im Panel unter **Einstellungen** durch echte Werte
ersetzen (siehe "Zahlung einrichten" oben).

Migration 0006 legt die drei Zusatzformate mit 1/2/3 statt 4 Fotos an.

Migration 0007 legt die Tabelle `coupons` an und ersetzt das einzelne
Freitext-Adressfeld `bookings.customer_address` durch strukturierte Felder
(`customer_street`, `customer_zip`, `customer_city`, `customer_company`,
`invoice_to_company`) sowie `coupon_code`/`discount_cents`. Bestehende
Freitextadressen werden dabei bestmoeglich in `customer_street` uebernommen.

Migration 0008 ergaenzt `boxes` um `admin_pin` (siehe "Schnittstelle fuer
die Box" oben) - im Panel unter **Boxen → Layouts & Zugang** pflegbar.

Ist eine Migration noch nicht eingespielt, zeigt das Panel eine Hinweis-
meldung statt abzustuerzen.

## Offen (siehe auch CLAUDE.md)

- E-Mail-Benachrichtigung nur bei erfolgreicher Zahlung, nicht bei einer
  reinen Angebotsanfrage (dort weiterhin nur im Panel sichtbar).
- Online-Designer bietet nur Farbe/Muster/Text plus verschiebbare
  Fotoflaechen, kein Logo-Upload oder frei platzierbare Textelemente.
- Stripe-Webhook verschickt Rechnung/Mail synchron in der Webhook-Antwort;
  bei SMTP-Ausfaellen dauert die Antwort laenger (Bestaetigung selbst ist
  davon unabhaengig, nur die Mail muesste dann manuell nachverschickt
  werden - `storage/invoices/<Rechnungsnummer>.pdf` liegt in jedem Fall
  bereits vor).

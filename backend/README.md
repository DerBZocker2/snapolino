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
  storage/
    frames/                  hochgeladene Rahmen-PNGs (nicht im Repo)
  public/                   Docroot fuer den Webserver
    index.php                Oeffentliche Startseite
    buchen.php               Oeffentlicher Buchungsassistent (5 Schritte)
    booking_availability.php JSON-Endpunkt: blockierte Tage fuer den Kalender
    layout_preview.php       Oeffentliche Vorschau-PNG fuer die Design-Galerie
    api.php                  Konfigurations-Endpunkt fuer die Box
    frame.php                Liefert eine Rahmen-PNG aus (API-Key, fuer die Box)
    admin/                   Verwaltungs-Panel (Login-geschuetzt, Sidebar-Layout)
      bookings.php             Buchungsanfragen bestaetigen/ablehnen/stornieren
      booking_detail.php       Details, Extras, Gesamtpreis + interne Notiz
      extras.php, extra_form.php   Zusatzoptionen verwalten (Preis, Ein/Aus/Menge)
      settings.php             Basispreis + Beschriftung fuer den Assistenten
```

**Wichtig:** Das Document Root des vhosts muss auf `backend/public` zeigen,
nicht auf `backend/`. So sind `includes/`, `sql/`, `storage/` und `bin/`
grundsaetzlich nicht ueber HTTP erreichbar.

Fuer die konkrete Einrichtung auf dem Raspberry Pi (Apache, MariaDB,
Cloudflare-DNS/TLS) siehe `deploy/RASPBERRY_PI.md`.

## Einrichtung

1. Datenbank anlegen und Schema importieren:
   ```
   mysql -u root -p -e "CREATE DATABASE snapolino CHARACTER SET utf8mb4"
   mysql -u root -p snapolino < sql/schema.sql
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
3. **Design waehlen** - "Fertige Vorlage" zeigt eine nach Kategorie
   filterbare Galerie der echten Layouts aus der Datenbank (Vorschaubilder
   ueber `layout_preview.php`, oeffentlich ohne API-Key). "Online-Designer"
   und "Eigenes hochladen" sind als "Bald verfuegbar" markiert - siehe
   `## Offen`.
4. **Extras** - admin-verwaltete Zusatzoptionen (`extras`-Tabelle), je nach
   Typ als Ein/Aus-Schalter oder mit Mengenauswahl, Preis kann auch negativ
   sein (Rabatt, z.B. "Ohne Druck").
5. **Zusammenfassung** - Telefon/Versandadresse, optional "schriftliches
   Angebot gewuenscht" (`wants_quote`), Versand-Zeitplan und Preisuebersicht.
   Erst hier wird `total_price_cents` (`calc_booking_total()`) berechnet und
   der Status auf `angefragt` gesetzt - vorher ist die Reservierung
   unverbindlich.

Eine `reserviert`-Buchung, die **nicht** innerhalb von `RESERVATION_HOLD_DAYS`
(14 Tage) zu `angefragt` wird, blockiert den Kalender danach nicht mehr
(`fetch_blocked_dates()` prueft das per Zeitfenster, kein Cron noetig).

Im Panel unter **Buchungen**:
- **Bestaetigen** weist der Buchung eine Box zu (bei nur einer Box
  automatisch, sonst per Auswahl), traegt alle gewuenschten Layouts in
  `box_layouts` dieser Box ein und erhoeht ihre `config_version` - die Box
  muss also vor dem Versand einmal online sein, um sie abzuholen.
- **Ablehnen**/**Stornieren** setzen den Status, eine stornierte oder
  abgelehnte Buchung blockiert den Kalender nicht mehr.
- Die Liste zeigt den Gesamtpreis (leer, solange die Buchung noch bei
  `reserviert` haengt), das Detail zusaetzlich die gewaehlten Extras und ob
  ein schriftliches Angebot gewuenscht wurde.

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
  ]
}
```

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
Datenbank (siehe `deploy/RASPBERRY_PI.md`). Der Reihe nach einspielen:

```bash
mysql -u snapolino -p snapolino < backend/sql/migrations/0002_bookings.sql
mysql -u snapolino -p snapolino < backend/sql/migrations/0003_extras_and_wizard.sql
```

Migration 0003 ergaenzt `bookings` um `edit_token`, `total_price_cents` und
`wants_quote`, macht `customer_address` optional (erst ab Schritt 5
Pflicht), fuegt `layouts.category` hinzu und legt `extras`,
`booking_extras` sowie `settings` (inkl. Basispreis) neu an - mit den
gleichen Beispiel-Extras, die `schema.sql` auch bei einer Neuinstallation
seedet.

Ist eine Migration noch nicht eingespielt, zeigt das Panel eine Hinweis-
meldung statt abzustuerzen.

## Offen (siehe auch CLAUDE.md)

- Visueller Slot-Editor im Panel (aktuell Koordinaten per Hand, siehe
  `admin/layout_form.php`).
- E-Mail-Benachrichtigung bei neuer Buchung/Bestaetigung (aktuell nur im
  Panel sichtbar, kein Mailversand).
- Online-Designer und "Eigenes Design hochladen" im Buchungsassistenten
  (Schritt 3 zeigt beide Optionen schon als "Bald verfuegbar" an, aber ohne
  Funktion dahinter - nur "Fertige Vorlage" ist echt).
- Admin-Panel-Design ist funktional, aber optisch noch nicht an die
  bunte/freundliche Optik der oeffentlichen Seite angeglichen.

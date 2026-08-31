# Snapolino Cloud-Backend

PHP 8 + MySQL. Panel zum Anlegen von Layouts (Rahmen-PNG, Leinwandgroesse,
Slot-Koordinaten) und zur Zuordnung von Layouts zu Boxen. Die Box selbst
holt sich diese Konfiguration nur im Vorbereitungsmodus vor dem Versand ab
(`api.php`) und laeuft danach komplett offline weiter.

## Verzeichnisse

```
backend/
  sql/schema.sql        Datenbankschema + Beispiel-Standardlayout
  includes/              PHP-Code, der NICHT direkt aus dem Web erreichbar sein darf
    config.php.example    Vorlage fuer die Zugangsdaten
    config.php             (nicht im Repo, siehe Einrichtung unten)
  bin/
    create_admin.php      CLI-Skript zum Anlegen/Aendern eines Admin-Logins
  storage/
    frames/                hochgeladene Rahmen-PNGs (nicht im Repo)
  public/                 Docroot fuer den Webserver
    api.php                Konfigurations-Endpunkt fuer die Box
    frame.php              Liefert eine Rahmen-PNG aus
    admin/                 Verwaltungs-Panel (Login-geschuetzt)
```

**Wichtig:** Das Document Root des vhosts muss auf `backend/public` zeigen,
nicht auf `backend/`. So sind `includes/`, `sql/`, `storage/` und `bin/`
grundsaetzlich nicht ueber HTTP erreichbar.

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

## Offen (siehe auch CLAUDE.md)

- Visueller Slot-Editor im Panel (aktuell Koordinaten per Hand, siehe
  `admin/layout_form.php`).
- Client-seitiges `cloudsync.py`, das diese API tatsaechlich aufruft.

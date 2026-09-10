# Snapolino – Fotobox-Software

## Kontext
Fotobox-Vermietung (Einzelunternehmen, Region Verden/Bremen, später Versand
deutschlandweit). Diese Software läuft auf der Box selbst.

## Zielhardware
- Windows 11, x86 (aktuell Surface Go, geplant Lenovo ThinkPad X12 Detachable)
- Webcam Logitech Brio (kein DSLR)
- Canon Selphy CP1500, Thermosublimation, 10x15 cm, ~41 s pro Druck
- Touchscreen, Vollbild, keine Tastatur im Betrieb

## Stack
Python 3.10, PySide6 (Qt), OpenCV, Pillow, pywin32, pygrabber, requests.
Build mit PyInstaller `--onedir --windowed`.

## Dateien
- `main.py` – Qt-Oberfläche, Zustandsautomat
- `camera.py` – CameraThread, hält die Kamera dauerhaft offen
- `hardware.py` – USB-, Drucker- und Kameraerkennung über Windows-APIs
- `output.py` – OutputWorker: Speichern, USB-Kopie, Drucken in Warteschlange
- `cloudsync.py` – Konfiguration und Rahmen vom Server holen, Preflight
- `config.py` – Konstanten, liest `box.ini` (nicht im Repo)

## Ablauf
BEREIT → (LAYOUTWAHL falls >1 Layout) → LIVE → COUNTDOWN → AUFNAHME
→ EINZELANSICHT (Wiederholen/Weiter) → nächstes Bild oder COLLAGE
→ Druckfrage mit grünem Knopf → speichern/drucken → BEREIT

Standard sind 4 Bilder als Collage. Andere Layouts nur, wenn der Kunde sie
zur Buchung dazugekauft hat.

## Architekturentscheidungen (bitte beibehalten)
- **Offline-First.** Die Box muss ohne Internet voll funktionieren. Cloud-Sync
  passiert nur im Vorbereitungsmodus vor dem Versand, nie während eines Events.
- **Kamera einmal öffnen und offen lassen.** Wiederholtes Öffnen/Schließen
  hängt Webcams nach einigen hundert Zyklen auf.
- **Nie `time.sleep()`** in der GUI. Alles über QTimer.
- **Drucken im Worker-Thread**, damit die Box während der 41 s weiterläuft.
- **Konfigurationswechsel nur zwischen Sessions**, nie mitten im Ablauf
  (`pending_cfg`).
- Dateien atomar schreiben (`os.replace`), kein halber Zustand nach Stromausfall.

## Cloud-Backend
PHP 8 + MySQL auf snapolino.de, liegt in `backend/` (siehe `backend/README.md`
für Deployment). Docroot ist `backend/public`, alles andere
(`includes/`, `sql/`, `storage/`, `bin/`) ist nicht über HTTP erreichbar.
Panel (`backend/public/admin/`, Login-geschützt) zum Anlegen von Layouts
(Rahmen-PNG, Leinwandgröße, Slot-Koordinaten per Hand) und Zuordnung zu
Boxen. Jede Box bekommt beim Anlegen automatisch das Standard-Layout
(4er-Collage), zusätzliche Formate werden pro Box angehakt (Aufpreis wird
im Panel angezeigt).
- `api.php?box=<box_key>` mit Header `X-API-Key` → Konfigurations-JSON
  (Layouts inkl. Slot-Koordinaten). Optional `?since=<config_version>` für
  einen günstigen Preflight-Check (`304` wenn unverändert).
- `frame.php?file=<name>.png` mit Header `X-API-Key` → Rahmen-Download,
  nur wenn die Box das Layout mit dieser Datei zugeordnet hat.
- Jede Änderung an einer Box-Layout-Zuordnung oder an einem Layout selbst
  erhöht `config_version` der betroffenen Box(en).
- DB-Zugangsdaten in `backend/includes/config.php` (nicht im Repo, siehe
  `config.php.example`), analog zu `box.ini` auf der Box.
- Admin-Panel im Sidebar-Layout (Übersicht/Buchungen/Boxen/Layouts).

## Buchungssystem
Kunden buchen öffentlich unter `/buchen.php`, ein 5-Schritte-Assistent
(Datum → Reservierung → Design → Extras → Zusammenfassung, siehe
`backend/README.md`). Eine Reservierung hält den Termin 14 Tage
unverbindlich (`RESERVATION_HOLD_DAYS`, Status `reserviert`) und wird erst
mit der Zusammenfassung zu `angefragt`. Der Kalender blockiert auf
`angefragt`/`bestätigt` sowie frische `reserviert`-Eintraege, inkl.
`BOOKING_BUFFER_DAYS` Tage Puffer vor/nach dem Event für Versand.
Im Panel unter **Buchungen** bestätigen/ablehnen/stornieren - Bestätigen
weist eine Box zu und überträgt die vom Kunden gewünschten Layouts nach
`box_layouts` (erhöht `config_version`). Aktuell fest auf eine Box
ausgelegt (kein Verfügbarkeits-Overbooking-Schutz über mehrere Boxen).

Design-Schritt: fertige Vorlage aus der Galerie (nach Kategorie
filterbar), Online-Designer (Canvas-Editor für Farbe/Muster/Text, auch um
eine Vorlage per "Anpassen" umzufärben) oder eigenes PNG mit transparenten
Fotoflächen hochladen (Server erkennt die Flächen automatisch per
Connected-Component-Analyse). Beide Wege legen ein `is_custom=1`-Layout an,
das nur dieser einen Buchung zugeordnet ist und weder in der öffentlichen
Galerie noch im allgemeinen Panel bei anderen Kunden auftaucht.

## Konventionen
- Kommentare und Oberflächentexte auf Deutsch, Bezeichner auf Englisch
- Keine Umlaute in Code-Kommentaren (Encoding-Probleme bei PyInstaller)
- Alles Konfigurierbare gehört in `config.py` oder die Cloud, nicht in den Code

## Windows-Setup (Autostart/Kiosk)
`deploy/windows-kiosk-setup.ps1` (siehe `deploy/KIOSK_SETUP.md`) richtet
pro Box einmalig automatischen Login, automatischen App-Start beim
Anmelden (mit Neustart bei Absturz) und ein paar Kiosk-Einstellungen ein,
u.a. die Windows-Taste per Registry-Scancode-Map deaktivieren (Qt selbst
kann sie nicht abfangen).

## Offene Punkte
- Visueller Slot-Editor im Panel (aktuell Koordinaten per Hand)
- Galerie mit QR-Code pro Bild und pro Event (offline-first, verzögerter Upload)
- Vollständiger Windows-Kiosk-Modus ohne sichtbaren Desktop/Explorer
  (bräuchte Shell Launcher, also Windows 11 Enterprise/Education)
- `printer_ready()` erkennt Papierende noch nicht zuverlässig
- Automatische Löschung nach 30 Tagen, AVV, DSGVO-Konzept
- E-Mail-Benachrichtigung bei neuer/bestätigter Buchung (bisher nur im Panel sichtbar)
- Mehrere Boxen im Buchungssystem (aktuell fest auf eine Box ausgelegt)
- Online-Designer bietet nur Farbe/Muster/Text, kein Logo-Upload oder
  freie Platzierung von Elementen
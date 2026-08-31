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
PHP 8 + MySQL auf snapolino.de. Panel zum Anlegen von Layouts (Rahmen-PNG,
Leinwandgröße, Slot-Koordinaten) und Zuordnung zu Boxen.
- `api.php?box=<key>` mit Header `X-API-Key` → Konfigurations-JSON
- `frame.php?file=<name>.png` → Rahmen-Download
Jede Änderung erhöht `config_version`.

## Konventionen
- Kommentare und Oberflächentexte auf Deutsch, Bezeichner auf Englisch
- Keine Umlaute in Code-Kommentaren (Encoding-Probleme bei PyInstaller)
- Alles Konfigurierbare gehört in `config.py` oder die Cloud, nicht in den Code

## Offene Punkte
- Visueller Slot-Editor im Panel (aktuell Koordinaten per Hand)
- Galerie mit QR-Code pro Bild und pro Event (offline-first, verzögerter Upload)
- Windows-Kiosk-Modus (Windows-Taste lässt sich in Qt nicht abfangen)
- `printer_ready()` erkennt Papierende noch nicht zuverlässig
- Automatische Löschung nach 30 Tagen, AVV, DSGVO-Konzept
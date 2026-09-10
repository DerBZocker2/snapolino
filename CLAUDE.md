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
WILLKOMMEN (einmalig beim Start, nur falls eine Buchung bekannt ist -
"Hallo Name, danke fuer die Buchung") → BEREIT (zeigt "Deine Rahmen" -
alle verfuegbaren Formate direkt mit Vorschau, kein separater
Start-Knopf mehr, Antippen eines Rahmens startet sofort) → LIVE →
COUNTDOWN → AUFNAHME → EINZELANSICHT
(Wiederholen/Weiter) → nächstes Bild oder GESAMTUEBERSICHT (alle Bilder;
falls Extra "Einzelne Bilder drucken" gebucht ist, zusaetzlich ein Bild
fuer Extra-Druck auswaehlbar, mit Extra "Mehrfachabzug" dazu die Anzahl
der Abzuege bis zur gebuchten Menge) → COLLAGE → Druckfrage mit gruenem
Knopf → speichern/drucken (Collage + ggf. Einzelbild-Extra-Druck) → BEREIT.

Oben links ein Logo-Knopf oeffnet ein PIN-gesichertes Admin-Menue
(Ziffernblock statt Tastatur, PIN kommt per Cloud-Sync von der jeweiligen
Box - Panel unter **Boxen → Layouts & Zugang**, leer = kein Schutz):
Programm beenden oder die aktuell zwischengespeicherten Buchungsinfos
(Kundenname, Eventdatum, gebuchte Extras) ansehen.

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
  (Layouts inkl. Slot-Koordinaten, `admin_pin`, sowie `booking`/`extras` der
  naechsten bestaetigten Buchung dieser Box, falls vorhanden - fuer den
  Willkommens-Screen und Extra-Verhalten auf der Box). Optional
  `?since=<config_version>` für einen günstigen Preflight-Check (`304`
  wenn unverändert).
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
Im Panel unter **Buchungen** ablehnen/stornieren. Eine Box zuordnen (und
damit gleichzeitig bestätigen) passiert unter **Boxen** per Drag & Drop:
Buchungskarte auf eine Box-Karte ziehen (`assign_box.php` ruft dieselbe
`assign_box_and_confirm()` auf, die auch nach Zahlungseingang automatisch
läuft) - überträgt die vom Kunden gewünschten Layouts nach `box_layouts`
und erhöht `config_version`. Aktuell fest auf eine Box ausgelegt (kein
Verfügbarkeits-Overbooking-Schutz über mehrere Boxen).

Design-Schritt: fertige Vorlage aus der Galerie (nach Kategorie
filterbar, inkl. Formate mit 1/2/3 statt 4 Fotos), Online-Designer
(Canvas-Editor für Farbe/Muster/Text mit per Maus verschiebbaren
Fotoflächen, auch um eine Vorlage per "Anpassen" umzugestalten) oder
eigenes PNG mit transparenten Fotoflächen hochladen (Server erkennt die
Flächen automatisch per Connected-Component-Analyse). Alle drei Wege legen
bei einer Aenderung ein `is_custom=1`-Layout an, das nur dieser einen
Buchung zugeordnet ist und weder in der öffentlichen Galerie noch im
allgemeinen Panel bei anderen Kunden auftaucht. Admin legt neue
Layout-Vorlagen (auch mit anderer Fotoanzahl) im Panel per Drag-Editor an
(`layout_form.php`) statt Pixel-Koordinaten von Hand einzutippen.

Schritt 5 (Zusammenfassung): zweispaltiges Layout, links strukturierte
Rechnungsadresse (Strasse/PLZ/Ort, optional Firma), rechts eine sticky
Buchungsuebersicht mit Gutscheincode-Einloesung. Gutscheine (Prozent oder
Festbetrag, optional Ablaufdatum/Kontingent) werden im Panel unter
**Gutscheine** angelegt; `redemption_count` zaehlt erst hoch, wenn die
Buchung wirklich bestaetigt wird (bezahlt oder Admin bestaetigt eine
Angebots-Buchung), nicht schon beim Einloesen. Kunde zahlt direkt per
Stripe Checkout (Kreditkarte, Klarna etc. - keine Kartendaten beruehren
den eigenen Server) statt nur eine Anfrage abzuschicken. Erst der
Stripe-**Webhook**
(`stripe_webhook.php`, signaturgeprueft) bestaetigt die Buchung endgueltig
und loest Rechnung + Bestaetigungsmail aus - der Redirect zurueck zur Seite
ist nur fuers UI, niemals die Quelle der Wahrheit fuer "bezahlt". Wer noch
unsicher ist, kann stattdessen die Checkbox "nur ein schriftliches
Angebot" waehlen (dann wie bisher `status = angefragt`, keine Zahlung).
Rechnungen: fortlaufende Nummer (`invoice_counters`, ein Zaehler pro Jahr),
PDF per FPDF, Versand per SMTP (PHPMailer) - beide Bibliotheken ohne
Composer eingebunden (`backend/includes/lib/`), Rechnungsdaten
(Name/Adresse/§19-Hinweis) im Panel unter **Einstellungen** pflegbar.

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
- Galerie mit QR-Code pro Bild und pro Event (offline-first, verzögerter Upload)
- Vollständiger Windows-Kiosk-Modus ohne sichtbaren Desktop/Explorer
  (bräuchte Shell Launcher, also Windows 11 Enterprise/Education)
- `printer_ready()` erkennt Papierende noch nicht zuverlässig
- Automatische Löschung nach 30 Tagen, AVV, DSGVO-Konzept
- Mehrere Boxen im Buchungssystem (aktuell fest auf eine Box ausgelegt)
- Online-Designer bietet nur Farbe/Muster/Text plus verschiebbare
  Fotoflächen, kein Logo-Upload oder frei platzierbare Textelemente
- Stripe-Webhook-Verarbeitung ist synchron (PDF-Erzeugung + Mailversand
  laufen direkt in der Webhook-Antwort) - bei SMTP-Ausfaellen haengt das
  die Stripe-Antwortzeit hoch, ohne die Bestaetigung selbst zu verhindern
  (die Buchung ist trotzdem bestaetigt, nur die Mail fehlt und muesste
  manuell nachverschickt werden)
- Keine Rechnungskorrektur/Stornorechnung bei nachtraeglicher Stornierung
  einer bereits bezahlten Buchung
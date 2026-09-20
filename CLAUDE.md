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
  (als getrennte Auftraege, damit ein haengender Druck nicht das Speichern
  spaeter eingereihter Fotos blockiert)
- `gallery.py` – GalleryUploadWorker: laedt Fotos/Collagen automatisch in
  die Online-Galerie der Cloud hoch (siehe "Online-Galerie" unten)
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
fuer Extra-Druck auswaehlbar samt Anzahl der Abzuege (1 bis
`MAX_INDIVIDUAL_PRINT_COPIES`, aktuell 3, fest in main.py - keine zweite
Extra-Buchung fuer die Menge noetig)) → COLLAGE → Druckfrage mit gruenem
Knopf → speichern/drucken → BEREIT. Gespeichert werden dabei immer **alle**
Einzelbilder der Session (`<Zeitstempel>_foto1.jpg` usw.) und die Collage
(`<Zeitstempel>.jpg`) - unabhaengig davon, ob/wie gut der Drucker gerade
erkannt wird. Gedruckt wird weiterhin nur die Collage sowie, falls das
Extra "Einzelne Bilder drucken" gebucht und ein Bild dafuer ausgewaehlt
wurde, zusaetzlich dieses eine Einzelbild. `finish_session()` reiht dafuer
bewusst zuerst alle Speichervorgaenge in die `OutputWorker`-Warteschlange
ein und erst danach die eigentlichen Druckauftraege (`OutputWorker.submit(...,
save=False)`) - sonst wuerde ein an einem Druckerproblem haengender
Collage-Druck (siehe naechster Absatz) das Speichern der danach
eingereihten Einzelbilder verzoegern bzw. verhindern.

Oben links ein Logo-Knopf oeffnet ein PIN-gesichertes Admin-Menue
(Ziffernblock statt Tastatur, PIN kommt per Cloud-Sync von der jeweiligen
Box - Panel unter **Boxen → Layouts & Zugang**, leer = kein Schutz):
Programm beenden oder die aktuell zwischengespeicherten Buchungsinfos
(Kundenname, Eventdatum, gebuchte Extras) ansehen.

Standard sind 4 Bilder als Collage. Andere Layouts (bis zu 3 zusaetzliche
pro Buchung waehlbar) nur, wenn der Kunde sie zur Buchung dazugewaehlt
hat - kostenlos, ausser den 1-Bild- und 2-Bilder-Formaten (`surcharge_cents`
auf den Layouts, siehe Buchungssystem unten).

Schlaegt ein Druckauftrag fehl (Drucker aus, Papier/Farbband leer,
Papierstau...), haengt sich der `OutputWorker` an einem Popup auf
("Erneut versuchen"/"Diesen Druck überspringen") statt den Job stillschweigend
zu verwerfen - nach dem Beheben und "Erneut versuchen" wird derselbe
Ausdruck (aus dem Speicher, kein erneutes Foto noetig) einfach fortgesetzt.
Die Meldung enthaelt nach Moeglichkeit einen konkreten Hinweis
(`hardware.printer_status_message()`), ist aber je nach Treiber nicht
immer praezise (siehe Offene Punkte).

Unabhaengig davon prueft `PrinterWatcherThread` (main.py) den Druckerstatus
im Hintergrund fast sekuendlich (`PRINTER_POLL_INTERVAL_MS`, `hardware.printer_check()`)
und aktualisiert damit sofort den Punkt "Drucker" oben in der Leiste
(Farbe + Klartext als Detailtext). Erkennt es dabei ein Problem, poppt
zusaetzlich ein Hinweisfenster auf - aber nur in BEREIT (nicht auf
WILLKOMMEN, damit ein Druckerproblem direkt beim Start - Drucker evtl. noch
nicht hochgefahren/verbunden - nicht den frisch begruessten Kunden vor dem
"Weiter"-Knopf blockiert, bevor er ueberhaupt starten konnte; der Punkt
"Drucker" oben zeigt das Problem trotzdem durchgehend an) und nie mitten in
einer laufenden Aufnahmesession, sowie nur einmal pro neuem Problem, nicht
bei jeder Pruefung erneut, solange es unveraendert fortbesteht. Sowohl
dieses als auch das Druckfehler-Popup (`_on_print_trouble()`) setzen
`Qt.WindowStaysOnTopHint` und rufen vor `exec()` `raise_()`/`activateWindow()`
auf, damit sie im Vollbild-Kiosk-Fenster sicher sichtbar erscheinen statt
sich moeglicherweise unsichtbar dahinter zu verstecken.

Liegt das Eventdatum der aktuell hinterlegten Buchung mehr als
`return_buffer_days` Tage zurueck (box.ini, Standard 2 - Event 20.9 also
gesperrt ab dem 22.9), zeigt die Box statt WILLKOMMEN/BEREIT eine
Sperrbildschirm mit der Bitte um Ruecksendung (`return_lock_active()` in
main.py). Die Pruefung greift nur im Leerlauf, nie mitten in einer
laufenden Aufnahmesession. Admin-Menue bleibt ueber den Logo-Knopf
weiterhin erreichbar. Die Zuordnung laesst sich im Panel unter **Boxen**
per "Zuordnung aufheben" wieder entfernen (z.B. um die Box fuer den
naechsten Kunden vorzubereiten).

## Architekturentscheidungen (bitte beibehalten)
- **Offline-First.** Die Box muss ohne Internet voll funktionieren. Cloud-Sync
  passiert nur im Vorbereitungsmodus vor dem Versand, nie während eines Events.
- **Kamera einmal öffnen und offen lassen.** Wiederholtes Öffnen/Schließen
  hängt Webcams nach einigen hundert Zyklen auf.
- **Nie `time.sleep()`** in der GUI. Alles über QTimer.
- **Drucken im Worker-Thread**, damit die Box während der 41 s weiterläuft.
- **Konfigurationswechsel nur zwischen Sessions**, nie mitten im Ablauf
  (`pending_cfg`).
- **Kein echtes `showFullScreen()` verwenden** (im
  `if __name__ == "__main__":`-Block), sondern das Fenster randlos
  (`Qt.FramelessWindowHint`) manuell per `setGeometry()` auf
  `app.primaryScreen().geometry()` setzen. Qts eigener Vollbild-
  Fenstermodus hat sich auf manchen Windows-/Grafiktreiber-Kombinationen
  als unzuverlaessig erwiesen (auch mit vorherigem `show()` vor
  `showFullScreen()` half es nicht) - die erste Layout-Berechnung passte
  dann nicht zur tatsaechlichen Bildschirmgroesse, wodurch z.B. der
  "Weiter"-Knopf auf der WILLKOMMEN-Seite unterhalb des sichtbaren Bereichs
  haengen blieb, obwohl im normalen Fenstermodus (Testen mit
  `fullscreen = false` in `box.ini`) alles korrekt aussah.
  `geometry()` statt `availableGeometry()`, weil die Windows-Taskleiste
  dafuer bewusst per `set_taskbar_visible(False)` ausgeblendet wird (siehe
  unten) - mit `availableGeometry()` (Bildschirm ohne Taskleiste) waere die
  Taskleiste stattdessen weiterhin sichtbar am unteren Rand geblieben.
- **`set_dpi_aware()`** (main.py, `SetProcessDpiAwareness`/`SetProcessDPIAware`
  per ctypes) **vor der ersten QApplication-Instanz aufrufen.** Ohne explizite
  DPI-Awareness meldet Windows je nach Version/Kompatibilitaetseinstellung
  eine virtualisierte (skalierte) statt der tatsaechlichen Bildschirm-
  aufloesung - das war die eigentliche Ursache dafuer, dass
  `availableGeometry()`/`geometry()` je nach Rechner/Skalierung einen
  anderen Wert lieferte und das Vollbild-Fenster falsch berechnet wurde.
- **`set_taskbar_visible()`** blendet die Windows-Taskleiste beim Start
  aus (`FindWindowW("Shell_TrayWnd")` + `ShowWindow` per ctypes) und in
  `closeEvent()` beim sauberen Beenden wieder ein. Kein vollstaendiger
  Kiosk-Modus (dafuer braeuchte es Shell Launcher/Windows 11 Enterprise,
  siehe Offene Punkte), aber verhindert, dass die Taskleiste waehrend des
  Betriebs sichtbar/bedienbar ist. Stuerzt das Programm ab, ohne dass
  `closeEvent()` laeuft, bleibt die Taskleiste bis zum naechsten Explorer-/
  Windows-Neustart versteckt (akzeptiertes Restrisiko, siehe Offene Punkte).
- **Aktionsbuttons per `setMinimumHeight()` statt `setFixedHeight()`**
  (WILLKOMMEN-Weiter, Wiederholen/Weiter, Neu starten/Drucken,
  Rahmenauswahl, Uebersicht-Weiter). Ein fixiertes Widget kann Qt nie
  verkleinern, egal wie wenig Platz uebrig ist (kleiner Bildschirm, DPI-
  Skalierung) - dann wird stattdessen das zuletzt hinzugefuegte Widget
  (meist der Haupt-Button) unterhalb des sichtbaren Bereichs abgeschnitten.
  Mit einer Mindestgroesse bleiben die Buttons touch-tauglich, koennen bei
  echtem Platzmangel aber nachgeben statt zu verschwinden.
- **Rahmenauswahl (BEREIT) und Fotouebersicht (GESAMTUEBERSICHT) laufen in
  einer `QScrollArea`**, nicht direkt im Layout - bei vielen Rahmen/Fotos
  (z.B. mehrere Zusatzformate oder ein Layout mit vielen Slots) wuerde die
  Summe der Mindestgroessen sonst den Bildschirm uebersteigen koennen, ohne
  Ausweichmoeglichkeit. Die Fotouebersicht-Kacheln haben ausserdem eine
  feste Groesse (`REVIEW_THUMB_SIZE`) unabhaengig vom Seitenverhaeltnis des
  jeweiligen Fotos, sonst waeren sie je nach Rahmenformat unterschiedlich
  gross.
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
- Logo: `backend/public/assets/logo-icon.png` (Kamera-Icon, aus dem
  offiziellen Snapolino-Logo freigestellt, transparenter Hintergrund),
  dazu `favicon.ico`/`apple-touch-icon.png` im selben Ordner - eingebunden
  in `_site_header.php` (oeffentliche Seiten), `admin/_header.php`
  (Sidebar) und `admin/login.php`, jeweils per `asset_url()` mit
  Cache-Busting.
- Alle Transaktionsmails (`includes/mailer.php`, PHPMailer/SMTP) sind
  HTML mit `AltBody`-Fallback fuer Clients ohne HTML-Anzeige: zentrierte
  Karte (tabellenbasiert statt CSS `margin:auto`, damit es auch in Outlook
  Desktop - rendert HTML-Mails mit der Word-Engine - zuverlaessig
  zentriert bleibt), Logo oben per `addEmbeddedImage()`/`cid:` direkt in
  die Mail eingebettet statt extern verlinkt (funktioniert unabhaengig von
  Bilderblockierung, wirkt nicht wie eine nachladende externe Ressource).
  Gemeinsame Bausteine `render_email_html()`/`email_p()`/`email_button()`/
  `email_muted()`/`email_signoff()`, jede `send_*_email()`-Funktion baut
  daraus ihren HTML-Body und behaelt parallel eine reine Textversion fuer
  `AltBody`. Ein "Profilbild" neben der Absenderadresse (z.B. in Gmail)
  ist keine Sache des Mailinhalts, sondern des Postfachs/der Domain -
  siehe Offene Punkte.

## Buchungssystem
Kunden buchen öffentlich unter `/buchen.php`, ein 5-Schritte-Assistent
(Datum → Kontaktdaten → Design → Extras → Zusammenfassung, siehe
`backend/README.md`). Es gibt keine unverbindliche Zwischenstufe mehr -
bereits Schritt 2 (Name/E-Mail) legt die Buchung direkt mit Status
`angefragt` an, da aktuell nur eine Box existiert und sich eine
automatisch verfallende Reservierung (frueher `RESERVATION_HOLD_DAYS`
Tage, Status `reserviert`) dafuer nicht lohnt. Der Kalender blockiert auf
`angefragt`/`bestätigt`, inkl. `BOOKING_BUFFER_DAYS` Tage Puffer vor/nach
dem Event für Versand - eine abgebrochene Anfrage bleibt also bis zum
manuellen Ablehnen/Stornieren im Panel blockiert (kein Cronjob).
Im Panel unter **Buchungen** ablehnen/stornieren. Eine Box zuordnen (und
damit gleichzeitig bestätigen) passiert unter **Boxen** per Drag & Drop:
Buchungskarte auf eine Box-Karte ziehen (`assign_box.php` ruft dieselbe
`assign_box_and_confirm()` auf, die auch nach Zahlungseingang automatisch
läuft) - überträgt die vom Kunden gewünschten Layouts nach `box_layouts`
und erhöht `config_version`. Aktuell fest auf eine Box ausgelegt (kein
Verfügbarkeits-Overbooking-Schutz über mehrere Boxen). Über "Zuordnung
aufheben" auf der Box-Karte lässt sich die Zuordnung wieder entfernen
(`box_id` auf `NULL`, Status bleibt `bestätigt`, `config_version` wird
ebenfalls erhöht) - die Buchung erscheint danach wieder unter "Buchungen
ohne Box".

Design-Schritt: fertige Vorlage aus der Galerie (nach Kategorie
filterbar, inkl. Formate mit 1/2/3 statt 4 Fotos, bis zu 3 Zusatzformate
gleichzeitig ankreuzbar - clientseitig deaktiviert das JS weitere
Checkboxen, serverseitig kappt `buchen.php` zusaetzlich auf 3). Alle
Layouts sind kostenlos, ausser den 1-Bild- und 2-Bilder-Formaten (`layouts.surcharge_cents`,
weiterhin pro Layout im Panel unter Layouts editierbar - die Migration
`0010_free_designs_and_single_print.sql` setzt nur den Ausgangswert).
Online-Designer
(Canvas-Editor für Farbe/Muster mit per Maus verschieb- und am
Eck-Ziehpunkt größenveränderbaren Fotoflächen, dazu beliebig viele frei
platzierbare Text- und Sticker/Emoji-Elemente - je Element eigene
Größe, Text-Elemente zusätzlich eigene Farbe -, auch um eine Vorlage per
"Anpassen" umzugestalten) oder
eigenes PNG mit transparenten Fotoflächen hochladen (Server erkennt die
Flächen automatisch per Connected-Component-Analyse). Alle drei Wege legen
bei einer Aenderung ein `is_custom=1`-Layout an, das nur dieser einen
Buchung zugeordnet ist und weder in der öffentlichen Galerie noch im
allgemeinen Panel bei anderen Kunden auftaucht. Admin legt neue
Layout-Vorlagen (auch mit anderer Fotoanzahl) im Panel per Drag-Editor an
(`layout_form.php`) statt Pixel-Koordinaten von Hand einzutippen.

Die 22 mitgelieferten Preset-Rahmen (`storage/frames/preset_*_v2.png`) werden
per `backend/tools/generate_presets.py` (PIL, kein Laufzeit-Bestandteil)
erzeugt: vier unterschiedliche Foto-Anordnungen (`grid`/`hero`/`strip`/`stack`
in `ARRANGEMENTS`, je mit eigenem, ausserhalb aller Fotoflaechen liegendem
Beschriftungsband) statt eines einzigen geteilten 2x2-Rasters, dazu passend
zum Anlass Text (verschiedene Google-Fonts, siehe `tools/fonts/`) und
gezeichnete Icons (Herz, Stern, Schneeflocke, Ringe, Sonne, Blatt, Konfetti)
bei einem Teil der Designs. Jedes Fotofeld wird als scharfkantiges Rechteck
an die Slot-Koordinaten gesetzt (`compose_collage()` in main.py, unveraendert)
und danach von der Rahmen-PNG mit einem abgerundeten, ausgeschnittenen
Fenster ueberdeckt (`punch_window()`) - die Rundung entsteht also rein durch
den Rahmen, main.py muss nichts davon wissen. Aendert sich ein Preset-Design,
bekommt die Datei ein neues Versions-Suffix (aktuell `_v2`) statt den Namen
wiederzuverwenden - sonst wuerde eine bereits synchronisierte Box die neue
Grafik nie herunterladen, weil `cloudsync.py` eine lokal bereits vorhandene
Datei desselben Namens als aktuell ansieht, unabhaengig vom tatsaechlichen
Inhalt.

Schritt 5 (Zusammenfassung): zweispaltiges Layout, links strukturierte
Rechnungsadresse (Strasse/PLZ/Ort, optional Firma) - waehrend der Eingabe
schlaegt eine Autocomplete gegen den oeffentlichen Adresssuchdienst Photon
(komoot, OpenStreetMap-Daten, siehe `datenschutz.php`) passende Adressen
vor und fuellt PLZ/Ort automatisch korrekt aus; rein optional, ohne
Vorschlagsauswahl bleiben die drei Felder normal von Hand ausfuellbar,
rechts eine sticky Buchungsuebersicht mit Vorschaubild je gewaehltem
Layout (holt die Zeilen bewusst per `fetch_layout_with_slots()` statt aus
der schon geladenen Layout-Liste, da diese eigene Designs ausschliesst -
sonst waere ein per Online-Designer/Upload erstelltes Design in der
Uebersicht unsichtbar gewesen) und Gutscheincode-Einloesung. Gutscheine (Prozent oder
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
Rechnungen: ausschliesslich ueber Stripe, kein eigenes PDF mehr (bis
Migration 0015 gab es zusaetzlich eine eigene fortlaufende Nummer + FPDF-
PDF, das entfaellt fuer neue Buchungen komplett). `invoice_creation` bei
der Stripe-Checkout-Session laesst automatisch eine bei Stripe gehostete
Rechnung entstehen (`payments.php::store_stripe_invoice()` holt PDF-/
Ansichtslink ab und loest den Mailversand direkt durch Stripe aus,
`send_stripe_invoice()`) - die eigene Bestaetigungsmail (PHPMailer/SMTP,
Versand per `includes/mailer.php`) verlinkt sie zusaetzlich. Rechnungsdaten
(Name/Adresse/§19-Hinweis, zusaetzlich Kontakt-E-Mail/Telefon) im Panel
unter **Einstellungen** pflegbar - diese speisen nur noch Impressum und
Datenschutzerklaerung (siehe unten), fuer die Rechnung selbst zaehlt das
Geschaeftsprofil im Stripe-Dashboard. Storniert ein Admin im Panel unter
**Buchungen** eine bereits bezahlte Buchung, erstattet `cancel_booking()`
die Zahlung automatisch vollstaendig ueber Stripe zurueck
(`stripe_refund_payment()`) und erstellt zur bestehenden Stripe-Rechnung
eine Stornorechnung (`stripe_create_credit_note()`, ein Stripe Credit
Note, kreditiert alle Positionen) - der Kunde bekommt dazu eine
Stornobestaetigung mit Link zur Stornorechnung per Mail
(`send_booking_cancelled_email()`). Eine ohne Zahlung bestaetigte
Angebots-Buchung bekommt beim Stornieren nur die Mail. Schlaegt
Rueckerstattung/Stornorechnung bei Stripe fehl, wird trotzdem storniert
(sonst wuerde die Buchung den Kalender weiter blockieren) und die Warnung
landet im Log sowie als Hinweis in `booking_detail.php` - der Admin muss
es dann von Hand im Stripe-Dashboard nachholen.

**Rechtliche Pflichtseiten** (`impressum.php`, `datenschutz.php`,
`agb.php`, verlinkt im Footer jeder oeffentlichen Seite ueber
`_site_header.php`/`_site_footer.php`) ziehen Name/Anschrift/Kontakt aus
denselben Settings wie die Rechnung; fehlende Angaben werden sichtbar als
"[... bitte ergaenzen]" markiert statt sie stillschweigend wegzulassen.
Schritt 5 verlangt zusaetzlich eine Checkbox "AGB und Datenschutzerklaerung
akzeptiert", deren Zeitpunkt in `bookings.agb_accepted_at` als Nachweis
gespeichert wird (im Panel unter Buchungsdetails sichtbar). Das
Admin-Panel wird auf keiner oeffentlichen Seite verlinkt (kein
"Admin-Login" mehr auf Startseite/Buchungsseite) - Zugriff nur ueber die
direkte URL `admin/login.php`.

**Kundenkonten** (`konto.php`, `includes/customer_auth.php`): ein Konto
pro E-Mail-Adresse, Login per 6-stelligem Code per Mail statt Passwort
(15 Minuten gueltig, max. 5 Fehlversuche, 60s Sperre zwischen zwei
Codes). Wird automatisch beim Anlegen einer Buchungsanfrage angelegt/
wiederverwendet (`bookings.customer_account_id`). Nach Login zeigt das
Konto alle Buchungen dieser E-Mail-Adresse (auch Alt-Buchungen von vor
Einfuehrung der Kontenfunktion, per Migration ueber die E-Mail-Adresse
verknuepft) mit Bearbeiten-Link (fuehrt zum bestehenden `edit_token`-Link
in `buchen.php`) oder Ansehen-Link. Eine Buchung ist fuer den Kunden
bearbeitbar, solange sie noch `angefragt` ist, oder wenn ein
Admin sie trotz `bestaetigt`-Status ausdruecklich wieder freigeschaltet
hat (`bookings.edit_unlocked_by_admin`, Button in booking_detail.php) -
`buchen.php` selbst blockt Schritt 2-5 serverseitig fuer nicht (mehr)
bearbeitbare Buchungen (`booking_customer_editable()`), zeigt stattdessen
einen Hinweis "Buchung abgeschlossen".

**Admin-Bearbeitung von Buchungen** (`admin/booking_detail.php`): Admin
kann Eventdatum, Layouts und Extras direkt aendern (Checkbox-Liste analog
zum Assistenten). Erhoeht die Aenderung bei einer bereits `bestaetigt`en
Buchung den Gesamtpreis ueber das bisher Gebuchte/Bezahlte hinaus, fragt
eine Zwischenseite nach, ob die Differenz **kostenlos uebernommen** oder
per **neuem Stripe-Zahlungslink** an den Kunden nachgefordert werden soll.
"Kostenlos uebernehmen" wird sofort auf die Buchung angewendet. Bei
"Zahlungslink senden" dagegen wird die gewuenschte Aenderung (Datum/
Layouts/Extras/neuer Gesamtpreis) zunaechst nur in der neuen
`booking_addon_charges`-Zeile als `pending_changes_json` zwischengespeichert
(eigene Checkout-Session, `stripe_webhook.php` unterscheidet per Metadata
`booking_id` vs. `addon_charge_id` zwischen Erst- und Nachzahlung) - die
Buchung selbst (und damit auch, was die Box anzeigt) bleibt unveraendert,
bis Stripe die Zahlung per Webhook bestaetigt und `mark_addon_charge_paid()`
die gespeicherte Aenderung tatsaechlich uebernimmt. So wird ein bezahlpflichtiges
Extra nie schon aktiv, bevor der Kunde wirklich bezahlt hat. Erst dabei
ueberträgt `sync_booking_to_box()` (falls die Buchung bereits einer Box
zugeordnet ist) neue Layouts nach `box_layouts` und erhoeht die
`config_version` (auch bei reinen Extra-/Datumsaenderungen, die `api.php`
sonst nur dynamisch, aber am `?since`-Preflight vorbei mitliefern wuerde).
Fuer eine bezahlte Zusatzzahlung stellt Stripe automatisch eine eigene
Rechnung aus und verschickt sie per Mail (`invoice_creation`, wie bei der
Hauptbuchung - es gibt dafuer keine eigene fortlaufende Rechnungsnummer).
In der Buchungsdetailansicht sieht der Admin alle Zusatzzahlungen dieser
Buchung samt Status (offen/bezahlt) und Link zur jeweiligen Stripe-Rechnung.
Das individuelle Design einer Buchung
(`layouts.is_custom = 1`, unsichtbar im allgemeinen Panel) laesst sich
direkt aus den Buchungsdetails heraus ansehen/anpassen (Link zu
`layout_form.php?id=...`, das ohne weitere Anpassung auch fuer
Custom-Layouts funktioniert).

## Online-Galerie

Sobald die Fotobox nach dem Event wieder mit dem Internet verbunden ist
(typischerweise nach der Rueckgabe/dem Rueckversand), laedt sie alle
Einzelbilder und Collagen der Session automatisch in eine oeffentliche
Online-Galerie hoch - komplett unabhaengig vom lokalen Speichern/Drucken,
das immer funktioniert, auch ganz ohne Internet (Offline-First bleibt
dadurch unangetastet).

- **Box-Seite** (`gallery.py`, `GalleryUploadWorker`): eigener QThread,
  parallel zu `OutputWorker`. `finish_session()` (main.py) uebergibt ihm
  eine Kopie (`.copy()`, da parallel zum Speichern in einem zweiten Thread
  verarbeitet - `PIL.Image.save()` ist fuer gleichzeitige Aufrufe auf
  demselben Objekt nicht als threadsicher dokumentiert) jedes Einzelbilds
  und der Collage, zusammen mit der Buchungs-ID aus dem zuletzt
  synchronisierten Cloud-Stand (`cloudsync.get_booking()["id"]`, siehe
  api.php unten). Ohne bekannte Buchung oder Cloud-Zugangsdaten passiert
  nichts. Der Worker schreibt jedes Foto zunaechst atomar in
  `cache/gallery_queue/<booking_id>__<dateiname>.jpg` und versucht sofort
  den Upload per `requests.post` an `upload_photo.php` - schlaegt das fehl
  (kein Internet, Server nicht erreichbar, 5xx), bleibt die Datei liegen
  und wird periodisch (alle 5 Minuten) sowie bei jedem Programmstart
  automatisch erneut versucht. Eine dauerhaft abgelehnte Anfrage (4xx, z.B.
  weil die Box inzwischen einer anderen Buchung neu zugeordnet wurde) wird
  verworfen statt endlos wiederholt zu werden - das Foto bleibt aber in
  jedem Fall lokal auf der Box (und ggf. USB-Stick) gespeichert, nur der
  Galerie-Upload muesste dann von Hand nachgeholt werden.
- **api.php** liefert dafuer zusaetzlich die numerische `id` der aktuellen
  Buchung mit (`bookingData.id`), damit die Box Fotos auch nach einer
  spaeteren Neuzuordnung der Box eindeutig zuordnen kann.
- **Cloud-Seite**: `upload_photo.php` nimmt ein einzelnes Foto entgegen
  (Header `X-API-Key`, Felder `box`/`booking_id`, Datei `photo`) - wie
  frame.php erst nach Pruefung von box_key+api_key, zusaetzlich muss die
  Buchung *aktuell* dieser Box zugeordnet sein (`bookings.box_id`).
  Erzeugt beim ersten Foto einer Buchung einmalig einen Galerie-Token
  (`ensure_gallery_token()`, analog zu `edit_token`) und legt die Datei
  unter `backend/storage/gallery/<booking_id>/<dateiname>` ab (ausserhalb
  des Webroots, Ordner per `gallery_storage_dir()`/config.php-Schluessel
  `gallery_storage_dir`, siehe config.php.example). `gallery_photos`
  (Migration 0016) haelt Dateiname und Art (`foto`/`collage`) je Buchung
  fest, `gallery_photo.php` liefert ein einzelnes Foto per Galerie-Token
  aus (optional `&download=1` fuer "Datei speichern unter").
- **`galerie.php`** ist die oeffentliche Galerie-Seite unter dem
  unratbaren Link `?token=<gallery_token>` - bewusst in einem eigenen
  dunklen Design (anders als die uebrige, helle Webseite), kein
  Kundenkonto-Login noetig, damit auch Gaeste ohne eigenes Konto die
  Fotos ansehen/laden koennen. Zeigt alle Fotos als Grid (Collage mit
  Badge markiert), Klick oeffnet eine Lightbox (reines Vanilla-JS, mit
  Pfeiltasten-/Klick-Navigation) fuer die Grossansicht, jede Kachel und
  die Lightbox haben einen eigenen Download-Knopf.
- **Admin-Panel** (`booking_detail.php`) zeigt bei einer bestaetigten
  Buchung die Anzahl hochgeladener Fotos, einen Link zur Galerie und einen
  Knopf "Link per Mail an Kunde senden" (`send_gallery_email()` in
  mailer.php, reine Textmail wie die anderen `send_*_email()`-Funktionen -
  scheitert der Versand mangels SMTP-Konfiguration, bleibt das folgenlos,
  wie bei den anderen Mailversand-Funktionen auch).
- Die hochgeladenen Fotos enthalten personenbezogene Daten von Event-
  Gaesten - `backend/storage/gallery/` liegt daher wie `storage/frames`/
  `storage/invoices` ausserhalb des Webroots, ist per `.htaccess`
  zusaetzlich gegen Skriptausfuehrung/Directory-Listing abgesichert und
  nicht im Repo (`.gitignore`). Der Galerie-Link ist unratbar, aber ohne
  Login oeffentlich abrufbar (bewusst, damit auch Gaeste ohne Kundenkonto
  zugreifen koennen) - wer den Link kennt (z.B. weitergeleitet von einem
  Gast), sieht alle Fotos der Veranstaltung. Automatische Loeschung nach
  einer Frist gibt es noch nicht (siehe Offene Punkte).

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
- Absender-"Profilbild" neben `info@snapolino.de` (z.B. in Gmail) ist keine
  Code-Frage, sondern Konto-/Domain-Einstellung - noch nicht eingerichtet:
  Gravatar (gravatar.com-Konto fuer die Adresse, Logo hochladen - wirkt in
  Outlook/Apple Mail/Thunderbird), Google-Konto-Profilbild (falls das
  Postfach ueber Google Workspace laeuft) oder BIMI (zeigt das Logo bei
  DMARC-Enforcement in Gmail/Yahoo u.a. an, braucht i.d.R. ein teures
  verifiziertes Markenzertifikat - fuer die aktuelle Groesse eher nicht
  lohnend).
- Online-Galerie (siehe oben) hat noch keinen QR-Code auf der Box selbst
  (z.B. auf der COLLAGE-Seite fuers direkte Scannen durch Gaeste vor Ort) -
  aktuell nur per Mail-Link vom Admin verschickt, sobald die Box wieder
  synchronisiert hat
- Galerie-Fotos koennen nur einzeln heruntergeladen werden, kein
  "Alle als ZIP herunterladen"
- Keine automatische Loeschung der Galerie-Fotos nach einer Frist (DSGVO,
  siehe auch naechster Punkt)
- Vollständiger Windows-Kiosk-Modus ohne sichtbaren Desktop/Explorer
  (bräuchte Shell Launcher, also Windows 11 Enterprise/Education) -
  `set_taskbar_visible()` blendet immerhin die Taskleiste waehrend des
  Betriebs aus, bleibt aber bei einem Absturz ohne sauberes `closeEvent()`
  bis zum naechsten Explorer-/Windows-Neustart versteckt
- `printer_status_message()`/`printer_ready()` fragen inzwischen sowohl die
  klassischen Windows-Statusflags als auch WMI (`Win32_Printer.DetectedErrorState`)
  ab, und `output.py` wartet nach jedem Druckauftrag zusaetzlich auf dessen
  Job-Status (`hardware.job_status()`), weil manche Fotodrucker-Treiber
  (u.a. der Selphy CP1500) ein Problem wie eine entnommene Papierkassette
  erst dort und nicht im globalen Druckerstatus zeigen. Trotzdem nicht bei
  jedem Treiber zuverlässig/vollstaendig - `python hardware.py [Druckername]`
  auf dem Geraet mit dem Drucker gibt alle Rohwerte aller drei Quellen aus,
  falls ein Fehlerzustand weiterhin nicht erkannt wird. Das Speichern aller
  Fotos ist davon aber bewusst unabhaengig (siehe "Ablauf" oben) - selbst
  wenn der Drucker gar nicht oder falsch erkannt wird, landen alle
  Einzelbilder und die Collage trotzdem auf der Platte, nur der Ausdruck
  haengt dann am Popup fest.
- Automatische Löschung nach 30 Tagen, AVV, DSGVO-Konzept
- Mehrere Boxen im Buchungssystem (aktuell fest auf eine Box ausgelegt)
- Online-Designer bietet Farbe/Muster, verschieb- und
  größenveränderbare Fotoflächen sowie beliebig viele frei platzierbare
  Text- und Sticker/Emoji-Elemente (Position per Maus, Größe je Element
  per Schieberegler, bei Text zusätzlich Farbe) - kein Logo-/Bild-Upload
  als Element, keine Rotation der Elemente
- Stripe-Webhook-Verarbeitung ist synchron (Mailversand laeuft direkt in
  der Webhook-Antwort) - bei SMTP-Ausfaellen haengt das die
  Stripe-Antwortzeit hoch, ohne die Bestaetigung selbst zu verhindern
  (die Buchung ist trotzdem bestaetigt, nur die Mail fehlt und muesste
  manuell nachverschickt werden)
- Schlaegt die automatische Rueckerstattung/Stornorechnung beim Stornieren
  einer bezahlten Buchung bei Stripe fehl, gibt es noch keinen
  automatischen Retry - nur eine Warnung im Log/in `booking_detail.php`,
  der Admin muss es von Hand im Stripe-Dashboard nachholen
- Storniert eine bereits bezahlte Buchung mit noch offenen (nicht
  bezahlten) Zusatzzahlungen (`booking_addon_charges`) hebt diese nicht
  automatisch auf - die veraltete Zahlungslink-Mail bliebe gueltig,
  muesste der Admin von Hand im Stripe-Dashboard deaktivieren
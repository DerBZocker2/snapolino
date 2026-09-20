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
    lib/PHPMailer/           PHPMailer (SMTP-Mailversand), manuell eingebunden
    stripe.php               Rohe Stripe-API-Anbindung per cURL (kein SDK) -
                             Checkout, Rechnung, Rueckerstattung, Stornorechnung
    payments.php             mark_booking_paid()/cancel_booking(): Buchung
                             bestaetigen/stornieren, Mail ausloesen. Es gibt
                             keine eigene Rechnungs-PDF mehr - ausschliesslich
                             die bei Stripe gehostete Rechnung ist massgeblich.
    mailer.php               Bestaetigungs-/Stornomail verschicken (PHPMailer/SMTP)
  bin/
    create_admin.php        CLI-Skript zum Anlegen/Aendern eines Admin-Logins
    purge_expired_galleries.php  Loescht abgelaufene Galerie-Fotos (DSGVO), fuer taeglichen Cronjob
    send_event_reminders.php     Verschickt Erinnerungsmails vor dem Event, fuer taeglichen Cronjob
  tools/
    generate_presets.py      Erzeugt die 22 mitgelieferten Preset-Rahmen per PIL
                             (kein Laufzeit-Bestandteil, nur bei Design-Ueberarbeitung)
  storage/
    frames/                  Rahmen-PNGs: preset_*.png sind mitgelieferte
                             Design-Vorlagen (im Repo), alles andere sind
                             echte Uploads/Kundendesigns (nicht im Repo)
    invoices/                Vor Migration 0015 erzeugte eigene Rechnungs-PDFs
                             (historisch, personenbezogen, nicht im Repo)
    gallery/                 Von der Box automatisch hochgeladene Event-Fotos
                             (personenbezogen, nicht im Repo, siehe Online-Galerie unten)
  public/                   Docroot fuer den Webserver
    index.php                Oeffentliche Startseite
    buchen.php               Oeffentlicher Buchungsassistent (5 Schritte)
    booking_availability.php JSON-Endpunkt: blockierte Tage fuer den Kalender
    layout_preview.php       Oeffentliche Vorschau-PNG fuer die Design-Galerie
    stripe_webhook.php       Nimmt Stripe-Zahlungsbestaetigungen entgegen (signaturgeprueft)
    api.php                  Konfigurations-Endpunkt fuer die Box
    frame.php                Liefert eine Rahmen-PNG aus (API-Key, fuer die Box)
    upload_photo.php         Nimmt Fotos/Collagen von der Box fuer die Online-Galerie entgegen
    gallery_photo.php        Liefert ein einzelnes Galerie-Foto aus (Gallery-Token)
    galerie.php              Oeffentliche Online-Galerie einer Buchung (dunkles Design)
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
6. Fuer die automatische Loeschung der Online-Galerie-Fotos nach Ablauf der
   Aufbewahrungsfrist (DSGVO, Panel unter **Einstellungen** editierbar,
   Standard 30 Tage nach dem Eventdatum) einen taeglichen Cronjob einrichten:
   ```
   crontab -e
   # taeglich um 4 Uhr nachts:
   0 4 * * * php /var/www/snapolino.de/backend/bin/purge_expired_galleries.php >> /var/log/snapolino-gallery-purge.log 2>&1
   ```
   Ohne diesen Cronjob werden die Fotos weiterhin unbegrenzt gespeichert -
   die Anzeige der Aufbewahrungsfrist im Panel/auf der Galerie-Seite selbst
   loescht nichts, sie ist nur die Ankuendigung dafuer.
7. Fuer die automatische Erinnerungsmail vor dem Event (Panel unter
   **Einstellungen** editierbar, Standard 7 Tage vorher) ebenfalls einen
   taeglichen Cronjob einrichten:
   ```
   crontab -e
   # taeglich um 8 Uhr morgens:
   0 8 * * * php /var/www/snapolino.de/backend/bin/send_event_reminders.php >> /var/log/snapolino-reminders.log 2>&1
   ```
   Ohne diesen Cronjob bekommen Kunden keine automatische Erinnerung - im
   Panel unter Buchungsdetails laesst sie sich bei Bedarf trotzdem jederzeit
   manuell verschicken.

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
2. **Kontaktdaten** - Name/E-Mail legen eine Zeile in `bookings` direkt mit
   Status `angefragt` an (das Standard-Layout landet direkt in
   `booking_layouts`) und vergeben einen `edit_token`, mit dem der Assistent
   die Buchung ueber alle weiteren Schritte hinweg wiederfindet (Token steht
   in der URL, keine PHP-Session noetig - der Kunde kann die Seite also
   schliessen und mit dem Link aus der Bestaetigungsmail spaeter
   weitermachen). Es gibt keine unverbindliche Zwischenstufe - da nur eine
   Box existiert, blockiert die Anfrage den Kalender sofort und dauerhaft,
   bis ein Admin sie ablehnt/storniert oder sie bestaetigt/bezahlt wird
   (siehe "Verfuegbarkeit" unten).
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
   - **Online-Designer**: Canvas-Editor (Hintergrund-/Akzentfarbe, Muster),
     rendert clientseitig eine PNG mit der Slot-Geometrie eines gewaehlten
     Basis-Layouts (die Foto-Slots werden per
     `globalCompositeOperation = 'destination-out'` transparent
     ausgeschnitten). Die Fotoflaechen selbst lassen sich direkt im Canvas
     per Maus verschieben (gestrichelter Umriss zur Orientierung, "Zuruecksetzen"
     stellt die Ausgangsposition wieder her) - die neuen Koordinaten werden
     als JSON mit abgeschickt (`custom_slots`, serverseitig via
     `validate_custom_slots()` geprueft, faellt bei verdaechtigen Werten auf
     die Basis-Geometrie zurueck). Zusaetzlich beliebig viele Text- und
     Sticker/Emoji-Elemente per "+ Text"/"+ Sticker" hinzufuegbar (`elements`
     im JS, rein clientseitig - keine eigene Datenbankspalte), jedes einzeln
     per Maus im Canvas verschiebbar sowie ueber die Liste darunter in
     Inhalt/Farbe (nur Text) und Groesse (Schieberegler) anpassbar oder
     entfernbar. Landen alle vor dem finalen Rendern auf demselben Canvas
     wie Hintergrund/Muster/Fotoflaechen, es gibt also keinen separaten
     Speicherpfad dafuer - das Endergebnis ist wie bisher nur die fertig
     gerenderte PNG.
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
   optional Firma; das Strasse-Feld schlaegt beim Tippen passende Adressen
   ueber den oeffentlichen Adresssuchdienst Photon (komoot, basierend auf
   OpenStreetMap) vor und fuellt PLZ/Ort damit automatisch korrekt - Klick
   auf einen Vorschlag oder normal von Hand ausfuellen, Ergebnisse ohne
   Land Deutschland werden clientseitig herausgefiltert), Versand-Zeitplan,
   Gutscheincode-Einloesung und
   Preisuebersicht (`calc_booking_pricing()`, kombiniert `calc_booking_total()`
   mit einem eingeloesten Gutschein). Zwei Wege zum Abschluss:
   - **Jetzt bezahlen** (Standardfall): erstellt eine Stripe Checkout
     Session (`create_stripe_checkout_session()`, mit `invoice_creation`
     aktiviert) und leitet zur von Stripe gehosteten Kassenseite weiter -
     keine Kartendaten beruehren den eigenen Server. Erst der **Webhook**
     `stripe_webhook.php` (Event `checkout.session.completed`, Signatur per
     `verify_stripe_webhook()` geprueft) bestaetigt die Buchung endgueltig
     (`payments.php::mark_booking_paid()`): Status `bestaetigt`, Box
     automatisch zugewiesen (wenn eindeutig moeglich), Bestaetigungsmail
     verschickt. Es gibt seit Migration 0015 **keine eigene Rechnungs-PDF
     mehr** - `store_stripe_invoice()` holt lediglich die von Stripe bei der
     Checkout-Session automatisch erstellte Rechnung ab (PDF-/Ansichtslink,
     `bookings.stripe_invoice_*`) und laesst Stripe sie selbst per Mail
     verschicken (`send_stripe_invoice()`); die eigene Bestaetigungsmail
     verlinkt sie zusaetzlich. Der Redirect des Browsers zurueck auf die
     Erfolgsseite ist nur fuers UI gedacht und bestaetigt selbst nichts.
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

Jede `angefragt`-Buchung blockiert den Kalender dauerhaft, es gibt keine
automatisch verfallende Zwischenstufe mehr (`fetch_blocked_dates()` prueft
einfach `status IN ('angefragt', 'bestaetigt')`, kein Cron noetig) - eine
abgebrochene Anfrage muss also im Panel unter **Buchungen** aktiv
abgelehnt/storniert werden, um den Termin wieder freizugeben.

Im Panel unter **Buchungen**:
- **Ablehnen** setzt nur den Status (fuer eine noch unbezahlte `angefragt`-
  Buchung), **Stornieren** (`cancel_booking()`) macht bei einer bereits
  bezahlten Buchung zusaetzlich drei Dinge: die Zahlung wird ueber Stripe
  vollstaendig zurueckerstattet (`stripe_refund_payment()`, aufs
  urspruengliche Zahlungsmittel), zur bestehenden Stripe-Rechnung wird eine
  Stornorechnung erstellt (`stripe_create_credit_note()`, ein Stripe Credit
  Note, kreditiert alle Rechnungspositionen) und der Kunde bekommt eine
  Stornobestaetigung mit Link zur Stornorechnung per Mail. Eine ohne Zahlung
  bestaetigte Angebots-Buchung bekommt beim Stornieren nur die Mail, da es
  nichts zurueckzubuchen gibt. Beides (Ablehnen wie Stornieren) setzt den
  Status, eine stornierte oder abgelehnte Buchung blockiert den Kalender
  nicht mehr. Schlaegt Rueckerstattung oder Stornorechnung bei Stripe fehl,
  wird das geloggt und in `booking_detail.php` als Warnung angezeigt -
  storniert wird die Buchung trotzdem, damit sie den Kalender nicht laenger
  blockiert; der Admin muss die Rueckerstattung dann von Hand im
  Stripe-Dashboard nachholen.
- Die Liste zeigt den Gesamtpreis (leer, solange die Buchung Schritt 5 noch
  nicht erreicht hat), das Detail zusaetzlich die gewaehlten Extras und ob
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

Auf jeder Box-Karte kann die aktuelle Zuordnung ueber **Zuordnung
aufheben** wieder entfernt werden (setzt `box_id` auf `NULL`, der Status
`bestaetigt` bleibt unangetastet, da die Buchung ja bereits bezahlt sein
kann - sie taucht danach wieder unter "Buchungen ohne Box" auf). Erhoeht
ebenfalls `config_version`, sonst wuerde die Box beim naechsten
Preflight-Check (`?since=`) einen `304` bekommen und die alte Buchung
weiter anzeigen.

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
   Firma, Anschrift, Kontakt-E-Mail/Telefon, steuerlicher Hinweis) -
   erscheinen im Impressum und in der Datenschutzerklaerung auf der
   Buchungsseite (fuer die eigentliche Rechnung siehe Schritt 6, seit
   Migration 0015 stellt ausschliesslich Stripe sie aus). Voreingestellt
   ist der Kleinunternehmer-Hinweis nach § 19 UStG; bei Regelbesteuerung
   hier den Text anpassen und ggf. Umsatzsteuer-ID ergaenzen.
6. **Geschaeftsprofil bei Stripe** unter **Dashboard -> Einstellungen ->
   Unternehmen** ausfuellen (Name, Anschrift, Steuer-ID) - diese Angaben
   erscheinen auf der von Stripe automatisch erstellten Rechnung und
   Stornorechnung (siehe oben), die seit Migration 0015 die einzige
   Rechnung ist, die ein Kunde bekommt.

**Kostet die Stripe-Rechnung extra?** Eine per `invoice_creation` an eine
Checkout Session gehaengte Rechnung ist Teil von Stripe Checkout und kostet
zusaetzlich zur ohnehin anfallenden Zahlungsgebuehr (Standard EU-Karte:
i.d.R. 1,5 % + 0,25 € pro Zahlung, siehe Dashboard) nichts extra - anders
als Stripes eigenstaendiges "Invoicing"-Produkt (Rechnungen von Hand
erstellen/versenden, eigene Gebuehr pro bezahlter Rechnung), das hier gar
nicht genutzt wird. Rueckerstattungen (`stripe_refund_payment()`) und
Credit Notes sind ebenfalls kostenlos, erstatten aber nur den Betrag - die
urspruengliche Zahlungsgebuehr bekommt man bei einer Stornierung nicht
zurueck. Diese Angaben koennen sich aendern; verbindlich ist immer die
aktuelle Preisseite/das eigene Dashboard bei Stripe.

Zum Testen: Stripe im Test-Modus lassen (Kreditkartennummer
`4242 4242 4242 4242`, beliebiges zukuenftiges Datum/CVC) und mit der
[Stripe CLI](https://stripe.com/docs/stripe-cli) `stripe listen --forward-to
https://snapolino.de/stripe_webhook.php` laufen lassen, falls Webhooks
lokal statt gegen die echte Domain getestet werden sollen.

## Rechtliche Seiten (Impressum, Datenschutz, AGB)

`impressum.php`, `datenschutz.php` und `agb.php` sind oeffentliche Seiten,
verlinkt im Footer jeder Kundenseite (`_site_header.php`/`_site_footer.php`
- gemeinsamer Kopf-/Fussbereich fuer index.php/buchen.php/die drei
Rechtsseiten, damit z.B. kein Admin-Link versehentlich auf einer davon
landet). Sie ziehen Name/Anschrift/Kontakt-E-Mail/Telefon/Steuerhinweis
aus denselben Settings wie die Rechnungsdaten (siehe oben) - fehlt eine
Angabe, erscheint auf der Seite sichtbar "[... bitte ergaenzen]" statt sie
stillschweigend wegzulassen.

**Wichtig:** Die Texte sind ein sorgfaeltig recherchiertes Muster, aber
keine Rechtsberatung. Vor dem Livegang unbedingt insbesondere folgende
Punkte pruefen (lassen):
- Die Stornobedingungen in `agb.php` Ziffer 7 (30/14-Tage-Staffel,
  50 %/100 % Ausfallgebuehr) sind ein Vorschlag - an die eigene Kalkulation
  anpassen.
- Der Ausschluss des Widerrufsrechts (`agb.php` Ziffer 10, § 312g Abs. 2
  Nr. 9 BGB, Freizeitbetaetigung mit festem Termin) ist eine gaengige,
  aber nicht gerichtlich fuer Fotobox-Vermietung bestaetigte Einordnung.
- In `datenschutz.php` fehlt bewusst der Name des Hosting- und
  Versanddienstleisters (als "[bitte ergaenzen]" markiert) - dort die
  tatsaechlich genutzten Anbieter eintragen.

Jede erfolgreiche Buchung (Schritt 5) verlangt eine Pflicht-Checkbox "AGB
und Datenschutzerklaerung akzeptiert"; der Zeitpunkt wird als Nachweis in
`bookings.agb_accepted_at` gespeichert und ist im Panel bei den
Buchungsdetails sichtbar.

## Kundenkonten (`konto.php`)

Beim Anlegen einer Buchungsanfrage (Schritt 2) wird automatisch ein Konto zur
angegebenen E-Mail-Adresse angelegt/wiederverwendet
(`find_or_create_customer_account()`, `includes/customer_auth.php`) und
mit der Buchung verknuepft (`bookings.customer_account_id`). Login unter
`/konto.php` funktioniert ganz ohne Passwort: E-Mail eintragen, 6-stelligen
Code aus der Mail eingeben (15 Minuten gueltig, max. 5 Fehlversuche pro
Code, mindestens 60 Sekunden zwischen zwei angeforderten Codes). Nach dem
Login zeigt das Konto alle Buchungen dieser Adresse (auch Alt-Buchungen
von vor Einfuehrung dieser Funktion - Migration 0011 verknuepft sie
nachtraeglich ueber die E-Mail-Adresse) mit einem Link zum Weiterbearbeiten
(fuehrt zum bestehenden `edit_token`-Link von `buchen.php`) oder, wenn die
Buchung bereits abgeschlossen ist, zum reinen Ansehen.

Eine Buchung ist fuer den Kunden bearbeitbar, solange sie noch
`angefragt` ist. Sobald sie `bestaetigt` ist (bezahlt
oder Admin hat eine Angebots-Buchung manuell bestaetigt), blockiert
`buchen.php` Schritt 2-5 serverseitig (`booking_customer_editable()`) -
ausser ein Admin hat die Bearbeitung fuer genau diese eine Buchung wieder
freigeschaltet (`bookings.edit_unlocked_by_admin`, Schalter in
`admin/booking_detail.php` - fuer den Fall, dass dem Kunden nachtraeglich
ein Fehler auffaellt, z.B. eine falsche Adresse).

## Admin: Buchungen nachtraeglich bearbeiten

Im Panel unter **Buchungen → Details** kann ein Admin Eventdatum, Layouts
und Extras einer Buchung direkt aendern - dieselbe Checkbox-Auswahl wie im
Buchungsassistenten. Erhoeht die Aenderung bei einer bereits `bestaetigt`en
Buchung den Gesamtpreis ueber das bisher Gebuchte/Bezahlte hinaus, fragt
eine Zwischenseite nach, wie damit umgegangen werden soll:

- **Kostenlos uebernehmen**: neuer Gesamtpreis wird gespeichert, keine
  weitere Zahlung eingefordert (z.B. Kulanz).
- **Zahlungslink an Kunde senden**: die Preisdifferenz wird als eigene
  Zeile in `booking_addon_charges` angelegt, dafuer eine eigene Stripe-
  Checkout-Session erstellt (`create_addon_charge_checkout_session()`) und
  ein Zahlungslink per Mail verschickt. `stripe_webhook.php` unterscheidet
  anhand der Metadata (`booking_id` fuer die Erstzahlung, `addon_charge_id`
  fuer eine Nachforderung) und markiert die jeweils richtige Zeile als
  bezahlt. Fuer diese Nachforderungen gibt es keine eigene fortlaufende
  Rechnungsnummer wie bei der Hauptbuchung - stattdessen stellt Stripe
  dafuer automatisch eine eigene Rechnung aus (`invoice_creation`, wie bei
  der Hauptbuchung), die per Mail an den Kunden geht und im Panel bei den
  Buchungsdetails verlinkt ist.

Wird dabei eine bereits einer Box zugeordnete Buchung veraendert, ueberträgt
`sync_booking_to_box()` neu gewuenschte Layouts nach `box_layouts` (wie
beim urspruenglichen Bestaetigen) und erhoeht in jedem Fall die
`config_version` der Box - auch bei einer reinen Extra- oder
Datumsaenderung, die `api.php` sonst zwar dynamisch mitliefern wuerde,
aber am guenstigen `?since`-Preflight vorbei stumpf gecacht bliebe.

Das individuelle Design einer Buchung (Online-Designer/Upload,
`layouts.is_custom = 1`, taucht im allgemeinen Panel unter **Layouts**
nicht auf) laesst sich direkt aus den Buchungsdetails heraus ansehen bzw.
anpassen - ein Link fuehrt zu `layout_form.php?id=<id>`, das ohne weitere
Anpassung auch fuer Custom-Layouts funktioniert (dort gibt es sonst keinen
Einstiegspunkt dafuer).

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
  "booking": {"id": 42, "customer_name": "Julia Mueller", "event_date": "2026-10-03"},
  "extras": [
    {"name": "Einzelne Bilder drucken", "quantity": 1}
  ]
}
```

`admin_pin` ist der auf der Box lokal ohne Internet nutzbare PIN-Schutz
fuers On-Box-Admin-Menue (Panel unter **Boxen → Layouts & Zugang**, leer =
kein Schutz). `booking`/`extras` gehoeren zur naechsten bestaetigten
Buchung dieser Box (`event_date >= CURDATE()`, sonst `null`/`[]`) - die Box
matcht den Extra-Namen fest gegen "Einzelne Bilder drucken" (siehe
`main.py::extras_flags()`), ein Umbenennen dieses Extras im Panel wuerde
die Zuordnung also brechen. Ist das Extra gebucht, kann am Ende der
Session ein Foto fuer einen Zusatzdruck ausgewaehlt und bis zu
`MAX_INDIVIDUAL_PRINT_COPIES` (aktuell 3, fest in main.py) mal einzeln
gedruckt werden - keine zweite Extra-Buchung fuer die Anzahl noetig.

### `GET /frame.php?file=<name>.png`

Header: `X-API-Key: <api_key>`

Liefert die Rahmen-PNG aus, aber nur wenn die anfragende Box tatsaechlich
ein Layout zugeordnet hat, das genau diese Datei referenziert.

### `POST /upload_photo.php` (Online-Galerie)

Header: `X-API-Key: <api_key>`. Formularfelder (`multipart/form-data`):
`box` (box_key), `booking_id` (aus `booking.id` von `api.php`, siehe oben),
Datei im Feld `photo`. Der Dateiname muss dem von `main.py::finish_session()`
erzeugten Format entsprechen (`<Zeitstempel>.jpg` fuer die Collage,
`<Zeitstempel>_fotoN.jpg` fuer ein Einzelbild) - daraus wird automatisch
`kind` (`collage`/`foto`) abgeleitet. Die Buchung muss aktuell dieser Box
zugeordnet sein (`bookings.box_id`), sonst `404`. Erzeugt beim ersten Foto
einer Buchung automatisch deren `gallery_token`. Wird von `gallery.py` auf
der Box aufgerufen, sobald sie wieder Internet hat - unabhaengig vom
lokalen Speichern/Drucken, das immer funktioniert (Offline-First).

### `GET /gallery_photo.php?token=<gallery_token>&file=<name>.jpg`

Kein API-Key noetig (oeffentlich, wie die Galerie-Seite selbst) - der
Gallery-Token ist der Zugriffsschutz. Optional `&download=1` fuer
"Datei speichern unter" statt Inline-Anzeige. Genutzt von `galerie.php`,
der oeffentlichen Galerie-Seite unter `/galerie.php?token=<gallery_token>`
(Link steht im Admin-Panel bei der jeweiligen Buchung, siehe
`booking_detail.php`, und kann per Knopf per Mail an den Kunden
verschickt werden).

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
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0009_legal_pages.sql
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0010_free_designs_and_single_print.sql
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0011_customer_accounts.sql
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0012_addon_charge_pending_changes.sql
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0013_stripe_invoices.sql
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0014_remove_reservation_status.sql
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0015_booking_cancellation.sql
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0016_photo_gallery.sql
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0017_preset_redesign.sql
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0018_gallery_management.sql
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0019_event_reminders.sql
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0020_waitlist.sql
mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0021_referral_program.sql
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

Migration 0009 seedet `business_email`/`business_phone` in `settings` und
ergaenzt `bookings` um `agb_accepted_at` (siehe "Rechtliche Seiten" oben).

Migration 0010 setzt `layouts.surcharge_cents` auf 0 fuer alle Formate
ausser 1-/2-Bild (bleibt pro Layout im Panel weiterhin editierbar) und
benennt das Extra "Mehrfachdruck" in "Einzelne Bilder drucken" um (siehe
main.py `EXTRA_INDIVIDUAL_PRINTS`/`MAX_INDIVIDUAL_PRINT_COPIES` in
CLAUDE.md - das Extra hiess vorher nicht so, wie main.py es erwartet
hatte, das Feature lief also nie).

Migration 0011 legt `customer_accounts` und `booking_addon_charges` an,
ergaenzt `bookings` um `customer_account_id` (nachtraeglich fuer
Bestandsbuchungen ueber die E-Mail-Adresse befuellt) und
`edit_unlocked_by_admin` (siehe "Kundenkonten" und "Admin: Buchungen
nachtraeglich bearbeiten" oben).

Migration 0012 ergaenzt `booking_addon_charges` um `pending_changes_json`:
waehlt der Admin bei einer nachtraeglichen Aenderung "Zahlungslink an Kunde
senden", wird die Aenderung (Eventdatum/Layouts/Extras/neuer Gesamtpreis)
ab jetzt erst hier zwischengespeichert und NICHT sofort auf die Buchung
angewendet - das passiert erst in `mark_addon_charge_paid()`, wenn Stripe
per Webhook die Zahlung bestaetigt. "Kostenlos uebernehmen" bleibt weiterhin
sofort wirksam.

Migration 0013 ergaenzt `bookings` und `booking_addon_charges` um
`stripe_invoice_id`/`stripe_invoice_pdf_url`/`stripe_invoice_hosted_url` -
siehe "Zahlung (Stripe) und Rechnungen einrichten" oben.

Migration 0014 hebt bestehende `reserviert`-Datensaetze auf `angefragt` an
und setzt den Standardwert der Spalte `status` entsprechend um - es gibt
ab jetzt keine unverbindliche Reservierung mehr, jede Terminwahl ist sofort
eine echte, den Kalender blockierende Anfrage (siehe "Ablauf" oben).

Migration 0015 ergaenzt `bookings` um `cancelled_at`, `stripe_refund_id`,
`stripe_credit_note_id` und `stripe_credit_note_pdf_url` fuer die
automatische Rueckerstattung/Stornorechnung beim Stornieren einer bezahlten
Buchung (`cancel_booking()`, siehe "Im Panel unter Buchungen" oben). Das
eigene Rechnungs-PDF (FPDF, `includes/invoice.php`) entfaellt ab hier
komplett - `invoice_number` bleibt nur fuer vorher bezahlte Buchungen als
historischer Wert stehen.

Migration 0016 ergaenzt `bookings` um `gallery_token` und legt die Tabelle
`gallery_photos` an - Grundlage der automatischen Online-Galerie (siehe
"Online-Galerie" in CLAUDE.md und "Schnittstelle fuer die Box" oben).
Zusaetzlich muss der Ordner `backend/storage/gallery/` existieren und fuer
den Webserver-Nutzer beschreibbar sein (analog zu `storage/frames/`) -
optional laesst sich der Pfad ueber `gallery_storage_dir` in
`includes/config.php` anpassen (siehe `config.php.example`).

Migration 0017 ersetzt die 22 mitgelieferten Preset-Rahmen durch huebschere,
neu gestaltete Versionen (abgerundete Fotoflaechen mit Passepartout-Rand,
vier unterschiedliche Foto-Anordnungen statt immer desselben 2x2-Rasters,
einige Designs mit Text/Icons passend zum Anlass - siehe
`backend/tools/generate_presets.py`). Aktualisiert dafuer `frame_file` und
die Slot-Koordinaten aller betroffenen Layouts sowie `config_version` aller
Boxen, denen eines davon zugeordnet ist. Die neuen Rahmen-Dateien tragen ein
`_v2`-Suffix statt die alten Namen wiederzuverwenden, damit bereits
synchronisierte Boxen sie automatisch nachladen (siehe cloudsync.py: eine
lokal bereits vorhandene Datei desselben Namens gilt sonst faelschlich als
aktuell).

Migration 0018 trennt die Online-Galerie in einen Verwalter-Link
(`gallery_token` - Fotos aus-/einblenden, sieht den Gaeste-Link) und einen
neuen, separaten `gallery_guest_token` (read-only, keine ausgeblendeten
Fotos) - siehe "Online-Galerie" in CLAUDE.md. Ergaenzt ausserdem
`gallery_photos.hidden` und `bookings.gallery_deleted_at` sowie die neue
Einstellung `gallery_retention_days` (Standard 30 Tage nach Eventdatum) fuer
die automatische Loeschung per `bin/purge_expired_galleries.php` (siehe
Cronjob-Hinweis oben unter "Ablauf beim Anlegen einer Box").

Migration 0019 ergaenzt `bookings.reminder_sent_at` und die Einstellung
`reminder_days_before_event` (Standard 7 Tage vor Eventdatum) fuer die
automatische Erinnerungsmail per `bin/send_event_reminders.php` (siehe
Cronjob-Hinweis oben).

Migration 0020 legt `waitlist_entries` fuer die oeffentliche Warteliste
(`/warteliste.php`) bereits ausgebuchter Termine an - siehe "Warteliste" in
CLAUDE.md. Kein Cronjob noetig, die Benachrichtigung passiert direkt beim
Ablehnen/Stornieren einer Buchung im Panel.

Migration 0021 ergaenzt `coupons.referral_owner_booking_id` und
`bookings.referral_reward_sent_at` sowie die Einstellungen
`referral_discount_percent`/`referral_reward_cents` fuers Empfehlungsprogramm -
siehe "Empfehlungsprogramm" in CLAUDE.md. Kein Cronjob noetig, laeuft direkt
beim Bestaetigen einer Buchung im Panel bzw. per Stripe-Webhook.

Ist eine Migration noch nicht eingespielt, zeigt das Panel eine Hinweis-
meldung statt abzustuerzen.

## Offen (siehe auch CLAUDE.md)

- E-Mail-Benachrichtigung nur bei erfolgreicher Zahlung, nicht bei einer
  reinen Angebotsanfrage (dort weiterhin nur im Panel sichtbar).
- Online-Designer bietet Farbe/Muster, verschiebbare Fotoflaechen sowie
  beliebig viele frei platzierbare Text-/Sticker-Elemente, aber keinen
  Logo-/Bild-Upload als Element und keine Rotation der Elemente.
- Stripe-Webhook verschickt Bestaetigungsmail synchron in der
  Webhook-Antwort; bei SMTP-Ausfaellen dauert die Antwort laenger
  (Bestaetigung selbst ist davon unabhaengig, nur die Mail muesste dann
  manuell nachverschickt werden - die Stripe-Rechnung bleibt in jedem Fall
  im Stripe-Dashboard abrufbar).
- Storniert der Admin eine bereits bezahlte Buchung und die Rueckerstattung
  oder Stornorechnung schlaegt bei Stripe fehl (z.B. Netzwerkproblem), wird
  das nur geloggt/in `booking_detail.php` als Warnung angezeigt - es gibt
  noch keinen automatischen Retry, der Admin muss es von Hand im
  Stripe-Dashboard nachholen.
- Storniert eine bereits bezahlte Buchung mit noch offenen (nicht bezahlten)
  Zusatzzahlungen (`booking_addon_charges`) hebt diese nicht automatisch
  auf - die veraltete Zahlungslink-Mail bliebe gueltig, muesste der Admin
  von Hand im Stripe-Dashboard deaktivieren.

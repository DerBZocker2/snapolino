# Deployment auf dem Raspberry Pi (Apache + MariaDB + Cloudflare)

Diese Anleitung geht von folgendem Setup aus:
- Apache mit PHP (mod_php) laeuft schon fuer andere Seiten auf dem Pi.
- Feste oeffentliche IP, Port 80/443 werden bereits per Port-Forwarding
  im Router an den Pi weitergeleitet (fuer die anderen Seiten).
- MariaDB laeuft bereits auf dem Pi.
- Die Domain `snapolino.de` liegt bei Cloudflare (DNS + Proxy).

Da Apache mehrere Domains ueber denselben Port 80/443 per Name-based
Virtual Hosting bedient, ist **kein zusaetzliches Port-Forwarding**
noetig - nur ein neuer vhost.

## 1. Code auf den Pi bringen

```bash
sudo mkdir -p /var/www/snapolino.de
sudo chown $USER:$USER /var/www/snapolino.de
git clone https://github.com/DerBZocker2/snapolino.git /var/www/snapolino.de
```

Bei spaeteren Updates reicht `git pull` im selben Verzeichnis.

## 2. Datenbank anlegen

```bash
sudo mysql -u root -p
```

```sql
CREATE DATABASE snapolino CHARACTER SET utf8mb4;
CREATE USER 'snapolino'@'localhost' IDENTIFIED BY 'EIN_SICHERES_PASSWORT';
GRANT ALL PRIVILEGES ON snapolino.* TO 'snapolino'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

```bash
mysql -u snapolino -p snapolino < /var/www/snapolino.de/backend/sql/schema.sql
```

## 3. Konfiguration und Admin-Login

```bash
cd /var/www/snapolino.de/backend
cp includes/config.php.example includes/config.php
nano includes/config.php   # db_user/db_pass/base_url eintragen
```

`base_url` muss `https://snapolino.de` sein (wird fuer `frame_url` in der
API-Antwort verwendet).

```bash
php bin/create_admin.php mein_benutzername "sicheres passwort"
```

## 4. Rechte setzen

Apache (`www-data`) muss `storage/frames/` beschreiben koennen:

```bash
sudo chown -R www-data:www-data /var/www/snapolino.de/backend/storage
sudo chmod 750 /var/www/snapolino.de/backend/storage/frames
```

`includes/config.php` sollte nicht world-readable sein:

```bash
sudo chown www-data:www-data /var/www/snapolino.de/backend/includes/config.php
sudo chmod 640 /var/www/snapolino.de/backend/includes/config.php
```

## 5. DNS bei Cloudflare

Im Cloudflare-Dashboard fuer snapolino.de unter **DNS**:

| Typ | Name | Inhalt              | Proxy       |
|-----|------|----------------------|-------------|
| A   | @    | deine feste oeff. IP | An (orange) |
| A   | www  | deine feste oeff. IP | An (orange) |

Proxy "An" (oranges Wolkensymbol) verbirgt die private Heim-IP hinter
Cloudflare.

## 6. TLS: Cloudflare Origin-Zertifikat

Im Cloudflare-Dashboard:
1. **SSL/TLS -> Overview**: Modus auf **Full (strict)** stellen.
2. **SSL/TLS -> Origin Server -> Create Certificate**: Hostnamen
   `snapolino.de` und `*.snapolino.de` eintragen, RSA 2048, Gueltigkeit
   15 Jahre. Cloudflare zeigt dann ein Zertifikat + privaten Schluessel an.

Auf dem Pi ablegen:

```bash
sudo mkdir -p /etc/ssl/cloudflare
sudo nano /etc/ssl/cloudflare/snapolino.de.pem   # Zertifikat einfuegen
sudo nano /etc/ssl/cloudflare/snapolino.de.key   # privaten Schluessel einfuegen
sudo chmod 600 /etc/ssl/cloudflare/snapolino.de.key
```

## 7. Apache-vhost einrichten

```bash
sudo cp /var/www/snapolino.de/backend/deploy/apache-snapolino.de.conf \
        /etc/apache2/sites-available/snapolino.de.conf
sudo a2enmod ssl headers
sudo a2ensite snapolino.de
sudo apachectl configtest
sudo systemctl reload apache2
```

Prueft der `configtest`, ob sich der neue vhost nicht mit einem
bestehenden `*:443`-Default-vhost auf dem Pi in die Quere kommt (Apache
waehlt sonst per SNI/ServerName den richtigen aus - bei Problemen die
anderen vhost-Dateien auf doppelte `ServerName`-Eintraege pruefen).

## 8. Testen

```bash
curl -I https://snapolino.de/admin/login.php
```

Sollte `200 OK` liefern. Danach im Browser `https://snapolino.de/admin/`
oeffnen und mit dem in Schritt 3 angelegten Login einloggen.

## Danach

- Erste Box im Panel anlegen (siehe `backend/README.md`).
- `box_key` und `api_key` in die `box.ini` der Fotobox eintragen.
- Box einmal mit Internetverbindung starten, damit sie Konfiguration und
  Rahmen abholt - danach laeuft sie komplett offline weiter.

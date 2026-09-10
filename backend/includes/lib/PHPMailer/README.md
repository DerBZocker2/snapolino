# PHPMailer

Version 6.9.3, unveraendert von https://github.com/PHPMailer/PHPMailer
uebernommen (nur PHPMailer.php, SMTP.php, Exception.php - alles was fuer
reinen SMTP-Versand mit Anhang gebraucht wird). Lizenz: LGPL 2.1, siehe
`LICENSE` in diesem Ordner.

Manuell eingebunden statt per Composer (`autoload.php` bindet die drei
Dateien in der richtigen Reihenfolge ein), damit das Projekt ohne
Build-Step bleibt - das ist der von PHPMailer selbst dokumentierte Weg
ohne Composer.

Bei Sicherheitsluecken in zukuenftigen PHPMailer-Versionen: die drei
Dateien hier durch eine neuere Version von
https://github.com/PHPMailer/PHPMailer/releases ersetzen.

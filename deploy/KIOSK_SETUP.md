# Windows-Kiosk-Setup fuer die Fotobox

Die Box wird meist per Versand unbeaufsichtigt beim Kunden aufgebaut -
sie muss nach dem Einschalten also ganz ohne Bedienung direkt startklar
sein. `windows-kiosk-setup.ps1` richtet dafuer einmalig ein:

1. **Automatischer Windows-Login** fuer einen dedizierten Fotobox-Benutzer
   (kein Login-Bildschirm, den der Kunde sehen wuerde).
2. **Windows-Taste systemweit deaktiviert** (per Registry-Scancode-Map).
   Qt kann die Windows-Taste nicht selbst abfangen (siehe CLAUDE.md,
   "Offene Punkte") - auf Betriebssystem-Ebene geht es aber.
3. **Ruhezustand/Bildschirmabschaltung/Sperrbildschirm aus.** Ergaenzt
   das `SetThreadExecutionState` aus `main.py` (das verhindert nur
   Standby waehrend das Programm laeuft, nicht generelle Power-Settings).
4. **Geplante Aufgabe**, die `Fotobox.exe` bei der Anmeldung automatisch
   startet, mit 10 Sekunden Verzoegerung (Kamera/Treiber brauchen kurz)
   und automatischem Neustart, falls die App abstuerzt.

## Voraussetzungen

- Ein dedizierter lokaler Windows-Benutzer fuer die Fotobox (normaler
  Standard-Benutzer reicht, kein Administrator noetig fuer den Betrieb).
- Die fertig gebaute `Fotobox.exe` liegt bereits an ihrem endgueltigen
  Ort (z.B. `C:\Fotobox\Fotobox.exe`), inklusive `box.ini` daneben (siehe
  `deploy/windows-kiosk-setup.ps1` verlangt nur den Pfad, nicht dass
  alles schon perfekt konfiguriert ist).

## Ausfuehren

PowerShell **als Administrator** oeffnen:

```powershell
cd C:\Pfad\zum\snapolino-repo\deploy
.\windows-kiosk-setup.ps1 -Username fotobox -Password "IhrPasswort" -ExePath "C:\Fotobox\Fotobox.exe"
```

Danach einmal neu starten, um zu pruefen, dass die Box direkt im
Vollbild landet.

## Sicherheitshinweis

Das Passwort des Auto-Login-Benutzers liegt danach im Klartext in der
Registry (`HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon`).
Das ist bei einem dediziertem Kiosk-Geraet ohne sensible Daten der
uebliche und akzeptierte Weg - trotzdem: kein Passwort verwenden, das
auch woanders (E-Mail, Cloud-Zugang) benutzt wird, und dem Fotobox-
Benutzer keine Administratorrechte geben.

## Wenn sich box.ini oder die .exe spaeter aendern

Der Autostart zeigt fest auf den `-ExePath` von damals. Wird die .exe
per Update ausgetauscht, aber am selben Pfad abgelegt, ist nichts weiter
zu tun. Aendert sich der Pfad, das Skript mit dem neuen `-ExePath`
erneut ausfuehren (ueberschreibt die bestehende geplante Aufgabe).

## Rueckgaengig machen

```powershell
Unregister-ScheduledTask -TaskName "Fotobox-Autostart" -Confirm:$false
Remove-ItemProperty -Path "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" -Name "AutoAdminLogon","DefaultPassword"
Remove-ItemProperty -Path "HKLM:\SYSTEM\CurrentControlSet\Control\Keyboard Layout" -Name "Scancode Map"
```

## Was das Skript NICHT abdeckt (spaeter ggf. noetig)

- **Vollstaendiger Kiosk-Modus** (kein Desktop/Explorer/Taskleiste
  sichtbar, App laeuft als Windows-Shell): geht robust nur mit
  "Shell Launcher" - das braucht Windows 11 Enterprise/Education, nicht
  Home/Pro. Fuer den Anfang reicht Vollbild + blockiertes Alt+F4 (schon
  in `main.py` umgesetzt) + deaktivierte Windows-Taste meist aus.
- **Windows-Update-Neustarts**: unkritisch, da die Box waehrend eines
  Events sowieso offline ist (kein Internet = kein automatisches Update
  moeglich). Nur vor dem Versand kurz pruefen, ob gerade ein Update
  ansteht, das einen Neustart erzwingen koennte.

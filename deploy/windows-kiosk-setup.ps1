<#
    Einmalig als Administrator ausfuehren, wenn eine neue Fotobox-Windows-
    Installation fertig eingerichtet ist (Treiber, Drucker, box.ini, .exe
    liegen schon bereit). Richtet automatischen Login, automatischen
    App-Start beim Anmelden und ein paar Kiosk-Einstellungen ein, damit
    die Box nach dem Einschalten ganz ohne Bedienung sofort startklar ist
    (wichtig, da die Box meist unbeaufsichtigt beim Kunden per Versand
    ankommt).

    Aufruf (PowerShell als Administrator):
        .\windows-kiosk-setup.ps1 -Username fotobox -Password "IhrPasswort" -ExePath "C:\Fotobox\Fotobox.exe"
#>

#Requires -RunAsAdministrator

param(
    [Parameter(Mandatory = $true)]
    [string]$Username,

    [Parameter(Mandatory = $true)]
    [string]$Password,

    [string]$ExePath = "C:\Fotobox\Fotobox.exe"
)

if (-not (Test-Path $ExePath)) {
    Write-Warning "Achtung: $ExePath existiert (noch) nicht - Autostart wird trotzdem eingerichtet."
}

Write-Host "1. Automatischer Windows-Login fuer '$Username' wird eingerichtet..."
$winlogonPath = "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon"
Set-ItemProperty -Path $winlogonPath -Name "AutoAdminLogon" -Value "1"
Set-ItemProperty -Path $winlogonPath -Name "DefaultUserName" -Value $Username
Set-ItemProperty -Path $winlogonPath -Name "DefaultPassword" -Value $Password
Set-ItemProperty -Path $winlogonPath -Name "DefaultDomainName" -Value $env:COMPUTERNAME

Write-Host "2. Windows-Taste wird systemweit deaktiviert (Qt kann sie selbst nicht abfangen)..."
$layoutPath = "HKLM:\SYSTEM\CurrentControlSet\Control\Keyboard Layout"
# Scancode-Map: deaktiviert linke (E0,5B) und rechte (E0,5C) Windows-Taste.
$scancodeMap = [byte[]](
    0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
    0x03, 0x00, 0x00, 0x00,
    0x00, 0x00, 0x5B, 0xE0,
    0x00, 0x00, 0x5C, 0xE0,
    0x00, 0x00, 0x00, 0x00
)
Set-ItemProperty -Path $layoutPath -Name "Scancode Map" -Value $scancodeMap -Type Binary

Write-Host "3. Ruhezustand, Bildschirmabschaltung und Sperrbildschirm werden deaktiviert..."
powercfg /change standby-timeout-ac 0
powercfg /change monitor-timeout-ac 0
powercfg /change hibernate-timeout-ac 0
New-Item -Path "HKCU:\Software\Policies\Microsoft\Windows\Control Panel\Desktop" -Force | Out-Null
Set-ItemProperty -Path "HKCU:\Software\Policies\Microsoft\Windows\Control Panel\Desktop" -Name "ScreenSaveActive" -Value "0"

Write-Host "4. Geplante Aufgabe fuer den automatischen Start der Fotobox wird angelegt..."
$action = New-ScheduledTaskAction -Execute $ExePath -WorkingDirectory (Split-Path $ExePath)
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $Username
$trigger.Delay = "PT10S"   # 10 Sekunden Verzoegerung, damit Kamera/Treiber hochgefahren sind
$settings = New-ScheduledTaskSettingsSet -RestartCount 5 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries

Unregister-ScheduledTask -TaskName "Fotobox-Autostart" -Confirm:$false -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName "Fotobox-Autostart" -Action $action -Trigger $trigger -Settings $settings -User $Username -Force | Out-Null

Write-Host ""
Write-Host "Fertig. Passwort des Benutzers '$Username' aendert sich normalerweise nicht mehr -"
Write-Host "falls doch, dieses Skript einfach erneut mit dem neuen Passwort ausfuehren."
Write-Host "Zum Testen jetzt einmal neu starten."

import ctypes
import string
import os

import win32print
from pygrabber.dshow_graph import FilterGraph

DRIVE_REMOVABLE = 2


def find_usb_sticks():
    """Gibt alle Wechseldatentraeger zurueck, z.B. ['E:\\\\']."""
    found = []
    bitmask = ctypes.windll.kernel32.GetLogicalDrives()
    for i, letter in enumerate(string.ascii_uppercase):
        if bitmask & (1 << i):
            path = f"{letter}:\\"
            if ctypes.windll.kernel32.GetDriveTypeW(path) == DRIVE_REMOVABLE:
                found.append(path)
    return found


def find_printers():
    """Alle installierten Drucker. Sagt nichts darueber, ob sie bereit sind."""
    flags = win32print.PRINTER_ENUM_LOCAL | win32print.PRINTER_ENUM_CONNECTIONS
    try:
        return [p[2] for p in win32print.EnumPrinters(flags)]
    except Exception:
        return []


def printer_ready(name):
    """Prueft, ob ein bestimmter Drucker keinen bekannten Fehlerzustand
    meldet - sowohl ueber den klassischen Windows-Status (GetPrinter) als
    auch ueber WMI (siehe printer_status_message()), weil manche
    Fotodrucker (u.a. der Selphy CP1500) einen Fehler wie eine entnommene
    Papierkassette nur ueber einen der beiden Wege zeigen, oft auch erst
    waehrend eines laufenden Druckauftrags (siehe job_status())."""
    try:
        handle = win32print.OpenPrinter(name)
        try:
            info = win32print.GetPrinter(handle, 2)
        finally:
            win32print.ClosePrinter(handle)
    except Exception:
        return False
    if info["Status"] != 0:
        return False
    return printer_status_message(name) == ""


# Bekannte Windows-Druckerstatus-Flags mit deutschem Klartext. Nicht jeder
# Treiber (insbesondere kleine Fotodrucker wie der Selphy CP1500) setzt
# diese zuverlaessig oder unterscheidet Papier/Farbband ueberhaupt -
# printer_status_message() liefert also einen bestmoeglichen Hinweis,
# keine garantiert korrekte Diagnose.
_STATUS_FLAG_MESSAGES = [
    ("PRINTER_STATUS_PAPER_OUT", "Kein Papier mehr"),
    ("PRINTER_STATUS_PAPER_JAM", "Papierstau"),
    ("PRINTER_STATUS_NO_TONER", "Farbband/Tinte leer"),
    ("PRINTER_STATUS_DOOR_OPEN", "Klappe/Kassette nicht richtig eingesetzt"),
    ("PRINTER_STATUS_OFFLINE", "Drucker offline"),
    ("PRINTER_STATUS_OUT_OF_MEMORY", "Drucker meldet Speicherproblem"),
    ("PRINTER_STATUS_USER_INTERVENTION", "Drucker braucht manuelles Eingreifen"),
    ("PRINTER_STATUS_ERROR", "Druckerfehler"),
]

# WMI (Win32_Printer.DetectedErrorState) kennt mehr Fehlerzustaende als die
# klassischen win32print-Statusflags und wird von manchen Treibern
# zuverlaessiger gepflegt, auch schon im Leerlauf ohne laufenden
# Druckauftrag. Codes/Bedeutung laut Microsoft-Dokumentation der Klasse.
_WMI_DETECTED_ERROR_MESSAGES = {
    3: "Wenig Papier",
    4: "Kein Papier mehr",
    5: "Wenig Farbband/Tinte",
    6: "Farbband/Tinte leer",
    7: "Klappe/Kassette nicht richtig eingesetzt",
    8: "Papierstau",
    9: "Drucker offline",
    10: "Service erforderlich",
    11: "Ausgabefach voll",
    12: "Papierproblem (z.B. Kassette pruefen)",
    13: "Seite kann nicht gedruckt werden",
    14: "Drucker braucht manuelles Eingreifen",
    15: "Drucker meldet Speicherproblem",
}


def _wmi_printer_error(name):
    """Fragt den Drucker zusaetzlich per WMI ab (Win32_Printer,
    DetectedErrorState) - Zusatzquelle zu den win32print-Statusflags, siehe
    _WMI_DETECTED_ERROR_MESSAGES. Leerer String, falls kein bekannter Fehler
    erkannt wurde, WMI den Drucker nicht kennt oder die Abfrage fehlschlaegt
    (z.B. WMI-Dienst nicht verfuegbar)."""
    try:
        import win32com.client
        wmi = win32com.client.GetObject("winmgmts:")
        escaped = name.replace("'", "''")
        rows = wmi.ExecQuery(
            f"SELECT DetectedErrorState FROM Win32_Printer WHERE Name = '{escaped}'"
        )
        for row in rows:
            return _WMI_DETECTED_ERROR_MESSAGES.get(row.DetectedErrorState, "")
    except Exception:
        return ""
    return ""


def printer_status_message(name):
    """Menschenlesbarer Hinweis zum Druckerstatus (z.B. "Kein Papier mehr"),
    kombiniert aus den klassischen Windows-Statusflags und WMI. Leerer
    String falls kein bekanntes Problem erkannt wurde - das heisst nicht
    zwingend, dass wirklich alles in Ordnung ist (siehe Kommentar oben)."""
    messages = []
    try:
        handle = win32print.OpenPrinter(name)
        try:
            status = win32print.GetPrinter(handle, 2)["Status"]
        finally:
            win32print.ClosePrinter(handle)
        messages = [
            text for flag_name, text in _STATUS_FLAG_MESSAGES
            if status & getattr(win32print, flag_name, 0)
        ]
    except Exception:
        pass

    wmi_message = _wmi_printer_error(name)
    if wmi_message and wmi_message not in messages:
        messages.append(wmi_message)

    return ", ".join(messages)


# Bekannte Windows-Druckauftrags-Statusflags (JOB_STATUS_*). Manche Treiber
# melden ein Problem wie eine entnommene Papierkassette gar nicht am
# Drucker selbst, sondern erst am einzelnen Auftrag, sobald der Drucker
# ihn tatsaechlich zu drucken versucht (siehe output.py, wartet nach dem
# Uebergeben eines Auftrags kurz auf genau diese Flags).
_JOB_STATUS_FLAG_MESSAGES = [
    ("JOB_STATUS_PAPEROUT", "Kein Papier mehr"),
    ("JOB_STATUS_OFFLINE", "Drucker offline"),
    ("JOB_STATUS_USER_INTERVENTION", "Drucker braucht manuelles Eingreifen (Papier/Kassette/Farbband pruefen)"),
    ("JOB_STATUS_BLOCKED_DEVQ", "Drucker antwortet nicht (Geraet blockiert)"),
    ("JOB_STATUS_ERROR", "Druckerfehler"),
]


def job_status(printer_name, job_id):
    """Liest Statusflags eines einzelnen Druckauftrags aus der
    Warteschlange. Gibt (Bitmaske oder None falls der Auftrag nicht mehr in
    der Warteschlange ist, Klartext-Hinweis) zurueck."""
    try:
        handle = win32print.OpenPrinter(printer_name)
        try:
            job = win32print.GetJob(handle, job_id, 1)
        finally:
            win32print.ClosePrinter(handle)
    except Exception:
        return None, ""

    status = job.get("Status", 0)
    messages = [
        text for flag_name, text in _JOB_STATUS_FLAG_MESSAGES
        if status & getattr(win32print, flag_name, 0)
    ]
    return status, ", ".join(messages)


def find_cameras():
    """Namen aller DirectShow-Kameras. Nur beim Programmstart aufrufen."""
    try:
        return FilterGraph().get_input_devices()
    except Exception:
        return []

def usb_target_dir(subfolder="Fotobox"):
    """Zielordner auf dem ersten gefundenen USB-Stick, sonst None."""
    sticks = find_usb_sticks()
    if not sticks:
        return None
    target = os.path.join(sticks[0], subfolder)
    try:
        os.makedirs(target, exist_ok=True)
        return target
    except OSError:
        return None


def _print_diagnostics(name):
    """Gibt alle verfuegbaren Rohdaten zu einem Drucker auf der Konsole
    aus - Hilfsmittel, um bei einem konkreten Fehlerzustand (z.B.
    entnommene Papierkassette) herauszufinden, welche der Windows-/WMI-
    Quellen ihn ueberhaupt meldet. Aufruf: python hardware.py [Druckername]"""
    print("Gefundene Drucker:", find_printers())
    print()
    print(f"Druckername: {name}")
    print("printer_ready():", printer_ready(name))
    print("printer_status_message():", printer_status_message(name) or "(leer)")
    print()

    try:
        handle = win32print.OpenPrinter(name)
        try:
            info = win32print.GetPrinter(handle, 2)
            print("GetPrinter(2) Status (roh):", info["Status"])
            print("GetPrinter(2) Attributes (roh):", info.get("Attributes"))
        finally:
            win32print.ClosePrinter(handle)
    except Exception as exc:
        print("GetPrinter fehlgeschlagen:", exc)
    print()

    try:
        import win32com.client
        wmi = win32com.client.GetObject("winmgmts:")
        escaped = name.replace("'", "''")
        rows = wmi.ExecQuery(
            "SELECT PrinterStatus, PrinterState, DetectedErrorState, WorkOffline "
            f"FROM Win32_Printer WHERE Name = '{escaped}'"
        )
        found = False
        for row in rows:
            found = True
            print("WMI PrinterStatus (roh):", row.PrinterStatus)
            print("WMI PrinterState (roh):", row.PrinterState)
            print("WMI DetectedErrorState (roh):", row.DetectedErrorState)
            print("WMI WorkOffline:", row.WorkOffline)
        if not found:
            print("WMI kennt keinen Drucker mit diesem Namen")
    except Exception as exc:
        print("WMI-Abfrage fehlgeschlagen:", exc)


if __name__ == "__main__":
    import sys

    printer_name = sys.argv[1] if len(sys.argv) > 1 else win32print.GetDefaultPrinter()
    _print_diagnostics(printer_name)
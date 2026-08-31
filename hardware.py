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
    """Prueft, ob ein bestimmter Drucker keinen Fehlerstatus meldet."""
    try:
        handle = win32print.OpenPrinter(name)
        try:
            info = win32print.GetPrinter(handle, 2)
            return info["Status"] == 0
        finally:
            win32print.ClosePrinter(handle)
    except Exception:
        return False


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
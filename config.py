import configparser
import os
import sys


def resource_path(rel):
    """Pfad zu mitgelieferten Dateien, auch im PyInstaller-Bundle."""
    base = getattr(sys, "_MEIPASS", os.path.dirname(os.path.abspath(__file__)))
    return os.path.join(base, rel)


def _install_dir():
    """Ordner neben der .exe (bzw. dem Skript), nicht der PyInstaller-Temp-Ordner.

    box.ini liegt hier, damit sie beim Update der .exe erhalten bleibt.
    """
    if getattr(sys, "frozen", False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


_ini = configparser.ConfigParser()
_ini.read(os.path.join(_install_dir(), "box.ini"), encoding="utf-8")


def _get_str(section, key, fallback):
    return _ini.get(section, key, fallback=fallback)


def _get_int(section, key, fallback):
    return _ini.getint(section, key, fallback=fallback)


def _get_bool(section, key, fallback):
    return _ini.getboolean(section, key, fallback=fallback)


# Cloud-Zugang (siehe backend/README.md). Leer = Cloud-Sync wird beim
# Start uebersprungen, die Box laeuft dann nur mit dem Fallback-Layout.
CLOUD_BOX_KEY = _get_str("cloud", "box_key", "")
CLOUD_API_KEY = _get_str("cloud", "api_key", "")
CLOUD_BASE_URL = _get_str("cloud", "base_url", "https://snapolino.de").rstrip("/")

# Box-Einstellungen, alle per box.ini ueberschreibbar.
COUNTDOWN_START = _get_int("box", "countdown_start", 6)
RATIO = (3, 2)              # 3:2 fuer 10x15 quer, nur fuer die Live-Vorschau vor Layoutwahl
CAMERA_INDEX = _get_int("box", "camera_index", 0)
CAM_WIDTH = _get_int("box", "cam_width", 1920)
CAM_HEIGHT = _get_int("box", "cam_height", 1080)

OUTPUT_DIR = os.path.join(_install_dir(), _get_str("box", "output_dir", "ausgabe"))
LOG_FILE = os.path.join(_install_dir(), _get_str("box", "log_file", "fotobox.log"))
CACHE_DIR = os.path.join(_install_dir(), _get_str("box", "cache_dir", "cache"))

PRINT_ENABLED = _get_bool("box", "print_enabled", True)
PRINTER_NAME = _get_str("box", "printer_name", "") or None  # leer = Windows-Standarddrucker
COPY_TO_USB = _get_bool("box", "copy_to_usb", True)

FULLSCREEN = _get_bool("box", "fullscreen", True)
MIRROR_PREVIEW = _get_bool("box", "mirror_preview", True)   # Live-Bild spiegeln, Foto nicht
RESULT_SECONDS = _get_int("box", "result_seconds", 0)       # 0 = warten auf Tastendruck

# Eingebautes Standardlayout (4 Bilder als 2x2-Collage), falls die Box
# noch nie erfolgreich mit der Cloud synchronisiert hat. Die Box muss
# auch ganz ohne Internetverbindung voll funktionieren.
FALLBACK_LAYOUT = {
    "id": 0,
    "name": "Standard 4er-Collage",
    "slot_count": 4,
    "is_default": True,
    "surcharge_cents": 0,
    "canvas_width": 1800,
    "canvas_height": 1200,
    "frame_path": resource_path("assets/rahmen.png"),
    "slots": [
        {"index": 0, "x": 40, "y": 40, "width": 850, "height": 550},
        {"index": 1, "x": 910, "y": 40, "width": 850, "height": 550},
        {"index": 2, "x": 40, "y": 610, "width": 850, "height": 550},
        {"index": 3, "x": 910, "y": 610, "width": 850, "height": 550},
    ],
}

import json
import logging
import os
import urllib.error
import urllib.request

import config

log = logging.getLogger("fotobox.cloudsync")

CONFIG_FILE = os.path.join(config.CACHE_DIR, "config.json")
VERSION_FILE = os.path.join(config.CACHE_DIR, "config_version.txt")
FRAMES_DIR = os.path.join(config.CACHE_DIR, "frames")


def _atomic_write_bytes(path, data):
    tmp = path + ".tmp"
    with open(tmp, "wb") as f:
        f.write(data)
    os.replace(tmp, path)


def _atomic_write_text(path, text):
    _atomic_write_bytes(path, text.encode("utf-8"))


def _fetch(url, timeout):
    req = urllib.request.Request(url, headers={"X-API-Key": config.CLOUD_API_KEY})
    return urllib.request.urlopen(req, timeout=timeout)


def _local_version():
    try:
        with open(VERSION_FILE, "r", encoding="utf-8") as f:
            return int(f.read().strip())
    except (OSError, ValueError):
        return 0


def _load_cached_layouts():
    try:
        with open(CONFIG_FILE, "r", encoding="utf-8") as f:
            data = json.load(f)
        return data.get("layouts", [])
    except (OSError, ValueError):
        return []


def sync(timeout=5):
    """Preflight: Konfiguration und Rahmen von der Cloud holen, falls noetig.

    Wird nur im Vorbereitungsmodus vor dem Versand aufgerufen (bzw. beim
    Programmstart), nie waehrend eines laufenden Events. Bei fehlender
    Internetverbindung oder fehlendem Box-Key passiert einfach nichts -
    die Box arbeitet dann mit dem zuletzt bekannten bzw. dem eingebauten
    Fallback-Stand weiter.
    """
    if not config.CLOUD_BOX_KEY or not config.CLOUD_API_KEY:
        if not config.BOX_INI_FOUND:
            log.info("box.ini nicht gefunden unter %s, Cloud-Sync uebersprungen", config.BOX_INI_PATH)
        else:
            log.info(
                "box.ini gefunden (%s), aber box_key/api_key leer, Cloud-Sync uebersprungen",
                config.BOX_INI_PATH,
            )
        return

    os.makedirs(FRAMES_DIR, exist_ok=True)

    local_version = _local_version()
    url = f"{config.CLOUD_BASE_URL}/api.php?box={config.CLOUD_BOX_KEY}&since={local_version}"

    try:
        with _fetch(url, timeout) as resp:
            data = json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        if exc.code == 304:
            log.info("Cloud-Konfiguration unveraendert (Version %s)", local_version)
        else:
            log.warning("Cloud-Sync abgelehnt (HTTP %s), nutze lokalen Stand", exc.code)
        return
    except (urllib.error.URLError, OSError, ValueError) as exc:
        log.warning("Cloud-Sync nicht erreichbar, nutze lokalen Stand: %s", exc)
        return

    for layout in data.get("layouts", []):
        frame_file = layout["frame_file"]
        local_path = os.path.join(FRAMES_DIR, frame_file)
        if os.path.exists(local_path):
            continue  # Dateiname enthaelt Zufallsanteil, existierende Datei ist aktuell
        try:
            with _fetch(layout["frame_url"], timeout) as resp:
                _atomic_write_bytes(local_path, resp.read())
            log.info("Rahmen heruntergeladen: %s", frame_file)
        except (urllib.error.URLError, OSError) as exc:
            log.warning("Rahmen-Download fehlgeschlagen (%s): %s", frame_file, exc)
            return  # unvollstaendiger Satz, lieber beim alten Stand bleiben

    _atomic_write_text(CONFIG_FILE, json.dumps(data, ensure_ascii=False, indent=2))
    _atomic_write_text(VERSION_FILE, str(data.get("config_version", local_version)))
    log.info("Cloud-Konfiguration aktualisiert auf Version %s", data.get("config_version"))


def get_layouts():
    """Aktuell verfuegbare Layouts mit lokalem Rahmen-Pfad.

    Layouts, deren Rahmen-Datei (noch) nicht lokal vorliegt, werden
    uebersprungen. Gibt es gar keine gueltigen Cloud-Layouts, wird das
    eingebaute Fallback-Layout verwendet.
    """
    resolved = []
    for layout in _load_cached_layouts():
        frame_path = os.path.join(FRAMES_DIR, layout["frame_file"])
        if not os.path.exists(frame_path):
            continue
        item = dict(layout)
        item["frame_path"] = frame_path
        resolved.append(item)
    return resolved or [config.FALLBACK_LAYOUT]


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO, format="%(levelname)-8s %(message)s")
    sync()

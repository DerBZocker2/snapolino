import glob
import logging
import os
import queue
import time

import requests
from PySide6.QtCore import QThread

import config

log = logging.getLogger(__name__)

GALLERY_QUEUE_DIR = os.path.join(config.CACHE_DIR, "gallery_queue")
UPLOAD_URL = f"{config.CLOUD_BASE_URL}/upload_photo.php"

POLL_INTERVAL_SECONDS = 5      # wie oft der Worker auf neue Auftraege oder zum Beenden prueft
RETRY_INTERVAL_SECONDS = 300   # Abstand zwischen erneuten Versuchen bei fehlendem Internet

_SEPARATOR = "__"  # trennt booking_id vom eigentlichen Dateinamen im Warteschlangen-Dateinamen


def _queue_path(booking_id, filename):
    return os.path.join(GALLERY_QUEUE_DIR, f"{booking_id}{_SEPARATOR}{filename}")


class GalleryUploadWorker(QThread):
    """Laedt Fotos/Collagen nach Sessionende automatisch in die
    Online-Galerie (backend/public/upload_photo.php) hoch - komplett
    unabhaengig vom lokalen Speichern/Drucken (siehe output.OutputWorker),
    damit ein fehlender Internetzugang waehrend des Events weder den Ablauf
    noch das garantierte lokale Speichern aller Fotos beeintraechtigt
    (Offline-First). Nicht erfolgreich hochgeladene Bilder bleiben als
    Datei in GALLERY_QUEUE_DIR liegen und werden periodisch sowie bei
    jedem Programmstart automatisch erneut versucht - so kommen auch
    Fotos einer Session, die noch ohne Internet stattfand, spaetestens
    an, sobald die Box (typischerweise nach Rueckgabe) wieder online ist.
    """

    def __init__(self):
        super().__init__()
        self.jobs = queue.Queue()
        self._running = True
        os.makedirs(GALLERY_QUEUE_DIR, exist_ok=True)

    def submit(self, pil_image, filename, booking_id):
        """Reiht ein Foto zum Hochladen ein. Ohne bekannte Buchung oder
        Cloud-Zugangsdaten gibt es nichts zu tun - die Box arbeitet dann
        rein lokal (siehe cloudsync.sync())."""
        if not booking_id or not config.CLOUD_BOX_KEY or not config.CLOUD_API_KEY:
            return
        self.jobs.put((pil_image, filename, booking_id))

    def run(self):
        # Beim Start liegen ggf. schon Fotos aus einer fruehren Session in
        # der Warteschlange (z.B. Programm wurde beendet, bevor Internet
        # verfuegbar war) - gleich einmal versuchen, statt erst nach dem
        # ersten RETRY_INTERVAL_SECONDS zu warten.
        self._retry_pending()
        last_retry = time.monotonic()

        while self._running:
            try:
                image, filename, booking_id = self.jobs.get(timeout=POLL_INTERVAL_SECONDS)
            except queue.Empty:
                if time.monotonic() - last_retry >= RETRY_INTERVAL_SECONDS:
                    self._retry_pending()
                    last_retry = time.monotonic()
                continue

            self._save_and_upload(image, filename, booking_id)
            self.jobs.task_done()

    def _save_and_upload(self, image, filename, booking_id):
        path = _queue_path(booking_id, filename)
        tmp_path = path + ".tmp"
        try:
            image.save(tmp_path, format="JPEG", quality=90)
            os.replace(tmp_path, path)
        except OSError:
            log.exception("Galerie-Foto konnte nicht zwischengespeichert werden: %s", filename)
            return
        self._try_upload(path)

    def _retry_pending(self):
        for path in glob.glob(os.path.join(GALLERY_QUEUE_DIR, f"*{_SEPARATOR}*")):
            if path.endswith(".tmp"):
                continue
            self._try_upload(path)

    def _try_upload(self, path):
        name = os.path.basename(path)
        booking_id, sep, filename = name.partition(_SEPARATOR)
        if not sep:
            return

        try:
            with open(path, "rb") as f:
                resp = requests.post(
                    UPLOAD_URL,
                    headers={"X-API-Key": config.CLOUD_API_KEY, "User-Agent": "SnapolinoBox/1.0"},
                    data={"box": config.CLOUD_BOX_KEY, "booking_id": booking_id, "filename": filename},
                    files={"photo": (filename, f, "image/jpeg")},
                    timeout=15,
                )
        except requests.RequestException as exc:
            log.info("Galerie-Upload nicht erreichbar (%s), versuche es spaeter erneut", exc)
            return

        if resp.status_code == 200:
            log.info("Galerie-Upload erfolgreich: %s", filename)
            _remove_quiet(path)
        elif 400 <= resp.status_code < 500:
            # Dauerhafter Fehler (z.B. Buchung inzwischen einer anderen Box
            # zugeordnet) - ein Wiederholen wuerde nie erfolgreich sein.
            log.warning("Galerie-Upload dauerhaft abgelehnt (HTTP %s), gebe auf: %s", resp.status_code, filename)
            _remove_quiet(path)
        else:
            log.warning("Galerie-Upload fehlgeschlagen (HTTP %s), versuche es spaeter erneut: %s", resp.status_code, filename)

    def stop(self):
        self._running = False
        self.wait((POLL_INTERVAL_SECONDS + 2) * 1000)


def _remove_quiet(path):
    try:
        os.remove(path)
    except OSError:
        pass

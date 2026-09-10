import logging
import os
import queue
import shutil

import win32print
import win32ui
from PIL import ImageWin
from PySide6.QtCore import QThread, Signal

import config
import hardware

log = logging.getLogger(__name__)

HORZRES, VERTRES = 8, 10
PHYSICALWIDTH, PHYSICALHEIGHT = 110, 111
PHYSICALOFFSETX, PHYSICALOFFSETY = 112, 113


def _printer_hint(printer_name):
    """Kurzer Hinweistext fuers Protokoll/die Oberflaeche, wenn ein
    Druckauftrag fehlschlaegt - der Windows-Druckerstatus ist zwar nicht
    ganz zuverlaessig (siehe CLAUDE.md), liefert aber meistens einen
    brauchbaren Hinweis, woran es liegen koennte."""
    message = hardware.printer_status_message(printer_name)
    if message:
        return f" ({message})"
    if not hardware.printer_ready(printer_name):
        return " (Drucker meldet 'nicht bereit' - eingeschaltet, USB verbunden, Papier/Farbband eingelegt?)"
    return ""


def print_image(pil_image, printer_name=None):
    """Druckt ein PIL-Bild seitenfuellend auf dem angegebenen Drucker."""
    if printer_name is None:
        printer_name = win32print.GetDefaultPrinter()

    hdc = win32ui.CreateDC()
    try:
        hdc.CreatePrinterDC(printer_name)
    except win32ui.error as exc:
        hdc.DeleteDC()
        raise RuntimeError(f"Drucker '{printer_name}' nicht erreichbar{_printer_hint(printer_name)}: {exc}") from exc

    try:
        pw = hdc.GetDeviceCaps(PHYSICALWIDTH)
        ph = hdc.GetDeviceCaps(PHYSICALHEIGHT)
        offx = hdc.GetDeviceCaps(PHYSICALOFFSETX)
        offy = hdc.GetDeviceCaps(PHYSICALOFFSETY)

        img = pil_image
        # Bild drehen, falls Ausrichtung nicht zur Seite passt
        if (img.width > img.height) != (pw > ph):
            img = img.rotate(90, expand=True)

        scale = min(pw / img.width, ph / img.height)
        w = int(img.width * scale)
        h = int(img.height * scale)
        x = (pw - w) // 2 - offx
        y = (ph - h) // 2 - offy

        try:
            hdc.StartDoc("Fotobox")
        except win32ui.error as exc:
            raise RuntimeError(f"Drucken auf '{printer_name}' fehlgeschlagen{_printer_hint(printer_name)}: {exc}") from exc
        hdc.StartPage()
        ImageWin.Dib(img).draw(hdc.GetHandleOutput(), (x, y, x + w, y + h))
        hdc.EndPage()
        hdc.EndDoc()
        log.info("Gedruckt auf %s", printer_name)
    finally:
        hdc.DeleteDC()


class OutputWorker(QThread):
    """Arbeitet Speicher-, Kopier- und Druckauftraege der Reihe nach ab."""

    job_done = Signal(str)
    job_failed = Signal(str)
    print_trouble = Signal(str)  # Druckfehler, Nutzer soll entscheiden (siehe respond_print_trouble)

    def __init__(self):
        super().__init__()
        self.jobs = queue.Queue()
        self._retry_decision = queue.Queue()
        self._running = True

    def submit(self, pil_image, filename, do_print, copies=1):
        self.jobs.put((pil_image, filename, do_print, copies))

    def respond_print_trouble(self, retry):
        """Vom GUI-Thread aufgerufen, nachdem print_trouble beantwortet
        wurde (z.B. Papier/Farbband nachgelegt und "Erneut versuchen")."""
        self._retry_decision.put(retry)

    def _print_with_retry(self, image, copies):
        """Druckt, und haengt sich bei einem Fehler an print_trouble auf,
        bis der Nutzer per respond_print_trouble() antwortet - "Erneut
        versuchen" wiederholt denselben Druckauftrag, sonst wird
        abgebrochen (Job gilt dann als fehlgeschlagen wie bisher)."""
        while True:
            try:
                for _ in range(max(1, copies)):
                    print_image(image, config.PRINTER_NAME)
                return
            except Exception as exc:
                log.exception("Druck fehlgeschlagen, warte auf Nutzerentscheidung")
                self.print_trouble.emit(str(exc))
                if not self._retry_decision.get():
                    raise

    def run(self):
        while self._running:
            try:
                image, filename, do_print, copies = self.jobs.get(timeout=0.5)
            except queue.Empty:
                continue

            try:
                os.makedirs(config.OUTPUT_DIR, exist_ok=True)
                path = os.path.join(config.OUTPUT_DIR, filename)
                # Erst auf Temp-Datei im selben Ordner schreiben, dann atomar
                # umbenennen - sonst bleibt bei Stromausfall waehrend des
                # Schreibens ein halbes JPEG liegen.
                tmp_path = path + ".tmp"
                # format explizit angeben: PIL erkennt das Format sonst an
                # der Dateiendung, ".tmp" waere ihm unbekannt.
                image.save(tmp_path, format="JPEG", quality=95)
                os.replace(tmp_path, path)
                log.info("Gespeichert: %s", path)

                if config.COPY_TO_USB:
                    target = hardware.usb_target_dir()
                    if target:
                        usb_path = os.path.join(target, filename)
                        usb_tmp_path = usb_path + ".tmp"
                        shutil.copy2(path, usb_tmp_path)
                        os.replace(usb_tmp_path, usb_path)
                        log.info("Auf USB kopiert: %s", target)
                    else:
                        log.warning("Kein USB-Stick gefunden")

                if do_print and config.PRINT_ENABLED:
                    self._print_with_retry(image, copies)

                self.job_done.emit(filename)
            except Exception as exc:
                log.exception("Auftrag fehlgeschlagen: %s", filename)
                self.job_failed.emit(str(exc))
            finally:
                self.jobs.task_done()

    def stop(self):
        self._running = False
        self.wait(30000)   # laufenden Druck zu Ende bringen lassen
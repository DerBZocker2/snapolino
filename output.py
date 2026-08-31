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


def print_image(pil_image, printer_name=None):
    """Druckt ein PIL-Bild seitenfuellend auf dem angegebenen Drucker."""
    if printer_name is None:
        printer_name = win32print.GetDefaultPrinter()

    hdc = win32ui.CreateDC()
    hdc.CreatePrinterDC(printer_name)
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

        hdc.StartDoc("Fotobox")
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

    def __init__(self):
        super().__init__()
        self.jobs = queue.Queue()
        self._running = True

    def submit(self, pil_image, filename, do_print):
        self.jobs.put((pil_image, filename, do_print))

    def run(self):
        while self._running:
            try:
                image, filename, do_print = self.jobs.get(timeout=0.5)
            except queue.Empty:
                continue

            try:
                os.makedirs(config.OUTPUT_DIR, exist_ok=True)
                path = os.path.join(config.OUTPUT_DIR, filename)
                image.save(path, quality=95)
                log.info("Gespeichert: %s", path)

                if config.COPY_TO_USB:
                    target = hardware.usb_target_dir()
                    if target:
                        shutil.copy2(path, os.path.join(target, filename))
                        log.info("Auf USB kopiert: %s", target)
                    else:
                        log.warning("Kein USB-Stick gefunden")

                if do_print and config.PRINT_ENABLED:
                    print_image(image, config.PRINTER_NAME)

                self.job_done.emit(filename)
            except Exception as exc:
                log.exception("Auftrag fehlgeschlagen: %s", filename)
                self.job_failed.emit(str(exc))
            finally:
                self.jobs.task_done()

    def stop(self):
        self._running = False
        self.wait(30000)   # laufenden Druck zu Ende bringen lassen
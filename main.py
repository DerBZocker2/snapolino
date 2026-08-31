import ctypes
import logging
import os
import sys
from datetime import datetime
from logging.handlers import RotatingFileHandler

import cv2
from PIL import Image
from PySide6.QtCore import Qt, QTimer
from PySide6.QtGui import QImage, QKeySequence, QPixmap, QShortcut
from PySide6.QtWidgets import (
    QApplication, QWidget, QLabel, QPushButton,
    QVBoxLayout, QHBoxLayout, QStackedWidget,
)

import config
import hardware
from camera import CameraThread
from output import OutputWorker

ES_CONTINUOUS = 0x80000000
ES_SYSTEM_REQUIRED = 0x00000001
ES_DISPLAY_REQUIRED = 0x00000002


def setup_logging():
    handler = RotatingFileHandler(
        config.LOG_FILE, maxBytes=2_000_000, backupCount=3, encoding="utf-8"
    )
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)-8s %(name)s: %(message)s",
        handlers=[handler, logging.StreamHandler(sys.stdout)],
    )


log = logging.getLogger("fotobox")


def keep_awake():
    """Verhindert Standby und Bildschirmabschaltung, solange das Programm laeuft."""
    ctypes.windll.kernel32.SetThreadExecutionState(
        ES_CONTINUOUS | ES_SYSTEM_REQUIRED | ES_DISPLAY_REQUIRED
    )


def release_awake():
    ctypes.windll.kernel32.SetThreadExecutionState(ES_CONTINUOUS)


def resource_path(rel):
    base = getattr(sys, "_MEIPASS", os.path.dirname(os.path.abspath(__file__)))
    return os.path.join(base, rel)


def crop_to_ratio(frame, ratio=config.RATIO):
    h, w = frame.shape[:2]
    target = ratio[0] / ratio[1]
    if w / h > target:
        new_w = int(h * target)
        x0 = (w - new_w) // 2
        return frame[:, x0:x0 + new_w]
    new_h = int(w / target)
    y0 = (h - new_h) // 2
    return frame[y0:y0 + new_h, :]


def to_pixmap(frame, max_w, max_h):
    rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
    h, w, ch = rgb.shape
    img = QImage(rgb.data, w, h, ch * w, QImage.Format_RGB888).copy()
    return QPixmap.fromImage(img).scaled(
        max_w, max_h, Qt.KeepAspectRatio, Qt.SmoothTransformation
    )


def pil_to_pixmap(img, max_w, max_h):
    qimg = QImage(
        img.tobytes(), img.width, img.height, img.width * 3, QImage.Format_RGB888
    ).copy()
    return QPixmap.fromImage(qimg).scaled(
        max_w, max_h, Qt.KeepAspectRatio, Qt.SmoothTransformation
    )


def compose(frame_bgr, overlay_path):
    cropped = crop_to_ratio(frame_bgr)
    rgb = cv2.cvtColor(cropped, cv2.COLOR_BGR2RGB)
    photo = Image.fromarray(rgb).convert("RGBA")
    if os.path.exists(overlay_path):
        overlay = Image.open(overlay_path).convert("RGBA")
        photo = photo.resize(overlay.size, Image.LANCZOS)
        photo = Image.alpha_composite(photo, overlay)
    return photo.convert("RGB")


class StatusDot(QWidget):
    def __init__(self, caption):
        super().__init__()
        self.caption = caption
        lay = QHBoxLayout(self)
        lay.setContentsMargins(10, 4, 10, 4)
        self.dot = QLabel("\u25cf")
        self.text = QLabel(caption)
        self.text.setStyleSheet("font-size: 15px; color: #ddd;")
        lay.addWidget(self.dot)
        lay.addWidget(self.text)
        self.set_ok(False)

    def set_ok(self, ok, detail=""):
        color = "#27ae60" if ok else "#c0392b"
        self.dot.setStyleSheet(f"color: {color}; font-size: 20px;")
        self.text.setText(f"{self.caption}{': ' + detail if detail else ''}")


class Fotobox(QWidget):
    def __init__(self):
        super().__init__()
        self.setWindowTitle("Fotobox")
        self.setStyleSheet("background: #111; color: #eee;")
        self.setContextMenuPolicy(Qt.NoContextMenu)
        self.setCursor(Qt.BlankCursor)

        self.overlay_path = resource_path("assets/rahmen.png")
        os.makedirs(config.OUTPUT_DIR, exist_ok=True)

        self.countdown = 0
        self.captured = None
        self.busy = False          # Doppelklick-Schutz
        self.allow_close = False   # Alt+F4-Sperre
        self.cam_ok = False

        self._build_ui()
        self._start_camera()
        self._start_worker()

        QShortcut(QKeySequence("Ctrl+Shift+Q"), self, activated=self.request_exit)

        self.status_timer = QTimer(self)
        self.status_timer.timeout.connect(self.refresh_status)
        self.status_timer.start(2000)
        self.refresh_status()

        self.tick = QTimer(self)
        self.tick.setInterval(1000)
        self.tick.timeout.connect(self._on_tick)

        log.info("Fotobox gestartet")

    # ---------- Aufbau ----------

    def _build_ui(self):
        root = QVBoxLayout(self)

        bar = QHBoxLayout()
        self.dot_usb = StatusDot("USB-Stick")
        self.dot_printer = StatusDot("Drucker")
        self.dot_camera = StatusDot("Kamera")
        for d in (self.dot_usb, self.dot_printer, self.dot_camera):
            bar.addWidget(d)
        bar.addStretch()
        self.hint = QLabel("")
        self.hint.setStyleSheet("font-size: 14px; color: #f39c12;")
        bar.addWidget(self.hint)
        root.addLayout(bar)

        self.pages = QStackedWidget()
        root.addWidget(self.pages, 1)

        # Seite 0: BEREIT
        p0 = QWidget()
        l0 = QVBoxLayout(p0)
        self.frame_preview = QLabel("Kein Rahmen gefunden")
        self.frame_preview.setAlignment(Qt.AlignCenter)
        self.btn_start = QPushButton("START")
        self.btn_start.setFixedHeight(90)
        self.btn_start.setStyleSheet(
            "font-size: 34px; background: #27ae60; color: white; border-radius: 12px;"
        )
        self.btn_start.clicked.connect(self.start_session)
        l0.addWidget(self.frame_preview, 1)
        l0.addWidget(self.btn_start)
        self.pages.addWidget(p0)

        # Seite 1: LIVE + COUNTDOWN
        p1 = QWidget()
        l1 = QVBoxLayout(p1)
        self.live_view = QLabel()
        self.live_view.setAlignment(Qt.AlignCenter)
        l1.addWidget(self.live_view, 1)
        self.pages.addWidget(p1)

        # Seite 2: ANSICHT
        p2 = QWidget()
        l2 = QVBoxLayout(p2)
        self.result_view = QLabel()
        self.result_view.setAlignment(Qt.AlignCenter)
        row = QHBoxLayout()
        self.btn_again = QPushButton("Wiederholen")
        self.btn_next = QPushButton("Fortfahren")
        for b, col in ((self.btn_again, "#7f8c8d"), (self.btn_next, "#27ae60")):
            b.setFixedHeight(80)
            b.setStyleSheet(
                f"font-size: 26px; background: {col}; color: white; border-radius: 12px;"
            )
            row.addWidget(b)
        self.btn_again.clicked.connect(self.start_session)
        self.btn_next.clicked.connect(self.finish_session)
        l2.addWidget(self.result_view, 1)
        l2.addLayout(row)
        self.pages.addWidget(p2)

        self._load_frame_preview()

    def _load_frame_preview(self):
        if os.path.exists(self.overlay_path):
            self.frame_preview.setPixmap(
                QPixmap(self.overlay_path).scaled(
                    900, 600, Qt.KeepAspectRatio, Qt.SmoothTransformation
                )
            )

    def _start_camera(self):
        names = hardware.find_cameras()
        self.camera_name = names[config.CAMERA_INDEX] if names else "keine"
        self.cam = CameraThread(
            index=config.CAMERA_INDEX,
            width=config.CAM_WIDTH,
            height=config.CAM_HEIGHT,
        )
        self.cam.frame_ready.connect(self._on_frame)
        self.cam.status_changed.connect(self._on_cam_status)
        self.cam.start()

    def _start_worker(self):
        self.worker = OutputWorker()
        self.worker.job_done.connect(lambda n: self.hint.setText(""))
        self.worker.job_failed.connect(
            lambda e: self.hint.setText("Ausgabe-Fehler – siehe Protokoll")
        )
        self.worker.start()

    # ---------- Status ----------

    def refresh_status(self):
        sticks = hardware.find_usb_sticks()
        self.dot_usb.set_ok(bool(sticks), sticks[0] if sticks else "")

        printers = hardware.find_printers()
        ready = [p for p in printers if hardware.printer_ready(p)]
        self.dot_printer.set_ok(bool(ready), ready[0] if ready else "")

        self.dot_camera.set_ok(self.cam_ok, self.camera_name if self.cam_ok else "")

    def _on_cam_status(self, ok):
        if ok != self.cam_ok:
            log.warning("Kamerastatus: %s", "OK" if ok else "kein Bild")
        self.cam_ok = ok

    # ---------- Ablauf ----------

    def _on_frame(self, frame):
        if self.pages.currentIndex() != 1:
            return
        shown = crop_to_ratio(frame)
        if config.MIRROR_PREVIEW:
            shown = cv2.flip(shown, 1)
        if self.countdown > 0:
            cv2.putText(
                shown, str(self.countdown),
                (shown.shape[1] // 2 - 80, shown.shape[0] // 2 + 80),
                cv2.FONT_HERSHEY_SIMPLEX, 7, (255, 255, 255), 14, cv2.LINE_AA,
            )
        self.live_view.setPixmap(
            to_pixmap(shown, self.live_view.width(), self.live_view.height())
        )

    def start_session(self):
        if self.busy:
            return
        if not self.cam_ok:
            self.hint.setText("Keine Kamera – bitte Kabel prüfen")
            log.warning("Start ohne Kamera abgelehnt")
            return
        self.busy = True
        self._set_buttons(False)
        self.countdown = config.COUNTDOWN_START
        self.pages.setCurrentIndex(1)
        self.tick.start()
        log.info("Session gestartet")

    def _on_tick(self):
        self.countdown -= 1
        if self.countdown <= 0:
            self.tick.stop()
            self.capture()

    def capture(self):
        frame = self.cam.grab()
        if frame is None:
            log.error("Aufnahme fehlgeschlagen – kein Frame")
            self.hint.setText("Aufnahme fehlgeschlagen")
            self._reset()
            return
        self.captured = compose(frame, self.overlay_path)
        self.result_view.setPixmap(
            pil_to_pixmap(
                self.captured, self.result_view.width(), self.result_view.height()
            )
        )
        self.pages.setCurrentIndex(2)
        self.busy = False
        self._set_buttons(True)
        log.info("Foto aufgenommen")

        if config.RESULT_SECONDS > 0:
            QTimer.singleShot(config.RESULT_SECONDS * 1000, self.finish_session)

    def finish_session(self):
        if self.captured is None:
            self._reset()
            return
        name = datetime.now().strftime("%Y%m%d_%H%M%S") + ".jpg"
        self.worker.submit(self.captured, name, do_print=True)
        self.hint.setText("Wird gespeichert und gedruckt …")
        self.captured = None
        self._reset()

    def _reset(self):
        self.busy = False
        self.countdown = 0
        self._set_buttons(True)
        self.pages.setCurrentIndex(0)

    def _set_buttons(self, enabled):
        self.btn_start.setEnabled(enabled)
        self.btn_again.setEnabled(enabled)
        self.btn_next.setEnabled(enabled)

    # ---------- Beenden ----------

    def request_exit(self):
        log.info("Beenden per Tastenkombination")
        self.allow_close = True
        self.close()

    def closeEvent(self, event):
        if not self.allow_close:
            log.info("Schliessen blockiert")
            event.ignore()
            return
        self.cam.stop()
        self.worker.stop()
        release_awake()
        log.info("Fotobox beendet")
        super().closeEvent(event)


if __name__ == "__main__":
    setup_logging()
    keep_awake()
    app = QApplication(sys.argv)
    win = Fotobox()
    if config.FULLSCREEN:
        win.showFullScreen()
    else:
        win.resize(1000, 700)
        win.show()
    sys.exit(app.exec())
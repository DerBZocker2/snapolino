import ctypes
import logging
import os
import sys
from datetime import datetime
from logging.handlers import RotatingFileHandler

import cv2
from PIL import Image
from PySide6.QtCore import Qt, QTimer, QThread, Signal
from PySide6.QtGui import QImage, QKeySequence, QPixmap, QShortcut
from PySide6.QtWidgets import (
    QApplication, QWidget, QLabel, QPushButton,
    QVBoxLayout, QHBoxLayout, QStackedWidget,
)

import cloudsync
import config
import hardware
from camera import CameraThread
from output import OutputWorker

ES_CONTINUOUS = 0x80000000
ES_SYSTEM_REQUIRED = 0x00000001
ES_DISPLAY_REQUIRED = 0x00000002

PAGE_READY = 0
PAGE_LAYOUT = 1
PAGE_LIVE = 2
PAGE_SINGLE = 3
PAGE_COLLAGE = 4


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


def crop_to_ratio(frame, ratio):
    """Schneidet mittig auf das Seitenverhaeltnis zu (Breite/Hoehe als Zahl)."""
    h, w = frame.shape[:2]
    target = ratio[0] / ratio[1] if isinstance(ratio, tuple) else ratio
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


def compose_collage(slot_frames, layout):
    """Setzt die aufgenommenen Einzelbilder in die Slots des Rahmens ein.

    Erst werden alle Fotos auf eine leere Leinwand an ihre Slot-Position
    gesetzt, danach kommt die Rahmen-PNG obendrauf - so liegen Rahmen und
    Dekoration ueber den Fotokanten, nicht darunter.
    """
    canvas_size = (layout["canvas_width"], layout["canvas_height"])
    photo_layer = Image.new("RGBA", canvas_size, (255, 255, 255, 255))

    for slot, frame_bgr in zip(layout["slots"], slot_frames):
        w, h = slot["width"], slot["height"]
        cropped = crop_to_ratio(frame_bgr, w / h)
        rgb = cv2.cvtColor(cropped, cv2.COLOR_BGR2RGB)
        photo = Image.fromarray(rgb).convert("RGBA").resize((w, h), Image.LANCZOS)
        photo_layer.paste(photo, (slot["x"], slot["y"]))

    frame_path = layout["frame_path"]
    if os.path.exists(frame_path):
        frame_img = Image.open(frame_path).convert("RGBA").resize(canvas_size)
        photo_layer = Image.alpha_composite(photo_layer, frame_img)

    return photo_layer.convert("RGB")


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


class CloudSyncThread(QThread):
    """Fragt im Hintergrund die Cloud ab, ohne die GUI zu blockieren."""

    finished_sync = Signal(list)

    def run(self):
        cloudsync.sync()
        self.finished_sync.emit(cloudsync.get_layouts())


class Fotobox(QWidget):
    def __init__(self):
        super().__init__()
        self.setWindowTitle("Fotobox")
        self.setStyleSheet("background: #111; color: #eee;")
        self.setContextMenuPolicy(Qt.NoContextMenu)
        self.setCursor(Qt.BlankCursor)

        os.makedirs(config.OUTPUT_DIR, exist_ok=True)

        self.layouts = cloudsync.get_layouts()
        self.pending_layouts = None
        self.selected_layout = None
        self.slot_frames = []
        self.current_slot = 0
        self._pending_shot = None
        self.collage_result = None

        self.countdown = 0
        self.busy = False          # Doppelklick-Schutz
        self.allow_close = False   # Alt+F4-Sperre
        self.cam_ok = False
        self.sync_thread = None

        self._build_ui()
        self._start_camera()
        self._start_worker()

        QShortcut(QKeySequence("Ctrl+Shift+Q"), self, activated=self.request_exit)
        QShortcut(QKeySequence("Ctrl+Shift+S"), self, activated=self.trigger_resync)

        self.status_timer = QTimer(self)
        self.status_timer.timeout.connect(self.refresh_status)
        self.status_timer.start(2000)
        self.refresh_status()

        self.tick = QTimer(self)
        self.tick.setInterval(1000)
        self.tick.timeout.connect(self._on_tick)

        log.info("Fotobox gestartet mit %d Layout(s)", len(self.layouts))

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

        # Seite 1: LAYOUTWAHL (nur bei mehr als einem Layout sichtbar)
        p1 = QWidget()
        self.layout_choice_box = QVBoxLayout(p1)
        self.layout_choice_box.addWidget(QLabel("Welches Format?"))
        self.pages.addWidget(p1)

        # Seite 2: LIVE + COUNTDOWN
        p2 = QWidget()
        l2 = QVBoxLayout(p2)
        self.live_view = QLabel()
        self.live_view.setAlignment(Qt.AlignCenter)
        l2.addWidget(self.live_view, 1)
        self.pages.addWidget(p2)

        # Seite 3: EINZELANSICHT (ein aufgenommenes Bild, Wiederholen/Weiter)
        p3 = QWidget()
        l3 = QVBoxLayout(p3)
        self.single_view = QLabel()
        self.single_view.setAlignment(Qt.AlignCenter)
        row3 = QHBoxLayout()
        self.btn_retake = QPushButton("Wiederholen")
        self.btn_accept = QPushButton("Weiter")
        for b, col in ((self.btn_retake, "#7f8c8d"), (self.btn_accept, "#27ae60")):
            b.setFixedHeight(80)
            b.setStyleSheet(
                f"font-size: 26px; background: {col}; color: white; border-radius: 12px;"
            )
            row3.addWidget(b)
        self.btn_retake.clicked.connect(self.retake_slot)
        self.btn_accept.clicked.connect(self.accept_slot)
        l3.addWidget(self.single_view, 1)
        l3.addLayout(row3)
        self.pages.addWidget(p3)

        # Seite 4: COLLAGE + Druckfrage
        p4 = QWidget()
        l4 = QVBoxLayout(p4)
        self.collage_view = QLabel()
        self.collage_view.setAlignment(Qt.AlignCenter)
        row4 = QHBoxLayout()
        self.btn_restart = QPushButton("Neu starten")
        self.btn_print = QPushButton("Drucken")
        for b, col in ((self.btn_restart, "#7f8c8d"), (self.btn_print, "#27ae60")):
            b.setFixedHeight(80)
            b.setStyleSheet(
                f"font-size: 26px; background: {col}; color: white; border-radius: 12px;"
            )
            row4.addWidget(b)
        self.btn_restart.clicked.connect(self._reset)
        self.btn_print.clicked.connect(self.finish_session)
        l4.addWidget(self.collage_view, 1)
        l4.addLayout(row4)
        self.pages.addWidget(p4)

        self._refresh_layout_choices()
        self._load_frame_preview()

    def _default_layout(self):
        for layout in self.layouts:
            if layout.get("is_default"):
                return layout
        return self.layouts[0]

    def _load_frame_preview(self):
        frame_path = self._default_layout()["frame_path"]
        if os.path.exists(frame_path):
            self.frame_preview.setPixmap(
                QPixmap(frame_path).scaled(
                    900, 600, Qt.KeepAspectRatio, Qt.SmoothTransformation
                )
            )
        else:
            self.frame_preview.setText("Kein Rahmen gefunden")

    def _refresh_layout_choices(self):
        while self.layout_choice_box.count() > 1:
            item = self.layout_choice_box.takeAt(1)
            if item.widget():
                item.widget().deleteLater()

        for layout in self.layouts:
            label = layout["name"]
            if layout.get("surcharge_cents"):
                label += f" (+{layout['surcharge_cents'] / 100:.2f} EUR)"
            btn = QPushButton(label)
            btn.setFixedHeight(70)
            btn.setStyleSheet(
                "font-size: 22px; background: #2980b9; color: white; border-radius: 10px;"
            )
            btn.clicked.connect(lambda checked=False, ly=layout: self.choose_layout(ly))
            self.layout_choice_box.addWidget(btn)

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

    # ---------- Cloud-Resync (nur zwischen Sessions) ----------

    def trigger_resync(self):
        if self.sync_thread is not None and self.sync_thread.isRunning():
            return
        log.info("Manueller Cloud-Resync angestossen")
        self.sync_thread = CloudSyncThread(self)
        self.sync_thread.finished_sync.connect(self._on_cloud_synced)
        self.sync_thread.start()

    def _on_cloud_synced(self, layouts):
        idle = self.pages.currentIndex() == PAGE_READY and not self.busy
        if idle:
            self._apply_layouts(layouts)
            log.info("Cloud-Konfiguration sofort uebernommen (Box war im Leerlauf)")
        else:
            self.pending_layouts = layouts
            log.info("Cloud-Konfiguration wird erst nach der laufenden Session uebernommen")

    def _apply_layouts(self, layouts):
        self.layouts = layouts
        self._refresh_layout_choices()
        self._load_frame_preview()

    # ---------- Ablauf ----------

    def _on_frame(self, frame):
        if self.pages.currentIndex() != PAGE_LIVE or self.selected_layout is None:
            return
        slot = self.selected_layout["slots"][self.current_slot]
        shown = crop_to_ratio(frame, slot["width"] / slot["height"])
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
        if len(self.layouts) > 1:
            self.pages.setCurrentIndex(PAGE_LAYOUT)
        else:
            self.choose_layout(self.layouts[0])

    def choose_layout(self, layout):
        self.selected_layout = layout
        self.slot_frames = []
        self.current_slot = 0
        log.info("Layout gewaehlt: %s (%d Bilder)", layout["name"], layout["slot_count"])
        self._start_slot_capture()

    def _start_slot_capture(self):
        self.countdown = config.COUNTDOWN_START
        self.pages.setCurrentIndex(PAGE_LIVE)
        self.tick.start()

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
        self._pending_shot = frame
        slot = self.selected_layout["slots"][self.current_slot]
        shown = crop_to_ratio(frame, slot["width"] / slot["height"])
        self.single_view.setPixmap(
            to_pixmap(shown, self.single_view.width(), self.single_view.height())
        )
        self.pages.setCurrentIndex(PAGE_SINGLE)
        log.info(
            "Bild %d/%d aufgenommen",
            self.current_slot + 1, self.selected_layout["slot_count"],
        )

    def retake_slot(self):
        self._pending_shot = None
        self._start_slot_capture()

    def accept_slot(self):
        self.slot_frames.append(self._pending_shot)
        self._pending_shot = None
        if len(self.slot_frames) < self.selected_layout["slot_count"]:
            self.current_slot += 1
            self._start_slot_capture()
        else:
            self._build_collage()

    def _build_collage(self):
        self.collage_result = compose_collage(self.slot_frames, self.selected_layout)
        self.collage_view.setPixmap(
            pil_to_pixmap(
                self.collage_result, self.collage_view.width(), self.collage_view.height()
            )
        )
        self.pages.setCurrentIndex(PAGE_COLLAGE)
        self._set_buttons(True)
        log.info("Collage fertig")

        if config.RESULT_SECONDS > 0:
            QTimer.singleShot(config.RESULT_SECONDS * 1000, self.finish_session)

    def finish_session(self):
        if self.collage_result is None:
            self._reset()
            return
        name = datetime.now().strftime("%Y%m%d_%H%M%S") + ".jpg"
        self.worker.submit(self.collage_result, name, do_print=True)
        self.hint.setText("Wird gespeichert und gedruckt …")
        self.collage_result = None
        self._reset()

    def _reset(self):
        self.busy = False
        self.countdown = 0
        self.selected_layout = None
        self.slot_frames = []
        self.current_slot = 0
        self._pending_shot = None
        self.collage_result = None
        self._set_buttons(True)
        self.pages.setCurrentIndex(PAGE_READY)

        if self.pending_layouts is not None:
            self._apply_layouts(self.pending_layouts)
            self.pending_layouts = None
            log.info("Zwischenzeitlich synchronisierte Cloud-Konfiguration uebernommen")

    def _set_buttons(self, enabled):
        self.btn_start.setEnabled(enabled)
        self.btn_restart.setEnabled(enabled)
        self.btn_print.setEnabled(enabled)

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
    cloudsync.sync()
    app = QApplication(sys.argv)
    win = Fotobox()
    if config.FULLSCREEN:
        win.showFullScreen()
    else:
        win.resize(1000, 700)
        win.show()
    sys.exit(app.exec())

import cv2
from PySide6.QtCore import QThread, Signal


class CameraThread(QThread):
    frame_ready = Signal(object)
    status_changed = Signal(bool)

    def __init__(self, index=0, width=1920, height=1080):
        super().__init__()
        self.index = index
        self.width = width
        self.height = height
        self._running = False
        self._last_frame = None

    def run(self):
        self._running = True
        cap = cv2.VideoCapture(self.index, cv2.CAP_DSHOW)
        cap.set(cv2.CAP_PROP_FRAME_WIDTH, self.width)
        cap.set(cv2.CAP_PROP_FRAME_HEIGHT, self.height)

        last_state = None
        while self._running:
            ok, frame = cap.read()
            if ok:
                self._last_frame = frame
                self.frame_ready.emit(frame)
            if ok != last_state:
                last_state = ok
                self.status_changed.emit(ok)
            self.msleep(30)

        cap.release()

    def grab(self):
        """Aktuelles Bild in voller Aufloesung, unabhaengig von der Vorschau."""
        if self._last_frame is None:
            return None
        return self._last_frame.copy()

    def stop(self):
        self._running = False
        self.wait(2000)
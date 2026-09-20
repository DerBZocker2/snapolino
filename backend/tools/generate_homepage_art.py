#!/usr/bin/env python3
"""Erzeugt 1-2 illustrative Grafiken fuer die Startseite (kein Ersatz fuer
echte Kundenfotos - sobald genug echte Event-Fotos vorliegen, sollten diese
stattdessen verwendet werden, siehe "Offene Punkte" in CLAUDE.md).

Bewusst gezeichnete/abstrahierte Illustrationen statt KI-generierter
"Fake-Kundenfotos" - waere sonst irrefuehrend, echte Personen/Events zu
suggerieren, die es nicht gibt. Nutzt dieselbe Formsprache/Palette wie
tools/generate_presets.py (Herzen, Sterne, Konfetti), aber eigenstaendig
gehalten, da die Themen (Geraet, Fotostreifen) andere sind.

Nicht Teil des Laufzeit-Codes - einmalig ausfuehren, Ergebnis liegt unter
public/assets/. Ausgabepfade sind bewusst final (kein Versions-Suffix noetig,
da diese Dateien nicht auf die Fotobox synchronisiert werden - nur fuer die
oeffentliche Webseite).
"""
import math
import os
import random

from PIL import Image, ImageDraw, ImageFilter, ImageFont

OUT_DIR = os.path.join(os.path.dirname(__file__), "..", "public", "assets")
FONT_DIR = os.path.join(os.path.dirname(__file__), "fonts")

ACCENT = (255, 111, 89)      # #ff6f59
ACCENT_DARK = (232, 86, 63)  # #e8563f
ACCENT2 = (108, 92, 231)     # #6c5ce7
ACCENT2_DARK = (86, 67, 209) # #5643d1
BG = (255, 250, 245)         # #fffaf5
TEAL = (23, 195, 178)        # #17c3b2
INK = (44, 36, 64)           # #2c2440


def font(name, size):
    return ImageFont.truetype(os.path.join(FONT_DIR, name), size)


def lerp(a, b, t):
    return tuple(int(a[i] + (b[i] - a[i]) * t) for i in range(3))


def gradient(size, c1, c2, direction="diagonal"):
    w, h = size
    img = Image.new("RGB", size)
    px = img.load()
    for y in range(h):
        for x in range(w):
            if direction == "vertical":
                t = y / max(1, h - 1)
            elif direction == "horizontal":
                t = x / max(1, w - 1)
            else:
                t = (x / max(1, w - 1) + y / max(1, h - 1)) / 2
            px[x, y] = lerp(c1, c2, t)
    return img.convert("RGBA")


def rounded_mask(size, radius):
    mask = Image.new("L", size, 0)
    ImageDraw.Draw(mask).rounded_rectangle([0, 0, size[0] - 1, size[1] - 1], radius=radius, fill=255)
    return mask


def heart(draw, cx, cy, r, color):
    pts = []
    for i in range(361):
        t = math.radians(i)
        x = 16 * math.sin(t) ** 3
        y = 13 * math.cos(t) - 5 * math.cos(2 * t) - 2 * math.cos(3 * t) - math.cos(4 * t)
        pts.append((cx + x * r / 16, cy - y * r / 16))
    draw.polygon(pts, fill=color)


def star(draw, cx, cy, r_out, r_in, color, points=5, rot=-90):
    pts = []
    for i in range(points * 2):
        r = r_out if i % 2 == 0 else r_in
        a = math.radians(rot + i * 360 / (points * 2))
        pts.append((cx + r * math.cos(a), cy + r * math.sin(a)))
    draw.polygon(pts, fill=color)


def confetti_piece(draw, x, y, size, color, rot):
    half = size / 2
    pts = [(-half, -half), (half, -half), (half, half), (-half, half)]
    rad = math.radians(rot)
    rotated = [(x + px * math.cos(rad) - py * math.sin(rad), y + px * math.sin(rad) + py * math.cos(rad)) for px, py in pts]
    draw.polygon(rotated, fill=color)


def scatter_confetti(img, rect, colors, n=26, seed=1):
    rnd = random.Random(seed)
    draw = ImageDraw.Draw(img, "RGBA")
    x0, y0, x1, y1 = rect
    for _ in range(n):
        x = rnd.uniform(x0, x1)
        y = rnd.uniform(y0, y1)
        size = rnd.uniform(8, 18)
        color = rnd.choice(colors) + (rnd.randint(180, 255),)
        if rnd.random() < 0.5:
            confetti_piece(draw, x, y, size, color, rnd.uniform(0, 360))
        else:
            r = size / 2
            draw.ellipse([x - r, y - r, x + r, y + r], fill=color)


def soft_blob_glow(img, cx, cy, r, color, max_alpha=70):
    # Feine Kreis-Schrittweite + Gaussian-Blur statt weniger, grosser Ringe -
    # sonst sind einzelne Kreisraender als haessliche harte Kanten sichtbar.
    glow = Image.new("RGBA", img.size, (0, 0, 0, 0))
    gd = ImageDraw.Draw(glow)
    steps = 40
    for i in range(steps, 0, -1):
        t = i / steps
        alpha = int(max_alpha * (1 - t) ** 2)
        rr = r * t
        gd.ellipse([cx - rr, cy - rr, cx + rr, cy + rr], fill=color + (alpha,))
    glow = glow.filter(ImageFilter.GaussianBlur(r * 0.15))
    img.alpha_composite(glow)


def generate_hero_illustration():
    size = (960, 800)
    img = Image.new("RGBA", size, (0, 0, 0, 0))

    # Weicher Farbverlaufs-Blob als Hintergrund-Flourish
    soft_blob_glow(img, size[0] * 0.55, size[1] * 0.45, 420, ACCENT2)
    soft_blob_glow(img, size[0] * 0.35, size[1] * 0.6, 320, ACCENT)

    scatter_confetti(img, (40, 40, size[0] - 40, size[1] - 60), [ACCENT, ACCENT2, TEAL, ACCENT_DARK], n=22, seed=7)

    draw = ImageDraw.Draw(img, "RGBA")

    # Fotobox-Geraet: Staender + Geraetekoerper mit Bildschirm
    body_w, body_h = 340, 460
    bx, by = size[0] / 2 - body_w / 2, size[1] / 2 - body_h / 2 - 30

    # Schatten
    shadow = Image.new("RGBA", size, (0, 0, 0, 0))
    ImageDraw.Draw(shadow).rounded_rectangle(
        [bx + 14, by + 22, bx + body_w + 14, by + body_h + 22], radius=46, fill=(20, 10, 30, 70)
    )
    shadow = shadow.filter(ImageFilter.GaussianBlur(18))
    img.alpha_composite(shadow)

    # Staenderfuss - direkt an den Geraetekoerper anschliessend, kein Spalt
    stand_top = by + body_h - 30
    draw.rounded_rectangle([size[0] / 2 - 11, stand_top, size[0] / 2 + 11, stand_top + 170], radius=8, fill=INK)
    draw.ellipse([size[0] / 2 - 74, stand_top + 150, size[0] / 2 + 74, stand_top + 186], fill=(20, 10, 30, 110))

    # Geraetekoerper
    body = Image.new("RGBA", (body_w, body_h), (0, 0, 0, 0))
    body_grad = gradient((body_w, body_h), ACCENT2, ACCENT2_DARK, "vertical")
    body.paste(body_grad, (0, 0), rounded_mask((body_w, body_h), 46))
    img.alpha_composite(body, (int(bx), int(by)))

    # Bildschirm
    screen_pad = 26
    screen_w, screen_h = body_w - screen_pad * 2, body_h - 140
    screen = Image.new("RGBA", (int(screen_w), int(screen_h)), BG + (255,))
    screen_mask = rounded_mask((int(screen_w), int(screen_h)), 26)
    screen.putalpha(screen_mask)
    sx, sy = bx + screen_pad, by + screen_pad
    img.alpha_composite(screen, (int(sx), int(sy)))

    # Kamera-Icon im Bildschirm
    icon_cx, icon_cy = sx + screen_w / 2, sy + screen_h / 2 - 10
    draw.rounded_rectangle(
        [icon_cx - 70, icon_cy - 46, icon_cx + 70, icon_cy + 46], radius=18, fill=ACCENT
    )
    draw.rounded_rectangle(
        [icon_cx - 26, icon_cy - 66, icon_cx + 26, icon_cy - 44], radius=8, fill=ACCENT
    )
    draw.ellipse([icon_cx - 34, icon_cy - 34, icon_cx + 34, icon_cy + 34], fill=BG)
    draw.ellipse([icon_cx - 20, icon_cy - 20, icon_cx + 20, icon_cy + 20], fill=ACCENT2)
    draw.ellipse([icon_cx + 44, icon_cy - 56, icon_cx + 58, icon_cy - 42], fill=TEAL)

    smile_f = font("Poppins-SemiBold.ttf", 30)
    text = "Lächeln!"
    bbox = draw.textbbox((0, 0), text, font=smile_f)
    tw = bbox[2] - bbox[0]
    draw.text((icon_cx - tw / 2, icon_cy + 46), text, font=smile_f, fill=ACCENT2_DARK)

    # Druckerschlitz (rechte Seite, oberhalb des Staenders) mit
    # herausragendem Fotostreifen - bewusst seitlich versetzt statt
    # mittig ueber dem Staender, damit sich beide nicht optisch ueberlappen.
    slot_y = by + body_h * 0.62
    draw.rounded_rectangle([bx + body_w - 16, slot_y, bx + body_w + 4, slot_y + 62], radius=8, fill=(20, 10, 30, 170))

    strip_w, strip_h = 92, 150
    strip = Image.new("RGBA", (strip_w, strip_h), BG + (255,))
    strip_mask = rounded_mask((strip_w, strip_h), 10)
    strip.putalpha(strip_mask)
    strip_draw = ImageDraw.Draw(strip)
    photo_colors = [ACCENT, TEAL, ACCENT2]
    ph = 34
    for i, c in enumerate(photo_colors):
        py = 10 + i * (ph + 8)
        strip_draw.rounded_rectangle([8, py, strip_w - 8, py + ph], radius=6, fill=c)
    strip = strip.rotate(12, expand=True, resample=Image.BICUBIC)
    img.alpha_composite(strip, (int(bx + body_w - 30), int(slot_y + 26)))

    # Ein paar Sterne/Herzen als Deko
    star(draw, size[0] * 0.14, size[1] * 0.2, 22, 9, ACCENT2)
    star(draw, size[0] * 0.86, size[1] * 0.16, 16, 7, TEAL)
    heart(draw, size[0] * 0.82, size[1] * 0.72, 20, ACCENT)
    heart(draw, size[0] * 0.1, size[1] * 0.78, 16, ACCENT2)

    img.save(os.path.join(OUT_DIR, "hero-illustration.png"), optimize=True, compress_level=9)
    print("geschrieben: hero-illustration.png")


def generate_photo_strip_illustration():
    strip_w = 340
    frame_h = 260
    n = 4
    pad = 22
    footer_h = 90
    strip_h = pad * 2 + frame_h * n + 14 * (n - 1) + footer_h

    img = Image.new("RGBA", (strip_w, strip_h), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img, "RGBA")

    # Weisser Streifen mit Schatten (wie ein echter Fotostreifen)
    shadow = Image.new("RGBA", img.size, (0, 0, 0, 0))
    ImageDraw.Draw(shadow).rounded_rectangle([10, 12, strip_w - 6, strip_h - 4], radius=18, fill=(20, 10, 30, 60))
    shadow = shadow.filter(ImageFilter.GaussianBlur(14))
    img.alpha_composite(shadow)
    draw.rounded_rectangle([0, 0, strip_w - 10, strip_h - 10], radius=18, fill=(255, 255, 255, 255))

    scenes = [
        {"bg": ACCENT, "kind": "hearts"},
        {"bg": ACCENT2, "kind": "confetti"},
        {"bg": TEAL, "kind": "stars"},
        {"bg": ACCENT_DARK, "kind": "sun"},
    ]

    for i, scene in enumerate(scenes):
        fx, fy = pad, pad + i * (frame_h + 14)
        fw = strip_w - 10 - pad * 2
        frame = Image.new("RGBA", (fw, frame_h), scene["bg"] + (255,))
        fd = ImageDraw.Draw(frame, "RGBA")
        cx, cy = fw / 2, frame_h / 2

        if scene["kind"] == "hearts":
            heart(fd, cx, cy, 46, (255, 255, 255, 255))
            heart(fd, cx - 70, cy + 40, 22, (255, 255, 255, 160))
            heart(fd, cx + 74, cy - 44, 18, (255, 255, 255, 160))
        elif scene["kind"] == "confetti":
            for _ in range(14):
                rnd = random.Random(i * 97 + _)
                x = rnd.uniform(10, fw - 10)
                y = rnd.uniform(10, frame_h - 10)
                s = rnd.uniform(10, 20)
                confetti_piece(fd, x, y, s, (255, 255, 255, 200), rnd.uniform(0, 360))
            fd.ellipse([cx - 30, cy - 30, cx + 30, cy + 30], fill=(255, 255, 255, 255))
        elif scene["kind"] == "stars":
            star(fd, cx, cy, 50, 22, (255, 255, 255, 255))
            star(fd, cx - 76, cy + 50, 18, 8, (255, 255, 255, 170))
            star(fd, cx + 80, cy - 50, 14, 6, (255, 255, 255, 170))
        elif scene["kind"] == "sun":
            r = 40
            for a in range(0, 360, 30):
                rad = math.radians(a)
                x1, y1 = cx + math.cos(rad) * (r + 8), cy + math.sin(rad) * (r + 8)
                x2, y2 = cx + math.cos(rad) * (r + 26), cy + math.sin(rad) * (r + 26)
                fd.line([x1, y1, x2, y2], fill=(255, 255, 255, 255), width=6)
            fd.ellipse([cx - r, cy - r, cx + r, cy + r], fill=(255, 255, 255, 255))

        frame_masked = Image.new("RGBA", (fw, frame_h), (0, 0, 0, 0))
        frame_masked.paste(frame, (0, 0), rounded_mask((fw, frame_h), 10))
        img.alpha_composite(frame_masked, (int(fx), int(fy)))

    # Wortmarke im Fuss des Streifens
    wordmark_f = font("PlayfairDisplay-Variable.ttf", 30)
    text = "Snapolino"
    bbox = draw.textbbox((0, 0), text, font=wordmark_f)
    tw = bbox[2] - bbox[0]
    footer_cy = pad + n * frame_h + (n - 1) * 14 + footer_h / 2
    draw.text(((strip_w - 10 - tw) / 2, footer_cy - 22), text, font=wordmark_f, fill=INK)
    date_f = font("Poppins-Regular.ttf", 15)
    draw.text(((strip_w - 10) / 2, footer_cy + 18), "Danke fürs Feiern mit uns", font=date_f, fill=(150, 140, 165, 255), anchor="mm")

    img.save(os.path.join(OUT_DIR, "photo-strip-illustration.png"), optimize=True, compress_level=9)
    print("geschrieben: photo-strip-illustration.png")


if __name__ == "__main__":
    os.makedirs(OUT_DIR, exist_ok=True)
    generate_hero_illustration()
    generate_photo_strip_illustration()

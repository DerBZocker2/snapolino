"""Erzeugt die mitgelieferten Preset-Rahmen (backend/storage/frames/preset_*.png)
per PIL. Kein Laufzeit-Bestandteil von Box oder Backend - einmalig von Hand
ausgefuehrt, wenn die Design-Vorlagen ueberarbeitet werden sollen.

Vorher einmalig die benoetigten Fonts laden:
    fonts/download_fonts.sh

Ausfuehren:
    python3 generate_presets.py

Schreibt alle PNGs direkt nach ../storage/frames/. ARRANGEMENTS und DESIGNS
sind bewusst als Modul-Daten exportiert (nicht nur lokale Variablen), damit
ein Migrationsskript dieselbe Slot-Geometrie/denselben Dateinamen ohne
doppelte Pflege wiederverwenden kann (siehe sql/migrations/0017_preset_redesign.sql,
das mit denselben Werten von Hand geschrieben wurde).
"""
import math
import os
import random

from PIL import Image, ImageDraw, ImageFilter, ImageFont

HERE = os.path.dirname(os.path.abspath(__file__))
FONT_DIR = os.path.join(HERE, "fonts")
OUT_DIR = os.path.join(HERE, "..", "storage", "frames")

CANVAS = (1800, 1200)

PLAYFAIR = "PlayfairDisplay-Variable.ttf"
POPPINS_SEMI = "Poppins-SemiBold.ttf"
PACIFICO = "Pacifico-Regular.ttf"
BALOO = "Baloo2-Variable.ttf"
BEBAS = "BebasNeue-Regular.ttf"


# ---------------------------------------------------------------- Helfer ---

def font(name, size, weight=None):
    f = ImageFont.truetype(os.path.join(FONT_DIR, name), size)
    if weight is not None:
        try:
            f.set_variation_by_axes([weight])
        except Exception:
            pass
    return f


def _lerp(a, b, t):
    return tuple(int(a[i] + (b[i] - a[i]) * t) for i in range(3))


def gradient(size, c1, c2, direction="vertical"):
    """Einfacher zweifarbiger Farbverlauf. In 3-4px-Streifen gerastert statt
    Pixel fuer Pixel - bei 1800x1200 sonst spuerbar langsam, der Unterschied
    ist im fertigen Rahmen nicht sichtbar."""
    w, h = size
    img = Image.new("RGB", size, c1)
    px = img.load()
    step = 4
    if direction == "vertical":
        for y in range(h):
            col = _lerp(c1, c2, y / max(1, h - 1))
            for x in range(0, w, step):
                for dx in range(min(step, w - x)):
                    px[x + dx, y] = col
    elif direction == "diagonal":
        maxd = w + h
        for y in range(0, h, 3):
            for x in range(0, w, 3):
                col = _lerp(c1, c2, (x + y) / maxd)
                for dy in range(min(3, h - y)):
                    for dx in range(min(3, w - x)):
                        px[x + dx, y + dy] = col
    else:  # horizontal
        for x in range(w):
            col = _lerp(c1, c2, x / max(1, w - 1))
            for y in range(0, h, step):
                for dy in range(min(step, h - y)):
                    px[x, y + dy] = col
    return img.convert("RGBA")


def solid(color):
    return Image.new("RGBA", CANVAS, color + (255,))


def rounded_mask(size, radius):
    mask = Image.new("L", size, 0)
    ImageDraw.Draw(mask).rounded_rectangle([0, 0, size[0] - 1, size[1] - 1], radius=radius, fill=255)
    return mask


def punch_window(frame, rect, radius=28, mat_width=14, mat_color=(255, 255, 255, 255)):
    """Schneidet ein abgerundetes transparentes Fenster in frame (RGBA) an
    rect=(x,y,w,h) und zieht davor einen schmalen 'Passepartout'-Rand in
    mat_color. compose_collage() (main.py) setzt das aufgenommene Foto immer
    als scharfkantiges Rechteck an dieselbe Position, BEVOR diese Rahmen-PNG
    obendrauf kommt - der Rahmen ueberdeckt die Fotoecken also optisch mit
    dem abgerundeten Passepartout, ohne dass main.py selbst irgendetwas von
    Rundungen wissen muss."""
    x, y, w, h = rect
    outer = rounded_mask((w + mat_width * 2, h + mat_width * 2), radius + mat_width)
    mat_layer = Image.new("RGBA", frame.size, (0, 0, 0, 0))
    mat_patch = Image.new("RGBA", (w + mat_width * 2, h + mat_width * 2), mat_color)
    mat_patch.putalpha(outer)
    mat_layer.paste(mat_patch, (x - mat_width, y - mat_width), mat_patch)
    frame = Image.alpha_composite(frame, mat_layer)

    hole = rounded_mask((w, h), radius)
    hole_full = Image.new("L", frame.size, 255)
    hole_full.paste(Image.eval(hole, lambda a: 255 - a), (x, y))
    r, g, b, a = frame.split()
    a = Image.composite(a, Image.new("L", frame.size, 0), hole_full)
    return Image.merge("RGBA", (r, g, b, a))


def soft_shadow(frame, rect, radius=28, mat_width=14, blur=10, opacity=70):
    """Weicher Schatten unter dem Passepartout fuer etwas Tiefe/Plastizitaet."""
    x, y, w, h = rect
    shadow = Image.new("RGBA", frame.size, (0, 0, 0, 0))
    sh_mask = rounded_mask((w + mat_width * 2 + blur, h + mat_width * 2 + blur), radius + mat_width)
    sh_patch = Image.new("RGBA", sh_mask.size, (0, 0, 0, opacity))
    sh_patch.putalpha(sh_mask.point(lambda a: int(a * opacity / 255)))
    shadow.paste(sh_patch, (x - mat_width - blur // 2 + 3, y - mat_width - blur // 2 + 6), sh_patch)
    shadow = shadow.filter(ImageFilter.GaussianBlur(blur))
    return Image.alpha_composite(shadow, frame)


def build(bg, slots, mat_color, decorate=None, radius=28, mat_width=14, shadow=True):
    """Baut einen Rahmen: Hintergrund -> Dekoration (Text/Icons/Konfetti) ->
    je Slot Schatten + ausgeschnittenes Fenster. Dekoration IMMER vor dem
    Ausschneiden zeichnen, nie danach - sonst wuerde z.B. Konfetti, das in
    den Fensterbereich hineinragt, auf dem spaeter eingesetzten Gesichtsfoto
    des Gasts landen statt sauber weggeschnitten zu werden."""
    frame = bg
    if decorate:
        decorate(frame)
    for rect in slots:
        if shadow:
            frame = soft_shadow(frame, rect, radius=radius, mat_width=mat_width)
        frame = punch_window(frame, rect, radius=radius, mat_width=mat_width, mat_color=mat_color)
    return frame


def text_center(draw, cx, cy, text, f, fill, stroke_width=0, stroke_fill=None):
    bbox = draw.textbbox((0, 0), text, font=f, stroke_width=stroke_width)
    w, h = bbox[2] - bbox[0], bbox[3] - bbox[1]
    draw.text((cx - w / 2 - bbox[0], cy - h / 2 - bbox[1]), text, font=f, fill=fill,
               stroke_width=stroke_width, stroke_fill=stroke_fill)


def icon_text_row(draw, cx, cy, text, f, fill, icon_fn, icon_r, gap=18, stroke_width=0, stroke_fill=None):
    """Icon + Text als eine zentrierte Gruppe (Icon links, Text rechts),
    damit beide zusammen im Beschriftungsband mittig sitzen."""
    bbox = draw.textbbox((0, 0), text, font=f, stroke_width=stroke_width)
    text_w = bbox[2] - bbox[0]
    total_w = icon_r * 2 + gap + text_w
    icon_cx = cx - total_w / 2 + icon_r
    text_cx = icon_cx + icon_r + gap + text_w / 2
    icon_fn(draw, icon_cx, cy, icon_r)
    text_center(draw, text_cx, cy, text, f, fill, stroke_width=stroke_width, stroke_fill=stroke_fill)


def heart(draw, cx, cy, r, color):
    pts = []
    for t in range(63):
        a = t / 62 * 2 * math.pi
        x = 16 * math.sin(a) ** 3
        y = 13 * math.cos(a) - 5 * math.cos(2 * a) - 2 * math.cos(3 * a) - math.cos(4 * a)
        pts.append((cx + x * r / 16, cy - y * r / 16))
    draw.polygon(pts, fill=color)


def star(draw, cx, cy, r_out, r_in, color, points=5, rot=-90):
    pts = []
    for i in range(points * 2):
        r = r_out if i % 2 == 0 else r_in
        a = math.radians(rot + i * 180 / points)
        pts.append((cx + r * math.cos(a), cy + r * math.sin(a)))
    draw.polygon(pts, fill=color)


def snowflake(draw, cx, cy, r, color, width=3):
    for i in range(6):
        a = math.radians(i * 60)
        x2, y2 = cx + r * math.cos(a), cy + r * math.sin(a)
        draw.line([cx, cy, x2, y2], fill=color, width=width)
        for f_ in (0.55, 0.8):
            bx, by = cx + r * f_ * math.cos(a), cy + r * f_ * math.sin(a)
            for da in (35, -35):
                aa = math.radians(math.degrees(a) + da)
                ex, ey = bx + r * 0.18 * math.cos(aa), by + r * 0.18 * math.sin(aa)
                draw.line([bx, by, ex, ey], fill=color, width=max(1, width - 1))


def rings(draw, cx, cy, r, color, width=6, gap=0.55):
    draw.ellipse([cx - r * gap - r, cy - r, cx - r * gap + r, cy + r], outline=color, width=width)
    draw.ellipse([cx + r * gap - r, cy - r, cx + r * gap + r, cy + r], outline=color, width=width)


def sun(draw, cx, cy, r, color, rays=10, width=6):
    draw.ellipse([cx - r, cy - r, cx + r, cy + r], outline=color, width=width)
    for i in range(rays):
        a = math.radians(i * 360 / rays)
        x1, y1 = cx + r * 1.3 * math.cos(a), cy + r * 1.3 * math.sin(a)
        x2, y2 = cx + r * 1.65 * math.cos(a), cy + r * 1.65 * math.sin(a)
        draw.line([x1, y1, x2, y2], fill=color, width=width)


def leaf(draw, cx, cy, size, color, rot=15):
    pts = [(0, -size), (size * 0.55, 0), (0, size), (-size * 0.55, 0)]
    a = math.radians(rot)
    rp = [(cx + x * math.cos(a) - y * math.sin(a), cy + x * math.sin(a) + y * math.cos(a)) for x, y in pts]
    draw.polygon(rp, fill=color)
    draw.line([rp[0], rp[2]], fill=color, width=2)


def confetti(img, rect, colors, n=32, seed=1, size_range=(6, 14)):
    """Streut Punkte/Rechtecke auf img (RGBA) innerhalb rect=(x0,y0,x1,y1).
    Nimmt bewusst das Bild selbst (nicht nur ImageDraw), um rotierte
    Rechtecke sauber per paste() einzufuegen."""
    rnd = random.Random(seed)
    draw = ImageDraw.Draw(img)
    x0, y0, x1, y1 = rect
    for _ in range(n):
        x, y = rnd.uniform(x0, x1), rnd.uniform(y0, y1)
        c = rnd.choice(colors)
        s = rnd.uniform(*size_range)
        if rnd.random() < 0.6:
            draw.ellipse([x - s / 2, y - s / 2, x + s / 2, y + s / 2], fill=c)
        else:
            patch = Image.new("RGBA", (int(s * 2), int(s * 2)), (0, 0, 0, 0))
            ImageDraw.Draw(patch).rectangle([s * 0.5, s * 0.8, s * 1.5, s * 1.2], fill=c)
            patch = patch.rotate(rnd.uniform(0, 360), expand=True)
            img.paste(patch, (int(x - patch.width / 2), int(y - patch.height / 2)), patch)


def rainbow_arc(draw, cx, cy_bottom, r_out, band=16,
                 colors=((230, 57, 70, 255), (255, 158, 0, 255), (255, 209, 102, 255),
                         (76, 175, 110, 255), (69, 137, 219, 255), (108, 92, 231, 255))):
    r = r_out
    for c in colors:
        draw.arc([cx - r, cy_bottom - r, cx + r, cy_bottom + r], 180, 360, fill=c, width=band)
        r -= band + 2


def sprocket_band(draw, y_center, band_color, hole_color, w=1800, hole_r=14, gap=54):
    draw.rectangle([0, y_center - 45, w, y_center + 45], fill=band_color)
    x = gap / 2
    while x < w:
        draw.ellipse([x - hole_r, y_center - hole_r, x + hole_r, y_center + hole_r], fill=hole_color)
        x += gap


# ---------------------------------------------------------- Anordnungen ---
# Rechtecke (x, y, w, h) je Fotoslot auf der 1800x1200-Leinwand. Jede
# Anordnung reserviert bewusst ein eigenes Beschriftungsband (BAND) ausserhalb
# aller Slots fuer Text/Icons - Dekoration darf nie in einen Slot hineinragen,
# siehe build()/punch_window().

ARRANGEMENTS = {
    "grid": {
        "slots": [
            (50, 50, 835, 535), (915, 50, 835, 535),
            (50, 615, 835, 535), (915, 615, 835, 535),
        ],
        "band": None,
    },
    "strip": {
        "slots": [
            (40, 170, 415, 990), (475, 170, 415, 990),
            (910, 170, 415, 990), (1345, 170, 415, 990),
        ],
        "band": (40, 40, 1760, 170),
    },
    "hero": {
        "slots": [
            (50, 50, 1700, 650),
            (50, 790, 553, 360), (623, 790, 553, 360), (1196, 790, 554, 360),
        ],
        "band": (50, 700, 1750, 790),
    },
    "stack": {
        "slots": [
            (50, 50, 950, 1050),
            (1030, 50, 720, 337), (1030, 407, 720, 337), (1030, 764, 720, 336),
        ],
        "band": (50, 1100, 1780, 1200),
    },
    "format_1": {
        "slots": [(60, 60, 1680, 940)],
        "band": (60, 1000, 1740, 1140),
    },
    "format_2": {
        "slots": [(50, 50, 897, 1100), (1017, 50, 733, 1100)],
        "band": None,
    },
    "format_3": {
        "slots": [(50, 90, 553, 1020), (623, 90, 553, 1020), (1196, 90, 554, 1020)],
        "band": None,
    },
}


def band_center(arrangement):
    x0, y0, x1, y1 = ARRANGEMENTS[arrangement]["band"]
    return (x0 + x1) / 2, (y0 + y1) / 2


# -------------------------------------------------------------- Designs ---
# key = Dateiname (preset_<key>.png), name = Layout-Name in der DB (siehe
# sql/migrations/0017_preset_redesign.sql - dort per Name gematcht, nicht
# per key). "decorate" bekommt das fertige Hintergrundbild (RGBA) VOR dem
# Ausschneiden der Fenster.

GOLD = (201, 162, 39, 255)
CORAL = (255, 111, 89, 255)


def d_hochzeit_elegant(img):
    cx, cy = band_center("hero")
    icon_text_row(ImageDraw.Draw(img), cx, cy, "JUST MARRIED", font(PLAYFAIR, 50, 700), (120, 90, 40, 255),
                  icon_fn=lambda d, x, y, r: rings(d, x, y, r, GOLD, width=6, gap=0.55), icon_r=22, gap=22)


def d_hochzeit_modern(img):
    cx, cy = band_center("hero")
    icon_text_row(ImageDraw.Draw(img), cx, cy, "SAVE THE DATE", font(POPPINS_SEMI, 40), (232, 214, 165, 255),
                  icon_fn=lambda d, x, y, r: heart(d, x, y, r * 0.85, (232, 214, 165, 255)), icon_r=18, gap=20)


def d_hochzeit_rustikal(img):
    cx, cy = band_center("stack")
    icon_text_row(ImageDraw.Draw(img), cx, cy, "Mr & Mrs", font(PLAYFAIR, 52, 700), (91, 60, 38, 255),
                  icon_fn=lambda d, x, y, r: leaf(d, x, y, r, (122, 92, 58, 255)), icon_r=20, gap=18)


def d_geburtstag_bunt(img):
    band = ARRANGEMENTS["strip"]["band"]
    confetti(img, band, [CORAL, (255, 255, 255, 255), (23, 195, 178, 255), (255, 209, 102, 255)], n=30, seed=3)
    cx, cy = band_center("strip")
    text_center(ImageDraw.Draw(img), cx, cy, "Happy Birthday!", font(PACIFICO, 66), (255, 255, 255, 255),
                stroke_width=3, stroke_fill=(120, 40, 90, 255))


def d_geburtstag_kids(img):
    cx, cy = band_center("stack")
    icon_text_row(ImageDraw.Draw(img), cx, cy, "Happy Birthday", font(BALOO, 48, 700), (23, 130, 122, 255),
                  icon_fn=lambda d, x, y, r: star(d, x, y, r, r * 0.45, (255, 190, 60, 255)), icon_r=22, gap=16)


def d_geburtstag_glamour(img):
    cx, cy = band_center("hero")
    icon_text_row(ImageDraw.Draw(img), cx, cy, "Glamour Night", font(PLAYFAIR, 48, 700), GOLD,
                  icon_fn=lambda d, x, y, r: star(d, x, y, r, r * 0.4, GOLD), icon_r=20, gap=20)


def d_business_klassisch(img):
    pass  # bewusst schlicht, kein Text/Icon


def d_business_modern(img):
    cx, cy = band_center("hero")
    icon_text_row(ImageDraw.Draw(img), cx, cy, "TEAM EVENT", font(POPPINS_SEMI, 34), (95, 100, 112, 255),
                  icon_fn=lambda d, x, y, r: star(d, x, y, r * 0.9, r * 0.4, (108, 92, 231, 255), points=3, rot=-90),
                  icon_r=16, gap=18)


def d_silvester(img):
    band = ARRANGEMENTS["strip"]["band"]
    confetti(img, band, [GOLD, (255, 255, 255, 255)], n=22, seed=7, size_range=(4, 9))
    cx, cy = band_center("strip")
    text_center(ImageDraw.Draw(img), cx, cy, "HAPPY NEW YEAR", font(BEBAS, 62), GOLD)


def d_regenbogen(img):
    cx, y1 = band_center("strip")[0], ARRANGEMENTS["strip"]["band"][3]
    rainbow_arc(ImageDraw.Draw(img), cx, y1 + 40, 150, band=15)


def d_sommerfest(img):
    cx, cy = band_center("strip")
    d = ImageDraw.Draw(img)
    icon_text_row(d, cx, cy, "SUMMER VIBES", font(BEBAS, 54), (23, 120, 110, 255),
                  icon_fn=lambda dd, x, y, r: sun(dd, x, y, r * 0.55, (255, 158, 0, 255), rays=8, width=5),
                  icon_r=26, gap=20)


def d_gartenparty(img):
    cx, cy = band_center("stack")
    icon_text_row(ImageDraw.Draw(img), cx, cy, "Garden Party", font(PLAYFAIR, 46, 700), (60, 110, 70, 255),
                  icon_fn=lambda d, x, y, r: leaf(d, x, y, r, (90, 150, 95, 255), rot=-10), icon_r=20, gap=18)


def d_baby_boy(img):
    cx, cy = band_center("stack")
    icon_text_row(ImageDraw.Draw(img), cx, cy, "Willkommen Baby", font(BALOO, 46, 700), (90, 130, 170, 255),
                  icon_fn=lambda d, x, y, r: star(d, x, y, r, r * 0.45, (150, 190, 225, 255)), icon_r=20, gap=16)


def d_baby_girl(img):
    cx, cy = band_center("stack")
    icon_text_row(ImageDraw.Draw(img), cx, cy, "Willkommen Baby", font(BALOO, 46, 700), (190, 105, 140, 255),
                  icon_fn=lambda d, x, y, r: heart(d, x, y, r * 0.9, (235, 160, 190, 255)), icon_r=20, gap=16)


def d_weihnachten_klassisch(img):
    d = ImageDraw.Draw(img)
    for cx, cy in [(30, 30), (1770, 30), (30, 1170), (1770, 1170)]:
        snowflake(d, cx, cy, 20, (210, 190, 140, 255), width=3)


def d_weihnachten_elegant(img):
    cx, cy = band_center("hero")
    icon_text_row(ImageDraw.Draw(img), cx, cy, "Frohe Weihnachten", font(PLAYFAIR, 44, 700), GOLD,
                  icon_fn=lambda d, x, y, r: snowflake(d, x, y, r, GOLD, width=3), icon_r=22, gap=18)


def d_format_1bild(img):
    cx, cy = band_center("format_1")
    icon_text_row(ImageDraw.Draw(img), cx, cy, "Snapolino", font(PACIFICO, 60), CORAL,
                  icon_fn=lambda d, x, y, r: heart(d, x, y, r, CORAL), icon_r=18, gap=16)


def d_format_2bilder(img):
    d = ImageDraw.Draw(img)
    d.line([982, 100, 982, 1100], fill=(255, 111, 89, 110), width=3)
    heart(d, 982, 600, 34, CORAL)


def d_format_3bilder(img):
    d = ImageDraw.Draw(img)
    sprocket_band(d, 45, (20, 18, 22, 255), (250, 246, 240, 255))
    sprocket_band(d, 1155, (20, 18, 22, 255), (250, 246, 240, 255))


DESIGNS = [
    # key, DB-Name, Anordnung, Hintergrund, mat_color, decorate, radius, mat_width, shadow
    dict(key="standard", name="Standard 4er-Collage", arr="grid",
         bg=lambda: gradient(CANVAS, (255, 246, 237), (255, 238, 221)),
         mat=(255, 255, 255, 255), decorate=None),
    dict(key="hochzeit_elegant", name="Hochzeit Elegant", arr="hero",
         bg=lambda: gradient(CANVAS, (251, 239, 234), (246, 223, 211)),
         mat=(255, 255, 255, 255), decorate=d_hochzeit_elegant),
    dict(key="hochzeit_rustikal", name="Hochzeit Rustikal", arr="stack",
         bg=lambda: gradient(CANVAS, (239, 225, 206), (228, 203, 168)),
         mat=(252, 248, 240, 255), decorate=d_hochzeit_rustikal),
    dict(key="hochzeit_modern", name="Hochzeit Modern", arr="hero",
         bg=lambda: gradient(CANVAS, (43, 43, 46), (24, 24, 27)),
         mat=GOLD, mat_width=8, decorate=d_hochzeit_modern),
    dict(key="geburtstag_bunt", name="Geburtstag Bunt", arr="strip",
         bg=lambda: gradient(CANVAS, (255, 209, 102), (108, 92, 231), "diagonal"),
         mat=(255, 255, 255, 255), decorate=d_geburtstag_bunt),
    dict(key="geburtstag_kids", name="Geburtstag Kids", arr="stack",
         bg=lambda: gradient(CANVAS, (255, 233, 236), (232, 249, 255)),
         mat=(255, 255, 255, 255), decorate=d_geburtstag_kids),
    dict(key="geburtstag_glamour", name="Geburtstag Glamour", arr="hero",
         bg=lambda: gradient(CANVAS, (20, 20, 20), (46, 36, 30)),
         mat=(238, 225, 195, 255), decorate=d_geburtstag_glamour),
    dict(key="business_klassisch", name="Business Klassisch", arr="grid",
         bg=lambda: solid((31, 42, 68)),
         mat=(255, 255, 255, 255), mat_width=10, decorate=d_business_klassisch),
    dict(key="business_modern", name="Business Modern", arr="hero",
         bg=lambda: gradient(CANVAS, (244, 245, 247), (231, 233, 237)),
         mat=(255, 255, 255, 255), decorate=d_business_modern),
    dict(key="silvester", name="Silvester Party", arr="strip",
         bg=lambda: gradient(CANVAS, (13, 15, 43), (27, 30, 74)),
         mat=(238, 225, 195, 255), decorate=d_silvester),
    dict(key="regenbogen", name="Regenbogen", arr="strip",
         bg=lambda: gradient(CANVAS, (240, 248, 255), (255, 255, 255)),
         mat=(255, 255, 255, 255), decorate=d_regenbogen),
    dict(key="sommerfest", name="Sommerfest", arr="strip",
         bg=lambda: gradient(CANVAS, (255, 226, 154), (255, 153, 102)),
         mat=(255, 255, 255, 255), decorate=d_sommerfest),
    dict(key="gartenparty", name="Gartenparty", arr="stack",
         bg=lambda: gradient(CANVAS, (234, 247, 232), (205, 235, 197)),
         mat=(255, 255, 255, 255), decorate=d_gartenparty),
    dict(key="baby_boy", name="Babyparty Blau", arr="stack",
         bg=lambda: gradient(CANVAS, (234, 244, 255), (207, 232, 255)),
         mat=(255, 255, 255, 255), decorate=d_baby_boy),
    dict(key="baby_girl", name="Babyparty Rosa", arr="stack",
         bg=lambda: gradient(CANVAS, (255, 240, 245), (255, 217, 230)),
         mat=(255, 255, 255, 255), decorate=d_baby_girl),
    dict(key="weihnachten_klassisch", name="Weihnachten Klassisch", arr="grid",
         bg=lambda: solid((30, 66, 50)),
         mat=(250, 244, 230, 255), decorate=d_weihnachten_klassisch),
    dict(key="weihnachten_elegant", name="Weihnachten Elegant", arr="hero",
         bg=lambda: gradient(CANVAS, (92, 26, 26), (58, 15, 15)),
         mat=(240, 228, 205, 255), decorate=d_weihnachten_elegant),
    dict(key="neutral_schwarz", name="Modern Schwarz", arr="grid",
         bg=lambda: solid((20, 20, 20)),
         mat=(255, 255, 255, 255), mat_width=8, decorate=None),
    dict(key="neutral_weiss", name="Modern Weiss", arr="grid",
         bg=lambda: solid((250, 250, 250)),
         mat=(30, 30, 30, 255), mat_width=5, decorate=None),
    dict(key="format_1bild", name="1 Bild (Vollformat)", arr="format_1",
         bg=lambda: solid((255, 250, 245)),
         mat=(255, 255, 255, 255), radius=20, mat_width=18, decorate=d_format_1bild),
    dict(key="format_2bilder", name="2 Bilder nebeneinander", arr="format_2",
         bg=lambda: solid((255, 250, 245)),
         mat=(255, 255, 255, 255), radius=24, mat_width=16, decorate=d_format_2bilder),
    dict(key="format_3bilder", name="3 Bilder nebeneinander", arr="format_3",
         bg=lambda: solid((30, 28, 32)),
         mat=(255, 255, 255, 255), radius=10, mat_width=8, shadow=False, decorate=d_format_3bilder),
]


def generate_all():
    os.makedirs(OUT_DIR, exist_ok=True)
    for d in DESIGNS:
        arrangement = ARRANGEMENTS[d["arr"]]
        frame = build(
            d["bg"](), arrangement["slots"], d["mat"],
            decorate=d.get("decorate"),
            radius=d.get("radius", 28),
            mat_width=d.get("mat_width", 14),
            shadow=d.get("shadow", True),
        )
        path = os.path.join(OUT_DIR, f"preset_{d['key']}_v2.png")
        frame.save(path)
        print("geschrieben:", path)


if __name__ == "__main__":
    generate_all()

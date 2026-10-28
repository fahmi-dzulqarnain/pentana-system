"""Checks the rendered deck assets exist with the right shape. Run: python3 check_assets.py (from this folder)."""
import os
import subprocess
from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
SCREENS = ["home", "bills", "submit-proof", "lunch", "lunch-vote-sheet", "lunch-voted", "activities",
           "activity-registered", "notifications", "push-banner", "support-list", "support-thread", "login"]


def img(rel):
    path = os.path.join(HERE, "assets", rel)
    assert os.path.exists(path), f"missing assets/{rel}"
    return Image.open(path)


for name in ("bg-navy.png", "bg-glow.png"):
    im = img(name)
    assert im.size == (3840, 2160), f"{name} is {im.size}, want 3840x2160 (1920x1080 @2x)"

for screen in SCREENS:
    im = img(f"phone-{screen}.png")
    assert im.size == (1200, 2520), f"phone-{screen}.png is {im.size}"
    assert im.mode == "RGBA" and im.getpixel((2, 2))[3] == 0, f"phone-{screen}.png corner not transparent"
    assert im.getpixel((600, 1260))[3] == 255, f"phone-{screen}.png centre not opaque"

qr = img("qr.png")
assert qr.size == (1800, 1800), f"qr.png is {qr.size}"

dot = img("live-dot.gif")
assert dot.n_frames == 24 and dot.info.get("loop") == 0, f"live-dot.gif frames={dot.n_frames} loop={dot.info.get('loop')}"

for sfx in ("drumroll.m4a", "celebrate.m4a"):
    path = os.path.join(HERE, "assets", "sfx", sfx)
    assert os.path.exists(path), f"missing assets/sfx/{sfx}"
    dur = float(subprocess.run(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", path],
                               capture_output=True, text=True).stdout.strip() or 0)
    assert 0.5 < dur < 6, f"{sfx} duration {dur}s"

print("assets OK")

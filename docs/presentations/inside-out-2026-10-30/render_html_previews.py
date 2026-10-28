"""Screenshot every slide of the browser deck (static mode) into build/html-preview/, then check the data matches deck.json.
Run: python3 render_html_previews.py   -> prints "html previews OK (N)"."""
import json
import os
import subprocess

from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "build", "html-preview")
CHROME = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
deck = json.load(open(os.path.join(HERE, "deck.json")))
data = open(os.path.join(HERE, "html", "deck-data.js")).read()
assert data.startswith("window.DECK = ") and json.loads(data[len("window.DECK = "):].rstrip().rstrip(";")) == deck, \
    "html/deck-data.js is out of date: run python3 deck_src.py"

os.makedirs(OUT, exist_ok=True)
for n, s in enumerate(deck["slides"], 1):
    out = os.path.join(OUT, f"{n:02d}-{s['id']}.png")
    subprocess.run([CHROME, "--headless=new", "--disable-gpu", "--hide-scrollbars", "--allow-file-access-from-files",
                    "--window-size=1920,1080", "--virtual-time-budget=3000", f"--screenshot={out}",
                    f"file://{HERE}/html/index.html?static#{s['id']}"], capture_output=True, check=True)
    assert os.path.getsize(out) > 20_000, f"{out} looks blank"
    px = Image.open(out).convert("RGB").getpixel((960, 1070))
    assert px != (14, 13, 26), f"{out} shows the presenter view, not the slide"
print(f"html previews OK ({len(deck['slides'])})")

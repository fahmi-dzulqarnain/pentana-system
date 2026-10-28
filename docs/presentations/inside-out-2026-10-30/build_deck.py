"""Build build/inside-out.key from deck.json by generating one AppleScript and running it in Keynote.

Run: python3 build_deck.py   (from this folder; Keynote Creator Studio must be installed)

Keynote's scripting quirks this works around (found by probing Keynote 15.4):
- objects are created with `tell <slide> to make new image ...` (`make ... at end of images` fails);
- audio and GIFs go through `make new image` too (they become an audio clip / movie);
- text alignment is not scriptable, so "center" text gets an estimated box width and is positioned by x;
- the Blank master leaves empty placeholder text items behind; they are invisible and ignored.
"""
import json
import os
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
BUILD = os.path.join(HERE, "build")
LOOK_BG = {"navy": "assets/bg-navy.png", "glow": "assets/bg-glow.png"}
ORANGE = (0xF7, 0x93, 0x1E)


def q(s):
    """AppleScript string literal."""
    return '"' + s.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n") + '"'


def rgb(hex_or_tuple):
    if isinstance(hex_or_tuple, str):
        h = hex_or_tuple.lstrip("#")
        hex_or_tuple = tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))
    return "{" + ", ".join(str(round(c * 65535 / 255)) for c in hex_or_tuple) + "}"


def text_box(t):
    """(x, width) for a text item; centred text gets a width estimated from its longest line."""
    if t.get("align") != "center":
        return t["x"], t["w"]
    longest = max(len(line) for line in t["text"].split("\n"))
    width = min(t["w"], round(longest * t["size"] * 0.6))
    return round(t["x"] + (t["w"] - width) / 2), width


def slide_script(i, s):
    lines = [f"\t-- {s['id']}"]
    lines.append("\tset s to slide 1 of d" if i == 0 else
                 "\ttell d to set s to make new slide with properties {base slide:b}")
    lines.append("\tset base slide of s to b")
    lines.append("\ttry\n\t\tdelete every text item of s\n\tend try")
    if s["look"] in LOOK_BG:
        lines.append(f"\tset bg to my addImage(s, {q(os.path.join(HERE, LOOK_BG[s['look']]))}, 0, 0, 1920, 1080, 0)")
        lines.append("\tset locked of bg to true")
    for im in s["images"]:
        lines.append(f"\tmy addImage(s, {q(os.path.join(HERE, im['file']))}, {im['x']}, {im['y']}, {im['w']}, {im['h']}, "
                     f"{im.get('rotation', 0) % 360})")
    for t in s["texts"]:
        x, w = text_box(t)
        lines.append(f"\ttell s to set t to make new text item with properties {{object text:{q(t['text'])}}}")
        lines.append(f"\tset font of object text of t to {q(t['font'])}")
        lines.append(f"\tset size of object text of t to {t['size']}")
        lines.append(f"\tset color of object text of t to {rgb(t['color'])}")
        for wi in t.get("accentWords", []):
            lines.append(f"\tset color of word {wi + 1} of object text of t to {rgb(ORANGE)}")
        lines.append(f"\tset width of t to {w}")
        lines.append(f"\tset position of t to {{{x}, {t['y']}}}")
    if s.get("audio"):
        lines.append(f"\ttell s to make new image with properties {{file:POSIX file {q(os.path.join(HERE, s['audio']))}}}")
    lines.append(f"\tset presenter notes of s to {q(s['notes'])}")
    effect = s["transition"]["effect"]
    lines.append(f"\tset transition properties of s to {{transition effect:{effect}, "
                 f"transition duration:{s['transition']['duration']}, automatic transition:false}}")
    lines.append(f"\tset skipped of s to {'true' if s['skipped'] else 'false'}")
    return "\n".join(lines)


def build_script(deck):
    key = os.path.join(BUILD, "inside-out.key")
    preview = os.path.join(BUILD, "preview")
    body = "\n".join(slide_script(i, s) for i, s in enumerate(deck["slides"]))
    return f'''on addImage(s, p, x, y, w, h, r)
	tell application "Keynote Creator Studio"
		tell s to set i to make new image with properties {{file:POSIX file p}}
		set position of i to {{x, y}}
		set width of i to w
		set height of i to h
		if r is not 0 then set rotation of i to r
		return i
	end tell
end addImage
tell application "Keynote Creator Studio"
	repeat while (count of documents) > 0
		close document 1 saving no
	end repeat
	set d to make new document with properties {{document theme:theme "Basic White", width:1920, height:1080}}
	set b to master slide "Blank" of d
{body}
	save d in POSIX file {q(key)}
	export d to POSIX file {q(preview)} as slide images with properties {{image format:PNG, skipped slides:true}}
	close d saving no
	return "built " & {len(deck["slides"])} & " slides"
end tell
'''


if __name__ == "__main__":
    deck = json.load(open(os.path.join(HERE, "deck.json")))
    os.makedirs(BUILD, exist_ok=True)
    subprocess.run(["rm", "-rf", os.path.join(BUILD, "inside-out.key"), os.path.join(BUILD, "preview")], check=True)
    script = os.path.join(BUILD, "build.applescript")
    open(script, "w").write(build_script(deck))
    result = subprocess.run(["osascript", script], capture_output=True, text=True)
    print(result.stdout.strip() or result.stderr.strip())
    sys.exit(result.returncode)

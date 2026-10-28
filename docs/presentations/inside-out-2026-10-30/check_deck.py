"""Structural tests for deck.json. Run: python3 check_deck.py (from this folder). Prints "deck OK"."""
import json
import os

HERE = os.path.dirname(os.path.abspath(__file__))
deck = json.load(open(os.path.join(HERE, "deck.json")))
slides = deck["slides"]
ids = [s["id"] for s in slides]

assert deck["size"] == [1920, 1080], "slide size must be 1920x1080"

# Run-of-show anchors exist, in order.
anchors = ["title", "welcome", "housekeeping", "icebreaker", "demo-getting-in", "demo-bills", "demo-lunch",
           "demo-activities", "demo-committee", "tonight", "questions", "thanks"]
missing = [a for a in anchors if a not in ids]
assert not missing, f"missing slide ids: {missing}"
positions = [ids.index(a) for a in anchors]
assert positions == sorted(positions), f"anchors out of order: {anchors}"

for s in slides:
    for img in s.get("images", []):
        assert os.path.exists(os.path.join(HERE, img["file"])), f"{s['id']}: missing {img['file']}"
    if s.get("audio"):
        assert os.path.exists(os.path.join(HERE, s["audio"])), f"{s['id']}: missing {s['audio']}"
    assert s["look"] in ("navy", "white", "glow"), f"{s['id']}: bad look {s['look']}"
    assert s["transition"]["effect"] in ("magic move", "dissolve", "none"), f"{s['id']}: bad transition"
    texts = s.get("texts", [])
    if not s["skipped"]:
        assert s.get("notes", "").strip(), f"{s['id']}: no speaker notes"
        assert texts, f"{s['id']}: no text"
    for t in texts:
        assert t["size"] >= 40, f"{s['id']}: text under 40 pt ({t['size']}): {t['text'][:30]!r}"
    if texts and not s["id"].startswith("backup-") and texts[0]["font"].startswith("Manrope"):
        assert texts[0]["size"] >= 96, f"{s['id']}: headline under 96 pt"
    blob = " ".join([s.get("notes", "")] + [t["text"] for t in texts])
    assert "127.0.0.1" not in blob and "pentana.silentmode.net/api" not in blob, f"{s['id']}: demo plumbing leaked"

# Every demo slide is followed by its own skipped backups; skipped slides appear nowhere else.
for i, s in enumerate(slides):
    if s["id"].startswith("demo-"):
        section = s["id"][len("demo-"):]
        assert s["look"] == "glow", f"{s['id']}: demo hand-off must be the glow look"
        j = i + 1
        while j < len(slides) and slides[j]["skipped"]:
            assert slides[j].get("backupFor") == section, f"{slides[j]['id']}: backupFor should be {section}"
            j += 1
        assert j > i + 1, f"{s['id']}: no backup slides after it"
for i, s in enumerate(slides):
    if s["skipped"]:
        prev = i - 1
        while slides[prev]["skipped"]:
            prev -= 1
        assert slides[prev]["id"].startswith("demo-"), f"{s['id']}: skipped slide not after a demo"

# Timing: 60 minutes, anchors where the run-of-show puts them.
start, t = {}, 0.0
for s in slides:
    start[s["id"]] = t
    t += s["minutes"] if not s["skipped"] else 0
assert 58 <= t <= 62, f"total {t} min"
assert 12 <= start["getting-in-1"] <= 14, f"getting-in starts at {start['getting-in-1']}"
assert 46 <= start["questions"] <= 48, f"questions start at {start['questions']}"
assert start["thanks"] >= 58, f"thanks starts at {start['thanks']}"

print(f"deck OK ({len(slides)} slides, {sum(s['skipped'] for s in slides)} backups, {t:g} min)")

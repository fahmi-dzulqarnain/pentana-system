"""Pulsing red LIVE dot: 64x64, 24 frames over 1.6 s, looping, transparent background."""
import math
import sys
from PIL import Image, ImageDraw

out = sys.argv[1]
S, N, RED = 64, 24, (239, 68, 68)
frames = []
for i in range(N):
    t = i / N
    im = Image.new("RGBA", (S * 4, S * 4), (0, 0, 0, 0))  # draw at 4x, downsample for smooth edges
    d = ImageDraw.Draw(im)
    ring = 36 + 84 * t                                  # halo grows and fades
    d.ellipse([S * 2 - ring, S * 2 - ring, S * 2 + ring, S * 2 + ring], fill=RED + (int(150 * (1 - t)),))
    core = 36 + 4 * math.sin(2 * math.pi * t)
    d.ellipse([S * 2 - core, S * 2 - core, S * 2 + core, S * 2 + core], fill=RED + (255,))
    frames.append(im.resize((S, S), Image.LANCZOS))
# GIF has 1-bit alpha: map anything mostly transparent to the transparent index.
pal = [f.convert("RGBA") for f in frames]
gif = []
for f in pal:
    p = f.convert("RGB").quantize(colors=255)
    mask = f.getchannel("A").point(lambda a: 255 if a < 60 else 0)
    p.paste(255, mask=mask)
    gif.append(p)
gif[0].save(out, save_all=True, append_images=gif[1:], duration=int(1600 / N), loop=0, disposal=2, transparency=255)
print(f"saved {out}")

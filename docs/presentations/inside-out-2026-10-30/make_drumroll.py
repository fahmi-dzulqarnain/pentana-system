"""Synthesised snare drum roll: 2.2 s of accelerating, crescendoing noise strokes, then a hit + cymbal-ish tail."""
import math
import random
import struct
import sys
import wave

out = sys.argv[1]
RATE = 44100
random.seed(7)
samples = [0.0] * int(RATE * 3.4)

def stroke(at, gain, length=0.07, tone=190.0):
    start = int(at * RATE)
    for i in range(int(length * RATE)):
        if start + i >= len(samples):
            break
        env = math.exp(-i / (RATE * length / 5))
        samples[start + i] += gain * env * (0.75 * random.uniform(-1, 1) + 0.25 * math.sin(2 * math.pi * tone * i / RATE))

t = 0.0
while t < 2.2:
    progress = t / 2.2
    stroke(t, 0.15 + 0.45 * progress)
    t += 0.085 - 0.055 * progress          # strokes get faster
stroke(2.25, 1.0, length=0.35, tone=120)    # final hit
stroke(2.25, 0.35, length=1.1, tone=4200)   # bright tail

peak = max(abs(s) for s in samples) or 1
with wave.open(out, "wb") as w:
    w.setnchannels(1)
    w.setsampwidth(2)
    w.setframerate(RATE)
    w.writeframes(b"".join(struct.pack("<h", int(32000 * s / peak)) for s in samples))
print(f"saved {out}")

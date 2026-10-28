#!/usr/bin/env bash
# Render the deck's backgrounds, phone frames, QR code, live dot and sounds into assets/.
set -euo pipefail
cd "$(dirname "$0")"
CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
shot() { # shot <html-with-query> <out> <w> <h> [transparent]
  local bg=(); [ "${5:-}" = transparent ] && bg=(--default-background-color=00000000)
  "$CHROME" --headless=new --disable-gpu --hide-scrollbars --allow-file-access-from-files \
    --force-device-scale-factor=2 --window-size="$3,$4" --virtual-time-budget=5000 ${bg[@]+"${bg[@]}"} \
    --screenshot="$PWD/$2" "file://$PWD/$1" >/dev/null 2>&1
}
shot assets-src/bg-navy.html assets/bg-navy.png 1920 1080
shot assets-src/bg-glow.html assets/bg-glow.png 1920 1080
shot assets-src/qr.html assets/qr.png 900 900
for f in assets/screens/*.png; do
  n="$(basename "$f" .png)"; [ "$n" = claim-page ] && continue
  shot "assets-src/phone.html?screen=$n" "assets/phone-$n.png" 600 1260 transparent
done
python3 make_dot_gif.py assets/live-dot.gif
python3 make_drumroll.py assets/sfx/drumroll.wav
[ -f assets/sfx/drumroll.m4a ] || ffmpeg -loglevel error -y -i assets/sfx/drumroll.wav -c:a aac -b:a 160k assets/sfx/drumroll.m4a
rm -f assets/sfx/drumroll.wav
[ -f assets/sfx/celebrate.m4a ] || ffmpeg -loglevel error -y -i /System/Library/Sounds/Glass.aiff -c:a aac -b:a 160k assets/sfx/celebrate.m4a
echo "assets rendered"

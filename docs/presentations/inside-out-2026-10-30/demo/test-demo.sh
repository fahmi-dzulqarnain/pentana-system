#!/usr/bin/env bash
# Safety tests for the demo backend scripts. Run from anywhere: test-demo.sh  -> prints "demo tests OK".
set -uo pipefail
DEMO_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$DEMO_DIR/../../../.." && pwd)"
TMP="$(mktemp -d)"
fail() { echo "FAIL: $*"; exit 1; }

# 1. A DB_URL exported in the shell must not redirect the demo database.
EVIL="$TMP/evil.sqlite"; touch "$EVIL"
DB_URL="sqlite:///$EVIL" "$DEMO_DIR/start-demo.sh" --reset >/dev/null 2>&1
[ -s "$EVIL" ] && fail "DB_URL from the shell reached $EVIL"

# 2. A failing check must make start-demo exit non-zero.
cp "$DEMO_DIR/check-seed.php" "$TMP/check-seed.bak"
printf '<?php throw new RuntimeException("forced failure");\n' > "$DEMO_DIR/check-seed.php"
"$DEMO_DIR/start-demo.sh" >/dev/null 2>&1; code=$?
cp "$TMP/check-seed.bak" "$DEMO_DIR/check-seed.php"
[ "$code" -ne 0 ] || fail "start-demo exited 0 although check-seed failed"

# 3. Restarting without --reset after a live RSVP must still pass.
"$DEMO_DIR/start-demo.sh" --reset >/dev/null 2>&1 || fail "reset start failed"
PW="$(cat "$DEMO_DIR/.demo-password")"
T=$(curl -s -m 10 -X POST http://127.0.0.1:8765/api/v1/login -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"email\":\"ami@pentana.test\",\"password\":\"$PW\",\"device_name\":\"t\"}" | python3 -c 'import sys,json;print(json.load(sys.stdin)["token"])')
ID=$(curl -s -m 10 http://127.0.0.1:8765/api/v1/activities -H 'Accept: application/json' -H "Authorization: Bearer $T" \
  | python3 -c 'import sys,json;print([a["id"] for a in json.load(sys.stdin)["data"] if a["title"].startswith("Beach")][0])')
curl -s -m 10 -o /dev/null -X POST "http://127.0.0.1:8765/api/v1/activities/$ID/register" -H 'Accept: application/json' -H "Authorization: Bearer $T"
"$DEMO_DIR/start-demo.sh" >/dev/null 2>&1 || fail "restart without --reset failed after a live RSVP"

# 4. stop-demo must free the port.
"$DEMO_DIR/stop-demo.sh" >/dev/null 2>&1
sleep 1
lsof -ti tcp:8765 >/dev/null 2>&1 && fail "port 8765 still in use after stop-demo"

# Leave the demo running and fresh.
"$DEMO_DIR/start-demo.sh" --reset >/dev/null 2>&1 || fail "final reset failed"
rm -rf "$TMP"
echo "demo tests OK"

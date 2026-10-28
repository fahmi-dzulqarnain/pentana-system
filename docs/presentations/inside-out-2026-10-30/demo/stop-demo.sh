#!/usr/bin/env bash
# Stop the Inside Out demo backend (whatever holds port 8765) and delete its env file and database.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
PID_FILE="$ROOT/storage/insideout.pid"
[ -f "$PID_FILE" ] && kill "$(cat "$PID_FILE")" 2>/dev/null || true
for pid in $(lsof -ti tcp:8765 2>/dev/null); do kill "$pid" 2>/dev/null || true; done
rm -f "$PID_FILE" "$ROOT/.env.insideout" "$ROOT/storage/insideout.sqlite" "$ROOT/storage/insideout.config.php"
echo "demo backend stopped and removed"

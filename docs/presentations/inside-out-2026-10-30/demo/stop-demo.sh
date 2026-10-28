#!/usr/bin/env bash
# Stop the Inside Out demo backend and delete its env file and database.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
PID_FILE="$ROOT/storage/insideout.pid"
[ -f "$PID_FILE" ] && kill "$(cat "$PID_FILE")" 2>/dev/null || true
rm -f "$PID_FILE" "$ROOT/.env.insideout" "$ROOT/storage/insideout.sqlite"
echo "demo backend stopped and removed"

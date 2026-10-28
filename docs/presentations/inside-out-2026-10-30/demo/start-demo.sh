#!/usr/bin/env bash
# Start the isolated Inside Out demo backend on http://127.0.0.1:8765.
#   start-demo.sh          reuse the demo DB if present (seed is idempotent)
#   start-demo.sh --reset  wipe and reseed (run before the real session)
# Never touches production: Laravel runs with an empty environment (env -i), its own config cache
# path, and APP_ENV=insideout -> .env.insideout (sqlite); run.php refuses any non-demo connection.
set -euo pipefail

DEMO_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$DEMO_DIR/../../../.." && pwd)"
DB="$ROOT/storage/insideout.sqlite"
PID_FILE="$ROOT/storage/insideout.pid"
PW_FILE="$DEMO_DIR/.demo-password"
PORT=8765
RESET=0; [ "${1:-}" = "--reset" ] && RESET=1

cd "$ROOT"
[ -f "$PW_FILE" ] || echo "pentana-demo" > "$PW_FILE"

if [ ! -f .env.insideout ]; then
  sed -e "s#__APP_KEY__#base64:$(openssl rand -base64 32)#" -e "s#__DB_PATH__#$DB#" \
    "$DEMO_DIR/env.insideout.template" > .env.insideout
fi

# Nothing from the caller's shell (DB_URL, AWS_*, FILESYSTEM_DISK...) reaches Laravel.
demo_env() {
  env -i PATH="$PATH" HOME="$HOME" APP_ENV=insideout DEMO_DB="$DB" DEMO_RESET="$RESET" \
      APP_CONFIG_CACHE="$ROOT/storage/insideout.config.php" "$@"
}

stop_server() {
  [ -f "$PID_FILE" ] && kill "$(cat "$PID_FILE")" 2>/dev/null || true
  for pid in $(lsof -ti "tcp:$PORT" 2>/dev/null); do kill "$pid" 2>/dev/null || true; done
  rm -f "$PID_FILE"
}

if [ "$RESET" = 1 ] || [ ! -f "$DB" ]; then
  stop_server
  rm -f "$DB"; touch "$DB"
  demo_env php "$DEMO_DIR/run.php" guard
  demo_env php artisan migrate:fresh --force -q
fi

demo_env DEMO_PW="$(cat "$PW_FILE")" php "$DEMO_DIR/run.php" "$DEMO_DIR/seed.php"
demo_env php "$DEMO_DIR/run.php" "$DEMO_DIR/check-seed.php"

if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
  echo "server already running (pid $(cat "$PID_FILE"))"
else
  # exec: the pid file holds php itself; stdio detached so callers piping this script don't hang.
  (cd public && exec </dev/null >>"$ROOT/storage/logs/insideout-server.log" 2>&1 \
     && exec env -i PATH="$PATH" HOME="$HOME" APP_ENV=insideout DEMO_DB="$DB" \
        APP_CONFIG_CACHE="$ROOT/storage/insideout.config.php" PHP_CLI_SERVER_WORKERS=4 \
        php -S "127.0.0.1:$PORT" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) &
  echo $! > "$PID_FILE"
  sleep 1
fi

code=$(curl -s -m 10 -o /dev/null -w "%{http_code}" -X POST "http://127.0.0.1:$PORT/api/v1/login" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d "{\"email\":\"ami@pentana.test\",\"password\":\"$(cat "$PW_FILE")\",\"device_name\":\"start-demo\"}")
echo "login $code"
[ "$code" = "200" ]

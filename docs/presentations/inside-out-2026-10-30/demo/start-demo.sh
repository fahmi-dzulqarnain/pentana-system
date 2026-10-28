#!/usr/bin/env bash
# Start the isolated Inside Out demo backend on http://127.0.0.1:8765.
#   start-demo.sh          reuse the demo DB if present (seed is idempotent)
#   start-demo.sh --reset  wipe and reseed (run before the real session)
# Never touches .env: everything runs with APP_ENV=insideout -> .env.insideout (sqlite).
set -euo pipefail

DEMO_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$DEMO_DIR/../../../.." && pwd)"
DB="$ROOT/storage/insideout.sqlite"
PID_FILE="$ROOT/storage/insideout.pid"
PW_FILE="$DEMO_DIR/.demo-password"
PORT=8765

cd "$ROOT"
[ -f "$PW_FILE" ] || openssl rand -hex 12 > "$PW_FILE"

if [ ! -f .env.insideout ]; then
  sed -e "s#__APP_KEY__#base64:$(openssl rand -base64 32)#" -e "s#__DB_PATH__#$DB#" \
    "$DEMO_DIR/env.insideout.template" > .env.insideout
fi

# Run Laravel with no DB/mail overrides from the shell, so only .env.insideout applies.
demo_env() {
  env -u DB_CONNECTION -u DB_HOST -u DB_PORT -u DB_DATABASE -u DB_USERNAME -u DB_PASSWORD \
      -u CACHE_STORE -u SESSION_DRIVER -u MAIL_MAILER -u QUEUE_CONNECTION \
      APP_ENV=insideout "$@"
}

if [ "${1:-}" = "--reset" ] || [ ! -f "$DB" ]; then
  [ -f "$PID_FILE" ] && kill "$(cat "$PID_FILE")" 2>/dev/null || true
  rm -f "$DB" "$PID_FILE"
  touch "$DB"
  demo_env php artisan migrate:fresh --force -q
fi

demo_env DEMO_PW="$(cat "$PW_FILE")" php artisan tinker "$DEMO_DIR/seed.php" </dev/null
demo_env php artisan tinker "$DEMO_DIR/check-seed.php" </dev/null

if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
  echo "server already running (pid $(cat "$PID_FILE"))"
else
  # Fully detach the server (no inherited stdio) so callers piping this script don't hang.
  (cd public && exec </dev/null >>"$ROOT/storage/logs/insideout-server.log" 2>&1 \
     && demo_env PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$PORT" \
        ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php &
   echo $! > "$PID_FILE")
  sleep 1
fi

code=$(curl -s -o /dev/null -w "%{http_code}" -X POST "http://127.0.0.1:$PORT/api/v1/login" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d "{\"email\":\"ami@pentana.test\",\"password\":\"$(cat "$PW_FILE")\",\"device_name\":\"start-demo\"}")
echo "login $code"
[ "$code" = "200" ]

#!/usr/bin/env bash
# Pull-based auto-deploy for the internal server. Cron runs it every minute as the
# deploy user; it ships each new commit on origin/main once the test suite passes for it
# here on the server (GitHub Actions is unavailable while the account is billing-locked).
#
#   * * * * * /var/www/pentana/deploy/auto-deploy.sh >> $HOME/.local/state/pentana-deploy/deploy.log 2>&1
#
# Pull, not push: the host sits on the LAN behind a Cloudflare Tunnel, and the repo is
# public, so a self-hosted runner here would let fork PRs execute on the server.
#
# A commit whose tests fail is skipped. A failed deploy rolls the code back to the previous commit and is not retried until
# main moves again. Migrations are NOT rolled back - check the log if one fails.
set -Eeuo pipefail   # -E: the ERR trap must also fire inside build()

APP_DIR=${APP_DIR:-/var/www/pentana}
BRANCH=main
STATE_DIR="$HOME/.local/state/pentana-deploy"   # outside the repo: keeps the work tree clean
TEST_DIR="$HOME/.cache/pentana-test"            # throwaway checkout the gate runs in
export PATH=/usr/local/bin:/usr/bin:/bin

mkdir -p "$STATE_DIR"
exec 9>"$STATE_DIR/lock"
flock -n 9 || exit 0   # a deploy is still running

cd "$APP_DIR"

log() { echo "[$(date '+%F %T')] $*"; }
# Log a waiting state once, not every minute.
note() { [ "$(cat "$STATE_DIR/note" 2>/dev/null)" = "$1" ] || { log "$1"; echo "$1" > "$STATE_DIR/note"; }; }

git fetch --quiet origin "$BRANCH" || { note "git fetch failed - will retry"; exit 0; }
current=$(git rev-parse HEAD)
target=$(git rev-parse "origin/$BRANCH")
short=${target:0:7}

[ "$current" = "$target" ] && exit 0
[ "$(cat "$STATE_DIR/failed" 2>/dev/null)" = "$target" ] && exit 0

if ! git merge-base --is-ancestor "$current" "$target"; then
    note "$short is not a fast-forward of ${current:0:7} (force-push?) - deploy by hand"
    exit 0
fi

changed() { ! git diff --quiet "$1" "$2" -- "${@:3}"; }

# Gate: run the suite against $target in a separate checkout before touching production.
# It uses .env.example (SQLite, log mailer), never the live .env.
# Every step ends in `|| return`: set -e does not apply inside a function called from `if`.
test_commit() {
    [ -d "$TEST_DIR/.git" ] || git clone --quiet "$APP_DIR" "$TEST_DIR" || return
    cd "$TEST_DIR" || return
    git fetch --quiet "$APP_DIR" "$target" || return
    local before; before=$(git rev-parse HEAD) || return
    git checkout --quiet --force "$target" || return
    { [ -d node_modules ] && ! changed "$before" "$target" package-lock.json; } || npm ci --no-audit --no-fund || return
    npm run build || return
    composer install --no-interaction --no-progress || return
    [ -f .env ] || { cp .env.example .env && php artisan key:generate --quiet; } || return
    php artisan config:clear --quiet || return
    php artisan test
}

log "$short testing"
rm -f "$STATE_DIR/note"
if ! test_commit > "$STATE_DIR/test.log" 2>&1; then
    log "$short tests FAILED - not deploying (see $STATE_DIR/test.log)"
    echo "$target" > "$STATE_DIR/failed"
    exit 0
fi
log "$short tests passed"
cd "$APP_DIR"

build() {
    changed "$1" "$2" package-lock.json && npm ci --no-audit --no-fund
    npm run build              # before artisan: a service provider resolves a Vite asset at boot
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress
    php artisan filament:assets
}

rollback() {
    trap - ERR
    set +e
    log "$short FAILED - rolling back to ${current:0:7}"
    echo "$target" > "$STATE_DIR/failed"
    git reset --hard --quiet "$current"
    build "$target" "$current"
    php artisan optimize:clear && php artisan optimize
    php artisan up
    log "rolled back to ${current:0:7}"
    exit 1   # set +e above leaks into the main shell, so stop explicitly
}

log "deploying ${current:0:7} -> $short"
php artisan down --retry=30 || true
trap rollback ERR

git merge --ff-only --quiet "$target"
build "$current" "$target"
php artisan migrate --force
php artisan optimize:clear
php artisan optimize

trap - ERR
php artisan up
log "deployed $short"

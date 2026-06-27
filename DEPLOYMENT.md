# PENTANA — Production Deployment Guide

Deploying the PENTANA app (Laravel 12 · Filament 5 · PHP 8.5) on a **macOS machine** served by **nginx + PHP-FPM** and exposed to the internet through a **Cloudflare Tunnel**.

```
Internet ──HTTPS──> Cloudflare edge ──tunnel──> cloudflared (on the Mac)
                                                     │  http://localhost:8080
                                                     ▼
                                                  nginx ──FastCGI──> php-fpm ──> Laravel (public/)
                                                     │
                          PostgreSQL (Supabase, remote) ◀── app ──▶ storage/ (uploads), logs
                                                     ▲
                                       cron: `php artisan schedule:run` every minute
```

**Stack facts that shape the steps below**
- PHP **8.2+** (8.5 in use), Composer, Node (for Vite asset build).
- Database is **PostgreSQL on Supabase** (remote) — already migrated; no local DB to install.
- **No queue worker** — notifications/emails are delivered inline, so there is **no `queue:work` to run**.
- A **scheduler** drives recurring jobs (monthly billing, lunch order close, birthday + activity emails) and needs `schedule:run` every minute.
- **Passkeys (WebAuthn) require HTTPS in production** — the app must trust Cloudflare's forwarded scheme (see step 2e). This is the #1 thing that breaks if skipped.
- **Mail is on the `log` driver** until SMTP is configured — "sent" emails land in `storage/logs/laravel.log`, not real inboxes.
- **Push notifications (APNs) are off until configured** — member push needs an APNs `.p8` key + `APN_*` env (step 2i). Until then in-app + email notifications still work and push is silently skipped.

> Throughout, replace these placeholders:
> - `pentana.example.com` → your real public hostname (must be on a Cloudflare-managed domain)
> - `/Users/you/pentana-system` → the absolute path where the app lives on the Mac
> - `you` → the macOS user that will own/run the app

---

## 1. Prerequisites (one time)

Install the runtimes via Homebrew:

```bash
# Homebrew itself: https://brew.sh
brew install php composer node nginx cloudflared git
brew services start php        # starts php-fpm on 127.0.0.1:9000

php -v                         # expect 8.2+ (8.5)
composer --version
node -v && npm -v
```

You also need read access to the private repo `github.com/fahmi-dzulqarnain/pentana-system` (via `gh auth login` or an SSH/HTTPS credential), and your **Supabase** database connection details + the production **`APP_KEY`**.

---

## 2. First-time server setup

### 2a. Get the code

```bash
cd /Users/you
git clone https://github.com/fahmi-dzulqarnain/pentana-system.git
cd pentana-system
```

### 2b. Create the production `.env`

```bash
cp .env.example .env
```

Edit `.env` and set at least:

```dotenv
APP_NAME=PENTANA
APP_ENV=production
APP_DEBUG=false
APP_URL=https://pentana.example.com        # MUST be https — see 2e

APP_KEY=                                    # set in 2c if blank

# PostgreSQL on Supabase (copy from Supabase → Project → Settings → Database)
DB_CONNECTION=pgsql
DB_HOST=aws-0-xxxx.pooler.supabase.com
DB_PORT=6543                                # Supabase pooler port (or 5432 direct)
DB_DATABASE=postgres
DB_USERNAME=postgres.xxxxxxxx
DB_PASSWORD=********
# DB_SSLMODE=require                         # add if your client needs it

# Sessions/cache — database is fine (no Redis required for this app)
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync                        # inline; no worker

# Mail stays on log until SMTP is wired up
MAIL_MAILER=log

# Push notifications (APNs) — leave unset until you have the .p8 key (see step 2i)
# APN_KEY_ID=
# APN_TEAM_ID=7778Y2522V
# APN_BUNDLE_ID=my.silentmode.pentana
# APN_PRIVATE_KEY_PATH=storage/app/private/apns/AuthKey_XXXXXXXXXX.p8
# APN_PRODUCTION=false
```

Lock the file down (it holds live credentials):

```bash
chmod 600 .env
```

### 2c. Install dependencies, build assets, optimize

```bash
# PHP deps — production flags
composer install --no-dev --optimize-autoloader

# App key (only if APP_KEY is blank)
php artisan key:generate

# Front-end assets → public/build (the build manifest is gitignored, so this
# MUST run on every deploy/host)
npm ci
npm run build

# Filament's compiled CSS/JS → public/ (the guest /claim page and panels need this)
php artisan filament:assets

# Symlink public/storage → storage/app/public (lunch images, payment proofs)
php artisan storage:link

# Apply any pending DB migrations to Supabase (additive; confirm first on a shared DB)
php artisan migrate --force

# Cache config/routes/views/events for speed
php artisan optimize
```

Make the writable dirs writable by the php-fpm user (the Mac user running `brew services`):

```bash
chmod -R ug+rwX storage bootstrap/cache
```

### 2d. Create the first admin user

There's no admin seeder; create one via Tinker (members are normally imported + self-claimed):

```bash
php artisan tinker --execute="\App\Models\User::create([
    'name' => 'Admin',
    'email' => 'admin@your-org.com',
    'password' => bcrypt('CHANGE-THIS-STRONG-PASSWORD'),
    'is_admin' => true,
    'activated_at' => now(),
]);"
```

### 2e. Trust the Cloudflare proxy (REQUIRED for HTTPS / passkeys)

cloudflared connects to nginx over plain HTTP on localhost and forwards
`X-Forwarded-Proto: https`. Laravel must trust that, otherwise it thinks requests
are HTTP — which breaks passkey sign-in (WebAuthn rejects non-HTTPS in production)
and generates `http://` asset/redirect URLs.

Edit `bootstrap/app.php` so the middleware closure trusts the proxy:

```php
->withMiddleware(function (Middleware $middleware): void {
    // Served behind Cloudflare Tunnel: cloudflared forwards X-Forwarded-Proto=https
    // from localhost. Trust it so the app knows it's HTTPS (required for passkeys
    // and correct URL generation).
    $middleware->trustProxies(at: '*');
})
```

Re-cache after editing: `php artisan optimize`. (Combined with `APP_URL=https://…`, this makes `request()->isSecure()` true and passkeys work.)

> If you'd rather not trust all proxies, bind nginx to `127.0.0.1` only (step 2f) so the Mac is unreachable except through cloudflared, which makes `at: '*'` safe in practice.

### 2f. nginx + PHP-FPM

Create an nginx server block at `/opt/homebrew/etc/nginx/servers/pentana.conf`:

```nginx
server {
    listen 127.0.0.1:8080;            # localhost only — cloudflared reaches it
    server_name pentana.example.com;
    root /Users/you/pentana-system/public;

    index index.php;
    charset utf-8;
    client_max_body_size 20M;         # allow payment-proof / image uploads

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;  # Homebrew php-fpm default
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

Make sure the main `nginx.conf` includes `servers/*.conf` (Homebrew's default does), then:

```bash
nginx -t                       # test config
brew services restart nginx
brew services restart php      # ensure php-fpm is up

# Smoke test the origin locally (bypassing the tunnel)
curl -I -H "Host: pentana.example.com" http://127.0.0.1:8080   # expect HTTP 200/302
```

### 2g. Cloudflare Tunnel (cloudflared)

```bash
# Authenticate (opens a browser to pick the domain/zone)
cloudflared tunnel login

# Create a named tunnel — note the printed Tunnel UUID + credentials JSON path
cloudflared tunnel create pentana

# Route the public hostname to this tunnel (creates the DNS record in Cloudflare)
cloudflared tunnel route dns pentana pentana.example.com
```

Create `~/.cloudflared/config.yml`:

```yaml
tunnel: <TUNNEL-UUID>
credentials-file: /Users/you/.cloudflared/<TUNNEL-UUID>.json

ingress:
  - hostname: pentana.example.com
    service: http://localhost:8080
  - service: http_status:404
```

Test in the foreground, then install it as a launchd service so it survives reboots:

```bash
cloudflared tunnel run pentana          # Ctrl-C once you see it connect
sudo cloudflared service install        # installs a launchd daemon using config.yml
```

Browse to `https://pentana.example.com` — you should reach the login page over HTTPS.

### 2h. Scheduler (recurring jobs)

The app schedules monthly billing, lunch-order close (every 15 min), and the daily
birthday + activity-reminder emails. A single cron entry running every minute drives
all of them:

```bash
crontab -e
```

Add:

```cron
* * * * * cd /Users/you/pentana-system && /opt/homebrew/bin/php artisan schedule:run >> /dev/null 2>&1
```

> **macOS caveat:** the `cron` process may need **Full Disk Access** (System Settings → Privacy & Security → Full Disk Access → add `/usr/sbin/cron`). If you prefer launchd, create a `LaunchAgent` that runs `php artisan schedule:run` with `StartInterval 60` instead.

Confirm the schedule is registered:

```bash
php artisan schedule:list
```

There is **no queue worker to start** — notifications are delivered inline by design.

### 2i. Push notifications (APNs) — optional, enable when ready

Member push (activity + lunch alerts — the same events as the in-app bell) is sent through Apple Push Notification service using a **token-based `.p8` key**. It stays dormant until the key + env below are in place: with nothing configured the app still sends in-app + email notifications and simply skips push, so this step can be deferred.

**1. Apple Developer (one time).** In the [developer portal](https://developer.apple.com/account):
- Enable the **Push Notifications** capability on App ID `my.silentmode.pentana`.
- Create an **APNs Auth Key**: *Keys → ➕ → tick "Apple Push Notifications service (APNs)"* → download the `.p8` **once** (it can't be re-downloaded). Note the **Key ID**; the **Team ID** is `7778Y2522V`. One key covers both sandbox and production and never expires.

**2. Put the key on the server (never in git).** Store it outside the web root and lock it down:

```bash
mkdir -p storage/app/private/apns
mv ~/Downloads/AuthKey_XXXXXXXXXX.p8 storage/app/private/apns/
chmod 600 storage/app/private/apns/AuthKey_XXXXXXXXXX.p8
```

nginx only serves `public/`, and `storage/app` is gitignored — so the key is both unreachable over HTTP and uncommittable.

**3. Set the `APN_*` env** in `.env` (these map 1:1 to `config/broadcasting.php`):

```dotenv
APN_KEY_ID=XXXXXXXXXX                         # the Key ID from step 1
APN_TEAM_ID=7778Y2522V
APN_BUNDLE_ID=my.silentmode.pentana           # must match the app's bundle id
APN_PRIVATE_KEY_PATH=storage/app/private/apns/AuthKey_XXXXXXXXXX.p8
# APN_PRIVATE_KEY_SECRET=                      # only if the .p8 is passphrase-protected (it usually isn't)
APN_PRODUCTION=false                           # see step 4
```

**4. Match `APN_PRODUCTION` to the app build.** APNs has two environments and the server must target the one the installed app was built for:

| App build | App entitlement (`aps-environment`) | Set |
|-----------|-------------------------------------|-----|
| Run from Xcode (debug) | `development` | `APN_PRODUCTION=false` |
| **TestFlight or App Store** | `production` | `APN_PRODUCTION=true` |

> **Gotcha:** TestFlight already uses the **production** APNs environment. A token from a TestFlight build fails (`BadDeviceToken`) against the sandbox — so flip this to `true` the moment you ship to TestFlight. The repo's entitlement is currently `development`.

**5. Apply the schema + reload config.** The `device_tokens` table is created by the normal migrations (already applied to Supabase on 2026‑06‑27; a fresh host gets it from `php artisan migrate --force` in step 2c). After editing `.env`, re-cache so the new values are read:

```bash
php artisan optimize        # or: php artisan config:clear && php artisan config:cache
```

**How delivery works (no queue worker).** Push is sent **inline**, inside the request that triggers the notification (e.g. an admin publishing an activity in Filament), and only to members with a registered device token. The app POSTs its APNs token to `POST /api/v1/device-tokens` after sign-in and removes it on logout; members without a token — or everyone, when APNs is unconfigured — are silently skipped, nothing errors. The server needs outbound HTTPS to `api.push.apple.com` (and `api.sandbox.push.apple.com` for dev builds).

**Verify it's working** (push needs a **physical device** — the simulator can't register a real APNs token):

```bash
# After a member signs in on a real device:
php artisan tinker --execute="echo \App\Models\DeviceToken::count();"   # expect >= 1

# Then publish an activity/lunch to trigger a notification and watch for APNs errors:
tail -f storage/logs/laravel.log
```

---

## 3. Redeploy / update workflow

After the first-time setup, shipping a new version is:

```bash
cd /Users/you/pentana-system

php artisan down                              # maintenance mode (optional)

git pull origin main

composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan filament:assets                   # refresh published Filament assets

php artisan migrate --force                   # review pending migrations first!

php artisan optimize:clear
php artisan optimize                          # re-cache config/routes/views/events

brew services restart php                     # clear opcache so new code is picked up

php artisan up
```

Save this as `deploy.sh` in the repo root for convenience:

```bash
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
php artisan down || true
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan filament:assets
php artisan migrate --force
php artisan optimize:clear && php artisan optimize
brew services restart php
php artisan up
echo "Deployed $(git rev-parse --short HEAD)"
```

```bash
chmod +x deploy.sh    # then run ./deploy.sh for each release
```

cloudflared and the scheduler keep running across deploys — no need to touch them unless their config changes.

---

## 4. Operations

**Logs**
- App: `storage/logs/laravel.log` (also where "sent" emails land while `MAIL_MAILER=log`)
- nginx: `/opt/homebrew/var/log/nginx/{access,error}.log`
- cloudflared: `sudo launchctl print system/com.cloudflare.cloudflared` / Console.app
- Tail app errors: `tail -f storage/logs/laravel.log`

**Health check**
```bash
curl -I https://pentana.example.com           # 200/302 over HTTPS
php artisan about                              # env, cache, DB connection summary
```

**Backups**
- **Database**: Supabase takes automatic backups (verify the retention on your plan). For manual dumps, use `pg_dump` against the Supabase connection.
- **Uploads**: back up `storage/app/public` (payment proofs, lunch images) — these live only on the Mac, not in Supabase. e.g. a daily `rsync`/Time Machine of that directory.

**Pending follow-ups for this app**
- **SMTP**: while `MAIL_MAILER=log`, birthday/activity/publish emails are only written to the log. Switch `MAIL_*` to a real provider when ready; the in-app notification bell already works.
- **First-run passkeys**: confirm a passkey can be registered and used over the live HTTPS domain (this is the canary that step 2e is correct).
- **Push notifications**: dormant until the APNs `.p8` key + `APN_*` env are set (step 2i) and the iOS build has the Push capability. Confirm a real device receives a push after publishing an activity; remember TestFlight builds need `APN_PRODUCTION=true`.

---

## 5. Security checklist

- [ ] `APP_DEBUG=false` and `APP_ENV=production` in `.env`
- [ ] `.env` is `chmod 600` and never committed (it's gitignored)
- [ ] APNs `.p8` key (if push is enabled) is `chmod 600`, stored outside `public/`, and never committed
- [ ] nginx listens on `127.0.0.1` only — the Mac is reachable from the internet **only** through the tunnel
- [ ] `APP_URL` is the `https://` hostname and `trustProxies` is configured (step 2e)
- [ ] Strong password on the admin account; rotate the Tinker-set one after first login
- [ ] macOS kept updated; FileVault on; the machine physically secured / on a trusted network
- [ ] (Optional) Put **Cloudflare Access** in front of `/admin`-style routes for an extra auth layer
- [ ] Supabase database password is strong and not shared with non-prod environments long-term

---

## 6. Quick reference

| Task | Command |
|------|---------|
| Deploy a new release | `./deploy.sh` |
| Apply DB migrations | `php artisan migrate --force` |
| Rebuild assets | `npm run build && php artisan filament:assets` |
| Re-cache after `.env`/code change | `php artisan optimize` |
| Clear all caches | `php artisan optimize:clear` |
| List scheduled jobs | `php artisan schedule:list` |
| Restart web stack | `brew services restart php nginx` |
| Tunnel status | `cloudflared tunnel info pentana` |
| Tail app log | `tail -f storage/logs/laravel.log` |
| App/env summary | `php artisan about` |

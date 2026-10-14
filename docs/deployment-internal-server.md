# PENTANA — Internal Server Deployment (Ubuntu)

Deploying PENTANA on the **internal application server** (`pentana`, 192.168.50.20).
This replaces `DEPLOYMENT.md` — that runbook targets macOS + Homebrew + Cloudflare Tunnel
and does not apply here.

```
Internet ──HTTPS──> Cloudflare edge ──tunnel──> cloudflared (on the host)
                                                    │  https://localhost:443
                                                    ▼
LAN ─────HTTPS────────────────────────────────> nginx ──FastCGI──> php-fpm ──> Laravel (public/)
                              │
      PostgreSQL (Supabase, remote) ◀── app ──▶ storage/ (uploads), logs
                              ▲
                cron: `php artisan schedule:run` every minute
```

## The server

| | |
|---|---|
| Host | `pentana` / 192.168.50.20, alias `ssh pentana` |
| OS | Ubuntu 24.04 LTS |
| Type | LXC container on Proxmox — 4 GB RAM, ~92 GB free |
| Public URL | **https://pentana.silentmode.net** via Cloudflare Tunnel (token-managed) |
| Access | SSH key only (`~/.ssh/pentana_ed25519`). The app is **publicly reachable** through the tunnel — this departs from the original change request's "internal network only"; put Cloudflare Access in front to restore an equivalent control |
| Database | Supabase PostgreSQL (remote) — verified reachable on 5432 from this host |

**Stack facts that shape the steps below**

- PHP **8.4** from the **ondrej/php PPA**, not the distro's 8.3. `composer.lock` resolves
  `symfony/* 8.1` (`>=8.4.1`) and `laravel-notification-channels/apn v6.4` (`^8.4`), so the
  lock cannot install on 8.3 even though `composer.json` declares `"php": "^8.2"`. That
  mismatch is a repo-level issue worth fixing at the source.
- **Do not `apt install nodejs`.** Ubuntu 24.04 ships Node 18.19; Vite 7 requires
  `^20.19 || >=22.12`, so the asset build fails. Use NodeSource Node 22.
- **No queue worker** — notifications/emails are delivered inline.
- A **scheduler** drives monthly billing, lunch-order close and the daily emails; it needs
  `schedule:run` every minute.
- **Passkeys (WebAuthn) require HTTPS.** Over plain `http://` passkey sign-in is unavailable
  and everyone falls back to passwords. See "TLS" below — this is the one open decision.
- **Mail is on the `log` driver** until SMTP is configured.
- `sudo` on this host requires a password, so provisioning is interactive.

---

## 1. One-time provisioning

`provision-server.sh` in the repo root installs the runtimes and web stack. It does **not**
deploy the application, so it is safe to run before the change request is approved — it
leaves a correctly configured but empty server.

```bash
scp provision-server.sh pentana:/tmp/
ssh -t pentana 'sudo bash /tmp/provision-server.sh'
```

It installs PHP 8.3 + extensions (`pgsql mbstring xml curl zip bcmath intl gd`), Composer
(checksum-verified), Node 22 from NodeSource, and nginx; sets upload limits to 20 MB to match
the payment-proof uploads; writes the nginx site; and creates `/var/www/pentana` owned by
`fahmi:www-data`.

## 2. TLS

`setup-tls-selfsigned.sh` issues a self-signed certificate and switches nginx to HTTPS:

```bash
scp setup-tls-selfsigned.sh pentana:/tmp/
ssh -t pentana 'sudo bash /tmp/setup-tls-selfsigned.sh pentana.silentmode.my'
```

It detects the running PHP-FPM socket, so it is safe to re-run after a PHP upgrade.
**This is interim** — the options below remain the real decision.

Passkey sign-in is the app's primary auth and needs a secure context. Three options:

1. **Certificate from an internal CA** *(recommended if one exists)* — issue for a hostname
   such as `pentana.internal`, trusted by managed devices already. Nothing to accept.
2. **Public certificate for an internal name** — if the organisation owns a domain, a
   Let's Encrypt **DNS-01** certificate works for a host that isn't publicly reachable.
   Renewal needs DNS API access from this box.
3. **Self-signed** *(fallback)* — works, but every browser shows a warning until the cert is
   installed on each device, and mobile clients are stricter about it. Acceptable for a pilot,
   poor for a member-facing rollout.

Whichever is chosen, add the `listen 443 ssl;` server block and redirect port 80 to it, then
set `APP_URL=https://<hostname>` in `.env`.

> **A Cloudflare Tunnel does front this host**, so `trustProxies(at: '*')` in
> `bootstrap/app.php` **is** required — cloudflared forwards `X-Forwarded-Proto`, and
> without trusting it Laravel generates `http://` URLs and passkeys break. It is already
> configured.
>
> **Tunnel origin must be `https://localhost:443` with No TLS Verify on.** Pointing it at
> `http://localhost:80` produces an infinite redirect loop, because the port-80 server block
> 301s everything to HTTPS. No TLS Verify is needed because the origin cert is self-signed —
> it only protects the loopback hop; Cloudflare serves a publicly trusted cert at the edge.
>
> **`APP_URL` must match the hostname actually served.** `config/passkeys.php` derives the
> WebAuthn relying-party ID from it (`parse_url(config('app.url'), PHP_URL_HOST)`), and a
> mismatch fails passkey registration with a SecurityError even when TLS is perfect.

## 3. Deploy the application

> **Build assets before running any artisan command.** A service provider resolves a Vite
> asset at boot, so `composer install`'s `package:discover` hook fails with "Vite manifest
> not found" on a host where `public/build/` doesn't exist yet. Order: `npm run build`
> first, then `composer install`.

```bash
ssh pentana
cd /var/www/pentana

git clone https://github.com/fahmi-dzulqarnain/pentana-system.git .

cp .env.example .env
chmod 600 .env
# Set: APP_ENV=production, APP_DEBUG=false, APP_URL=https://<hostname>,
#      the Supabase DB_* values, SESSION_DRIVER=database, CACHE_STORE=database,
#      QUEUE_CONNECTION=sync, MAIL_MAILER=log
php artisan key:generate

npm ci && npm run build          # FIRST — manifest is gitignored, and artisan needs it
composer install --no-dev --optimize-autoloader
php artisan filament:assets
php artisan storage:link

php artisan migrate --force      # review pending migrations first
php artisan optimize

# php-fpm runs as www-data and must write to these
sudo chown -R fahmi:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
```

Create the first admin (no seeder exists — members self-claim):

```bash
php artisan tinker --execute="\App\Models\User::create([
    'name' => 'Admin',
    'email' => 'admin@your-org.com',
    'password' => bcrypt('CHANGE-THIS-STRONG-PASSWORD'),
    'is_admin' => true,
    'activated_at' => now(),
]);"
```

## 4. Scheduler

```bash
crontab -e
```

```cron
* * * * * cd /var/www/pentana && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Confirm with `php artisan schedule:list` — expect `app:generate-monthly-fees`,
`app:close-lunch-orders`, `app:send-birthday-emails`, `app:send-activity-reminders`.
There is **no queue worker to start**.

## 5. Redeploy / update

```bash
#!/usr/bin/env bash
set -euo pipefail
cd /var/www/pentana
php artisan down || true
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan filament:assets
php artisan migrate --force
php artisan optimize:clear && php artisan optimize
sudo systemctl reload php8.4-fpm
php artisan up
echo "Deployed $(git rev-parse --short HEAD)"
```

## 6. Operations

**Logs**
- App: `storage/logs/laravel.log` (also where "sent" mail lands while `MAIL_MAILER=log`)
- nginx: `/var/log/nginx/{access,error}.log`
- php-fpm: `journalctl -u php8.4-fpm`

**Health**
```bash
curl -I https://<hostname>
php artisan about
```

**Backups**
- **Database**: Supabase automatic backups — verify retention on the plan. Manual dumps via
  `pg_dump` against the Supabase connection.
- **Uploads**: `storage/app/public` (payment proofs, lunch images) lives only on this
  container, not in Supabase. It needs its own backup — a nightly rsync, or a Proxmox
  container snapshot, which also covers the whole VM.

**Hardening follow-ups**
- Disable SSH password authentication now that keys are installed
  (`PasswordAuthentication no`, then `sudo systemctl reload ssh`).
- Consider restricting nginx to the internal subnet if the container is ever multi-homed.

## 7. Push notifications (APNs)

Working in production as of 2026-10-14. Member push uses a token-based `.p8` key.

**Key on the server** — never in git, and `php-fpm` (running as `www-data`) must read it:

```bash
ssh pentana 'mkdir -p /var/www/pentana/storage/app/private/apns'
scp ~/Downloads/AuthKey_XXXXXXXXXX.p8 pentana:/var/www/pentana/storage/app/private/apns/
ssh pentana 'cd /var/www/pentana && chmod 640 storage/app/private/apns/AuthKey_*.p8'
```

`chgrp www-data` fails here — the deploy user isn't in that group and the operation needs
root. Grant access with ACLs instead, which the file owner may set. Both parent directories
need traverse, not just the key:

```bash
setfacl -m u:www-data:x storage/app/private
setfacl -m u:www-data:x storage/app/private/apns
setfacl -m u:www-data:r storage/app/private/apns/AuthKey_XXXXXXXXXX.p8
```

**Env** (then `php artisan optimize` — config is cached, so edits don't take effect without it):

```dotenv
APN_KEY_ID=XXXXXXXXXX
APN_TEAM_ID=7778Y2522V
APN_BUNDLE_ID=my.silentmode.pentana
APN_PRIVATE_KEY_PATH=storage/app/private/apns/AuthKey_XXXXXXXXXX.p8
APN_PRODUCTION=true
```

### The `APN_PRODUCTION` trap

Apple runs two independent APNs environments, and a device token is valid in exactly one:

| Build | Token valid against | `APN_PRODUCTION` |
|---|---|---|
| Run from Xcode (debug) | sandbox | `false` |
| **TestFlight or App Store** | **production** | **`true`** |

TestFlight uses the *production* environment despite being a pre-release channel — this
catches people out. The setting is **global**, so one server targets one environment: with
`true`, Xcode debug builds silently stop receiving push, and vice versa.

**Failure is silent.** `laravel-notification-channels/apn` throws nothing on
`BadDeviceToken`, so a wrong flag looks exactly like success — `notify()` returns cleanly,
the bell notification appears, the email is logged, and the phone simply stays quiet.
Nothing in `laravel.log` indicates a problem.

### Diagnosing silent non-delivery

Ask Apple directly rather than inferring from the absence of errors. Post to both
environments with the same token and read the `reason`:

```
POST https://api.push.apple.com/3/device/<token>          # production
POST https://api.sandbox.push.apple.com/3/device/<token>  # sandbox
  authorization: bearer <ES256 JWT: {alg,kid} / {iss=team,iat}>
  apns-topic: my.silentmode.pentana
  apns-push-type: alert
```

`200` means accepted; `400 BadDeviceToken` means that token belongs to the *other*
environment. Which one answers `200` tells you what `APN_PRODUCTION` must be. Note the JWS
signature needs the raw R‖S form, not the DER that `openssl_sign` returns.

Tokens from a build type you no longer target are permanently dead and can be deleted from
`device_tokens`.

## 8. Open items before go-live

- [x] TLS — Cloudflare edge certificate (trusted) via the tunnel; origin keeps a self-signed cert for the loopback hop
- [ ] SMTP configured, or members told email is not yet delivered — `MAIL_FROM_ADDRESS` is
      still Laravel's `hello@example.com` placeholder
- [ ] Demo data removed from production (DemoContentSeeder lunches/activities, the
      `member@silentmode.my` test account) — it is there because local dev shares the live
      Supabase instance, which is worth separating before real dues data exists
- [x] APNs push — key `CVS8SF75ZU` deployed, `APN_PRODUCTION=true`, delivery verified on device
- [x] Member access from outside — resolved by the tunnel; mobile app can now reach it
- [ ] Cloudflare Access (or equivalent) in front, and a change-request addendum for the public exposure
- [x] Change request approved (2026-10-14)

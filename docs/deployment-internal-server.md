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

### Automatic (default)

Every push to `main` deploys itself once the test suite passes **on this host**. This
covers the public site and the member portal (`/account`): both are the same Laravel app.

```
push to main          cron, every minute, on this host
     │                deploy/auto-deploy.sh
     └──> git fetch ──> tests in ~/.cache/pentana-test ──pass──> deploy /var/www/pentana
```

It is **pull-based** on purpose: the host is LAN-only behind the tunnel, so nothing outside
can reach it, and the repo is public, so a self-hosted Actions runner here would let fork PRs
execute code on the server. Tests run here rather than on GitHub Actions because the GitHub
account is billing-locked; the script needs no secrets either way.

The test checkout uses `.env.example` (SQLite in memory, log mailer), never the live `.env`,
and needs the SQLite PHP extension, which production itself does not:

```bash
sudo apt-get install -y php8.4-sqlite3 && sudo systemctl reload php8.4-fpm
```

Install the cron once, as `fahmi` (no sudo):

```bash
mkdir -p ~/.local/state/pentana-deploy
(crontab -l; echo '* * * * * /var/www/pentana/deploy/auto-deploy.sh >> $HOME/.local/state/pentana-deploy/deploy.log 2>&1') | crontab -
```

How it behaves:

- **Tests first, outside production.** The suite runs against the new commit in
  `~/.cache/pentana-test` (dev dependencies, its own `node_modules` and build). Production is
  untouched until it passes, so testing adds no downtime. A failing commit is skipped.
- **Skips `npm ci`** unless `package-lock.json` changed; always rebuilds assets and runs
  `composer install`, `filament:assets`, `migrate --force` and `optimize`.
- **Maintenance mode** for the length of the deploy (about a minute; members and the mobile
  app see a 503 with `Retry-After`).
- **Rolls back on failure** to the previous commit and does not retry that commit until
  `main` moves. **Migrations are not rolled back**: a migration that half-applies needs a
  hand fix.
- **No php-fpm reload** (that needs sudo). Not required while `opcache.validate_timestamps`
  stays on, which is the Ubuntu default; turning it off would make deploys serve stale code.
- **Fast-forward only.** After a force-push to `main` it logs and stops; deploy by hand.

Operating it:

| | |
|---|---|
| Log | `~/.local/state/pentana-deploy/deploy.log` |
| Last test run | `~/.local/state/pentana-deploy/test.log` |
| Retry a skipped commit (e.g. a flaky test) | `rm ~/.local/state/pentana-deploy/failed` |
| Pause auto-deploy | comment out the cron line (`crontab -e`) |

### Manual

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

## 8. Email (Resend)

Working in production as of 2026-10-14. Outbound mail goes through Resend's SMTP relay,
sending as `noreply@pentana.silentmode.net`.

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.resend.com
MAIL_PORT=587
MAIL_USERNAME=resend
MAIL_PASSWORD=re_...            # Resend API key
MAIL_FROM_ADDRESS="noreply@pentana.silentmode.net"
MAIL_FROM_NAME="PENTANA"
```

Laravel 12 uses `MAIL_SCHEME`, not `MAIL_ENCRYPTION`. Config is cached, so any `.env`
change needs `php artisan optimize` before it takes effect.

### DNS

Four records on the `pentana` subdomain of `silentmode.net` (changes there need an IT
ticket — the zone is not self-service):

```
TXT    resend._domainkey.pentana   p=MIGfMA0GCSqG…          DKIM signing
CNAME  rsend.pentana               rsend-apne1.forge.rmta.net   sending
CNAME  send.pentana                send.forge.rmta.net          sending
MX     pentana                     inbound-smtp.ap-northeast-1.amazonaws.com (10)   receiving
```

Company email is on **`silentmode.my`** (Microsoft 365). `silentmode.net` has no MX of its
own, so these records disturb nothing — worth stating in any DNS ticket, since it is the
first question a reviewer asks.

### Gotchas

**Resend treats subdomains as separate domains.** Verifying `silentmode.net` does *not*
authorise `pentana.silentmode.net`; each is added and verified in its own right. The
failure is a clear `550 The <domain> is not verified` at send time.

**Adding the DNS records does not verify the domain.** Resend only checks when you click
"I've already added the records". Correct, resolving DNS plus an unverified domain still
gives the same 550.

**Never leave `MAIL_MAILER=smtp` with a broken mailer.** Notifications are sent *inline*,
not queued, so an SMTP failure throws inside the request that publishes a lunch or
activity — the admin gets a 500, and the bell and push notifications never fire either.
When configuring or debugging mail, keep `MAIL_MAILER=log` and only switch once a test
send succeeds.

**Testing before the domain is verified:** Resend allows `onboarding@resend.dev` as sender
to your own account address without verification. Useful for isolating the API key and
connection from the domain question — if that send succeeds, the credentials are fine and
only verification remains.

### Limits

Free tier is 100 emails/day, 3,000/month. Bill reminders run on the 15th and 31st to every
member with an outstanding balance; past ~100 members those runs will hit the daily cap and
some members silently will not be reminded. Resend's Logs tab is the only visibility into
delivery, bounces and spam complaints.

`noreply@pentana.silentmode.net` is send-only. The MX record above makes inbound possible
for later use.

## 9. Email send cap

Live since 2026-10-14. Resend's free tier allows 100 emails/day and 3,000/month; a message
over either limit is rejected and lost. `BudgetedTransport` wraps the real mail transport,
caps sends at **99/day** and **3,000/month**, and spools the overflow to `queued_emails` as
raw MIME. `app:drain-email-queue` sends what fits, every minute, in priority order.

Design and rationale: `docs/superpowers/specs/2026-10-14-email-send-cap-design.md`.

```dotenv
MAIL_MAILER=budgeted          # the cap is INERT on any other value
MAIL_INNER_MAILER=smtp        # what BudgetedTransport wraps
```

Setting `MAIL_MAILER=smtp` silently disables the cap while everything still looks deployed:
the tables exist, the drain runs, the widget renders, and every email bypasses the budget.
Check `config('mail.default')`, not the presence of the migrations.

`MAIL_INNER_MAILER` must name an **SMTP-family** mailer. API transports (`resend`, `ses`,
`postmark`, `mailgun`) cannot send a stored `RawMessage` — they call
`MessageConverter::toEmail()` and throw. The drain refuses to run against one rather than
burning attempts, but the misconfiguration is easy to make precisely because the provider
*is* Resend.

Admins see `EmailBudgetStats` on the account dashboard. The normal state is colourless;
amber warns on the monthly **projection** while there is still time to act; red means either
the queue has stopped draining or mail has permanently failed.

### Supabase pooler: 15 connections

The pooler rejects the sixteenth concurrent client:

```
FATAL: (EMAXCONNSESSION) max clients reached in session mode
       - max clients are limited to pool_size: 15
```

This bit during the concurrency verification below, where 20 parallel workers killed five of
themselves. It does not affect normal operation — PHP-FPM serves requests sequentially, and
a 150-member publish is one connection in a loop — but it is a hard ceiling to know about
before anything in this app runs in parallel, and it sharpens the risk in *Known issue*
below. Any script that fans out against this database must stay under 15 workers.

### Verifying the lock against Postgres

`EmailBudget::claim()` uses `lockForUpdate()`, which is a **no-op on SQLite**, so the test
suite cannot prove the budget holds under contention. It must be checked against Supabase by
hand after any change to `claim()`:

Run N concurrent workers (N < 15) each calling `App\Models\EmailBudget::claim()` in a loop,
with total attempts well above the remaining headroom. Then assert that claims granted equals
exactly the headroom and `sent_count` equals exactly 99 — never more. A broken lock shows up
as two workers reading the same count and both incrementing.

Verified 2026-10-14: 8 workers, 400 attempts, 1 slot already used. Exactly 98 granted, final
count exactly 99, no errors. Restore the live counter afterwards — the check inflates a real
production row, and anything sent while it is inflated gets queued rather than delivered.

### Known issue: the budget lock is held across SMTP sends

`App\Services\ActivityRegistrationService::promoteWaitlist()` calls `$next->user->notify()`
inside a loop, and its callers (`cancel()`, `reconcileWaitlist()`) wrap that in
`DB::transaction()`. Nested inside an outer transaction, Laravel's `DB::transaction()`
degrades to a SAVEPOINT, and releasing a savepoint does **not** release Postgres row locks.

So the `lockForUpdate()` on today's `email_budget` row is held until the outer COMMIT - that
is, across every synchronous SMTP send in the loop. Two consequences:

- Every other outbound email in the application blocks behind one activity cancellation,
  against a connection pool of 15.
- If anything after the `notify()` throws, the `sent_count` increment and the `queued_emails`
  insert both roll back while the message has already gone to SMTP. The budget then
  undercounts, and real sends can exceed 99.

Not fixed. A proper fix gives budget writes their own database connection so they commit
independently of the caller's transaction, which is a design change deserving its own spec.
Until then, treat activity cancellation on a large waitlist as a known contention point.

## 10. Open items before go-live

- [x] TLS — Cloudflare edge certificate (trusted) via the tunnel; origin keeps a self-signed cert for the loopback hop
- [x] Email — Resend SMTP, sending as `noreply@pentana.silentmode.net`, verified end to end
- [ ] Demo data removed from production (DemoContentSeeder lunches/activities, the
      `member@silentmode.my` test account) — it is there because local dev shares the live
      Supabase instance, which is worth separating before real dues data exists
- [x] APNs push — key `CVS8SF75ZU` deployed, `APN_PRODUCTION=true`, delivery verified on device
- [x] Member access from outside — resolved by the tunnel; mobile app can now reach it
- [ ] Cloudflare Access (or equivalent) in front, and a change-request addendum for the public exposure
- [ ] Give `EmailBudget` writes a dedicated connection so the budget lock is not held across
      SMTP sends (see *Known issue* in section 9)
- [x] Change request approved (2026-10-14)

# PENTANA — Internal Server Deployment (Ubuntu)

Deploying PENTANA on the **internal application server** (`pentana`, 192.168.50.20).
This replaces `DEPLOYMENT.md` — that runbook targets macOS + Homebrew + Cloudflare Tunnel
and does not apply here.

```
Internal network ──HTTPS──> nginx ──FastCGI──> php-fpm ──> Laravel (public/)
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
| Access | SSH key only (`~/.ssh/pentana_ed25519`); internal network, not internet-facing |
| Database | Supabase PostgreSQL (remote) — verified reachable on 5432 from this host |

**Stack facts that shape the steps below**

- PHP **8.3** from the Ubuntu repos satisfies Laravel 12's `^8.2` — no PPA needed.
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

## 2. TLS — decide before go-live

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

> Unlike the macOS runbook, there is **no Cloudflare Tunnel here**, so the `trustProxies`
> step in `DEPLOYMENT.md` §2e is not needed — nginx terminates TLS directly.

## 3. Deploy the application

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

composer install --no-dev --optimize-autoloader
npm ci && npm run build          # build manifest is gitignored — must run on the host
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
sudo systemctl reload php8.3-fpm
php artisan up
echo "Deployed $(git rev-parse --short HEAD)"
```

## 6. Operations

**Logs**
- App: `storage/logs/laravel.log` (also where "sent" mail lands while `MAIL_MAILER=log`)
- nginx: `/var/log/nginx/{access,error}.log`
- php-fpm: `journalctl -u php8.3-fpm`

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

## 7. Open items before go-live

- [ ] TLS certificate chosen and installed (§2) — blocks passkey sign-in
- [ ] SMTP configured, or members told email is not yet delivered
- [ ] APNs push key, if the mobile app is in scope for launch
- [ ] Member access from outside the internal network decided (VPN, or internal-only)
- [ ] Change request approved

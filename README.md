# PENTANA

Membership & welfare management system for an organization — dues/billing, a lunch
planner, activities/events, welfare emails, and member self-onboarding — built as a
**Laravel 12 + Filament 5** app, with a **JSON API** that powers a native member app
([`pentana-mobile`](https://github.com/fahmi-dzulqarnain/pentana-mobile), KMP + SwiftUI).

## Tech stack

- **PHP 8.2+** (8.5 in use), **Laravel 12**, **Filament 5**, **Livewire 4**
- **PostgreSQL** (Supabase in production) · **SQLite** in-memory for tests
- **Tailwind 4 + Vite** assets
- **Sanctum** for the mobile API · **spatie/laravel-passkeys** (WebAuthn) for sign-in
- **PHPUnit** + **Pint**

Admins and members share a single Filament panel (`/account`), gated per-resource by
`is_admin`; a guest `/claim` page handles account activation.

## Features

- **Finance / dues** — monthly fee periods per member (driven by member category, with a
  per-member `custom_fee` override). Payment is **manual proof-upload by design**: a proof
  (amount + image) is recorded, an admin verifies it, and `PaymentAllocation`s settle the
  member's bills; over-payment becomes member credit. Members see their bills + outstanding;
  admins manage Fee Payments, Fee Periods, and Member Categories.
- **Lunch planner** — caterers, meals, meal options, and per-caterer menus with pricing.
  Lunches are published for member voting (*My Lunch*), close at a deadline, and roll up into
  order counts, a calendar, and an admin tally.
- **Activities** — events with optional capacity + an auto-promoting **waitlist**,
  draft → publish → cancel lifecycle, member **RSVP** (*My Activities*), admin day-of
  **check-in**, optional per-activity **registration questions**, and ~1-day reminders.
- **Welfare** — auto-emails (birthday + one-off, RichEditor body); member birthday on the
  profile (admin-editable) and a daily birthday-email send.
- **Onboarding** — bulk member import, **self-claim** at `/claim` (email lookup → set
  birthday + password), **passkeys**, and login gating with a friendly "claim your account"
  nudge.
- **Member API** (`/api/v1`) — Sanctum-token auth (`login`/`logout`/`me`), `bills` +
  `bills/summary`, and payment-proof list + multipart submit. Consumed by the mobile app.

## Local development

```bash
composer install
cp .env.example .env
php artisan key:generate

# Configure DB in .env (Supabase pgsql, or local pgsql/sqlite), then:
php artisan migrate

npm install && npm run build      # or `npm run dev` while developing
php artisan filament:assets       # publish Filament's compiled assets
php artisan storage:link          # for uploaded images / payment proofs

php artisan serve                 # http://localhost:8000
```

Create an admin to log in with:

```bash
php artisan tinker --execute="\App\Models\User::create([
    'name' => 'Admin', 'email' => 'admin@example.com',
    'password' => bcrypt('change-me'), 'is_admin' => true, 'activated_at' => now(),
]);"
```

## Testing & style

```bash
php artisan test        # full suite (SQLite in-memory)
vendor/bin/pint         # code style
```

> Tests run on SQLite while production is PostgreSQL — verify DB-specific behavior
> (string-vs-int casts, row locks) against Supabase when relevant.

## Scheduler

Recurring jobs run via a single per-minute `php artisan schedule:run` (see
[`routes/console.php`](routes/console.php)):

| Command | Cadence |
|---|---|
| `app:generate-monthly-fees` | monthly, 1st @ 00:00 |
| `app:close-lunch-orders` | every 15 min |
| `app:send-birthday-emails` | daily @ 08:00 |
| `app:send-activity-reminders` | daily @ 09:00 |

## Conventions / good to know

- **No queue worker** — notifications and emails are delivered **inline** (not `ShouldQueue`).
- **Mail is on the `log` driver** until SMTP is configured — "sent" mail lands in
  `storage/logs/laravel.log`; the in-app notification bell works today.
- **Payments are intentionally manual** (proof-upload + admin verify); no payment gateway.

## Deployment

Production runs on a **macOS host (nginx + PHP-FPM) behind a Cloudflare Tunnel**. See
[`DEPLOYMENT.md`](DEPLOYMENT.md) for the full runbook (incl. the `trustProxies`/HTTPS setup
that passkeys require).

## Docs

Per-feature design specs and implementation plans live under
[`docs/superpowers/`](docs/superpowers/) (`specs/` and `plans/`).

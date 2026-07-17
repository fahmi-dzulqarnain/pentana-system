# Notifications & Reminders

Everything in PENTANA that proactively reaches a member — what fires it, who
receives it, over which channels, and what stops it from firing twice.

## Delivery pipeline

Every notification class lives in `app/Notifications/` and extends Laravel's
`Notification`. Three channels are in play:

| Channel | Surface | When it's used |
|---|---|---|
| `database` | Filament bell in the `/account` panel (and `GET /api/v1/notifications` in the mobile app) | Every notification except `BirthdayWish` |
| `mail` | Email via the configured mailer | Every notification |
| APNs push | Native iOS push via `laravel-notification-channels/apn` | Conditional — see below |

**APNs is opt-in per environment and per member.** The
`PushesViaApn` concern (`app/Notifications/Concerns/PushesViaApn.php`) appends
the APNs channel only when **both** hold:

1. `config('broadcasting.connections.apn.key_id')` is set (the `APN_*` env
   vars; dev/test environments without them silently skip push), and
2. the member has at least one registered device token
   (`User::routeNotificationForApn()` returns the tokens from the
   `device_tokens` table; the mobile app registers them via
   `POST /api/v1/device-tokens` and revokes via
   `DELETE /api/v1/device-tokens/{token}`).

**Everything is delivered synchronously — by design.** No notification
implements `ShouldQueue` because this deployment runs no queue worker (see
each class's docblock). The APNs channel is appended *last*, so when a push
send throws, the bell and email for that member have already been delivered.

## Catalog

### Event-triggered (fire immediately on an admin/member action)

| Notification | Trigger | Audience | Channels |
|---|---|---|---|
| `LunchPublished` | Admin clicks **Publish** on a lunch (`EditLunch`) | All users | bell + mail + push |
| `ActivityPublished` | Admin publishes a draft activity (`EditActivity`) — guarded so only a genuine draft → published transition fans out | Activated users only (`activated_at` not null) | bell + mail + push |
| `ActivityCancelled` | Admin cancels a **published** activity (`EditActivity`); cancelling a draft notifies nobody | That activity's `registered` + `waitlisted` members | bell + mail + push |
| `WaitlistPromoted` | A spot frees up and `ActivityRegistrationService` promotes the oldest waitlisted registration | The promoted member | bell + mail + push |

### Scheduled (fired by console commands via the Laravel scheduler)

All schedules are defined in `routes/console.php` and run in the app timezone.
Production must run `php artisan schedule:run` from cron every minute (see
`DEPLOYMENT.md`).

| Command | Schedule | Notification | Audience | Double-send guard |
|---|---|---|---|---|
| `app:send-birthday-emails` | daily 08:00 | `BirthdayWish` (**mail only**) | Members whose birthday is today, using the active `AutoEmail` birthday template (`{name}` placeholder substituted) | `users.birthday_email_sent_at` — skipped if already stamped this year |
| `app:send-activity-reminders` | daily 09:00 | `ActivityReminder` | `registered` members of published activities starting within the next 24 h | `activities.reminder_sent_at` |
| `app:send-bill-reminders` | 15th **and** last day of month, 09:00 | `BillReminder` | Every user whose outstanding balance is > 0 (no admin exclusion — fee generation bills `User::all()`, so the audience is "who owes", not a role) | None needed — date-based; both monthly touches are intentional |
| `app:send-lunch-vote-nudges` | daily 09:00 | `LunchVoteNudge` | Users with **no** `LunchResponse` for a published lunch whose deadline is within the next 24 h. A response with `meal_option_id = null` ("not attending") counts as responded | `lunches.vote_nudge_sent_at` |

Two scheduled commands support this system without sending notifications
themselves: `app:generate-monthly-fees` (1st of month, 00:00) creates the
`FeePeriod` bills that `BillReminder` later chases, and
`app:close-lunch-orders` (every 15 min) flips past-deadline lunches from
`published` to `closed`, which removes them from the nudge query.

## Failure semantics

Every scheduled command wraps each individual send in
`try { $user->notify(...) } catch (Throwable $e) { report($e); }` — one bad
APNs device token (or any throwing channel) is logged and the run continues,
so the remaining members are still notified and the command's dedup stamp is
still written. Each command has a mutation-verified test for this
(`test_a_failed_send_does_not_abort_the_rest_of_the_run` in the respective
test file), built by binding a Mockery mock of
`Illuminate\Contracts\Notifications\Dispatcher` into the container — the
seam `User::notify()` resolves — since `Notification::fake()` can never throw.

Consequence of the stamp + catch combination: a member whose send failed is
**not retried** (the stamp wins). "No double-nudge" was deliberately chosen
over "guaranteed delivery".

## Content conventions

- Push title/body and the Filament bell reuse the same `pushTitle()` /
  `pushBody()` strings, so the two surfaces always agree.
- Bell entries carry an action button (View / Vote now / View bills) built
  with `Filament\Notifications\Notification::make()->...->getDatabaseMessage()`
  and `markAsRead()`, deep-linking into the `/account` panel
  (`MyLunch`, `MyActivities`, or the dashboard for bills).
- Money is always formatted `MYR 1,234.56`; dates use `l, j M` and deadline
  times `g:i A, D j M`, matching the rest of the panel.
- Activity notifications share the abstract `ActivityNotification` base
  (constructor takes the `Activity`; subclasses override `pushTitle()` and
  `toMail()`); the lunch/bill notifications are standalone classes with the
  same shape.

## Mobile app surfaces

Authenticated with a Sanctum token (`/api/v1`):

- `GET  /notifications` — the member's database notifications (the same rows
  the bell shows).
- `POST /notifications/read` — mark as read.
- `POST /device-tokens` / `DELETE /device-tokens/{token}` — register/revoke
  the APNs token that `routeNotificationForApn()` pushes to.

## Known gaps & accepted trade-offs

- **One-off welfare emails are not wired to a sender.** The `AutoEmail` form
  offers `type = one_off` with `send_at` and a draft/scheduled/sent status,
  but no scheduled command consumes them — only the birthday type is sent
  automatically. Scheduling a one-off currently does nothing.
- **Short-fuse lunches can miss the nudge.** The nudge runs daily at 09:00;
  a lunch published after 09:00 whose deadline is before 09:00 the next day
  is never nudged (its only eligible tick already ran). Members still get
  `LunchPublished` at publish time. If tight same-day deadlines become
  routine, run the command hourly — the `vote_nudge_sent_at` stamp already
  makes that safe.
- **`BirthdayWish` is email-only** — no bell or push, by design.
- **`LunchPublished` goes to all users** (including not-yet-activated
  accounts, who receive the email but can't act on the bell/push), whereas
  `ActivityPublished` restricts to activated users. Inconsistent but
  harmless; unify if it starts to matter.

# Birthday Display + Birthday Emails

**Status:** Approved 2026-06-23
**Builds on:** `users.birthday` (already exists, set during account claim) and the scaffolded Email Auto Sender (`AutoEmail` — currently CRUD-only, no send mechanism). Email is on the `log` driver and there is no queue worker, so all sending is inline + logged until SMTP is wired.

## Goal

Show each member their birthday on the profile page (read-only; only an admin can change it), and let the Email Auto Sender send a birthday email to members on their birthday using an admin-editable template.

## Decisions (locked)

- **Birthday email = a "Birthday" template** inside the Email Auto Sender (an `AutoEmail` of `type=birthday`): admin-editable subject + body with a `{name}` placeholder + an on/off toggle.
- **Recipients:** anyone with a birthday on file (members *and* admins) whose birthday is today.
- **Scope now:** wire **birthday emails only**. The existing one-off "scheduled" emails stay CRUD-only (sending them is a later task).
- **Birthday is read-only for members** on the profile (set during claim); only admins change it (via the Users resource).
- **Mail-only** birthday email (logged until SMTP); **inline** send (no queue worker); duplicate-guarded to once per member per year.

## Part A — Birthday on the profile + admin form

### Profile page (`app/Filament/Auth/Profile.php`)
Add `birthday` to the profile content as a **disabled** `DatePicker` with `->dehydrated(false)` (members see the value but cannot change or persist it) and helper text "Contact an admin to update your birthday." Shows the value set at claim; empty when never set. Placed in the profile form section (above or alongside name/email).

### Admin Users form (`app/Filament/Resources/Users/Schemas/UserForm.php`)
Add an editable `DatePicker::make('birthday')->native(false)->maxDate(today())` so admins can set or correct a member's birthday.

## Part B — Birthday emails

### Data model

**`auto_emails` migration** — add:
| column | detail |
|---|---|
| `type` | `string`, default `'one_off'` — values `one_off` \| `birthday` |
| `is_active` | `boolean`, default `true` — the birthday template on/off toggle |

`AutoEmail` model: add `type`, `is_active` to `$fillable`; cast `is_active` => `boolean`.

**`users` migration** — add:
| column | detail |
|---|---|
| `birthday_email_sent_at` | `timestamp`, nullable — last time a birthday email was sent to this user |

`User` model: add `birthday_email_sent_at` to `$fillable`; cast => `datetime`.

### Email Auto Sender form (`AutoEmailForm`) + table
- Add a **Type** `Select` (`one_off` => "One-off", `birthday` => "Birthday"), default `one_off`, `->live()`.
- `subject` + `body` always shown. For `birthday`, the `body` helper text notes the `{name}` placeholder (replaced with the recipient's name).
- `send_at` + `status` `->visible(fn (Get $get) => $get('type') === 'one_off')` (one-off only).
- `is_active` Toggle `->visible(fn (Get $get) => $get('type') === 'birthday')`.
- Table: add a `type` column (badge) and show `is_active` for birthday rows.

### `BirthdayWish` notification (`app/Notifications/BirthdayWish.php`)
- A plain Laravel `Notification` (**not** `ShouldQueue` — inline, per the no-worker constraint).
- `via()` => `['mail']`.
- Constructed with the resolved subject + body (already personalized). `toMail()` builds a `MailMessage` with the subject and the body lines.
- Personalization happens in the command (replace `{name}` with `$user->name`) and is passed to the notification, keeping the notification a dumb renderer.

### Command `app:send-birthday-emails` (`app/Console/Commands/SendBirthdayEmails.php`)
1. Resolve the active template: `AutoEmail::where('type', 'birthday')->where('is_active', true)->latest()->first()`. If none → log a notice and return success (nothing to send).
2. Candidates: `User::whereNotNull('birthday')->get()` then filter in PHP with Carbon `->birthday->isBirthday()` (compares month+day to today; **DB-agnostic** — avoids Postgres/SQLite date-function differences).
3. Skip any user already emailed this year: `birthday_email_sent_at !== null && birthday_email_sent_at->year === now()->year`.
4. For each remaining user: build the personalized body (`str_replace('{name}', $user->name, $template->body)`), send `BirthdayWish` (mail, inline), then set `birthday_email_sent_at = now()`.
5. `$this->info("Sent N birthday email(s).")`; return `self::SUCCESS`.
- Schedule in `routes/console.php`: `Schedule::command('app:send-birthday-emails')->dailyAt('08:00');` (prod needs a cron running `schedule:run`).

### Multiple birthday templates
Only the newest active `type=birthday` row is used. The form/table make the active one visible; admins are expected to keep a single active birthday template (not strictly enforced).

## Testing (TDD)
- **Profile:** the profile page renders a disabled `birthday` field (read-only for the member); the admin `UserForm` includes an editable `birthday`.
- **AutoEmail:** `type`/`is_active` persist; the form shows `send_at`/`status` only for `one_off` and `is_active` only for `birthday` (assert via the form schema / a Filament form test).
- **Command (`Notification::fake`):**
  - sends `BirthdayWish` to a user whose birthday is today when an active birthday template exists;
  - does NOT send to a user whose birthday is not today;
  - does NOT send when there is no active birthday template (or it's inactive);
  - does NOT re-send to a user already emailed this year; stamps `birthday_email_sent_at`;
  - the sent mail body has `{name}` replaced with the user's name (assert via the notification's data / a built `MailMessage`).

## Files
**Create:** `database/migrations/*_add_type_and_active_to_auto_emails.php`, `database/migrations/*_add_birthday_email_sent_at_to_users.php`, `app/Notifications/BirthdayWish.php`, `app/Console/Commands/SendBirthdayEmails.php`, tests under `tests/Feature/Birthday/`.
**Modify:** `app/Models/AutoEmail.php`, `app/Models/User.php`, `app/Filament/Resources/AutoEmails/Schemas/AutoEmailForm.php`, `app/Filament/Resources/AutoEmails/Tables/AutoEmailsTable.php`, `app/Filament/Auth/Profile.php`, `app/Filament/Resources/Users/Schemas/UserForm.php`, `routes/console.php`.

## Out of scope (later)
Real SMTP delivery; an in-app (bell) birthday notification in addition to email; sending the one-off scheduled emails; Feb-29 birthday handling in non-leap years; enforcing a single birthday template. Migrations are additive — apply to Supabase during finalize.

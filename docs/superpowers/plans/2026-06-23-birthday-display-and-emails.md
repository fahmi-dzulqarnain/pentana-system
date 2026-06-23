# Birthday Display + Birthday Emails Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Show each member their birthday on the profile (read-only; admin-editable), and send a birthday email to anyone whose birthday is today via an admin-editable "Birthday" template in the Email Auto Sender.

**Architecture:** Birthday already exists on `users` (set at claim). Profile shows it disabled; admins edit it on the Users form. `AutoEmail` gains a `type` (`one_off`/`birthday`) + `is_active`; a daily `app:send-birthday-emails` command sends the active birthday template (with `{name}` personalization) to today's birthdays, inline + logged (no worker / SMTP yet), guarded to once-per-year via `users.birthday_email_sent_at`.

**Tech Stack:** Laravel 12, Filament 5.6, PHP 8.5, PostgreSQL (Supabase) / sqlite tests, PHPUnit, Pint.

**Spec:** `docs/superpowers/specs/2026-06-23-birthday-display-and-emails-design.md`

**Conventions:** PHPUnit under `tests/Feature/Birthday/`, `use RefreshDatabase`, `Tests\TestCase`. Panel/Livewire tests: `actingAs` + `Filament::setCurrentPanel(Filament::getPanel('account'))`. Run one: `php artisan test --filter <Class>`. Lint: `vendor/bin/pint --dirty`. `User::factory()` (+`->admin()`); `MemberCategory::create([...])`. Work on `main`.

---

## Task 1: Schema + models

**Files:**
- Create: `database/migrations/<ts>_add_type_and_is_active_to_auto_emails.php`
- Create: `database/migrations/<ts>_add_birthday_email_sent_at_to_users.php`
- Modify: `app/Models/AutoEmail.php`, `app/Models/User.php`
- Test: `tests/Feature/Birthday/BirthdaySchemaTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Birthday;

use App\Models\AutoEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BirthdaySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_email_has_type_and_active_flag(): void
    {
        $email = AutoEmail::create([
            'subject' => 'Happy Birthday!',
            'body' => 'Hi {name}, happy birthday!',
            'type' => 'birthday',
            'is_active' => true,
        ]);

        $email->refresh();
        $this->assertSame('birthday', $email->type);
        $this->assertTrue($email->is_active);
    }

    public function test_auto_email_defaults_to_one_off(): void
    {
        $email = AutoEmail::create(['subject' => 'Notice', 'body' => 'Hello']);
        $this->assertSame('one_off', $email->fresh()->type);
    }

    public function test_user_tracks_birthday_email_sent_at(): void
    {
        $user = User::factory()->create(['birthday_email_sent_at' => now()]);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $user->fresh()->birthday_email_sent_at);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (`php artisan test --filter BirthdaySchemaTest`): unknown columns `type`/`is_active`/`birthday_email_sent_at`.

- [ ] **Step 3: Migrations**

`php artisan make:migration add_type_and_is_active_to_auto_emails` →

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_emails', function (Blueprint $table) {
            $table->string('type')->default('one_off')->after('id'); // one_off | birthday
            $table->boolean('is_active')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('auto_emails', function (Blueprint $table) {
            $table->dropColumn(['type', 'is_active']);
        });
    }
};
```

`php artisan make:migration add_birthday_email_sent_at_to_users` →

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('birthday_email_sent_at')->nullable()->after('birthday');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('birthday_email_sent_at');
        });
    }
};
```

- [ ] **Step 4: Models**

`app/Models/AutoEmail.php` — `$fillable` add `'type'`, `'is_active'`; `$casts` add `'is_active' => 'boolean'`:

```php
protected $fillable = ['subject', 'body', 'send_at', 'status', 'type', 'is_active'];

protected $casts = [
    'send_at' => 'datetime',
    'is_active' => 'boolean',
];
```

`app/Models/User.php` — add `'birthday_email_sent_at'` to `$fillable`; in `casts()` add `'birthday_email_sent_at' => 'datetime'`.

- [ ] **Step 5: Run it — expect PASS (3 tests).**
- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations app/Models/AutoEmail.php app/Models/User.php tests/Feature/Birthday/BirthdaySchemaTest.php
git commit -m "feat(birthday): auto_email type/is_active + users.birthday_email_sent_at"
```

---

## Task 2: Birthday on the profile + admin Users form

**Files:**
- Modify: `app/Filament/Auth/Profile.php`
- Modify: `app/Filament/Resources/Users/Schemas/UserForm.php`
- Test: `tests/Feature/Birthday/BirthdayProfileTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Birthday;

use App\Filament\Auth\Profile;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BirthdayProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_shows_birthday_read_only(): void
    {
        $user = User::factory()->create(['birthday' => '2000-05-01', 'activated_at' => now()]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(Profile::class)
            ->assertSuccessful()
            ->assertFormFieldExists('birthday')
            ->assertFormFieldIsDisabled('birthday');
    }

    public function test_admin_user_form_has_editable_birthday(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(CreateUser::class)
            ->assertFormFieldExists('birthday')
            ->assertFormFieldIsEnabled('birthday');
    }
}
```

> If `assertFormFieldExists`/`assertFormFieldIsDisabled` resolve against a *named* form on EditProfile in v5.6, pass the form name (e.g. `assertFormFieldExists('birthday', 'form')`) — confirm against the installed Filament testing macros and adjust.

- [ ] **Step 2: Run it — expect FAIL** (`--filter BirthdayProfileTest`): no `birthday` field.

- [ ] **Step 3: Profile — override `form()` to add a disabled birthday**

In `app/Filament/Auth/Profile.php`, add `use Filament\Forms\Components\DatePicker;` and override `form()` (the parent composes name/email/password/passwordConfirmation/currentPassword):

```php
public function form(Schema $schema): Schema
{
    return $schema
        ->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            DatePicker::make('birthday')
                ->native(false)
                ->disabled()
                ->dehydrated(false)
                ->helperText('Contact an admin to update your birthday.'),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
}
```

(EditProfile fills the form from the authenticated user, so the disabled field shows the stored birthday; `dehydrated(false)` keeps it out of the save payload.)

- [ ] **Step 4: Admin Users form — editable birthday**

In `app/Filament/Resources/Users/Schemas/UserForm.php`, add `use Filament\Forms\Components\DatePicker;` and insert into the `->components([...])` array (e.g. after `email`):

```php
DatePicker::make('birthday')
    ->native(false)
    ->maxDate(today())
    ->helperText('Set or correct the member\'s date of birth.'),
```

- [ ] **Step 5: Run it — expect PASS (2 tests).**
- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Auth/Profile.php app/Filament/Resources/Users/Schemas/UserForm.php tests/Feature/Birthday/BirthdayProfileTest.php
git commit -m "feat(birthday): read-only birthday on profile + editable on admin Users form"
```

---

## Task 3: Email Auto Sender — Birthday type

**Files:**
- Modify: `app/Filament/Resources/AutoEmails/Schemas/AutoEmailForm.php`
- Modify: `app/Filament/Resources/AutoEmails/Tables/AutoEmailsTable.php`
- Test: `tests/Feature/Birthday/AutoEmailTypeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Birthday;

use App\Filament\Resources\AutoEmails\Pages\CreateAutoEmail;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AutoEmailTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_birthday_type_shows_active_toggle_and_hides_schedule(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(CreateAutoEmail::class)
            ->assertFormFieldExists('type')
            ->set('data.type', 'birthday')
            ->assertFormFieldExists('is_active')
            ->assertFormFieldIsHidden('send_at')
            ->assertFormFieldIsHidden('status');
    }

    public function test_one_off_type_shows_schedule_and_hides_active(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(CreateAutoEmail::class)
            ->set('data.type', 'one_off')
            ->assertFormFieldExists('send_at')
            ->assertFormFieldIsHidden('is_active');
    }
}
```

> Confirm the `assertFormFieldIsHidden` macro name in v5.6 (vs `assertFormFieldDoesNotExist`); a hidden-by-`visible()` field is typically asserted with `assertFormFieldIsHidden`. Adjust if the installed macro differs.

- [ ] **Step 2: Run it — expect FAIL** (`--filter AutoEmailTypeTest`).

- [ ] **Step 3: Form**

Rewrite `AutoEmailForm::configure` to add the `type` select + conditional fields (add `use Filament\Forms\Components\Toggle;` and `use Filament\Schemas\Components\Utilities\Get;`):

```php
return $schema
    ->components([
        Select::make('type')
            ->options(['one_off' => 'One-off', 'birthday' => 'Birthday'])
            ->default('one_off')
            ->required()
            ->live(),
        TextInput::make('subject')->required()->maxLength(255),
        Textarea::make('body')
            ->required()
            ->rows(6)
            ->columnSpanFull()
            ->helperText(fn (Get $get): ?string => $get('type') === 'birthday'
                ? 'Use {name} where the member\'s name should appear.'
                : null),
        DateTimePicker::make('send_at')
            ->label('Send at')
            ->helperText('Leave blank to keep as a draft.')
            ->native(false)
            ->seconds(false)
            ->visible(fn (Get $get): bool => $get('type') === 'one_off'),
        Select::make('status')
            ->options(['draft' => 'Draft', 'scheduled' => 'Scheduled', 'sent' => 'Sent'])
            ->default('draft')
            ->required()
            ->visible(fn (Get $get): bool => $get('type') === 'one_off'),
        Toggle::make('is_active')
            ->label('Active')
            ->default(true)
            ->helperText('When on, this birthday email is sent to members on their birthday.')
            ->visible(fn (Get $get): bool => $get('type') === 'birthday'),
    ]);
```

- [ ] **Step 4: Table — show the type**

In `AutoEmailsTable::configure`, add a `type` column at the top of `->columns([...])`:

```php
TextColumn::make('type')
    ->badge()
    ->formatStateUsing(fn (string $state): string => $state === 'birthday' ? 'Birthday' : 'One-off')
    ->color(fn (string $state): string => $state === 'birthday' ? 'info' : 'gray'),
```

- [ ] **Step 5: Run it — expect PASS (2 tests).**
- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/AutoEmails tests/Feature/Birthday/AutoEmailTypeTest.php
git commit -m "feat(birthday): Birthday email type in the Email Auto Sender"
```

---

## Task 4: BirthdayWish notification + daily command

**Files:**
- Create: `app/Notifications/BirthdayWish.php`
- Create: `app/Console/Commands/SendBirthdayEmails.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Birthday/SendBirthdayEmailsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Birthday;

use App\Models\AutoEmail;
use App\Models\User;
use App\Notifications\BirthdayWish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendBirthdayEmailsTest extends TestCase
{
    use RefreshDatabase;

    private function birthdayTemplate(bool $active = true): AutoEmail
    {
        return AutoEmail::create([
            'type' => 'birthday',
            'is_active' => $active,
            'subject' => 'Happy Birthday!',
            'body' => 'Hi {name}, happy birthday from PENTANA!',
        ]);
    }

    public function test_it_emails_users_whose_birthday_is_today_with_personalized_body(): void
    {
        Notification::fake();
        $this->birthdayTemplate();
        $user = User::factory()->create(['name' => 'Aisyah', 'birthday' => now()->subYears(30)->toDateString()]);

        $this->artisan('app:send-birthday-emails')->assertSuccessful();

        Notification::assertSentTo($user, BirthdayWish::class, function (BirthdayWish $n) {
            return str_contains($n->body, 'Aisyah') && ! str_contains($n->body, '{name}');
        });
        $this->assertNotNull($user->fresh()->birthday_email_sent_at);
    }

    public function test_it_skips_users_whose_birthday_is_not_today(): void
    {
        Notification::fake();
        $this->birthdayTemplate();
        $user = User::factory()->create(['birthday' => now()->addDay()->subYears(30)->toDateString()]);

        $this->artisan('app:send-birthday-emails')->assertSuccessful();

        Notification::assertNotSentTo($user, BirthdayWish::class);
    }

    public function test_it_does_nothing_without_an_active_template(): void
    {
        Notification::fake();
        $this->birthdayTemplate(active: false);
        $user = User::factory()->create(['birthday' => now()->subYears(20)->toDateString()]);

        $this->artisan('app:send-birthday-emails')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_it_does_not_resend_within_the_same_year(): void
    {
        Notification::fake();
        $this->birthdayTemplate();
        $user = User::factory()->create([
            'birthday' => now()->subYears(25)->toDateString(),
            'birthday_email_sent_at' => now(),
        ]);

        $this->artisan('app:send-birthday-emails')->assertSuccessful();

        Notification::assertNotSentTo($user, BirthdayWish::class);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (`--filter SendBirthdayEmailsTest`): notification/command missing.

- [ ] **Step 3: Notification**

`app/Notifications/BirthdayWish.php` (plain — NOT `ShouldQueue`; sent inline because there is no queue worker):

```php
<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BirthdayWish extends Notification
{
    public function __construct(public string $subject, public string $body) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->subject)->line($this->body);
    }
}
```

- [ ] **Step 4: Command**

`app/Console/Commands/SendBirthdayEmails.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\AutoEmail;
use App\Models\User;
use App\Notifications\BirthdayWish;
use Illuminate\Console\Command;

class SendBirthdayEmails extends Command
{
    protected $signature = 'app:send-birthday-emails';

    protected $description = 'Email the active birthday template to members whose birthday is today';

    public function handle(): int
    {
        $template = AutoEmail::where('type', 'birthday')->where('is_active', true)->latest()->first();

        if (! $template) {
            $this->info('No active birthday email template; nothing sent.');

            return self::SUCCESS;
        }

        $sent = User::whereNotNull('birthday')->get()
            ->filter(fn (User $user) => $user->birthday->isBirthday())
            ->reject(fn (User $user) => $user->birthday_email_sent_at?->year === now()->year)
            ->each(function (User $user) use ($template) {
                $body = str_replace('{name}', $user->name, $template->body);
                $user->notify(new BirthdayWish($template->subject, $body));
                $user->forceFill(['birthday_email_sent_at' => now()])->save();
            })
            ->count();

        $this->info("Sent {$sent} birthday email(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Schedule it** — in `routes/console.php`, after the existing `Schedule::command(...)` lines:

```php
Schedule::command('app:send-birthday-emails')->dailyAt('08:00');
```

- [ ] **Step 6: Run it — expect PASS (4 tests).**
- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/Notifications/BirthdayWish.php app/Console/Commands/SendBirthdayEmails.php routes/console.php tests/Feature/Birthday/SendBirthdayEmailsTest.php
git commit -m "feat(birthday): daily birthday-email command + BirthdayWish notification"
```

---

## Task 5: Verify, migrate Supabase, finalize

- [ ] **Step 1:** `php artisan test` — full suite green.
- [ ] **Step 2:** `vendor/bin/pint --dirty` (re-run tests if it changes anything).
- [ ] **Step 3: Migrate Supabase** — `php artisan migrate:status` to confirm only the two new additive migrations are pending, then `php artisan migrate --force` (adds `auto_emails.type`/`is_active`, `users.birthday_email_sent_at`).
- [ ] **Step 4: Smoke (localhost):** as admin, create a Birthday email in the Email Auto Sender (type=Birthday, body with `{name}`, Active on) → confirm the form shows the Active toggle and hides send_at/status; open a member's profile → birthday shows read-only; on Users, edit a member's birthday. Set a test user's birthday to today, run `php artisan app:send-birthday-emails`, and confirm the rendered email in `storage/logs/laravel.log` has the name substituted.
- [ ] **Step 5:** Use superpowers:finishing-a-development-branch (on `main`; verify green, confirm leaving commits on `main`).

---

## Self-Review (done during planning)

- **Spec coverage:** schema/models (T1) · profile read-only + admin-editable birthday (T2) · Birthday email type + conditional form/table (T3) · BirthdayWish + daily command incl. today-match, no-template, once-per-year, `{name}` (T4) · verify + Supabase + smoke (T5). All spec sections map to a task.
- **Type/name consistency:** `AutoEmail` `type`/`is_active`; `User` `birthday_email_sent_at`; `BirthdayWish(subject, body)`; `app:send-birthday-emails` used consistently across tasks/tests.
- **Known confirmations (flagged, not hidden):** the Filament v5.6 form-test macros (`assertFormFieldIsDisabled`/`IsHidden`/`IsEnabled`) and whether EditProfile's form is named are confirmed against the installed package in T2/T3 and the assertions adjusted if needed. Birthday matching is DB-agnostic via Carbon `isBirthday()` (no Postgres/SQLite date-function divergence). Email is mail-only + inline + logged until SMTP.

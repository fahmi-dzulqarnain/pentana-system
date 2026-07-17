# Smart Reminders & Nudges Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Twice-monthly outstanding-bill reminders and a 24h-before-deadline lunch-voting nudge, delivered via mail + Filament bell + APNs push.

**Architecture:** Two new scheduled console commands following the exact `SendActivityReminders` pattern (inline notification fan-out, `sent_at` stamp guard where needed). Notifications use the `PushesViaApn` concern with `['database', 'mail']` base channels. A small refactor extracts the outstanding-balance math from `MyBillsStats` into a shared `FinanceService::outstanding()` helper.

**Tech Stack:** Laravel 12, Filament 5, laravel-notification-channels/apn, PHPUnit (SQLite in-memory).

**Spec:** `docs/superpowers/specs/2026-07-16-smart-reminders-design.md`

**Conventions that apply to every task:**
- Notifications are synchronous (NO `ShouldQueue`) — this deployment has no queue worker.
- Run tests with `php artisan test --filter=<TestClass>`.
- Run `vendor/bin/pint --dirty` before each commit.

---

### Task 1: `FinanceService::outstanding()` shared helper

Extract the outstanding-balance math currently duplicated in `MyBillsStats::getOutstanding()` so the widget and the new reminder command share one definition. Pure function over a bills collection loaded via `FeePeriod::forMember()` (which pre-sums `allocations_sum_amount_allocated`).

**Files:**
- Modify: `app/Services/FinanceService.php`
- Modify: `app/Filament/Widgets/MyBillsStats.php:23-28`
- Test: `tests/Feature/FinanceServiceTest.php` (append)

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/FinanceServiceTest.php` (the class already has `bill()` and `pendingProof()` helpers and imports `FeePeriod`, `PaymentProof`, `User`, `FinanceService`):

```php
public function test_outstanding_sums_unpaid_remainders_and_clamps_overpaid_bills(): void
{
    $user = User::factory()->create();
    $this->bill($user, '2026-01-01', 100);
    $partial = $this->bill($user, '2026-02-01', 50);

    // Verify a 30 payment; allocation lands on the oldest bill (2026-01).
    $proof = $this->pendingProof($user, 30);
    $this->service()->verifyAndAllocate($proof, 30.0);

    $bills = FeePeriod::forMember($user->id)->get();

    // 100 - 30 = 70 outstanding on the first bill, 50 untouched on the second.
    $this->assertSame(120.0, FinanceService::outstanding($bills));
}

public function test_outstanding_is_zero_for_fully_paid_bills(): void
{
    $user = User::factory()->create();
    $this->bill($user, '2026-01-01', 100);

    $proof = $this->pendingProof($user, 100);
    $this->service()->verifyAndAllocate($proof, 100.0);

    $this->assertSame(0.0, FinanceService::outstanding(FeePeriod::forMember($user->id)->get()));
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=FinanceServiceTest`
Expected: FAIL — `Call to undefined method App\Services\FinanceService::outstanding()`

- [ ] **Step 3: Add the helper to FinanceService**

In `app/Services/FinanceService.php`, add to the imports:

```php
use Illuminate\Support\Collection;
```

and add this method to the class (below `applyAvailableFunds`):

```php
/**
 * Sum of unpaid remainders across a member's bills, as loaded by
 * FeePeriod::forMember() (relies on allocations_sum_amount_allocated).
 * An over-paid bill clamps to zero rather than offsetting other bills.
 */
public static function outstanding(Collection $bills): float
{
    return round($bills->sum(
        fn (FeePeriod $bill) => max(0, (float) $bill->amount_due - (float) $bill->allocations_sum_amount_allocated)
    ), 2);
}
```

- [ ] **Step 4: Delegate from the widget**

In `app/Filament/Widgets/MyBillsStats.php`, add the import:

```php
use App\Services\FinanceService;
```

and replace the body of `getOutstanding()` (lines 23-28) with:

```php
public function getOutstanding(): float
{
    return FinanceService::outstanding($this->bills());
}
```

- [ ] **Step 5: Run the affected tests**

Run: `php artisan test --filter="FinanceServiceTest|MyBillsStatsTest"`
Expected: PASS (both new tests and all pre-existing widget/service tests)

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/FinanceService.php app/Filament/Widgets/MyBillsStats.php tests/Feature/FinanceServiceTest.php
git commit -m "refactor(finance): shared FinanceService::outstanding() helper"
```

---

### Task 2: Bill reminders — notification + command + schedule

Twice a month (15th and last day, 09:00), every user with an outstanding balance gets one `BillReminder` via mail + bell + push. No sent-stamp: the schedule is date-based and each of the two monthly touches is intentional. Note fee generation covers `User::all()`, so the command keys off "who owes", not off an is_admin flag.

**Files:**
- Create: `app/Notifications/BillReminder.php`
- Create: `app/Console/Commands/SendBillReminders.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/SendBillRemindersTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SendBillRemindersTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Payment\FeePeriod;
use App\Models\User;
use App\Notifications\BillReminder;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendBillRemindersTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithBill(float $due): User
    {
        $user = User::factory()->create();
        FeePeriod::create([
            'user_id' => $user->id,
            'month_date' => now()->startOfMonth()->toDateString(),
            'amount_due' => $due,
            'status' => 'unpaid',
        ]);

        return $user;
    }

    public function test_reminds_members_with_outstanding_bills(): void
    {
        Notification::fake();
        $owing = $this->memberWithBill(50);
        $noBills = User::factory()->create();

        $this->artisan('app:send-bill-reminders')->assertSuccessful();

        Notification::assertSentTo($owing, BillReminder::class, function (BillReminder $notification) {
            return $notification->outstanding === 50.0 && $notification->unpaidCount === 1;
        });
        Notification::assertNotSentTo($noBills, BillReminder::class);
    }

    public function test_does_not_remind_settled_members(): void
    {
        Notification::fake();
        $user = $this->memberWithBill(80);
        $period = FeePeriod::where('user_id', $user->id)->first();

        $proof = \App\Models\Payment\PaymentProof::create([
            'user_id' => $user->id,
            'proof_image_path' => 'receipts/test.jpg',
            'amount_claimed' => 80,
            'status' => 'pending',
        ]);
        app(FinanceService::class)->verifyAndAllocate($proof, 80.0);

        $this->artisan('app:send-bill-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_partial_payment_reminds_with_the_remainder(): void
    {
        Notification::fake();
        $user = $this->memberWithBill(100);

        $proof = \App\Models\Payment\PaymentProof::create([
            'user_id' => $user->id,
            'proof_image_path' => 'receipts/test.jpg',
            'amount_claimed' => 40,
            'status' => 'pending',
        ]);
        app(FinanceService::class)->verifyAndAllocate($proof, 40.0);

        $this->artisan('app:send-bill-reminders')->assertSuccessful();

        Notification::assertSentTo($user, BillReminder::class, function (BillReminder $notification) {
            return $notification->outstanding === 60.0 && $notification->unpaidCount === 1;
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SendBillRemindersTest`
Expected: FAIL — `Class "App\Notifications\BillReminder" not found` (or command not found)

- [ ] **Step 3: Create the notification**

Create `app/Notifications/BillReminder.php`:

```php
<?php

namespace App\Notifications;

use App\Notifications\Concerns\PushesViaApn;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Pages\Dashboard;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnMessage;

/**
 * Twice-monthly nudge for members with an outstanding balance. Delivered INLINE
 * (not ShouldQueue): this deployment has no queue worker, so the bell + logged
 * email fire synchronously when the scheduled command runs.
 */
class BillReminder extends Notification
{
    use PushesViaApn;

    public function __construct(public float $outstanding, public int $unpaidCount) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->withApn($notifiable, ['database', 'mail']);
    }

    public function toApn(object $notifiable): ApnMessage
    {
        return ApnMessage::create()->title($this->pushTitle())->body($this->pushBody());
    }

    private function pushTitle(): string
    {
        return 'Outstanding dues reminder';
    }

    private function pushBody(): string
    {
        return 'MYR '.number_format($this->outstanding, 2)
            .' outstanding across '.$this->unpaidCount.' bill(s).';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reminder: outstanding dues')
            ->greeting('Friendly reminder')
            ->line('You have MYR '.number_format($this->outstanding, 2)
                .' outstanding across '.$this->unpaidCount.' bill(s).')
            ->line('Once you have transferred, upload a payment proof and an admin will verify it.')
            ->action('View my bills', $this->url());
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->pushTitle())
            ->body($this->pushBody())
            ->icon('heroicon-o-banknotes')
            ->warning()
            ->actions([
                Action::make('view')->label('View bills')->url($this->url())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    private function url(): string
    {
        return Dashboard::getUrl(panel: 'account');
    }
}
```

- [ ] **Step 4: Create the command**

Create `app/Console/Commands/SendBillReminders.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Payment\FeePeriod;
use App\Models\User;
use App\Notifications\BillReminder;
use App\Services\FinanceService;
use Illuminate\Console\Command;
use Throwable;

class SendBillReminders extends Command
{
    protected $signature = 'app:send-bill-reminders';

    protected $description = 'Remind members with an outstanding balance about their unpaid bills';

    public function handle(): int
    {
        $userIds = FeePeriod::where('status', '!=', 'paid')->distinct()->pluck('user_id');

        $sent = 0;
        foreach (User::whereIn('id', $userIds)->get() as $user) {
            $bills = FeePeriod::forMember($user->id)->get();
            $outstanding = FinanceService::outstanding($bills);

            if ($outstanding <= 0) {
                continue;
            }

            try {
                $user->notify(new BillReminder($outstanding, $bills->where('status', '!=', 'paid')->count()));
                $sent++;
            } catch (Throwable $e) {
                report($e); // one bad APNs token must not abort the whole run
            }
        }

        $this->info("Sent bill reminders to {$sent} member(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=SendBillRemindersTest`
Expected: PASS (3 tests)

- [ ] **Step 6: Schedule it**

In `routes/console.php`, after the existing `Schedule::` lines, add:

```php
Schedule::command('app:send-bill-reminders')->monthlyOn(15, '09:00');
Schedule::command('app:send-bill-reminders')->lastDayOfMonth('09:00');
```

Verify registration: `php artisan schedule:list` — expect `app:send-bill-reminders` listed twice.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/Notifications/BillReminder.php app/Console/Commands/SendBillReminders.php routes/console.php tests/Feature/SendBillRemindersTest.php
git commit -m "feat: twice-monthly outstanding-bill reminders (mail + bell + push)"
```

---

### Task 3: Lunch-voting nudge — migration + notification + command + schedule

Daily at 09:00, find published lunches whose deadline is within the next 24 hours that haven't been nudged, and notify every user without a `LunchResponse` for that lunch. Stamp `vote_nudge_sent_at` (same guard as `SendActivityReminders`).

**Files:**
- Create: `database/migrations/2026_07_16_000001_add_vote_nudge_sent_at_to_lunches.php`
- Modify: `app/Models/Lunch.php:16-18`
- Create: `app/Notifications/LunchVoteNudge.php`
- Create: `app/Console/Commands/SendLunchVoteNudges.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Lunch/SendLunchVoteNudgesTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Lunch/SendLunchVoteNudgesTest.php`:

```php
<?php

namespace Tests\Feature\Lunch;

use App\Models\Lunch;
use App\Models\LunchResponse;
use App\Models\User;
use App\Notifications\LunchVoteNudge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendLunchVoteNudgesTest extends TestCase
{
    use RefreshDatabase;

    public function test_nudges_non_responders_of_lunches_closing_within_a_day(): void
    {
        Notification::fake();
        $lunch = Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addHours(12)]);

        $responder = User::factory()->create();
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => $responder->id, 'meal_option_id' => null]);
        $nonResponder = User::factory()->create();

        $this->artisan('app:send-lunch-vote-nudges')->assertSuccessful();

        Notification::assertSentTo($nonResponder, LunchVoteNudge::class);
        Notification::assertNotSentTo($responder, LunchVoteNudge::class);
        $this->assertNotNull($lunch->fresh()->vote_nudge_sent_at);
    }

    public function test_does_not_nudge_twice(): void
    {
        Notification::fake();
        Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addHours(12)]);
        User::factory()->create();

        $this->artisan('app:send-lunch-vote-nudges')->assertSuccessful();
        $this->artisan('app:send-lunch-vote-nudges')->assertSuccessful();

        Notification::assertSentTimes(LunchVoteNudge::class, 1);
    }

    public function test_ignores_draft_closed_far_future_and_past_deadline_lunches(): void
    {
        Notification::fake();
        User::factory()->create();
        Lunch::factory()->create(['status' => 'draft', 'deadline' => now()->addHours(6)]);
        Lunch::factory()->create(['status' => 'closed', 'deadline' => now()->addHours(6)]);
        Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addWeek()]);
        Lunch::factory()->create(['status' => 'published', 'deadline' => now()->subHour()]);

        $this->artisan('app:send-lunch-vote-nudges')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SendLunchVoteNudgesTest`
Expected: FAIL — command/class not found (or unknown column `vote_nudge_sent_at`)

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_16_000001_add_vote_nudge_sent_at_to_lunches.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lunches', function (Blueprint $table) {
            $table->timestamp('vote_nudge_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lunches', function (Blueprint $table) {
            $table->dropColumn('vote_nudge_sent_at');
        });
    }
};
```

- [ ] **Step 4: Register the column on the model**

In `app/Models/Lunch.php`, update the two property lines (16-18):

```php
protected $fillable = ['date', 'caterer_id', 'caterer_meal_id', 'deadline', 'status', 'vote_nudge_sent_at'];

protected $casts = ['date' => 'date', 'deadline' => 'datetime', 'vote_nudge_sent_at' => 'datetime'];
```

- [ ] **Step 5: Create the notification**

Create `app/Notifications/LunchVoteNudge.php` (mirrors `LunchPublished`'s structure and wording):

```php
<?php

namespace App\Notifications;

use App\Filament\Pages\MyLunch;
use App\Models\Lunch;
use App\Notifications\Concerns\PushesViaApn;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnMessage;

/**
 * Last-call nudge for members who haven't voted on a lunch closing within a day.
 * Delivered INLINE (not ShouldQueue): this deployment has no queue worker.
 */
class LunchVoteNudge extends Notification
{
    use PushesViaApn;

    public function __construct(public Lunch $lunch) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->withApn($notifiable, ['database', 'mail']);
    }

    public function toApn(object $notifiable): ApnMessage
    {
        return ApnMessage::create()->title($this->pushTitle())->body($this->pushBody());
    }

    private function pushTitle(): string
    {
        return 'Last call: '.($this->lunch->catererMeal?->name ?? 'lunch menu');
    }

    private function pushBody(): string
    {
        return 'Voting closes at '.$this->lunch->deadline->format('g:i A, D j M').' and you haven\'t responded.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Last call to order lunch for '.$this->lunch->date->format('D, j M'))
            ->greeting('Voting closes soon')
            ->line('You haven\'t responded to the lunch for '.$this->lunch->date->format('l, j M').'.')
            ->line('Voting closes at '.$this->lunch->deadline->format('g:i A, D j M').'.')
            ->action('Place your order', $this->url());
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->pushTitle())
            ->body($this->pushBody())
            ->icon('heroicon-o-clock')
            ->warning()
            ->actions([
                Action::make('view')->label('Vote now')->url($this->url())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    private function url(): string
    {
        return MyLunch::getUrl(panel: 'account');
    }
}
```

- [ ] **Step 6: Create the command**

Create `app/Console/Commands/SendLunchVoteNudges.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Lunch;
use App\Models\User;
use App\Notifications\LunchVoteNudge;
use Illuminate\Console\Command;
use Throwable;

class SendLunchVoteNudges extends Command
{
    protected $signature = 'app:send-lunch-vote-nudges';

    protected $description = 'Nudge members who have not voted on published lunches closing within a day';

    public function handle(): int
    {
        $lunches = Lunch::where('status', 'published')
            ->whereNull('vote_nudge_sent_at')
            ->whereBetween('deadline', [now(), now()->addDay()])
            ->get();

        foreach ($lunches as $lunch) {
            $nonResponders = User::whereNotIn('id', $lunch->responses()->pluck('user_id'))->get();

            foreach ($nonResponders as $user) {
                try {
                    $user->notify(new LunchVoteNudge($lunch));
                } catch (Throwable $e) {
                    report($e); // one bad APNs token must not abort the whole run
                }
            }

            // Stamp so a re-run the same day doesn't double-nudge (same guard as activity reminders).
            $lunch->forceFill(['vote_nudge_sent_at' => now()])->save();
        }

        $this->info("Sent vote nudges for {$lunches->count()} lunch(es).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=SendLunchVoteNudgesTest`
Expected: PASS (3 tests)

- [ ] **Step 8: Schedule it**

In `routes/console.php`, add after the bill-reminder lines:

```php
Schedule::command('app:send-lunch-vote-nudges')->dailyAt('09:00');
```

Verify: `php artisan schedule:list` — expect `app:send-lunch-vote-nudges` daily at 09:00.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_07_16_000001_add_vote_nudge_sent_at_to_lunches.php app/Models/Lunch.php app/Notifications/LunchVoteNudge.php app/Console/Commands/SendLunchVoteNudges.php routes/console.php tests/Feature/Lunch/SendLunchVoteNudgesTest.php
git commit -m "feat: lunch-voting nudge 24h before deadline for non-responders"
```

---

### Task 4: Full-suite verification

**Files:** none (verification only)

- [ ] **Step 1: Run the whole test suite**

Run: `php artisan test`
Expected: PASS — no pre-existing test broken by the widget refactor or new columns.

- [ ] **Step 2: Lint everything touched**

Run: `vendor/bin/pint --dirty`
Expected: no changes (already clean from per-task runs); if it reformats anything, amend the relevant commit or make a `style:` commit.

- [ ] **Step 3: Smoke-check the scheduler**

Run: `php artisan schedule:list`
Expected output includes:

```
0 9 15 * *        php artisan app:send-bill-reminders
0 9 28-31 * *     php artisan app:send-bill-reminders   (lastDayOfMonth renders as a truthTest/cron variant)
0 9 * * *         php artisan app:send-lunch-vote-nudges
```

(The exact cron rendering of `lastDayOfMonth` differs by Laravel version — what matters is the command appears twice and once respectively.)

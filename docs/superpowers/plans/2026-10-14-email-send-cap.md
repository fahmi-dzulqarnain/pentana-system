# Email Send Cap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Never lose an email to Resend's rate limit — cap sends at 99/day and 3,000/month, spool the overflow, and drain it on the scheduler in priority order.

**Architecture:** A `BudgetedTransport` decorator wraps the real mail transport so every sender is covered without knowing the cap exists. It claims a slot from `EmailBudget` before delegating; without a slot the message is spooled to `queued_emails` as raw MIME. An `app:drain-email-queue` command runs every minute, sending what fits. An `is_admin`-gated Filament widget shows usage and warns before limits are hit.

**Tech Stack:** Laravel 12, Filament 5, PHP 8.4, Symfony Mailer, PostgreSQL (production) / SQLite (tests), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-14-email-send-cap-design.md`

## Global Constraints

- Daily limit is **99** (not 100) — one spare against miscounts or manual sends from the Resend dashboard.
- Monthly limit is **3,000**.
- Day boundary is **UTC** — `Carbon::now('UTC')->toDateString()`, never `today()`.
- Max send attempts is **3**, then `failed_at` is stamped and the message is never retried.
- Drain sends at most **25** messages per run.
- Default priority is **50**; lower sends first.
- Notifications must stay **inline** (not `ShouldQueue`) — this deployment has no queue worker.
- The `database` and APNs channels must be unaffected — only mail is capped.
- Admin gating uses the existing `is_admin` boolean. Do **not** introduce a `super_admin` role.
- Tests use `RefreshDatabase`; the suite is 245 tests and must stay green.

## Review Focus

Input classes the spec implies but which no task's happy path exercises. Each has a test assigned to the task that owns the code.

1. **A message that has exhausted its attempts must not block the queue head.** The drain orders by priority then age; a failed message left in that ordering would be re-selected forever and starve everything behind it. (Task 4)
2. **A send that throws must not consume a daily slot.** Resend did not accept the message, so the counter must be released or the cap silently shrinks every time Resend has a bad minute. (Task 3)
3. **One message counts as one send, regardless of recipient count.** The counter must increment per message, not per address, or a multi-recipient mail miscounts the budget. (Task 1)
4. **An empty queue must be a no-op.** The drain runs every minute, and 1,439 of 1,440 daily runs have nothing to do; it must not error, warn, or claim a slot. (Task 4)
5. **A queued message must be byte-identical when it finally sends.** The whole point of storing MIME rather than re-rendering is that a bill reminder queued today says what it said today. (Task 2)

---

### Task 1: `email_budget` table and `EmailBudget::claim()`

**Files:**
- Create: `database/migrations/2026_10_14_000001_create_email_budget_table.php`
- Create: `app/Models/EmailBudget.php`
- Test: `tests/Feature/Email/EmailBudgetTest.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `EmailBudget::DAILY_LIMIT` (int, 99), `EmailBudget::MONTHLY_LIMIT` (int, 3000)
  - `EmailBudget::claim(): bool` — grants and increments, or refuses
  - `EmailBudget::release(): void` — gives a granted slot back
  - `EmailBudget::usedToday(): int`
  - `EmailBudget::monthToDate(): int`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Email;

use App\Models\EmailBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EmailBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_claim_on_a_fresh_day_succeeds_and_counts_one(): void
    {
        $this->assertTrue(EmailBudget::claim());
        $this->assertSame(1, EmailBudget::usedToday());
    }

    public function test_the_ninety_ninth_claim_succeeds_and_the_hundredth_is_refused(): void
    {
        for ($i = 0; $i < 99; $i++) {
            $this->assertTrue(EmailBudget::claim(), "claim {$i} should succeed");
        }

        $this->assertFalse(EmailBudget::claim());
        $this->assertSame(99, EmailBudget::usedToday());
    }

    public function test_release_gives_a_slot_back(): void
    {
        EmailBudget::claim();
        EmailBudget::release();

        $this->assertSame(0, EmailBudget::usedToday());
    }

    public function test_release_never_goes_negative(): void
    {
        EmailBudget::release();

        $this->assertSame(0, EmailBudget::usedToday());
    }

    public function test_a_new_day_gets_a_fresh_budget(): void
    {
        for ($i = 0; $i < 99; $i++) {
            EmailBudget::claim();
        }
        $this->assertFalse(EmailBudget::claim());

        Carbon::setTestNow(Carbon::now('UTC')->addDay());

        $this->assertTrue(EmailBudget::claim());
        $this->assertSame(1, EmailBudget::usedToday());
    }

    public function test_the_monthly_ceiling_refuses_even_with_daily_headroom(): void
    {
        EmailBudget::create([
            'date' => Carbon::now('UTC')->startOfMonth()->toDateString(),
            'sent_count' => 3000,
        ]);

        $this->assertSame(3000, EmailBudget::monthToDate());
        $this->assertFalse(EmailBudget::claim());
        $this->assertSame(0, EmailBudget::usedToday());
    }

    public function test_last_months_sends_do_not_count_against_this_month(): void
    {
        EmailBudget::create([
            'date' => Carbon::now('UTC')->startOfMonth()->subDay()->toDateString(),
            'sent_count' => 3000,
        ]);

        $this->assertSame(0, EmailBudget::monthToDate());
        $this->assertTrue(EmailBudget::claim());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=EmailBudgetTest`
Expected: FAIL — `Class "App\Models\EmailBudget" not found`

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_budget', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->unsignedInteger('sent_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_budget');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One row per UTC date, counting email sent against Resend's free-tier limits.
 *
 * Both ceilings are enforced in claim() because a monthly overrun cannot be solved by
 * waiting until tomorrow — the system has to stop sending and show red.
 */
class EmailBudget extends Model
{
    public const DAILY_LIMIT = 99;

    public const MONTHLY_LIMIT = 3000;

    protected $table = 'email_budget';

    protected $primaryKey = 'date';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['date', 'sent_count'];

    protected function casts(): array
    {
        return ['sent_count' => 'integer'];
    }

    public static function today(): string
    {
        return Carbon::now('UTC')->toDateString();
    }

    /**
     * Grant one send, or refuse. One call == one message, regardless of how many
     * recipients that message carries.
     *
     * lockForUpdate() rather than Postgres's `UPDATE ... RETURNING` so the contended path
     * is the same code under SQLite tests and Postgres production. See the spec's
     * "Known limitation": the lock is a no-op on SQLite, so concurrency is not covered
     * by this suite.
     */
    public static function claim(): bool
    {
        return DB::transaction(function (): bool {
            $date = static::today();

            static::firstOrCreate(['date' => $date], ['sent_count' => 0]);

            $row = static::where('date', $date)->lockForUpdate()->first();

            if ($row->sent_count >= self::DAILY_LIMIT) {
                return false;
            }

            if (static::monthToDate() >= self::MONTHLY_LIMIT) {
                return false;
            }

            $row->increment('sent_count');

            return true;
        });
    }

    /** Give a granted slot back — the send was refused by the provider, so it must not count. */
    public static function release(): void
    {
        DB::transaction(function (): void {
            $row = static::where('date', static::today())->lockForUpdate()->first();

            if ($row && $row->sent_count > 0) {
                $row->decrement('sent_count');
            }
        });
    }

    public static function usedToday(): int
    {
        return (int) static::where('date', static::today())->value('sent_count');
    }

    public static function monthToDate(): int
    {
        $now = Carbon::now('UTC');

        return (int) static::whereBetween('date', [
            $now->copy()->startOfMonth()->toDateString(),
            $now->copy()->endOfMonth()->toDateString(),
        ])->sum('sent_count');
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=EmailBudgetTest`
Expected: PASS, 7 tests

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS, 252 tests

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_10_14_000001_create_email_budget_table.php app/Models/EmailBudget.php tests/Feature/Email/EmailBudgetTest.php
git commit -m "feat: email budget with daily and monthly ceilings"
```

---

### Task 2: `queued_emails` table and `QueuedEmail` spooling

**Files:**
- Create: `database/migrations/2026_10_14_000002_create_queued_emails_table.php`
- Create: `app/Models/QueuedEmail.php`
- Test: `tests/Feature/Email/QueuedEmailTest.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `QueuedEmail::MAX_ATTEMPTS` (int, 3), `QueuedEmail::DEFAULT_PRIORITY` (int, 50)
  - `QueuedEmail::spool(RawMessage $message, ?Envelope $envelope, ?string $error = null): self`
  - `QueuedEmail::nextPending(): ?self`
  - `$queued->envelope(): Envelope`
  - `$queued->rawMessage(): RawMessage`
  - `$queued->markSent(): void`
  - `$queued->markFailed(string $error): void`

> **Planning-time addition to the spec.** Re-sending a `RawMessage` needs an `Envelope`, and
> an envelope needs a sender address that raw MIME cannot reproduce through the Symfony API.
> The schema therefore carries a `sender` column that the spec does not mention.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Email;

use App\Models\QueuedEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class QueuedEmailTest extends TestCase
{
    use RefreshDatabase;

    private function email(string $subject = 'Hello', string $to = 'member@example.com'): Email
    {
        return (new Email)
            ->from('noreply@pentana.test')
            ->to($to)
            ->subject($subject)
            ->text('Body text');
    }

    public function test_spooling_captures_subject_recipients_and_sender(): void
    {
        $email = $this->email('Lunch published', 'ali@example.com');

        $queued = QueuedEmail::spool($email, Envelope::create($email));

        $this->assertSame('Lunch published', $queued->subject);
        $this->assertSame(['ali@example.com'], $queued->recipients);
        $this->assertSame('noreply@pentana.test', $queued->sender);
        $this->assertSame(QueuedEmail::DEFAULT_PRIORITY, $queued->priority);
        $this->assertSame(0, $queued->attempts);
        $this->assertNull($queued->sent_at);
    }

    public function test_the_stored_mime_round_trips_byte_for_byte(): void
    {
        $email = $this->email();
        $original = $email->toString();

        $queued = QueuedEmail::spool($email, Envelope::create($email));

        $this->assertSame($original, $queued->mime);
        $this->assertSame($original, $queued->rawMessage()->toString());
    }

    public function test_priority_is_read_from_the_header_when_present(): void
    {
        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-Pentana-Priority', '10');

        $queued = QueuedEmail::spool($email, Envelope::create($email));

        $this->assertSame(10, $queued->priority);
    }

    public function test_spooling_with_an_error_records_one_attempt(): void
    {
        $email = $this->email();

        $queued = QueuedEmail::spool($email, Envelope::create($email), 'Connection refused');

        $this->assertSame(1, $queued->attempts);
        $this->assertSame('Connection refused', $queued->last_error);
        $this->assertNull($queued->failed_at);
    }

    public function test_next_pending_orders_by_priority_then_age(): void
    {
        $older = $this->email('older');
        $newer = $this->email('newer');
        $urgent = $this->email('urgent');
        $urgent->getHeaders()->addTextHeader('X-Pentana-Priority', '10');

        QueuedEmail::spool($older, Envelope::create($older))->forceFill(['created_at' => now()->subHour()])->save();
        QueuedEmail::spool($newer, Envelope::create($newer));
        QueuedEmail::spool($urgent, Envelope::create($urgent));

        $this->assertSame('urgent', QueuedEmail::nextPending()->subject);
    }

    public function test_next_pending_skips_sent_and_exhausted_messages(): void
    {
        $sent = $this->email('already sent');
        QueuedEmail::spool($sent, Envelope::create($sent))->markSent();

        $dead = $this->email('exhausted');
        $deadRow = QueuedEmail::spool($dead, Envelope::create($dead));
        $deadRow->forceFill(['attempts' => QueuedEmail::MAX_ATTEMPTS, 'failed_at' => now()])->save();

        $this->assertNull(QueuedEmail::nextPending());
    }

    public function test_mark_failed_stamps_failed_at_on_the_final_attempt(): void
    {
        $email = $this->email();
        $queued = QueuedEmail::spool($email, Envelope::create($email));

        $queued->markFailed('boom');
        $this->assertNull($queued->fresh()->failed_at);

        $queued->markFailed('boom');
        $queued->markFailed('boom');

        $this->assertSame(3, $queued->fresh()->attempts);
        $this->assertNotNull($queued->fresh()->failed_at);
    }

    public function test_the_envelope_is_rebuilt_from_stored_addresses(): void
    {
        $email = $this->email('x', 'ali@example.com');
        $queued = QueuedEmail::spool($email, Envelope::create($email));

        $envelope = $queued->envelope();

        $this->assertSame('noreply@pentana.test', $envelope->getSender()->getAddress());
        $this->assertEquals([new Address('ali@example.com')], $envelope->getRecipients());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=QueuedEmailTest`
Expected: FAIL — `Class "App\Models\QueuedEmail" not found`

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queued_emails', function (Blueprint $table) {
            $table->id();
            $table->longText('mime');
            $table->json('recipients');
            $table->string('sender');
            $table->string('subject')->nullable();
            $table->unsignedSmallInteger('priority')->default(50);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['sent_at', 'failed_at', 'priority', 'created_at'], 'queued_emails_drain_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queued_emails');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * An email that did not fit today's budget, stored as raw MIME.
 *
 * MIME is stored rather than re-rendered so a reminder queued today says what it said
 * today — draining must not re-run Blade against a bill that has since been paid.
 */
class QueuedEmail extends Model
{
    public const MAX_ATTEMPTS = 3;

    public const DEFAULT_PRIORITY = 50;

    public const PRIORITY_HEADER = 'X-Pentana-Priority';

    protected $fillable = [
        'mime', 'recipients', 'sender', 'subject',
        'priority', 'attempts', 'last_error', 'failed_at', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'priority' => 'integer',
            'attempts' => 'integer',
            'failed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public static function spool(RawMessage $message, ?Envelope $envelope, ?string $error = null): self
    {
        $recipients = $envelope
            ? array_map(fn (Address $a) => $a->getAddress(), $envelope->getRecipients())
            : [];

        $sender = $envelope?->getSender()?->getAddress() ?? (string) config('mail.from.address');

        $priority = self::DEFAULT_PRIORITY;
        $subject = null;

        if ($message instanceof Email) {
            $subject = $message->getSubject();
            $header = $message->getHeaders()->get(self::PRIORITY_HEADER);

            if ($header !== null) {
                $priority = (int) $header->getBodyAsString();
            }
        }

        return static::create([
            'mime' => $message->toString(),
            'recipients' => $recipients,
            'sender' => $sender,
            'subject' => $subject,
            'priority' => $priority,
            'attempts' => $error === null ? 0 : 1,
            'last_error' => $error,
        ]);
    }

    public static function nextPending(): ?self
    {
        return static::whereNull('sent_at')
            ->whereNull('failed_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('priority')
            ->orderBy('created_at')
            ->first();
    }

    public function rawMessage(): RawMessage
    {
        return new RawMessage($this->mime);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            new Address($this->sender),
            array_map(fn (string $a) => new Address($a), $this->recipients),
        );
    }

    public function markSent(): void
    {
        $this->forceFill(['sent_at' => now()])->save();
    }

    public function markFailed(string $error): void
    {
        $attempts = $this->attempts + 1;

        $this->forceFill([
            'attempts' => $attempts,
            'last_error' => mb_substr($error, 0, 1000),
            'failed_at' => $attempts >= self::MAX_ATTEMPTS ? now() : null,
        ])->save();
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=QueuedEmailTest`
Expected: PASS, 8 tests

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_10_14_000002_create_queued_emails_table.php app/Models/QueuedEmail.php tests/Feature/Email/QueuedEmailTest.php
git commit -m "feat: queued_emails spool storing raw MIME"
```

---

### Task 3: `BudgetedTransport` and mailer registration

**Files:**
- Create: `app/Mail/BudgetedTransport.php`
- Modify: `config/mail.php` — add the `budgeted` mailer
- Modify: `app/Providers/AppServiceProvider.php` — register the transport
- Test: `tests/Feature/Email/BudgetedTransportTest.php`

**Interfaces:**
- Consumes: `EmailBudget::claim()`, `EmailBudget::release()`, `QueuedEmail::spool()`
- Produces:
  - `new BudgetedTransport(TransportInterface $inner)`
  - `config('mail.mailers.budgeted.inner')` — the name of the wrapped mailer, used by Task 4

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Email;

use App\Mail\BudgetedTransport;
use App\Models\EmailBudget;
use App\Models\QueuedEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class BudgetedTransportTest extends TestCase
{
    use RefreshDatabase;

    private function email(string $to = 'member@example.com'): Email
    {
        return (new Email)->from('noreply@pentana.test')->to($to)->subject('Hi')->text('Body');
    }

    private function recordingTransport(): TransportInterface
    {
        return new class implements TransportInterface
        {
            public array $sent = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->sent[] = $message;

                return new SentMessage($message, $envelope ?? Envelope::create($message));
            }

            public function __toString(): string
            {
                return 'recording://';
            }
        };
    }

    private function throwingTransport(): TransportInterface
    {
        return new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new RuntimeException('550 domain is not verified');
            }

            public function __toString(): string
            {
                return 'throwing://';
            }
        };
    }

    public function test_under_budget_the_message_reaches_the_inner_transport(): void
    {
        $inner = $this->recordingTransport();
        $email = $this->email();

        (new BudgetedTransport($inner))->send($email, Envelope::create($email));

        $this->assertCount(1, $inner->sent);
        $this->assertSame(0, QueuedEmail::count());
        $this->assertSame(1, EmailBudget::usedToday());
    }

    public function test_at_budget_the_message_is_spooled_and_never_sent(): void
    {
        for ($i = 0; $i < EmailBudget::DAILY_LIMIT; $i++) {
            EmailBudget::claim();
        }

        $inner = $this->recordingTransport();
        $email = $this->email('ali@example.com');

        (new BudgetedTransport($inner))->send($email, Envelope::create($email));

        $this->assertCount(0, $inner->sent);
        $this->assertSame(1, QueuedEmail::count());
        $this->assertSame(['ali@example.com'], QueuedEmail::first()->recipients);
        $this->assertSame(EmailBudget::DAILY_LIMIT, EmailBudget::usedToday());
    }

    public function test_a_throwing_send_releases_the_slot_and_spools(): void
    {
        $email = $this->email();

        (new BudgetedTransport($this->throwingTransport()))->send($email, Envelope::create($email));

        $this->assertSame(0, EmailBudget::usedToday(), 'a refused send must not consume a slot');
        $this->assertSame(1, QueuedEmail::count());
        $this->assertSame(1, QueuedEmail::first()->attempts);
        $this->assertStringContainsString('not verified', QueuedEmail::first()->last_error);
    }

    public function test_a_throwing_send_does_not_propagate(): void
    {
        $email = $this->email();

        $result = (new BudgetedTransport($this->throwingTransport()))->send($email, Envelope::create($email));

        $this->assertNull($result);
    }

    public function test_one_message_to_several_recipients_counts_as_one_send(): void
    {
        $email = (new Email)
            ->from('noreply@pentana.test')
            ->to('a@example.com', 'b@example.com', 'c@example.com')
            ->subject('Hi')
            ->text('Body');

        (new BudgetedTransport($this->recordingTransport()))->send($email, Envelope::create($email));

        $this->assertSame(1, EmailBudget::usedToday());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=BudgetedTransportTest`
Expected: FAIL — `Class "App\Mail\BudgetedTransport" not found`

- [ ] **Step 3: Write the transport**

```php
<?php

namespace App\Mail;

use App\Models\EmailBudget;
use App\Models\QueuedEmail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * Wraps the real mail transport and refuses to exceed Resend's free-tier limits.
 *
 * Every outbound message passes through Laravel's mailer, so this one place covers
 * notifications, Mail::raw, password resets and anything Filament sends — no sender needs
 * to know a cap exists. The database and APNs notification channels are untouched.
 */
class BudgetedTransport implements TransportInterface
{
    public function __construct(private readonly TransportInterface $inner) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if (! EmailBudget::claim()) {
            QueuedEmail::spool($message, $envelope);

            return null;
        }

        try {
            return $this->inner->send($message, $envelope);
        } catch (Throwable $e) {
            // The provider did not accept it, so the slot must not count.
            EmailBudget::release();
            QueuedEmail::spool($message, $envelope, $e->getMessage());
            report($e);

            // Deliberately swallowed: notifications send inline, so propagating here would
            // turn a mail outage into "admins cannot publish". The failure stays visible
            // through EmailBudgetStats and the log.
            return null;
        }
    }

    public function __toString(): string
    {
        return 'budgeted://'.$this->inner;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=BudgetedTransportTest`
Expected: PASS, 5 tests

- [ ] **Step 5: Add the `budgeted` mailer to `config/mail.php`**

Inside the `'mailers' => [ ... ]` array, after the `'smtp'` entry:

```php
        'budgeted' => [
            'transport' => 'budgeted',
            'inner' => env('MAIL_INNER_MAILER', 'smtp'),
        ],
```

- [ ] **Step 6: Register the transport in `AppServiceProvider::boot()`**

```php
use App\Mail\BudgetedTransport;
use Illuminate\Support\Facades\Mail;

// ... inside boot()

Mail::extend('budgeted', function (array $config) {
    $inner = Mail::mailer($config['inner'] ?? 'smtp')->getSymfonyTransport();

    return new BudgetedTransport($inner);
});
```

- [ ] **Step 7: Verify the mailer resolves**

Run: `php artisan tinker --execute="config(['mail.default' => 'budgeted']); echo (string) Illuminate\Support\Facades\Mail::mailer('budgeted')->getSymfonyTransport();"`
Expected: output beginning `budgeted://`

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add app/Mail/BudgetedTransport.php config/mail.php app/Providers/AppServiceProvider.php tests/Feature/Email/BudgetedTransportTest.php
git commit -m "feat: budgeted mail transport that spools over-cap messages"
```

---

### Task 4: `app:drain-email-queue` command

**Files:**
- Create: `app/Console/Commands/DrainEmailQueue.php`
- Modify: `routes/console.php` — add the schedule entry
- Test: `tests/Feature/Email/DrainEmailQueueTest.php`

**Interfaces:**
- Consumes: `QueuedEmail::nextPending()`, `$queued->rawMessage()`, `$queued->envelope()`, `$queued->markSent()`, `$queued->markFailed()`, `EmailBudget::claim()`, `EmailBudget::release()`, `config('mail.mailers.budgeted.inner')`
- Produces: artisan command `app:drain-email-queue`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Email;

use App\Models\EmailBudget;
use App\Models\QueuedEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class DrainEmailQueueTest extends TestCase
{
    use RefreshDatabase;

    private function queue(string $subject, int $priority = 50): QueuedEmail
    {
        $email = (new Email)->from('noreply@pentana.test')->to('member@example.com')
            ->subject($subject)->text('Body');
        $email->getHeaders()->addTextHeader(QueuedEmail::PRIORITY_HEADER, (string) $priority);

        return QueuedEmail::spool($email, Envelope::create($email));
    }

    private function fakeInner(bool $throws = false): object
    {
        $transport = new class($throws) implements TransportInterface
        {
            public array $sent = [];

            public function __construct(private bool $throws) {}

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                if ($this->throws) {
                    throw new RuntimeException('smtp down');
                }
                $this->sent[] = $message->toString();

                return new SentMessage($message, $envelope ?? Envelope::create($message));
            }

            public function __toString(): string
            {
                return 'fake://';
            }
        };

        Mail::extend('fake-inner', fn () => $transport);
        config(['mail.mailers.fake-inner' => ['transport' => 'fake-inner']]);
        config(['mail.mailers.budgeted.inner' => 'fake-inner']);

        return $transport;
    }

    public function test_an_empty_queue_is_a_no_op(): void
    {
        $this->fakeInner();

        $this->artisan('app:drain-email-queue')->assertSuccessful();

        $this->assertSame(0, EmailBudget::usedToday(), 'an empty drain must not claim a slot');
    }

    public function test_it_sends_pending_mail_oldest_first_within_priority(): void
    {
        $inner = $this->fakeInner();

        $this->queue('bill', 80)->forceFill(['created_at' => now()->subHour()])->save();
        $this->queue('lunch', 10);

        $this->artisan('app:drain-email-queue')->assertSuccessful();

        $this->assertCount(2, $inner->sent);
        $this->assertStringContainsString('lunch', $inner->sent[0]);
        $this->assertStringContainsString('bill', $inner->sent[1]);
    }

    public function test_sent_messages_are_stamped_and_not_resent(): void
    {
        $inner = $this->fakeInner();
        $this->queue('one');

        $this->artisan('app:drain-email-queue')->assertSuccessful();
        $this->artisan('app:drain-email-queue')->assertSuccessful();

        $this->assertCount(1, $inner->sent);
        $this->assertNotNull(QueuedEmail::first()->sent_at);
    }

    public function test_it_stops_when_the_budget_is_exhausted_and_leaves_the_rest_queued(): void
    {
        $inner = $this->fakeInner();

        for ($i = 0; $i < EmailBudget::DAILY_LIMIT - 1; $i++) {
            EmailBudget::claim();
        }

        $this->queue('first');
        $this->queue('second');

        $this->artisan('app:drain-email-queue')->assertSuccessful();

        $this->assertCount(1, $inner->sent);
        $this->assertSame(1, QueuedEmail::whereNull('sent_at')->count());
    }

    public function test_it_sends_at_most_twenty_five_per_run(): void
    {
        $inner = $this->fakeInner();

        for ($i = 0; $i < 30; $i++) {
            $this->queue("msg {$i}");
        }

        $this->artisan('app:drain-email-queue')->assertSuccessful();

        $this->assertCount(25, $inner->sent);
        $this->assertSame(5, QueuedEmail::whereNull('sent_at')->count());
    }

    public function test_a_failed_send_releases_the_slot_and_records_the_attempt(): void
    {
        $this->fakeInner(throws: true);
        $this->queue('doomed');

        $this->artisan('app:drain-email-queue')->assertSuccessful();

        $this->assertSame(0, EmailBudget::usedToday());
        $this->assertSame(1, QueuedEmail::first()->attempts);
    }

    public function test_an_exhausted_message_does_not_block_the_queue_head(): void
    {
        $inner = $this->fakeInner();

        $dead = $this->queue('dead', 10);
        $dead->forceFill(['attempts' => QueuedEmail::MAX_ATTEMPTS, 'failed_at' => now()])->save();

        $this->queue('alive', 20);

        $this->artisan('app:drain-email-queue')->assertSuccessful();

        $this->assertCount(1, $inner->sent);
        $this->assertStringContainsString('alive', $inner->sent[0]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=DrainEmailQueueTest`
Expected: FAIL — `The command "app:drain-email-queue" does not exist.`

- [ ] **Step 3: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\EmailBudget;
use App\Models\QueuedEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends queued email that did not fit an earlier budget.
 *
 * Runs every minute from the scheduler. Sends through the INNER transport, not the
 * budgeted one, because this command claims the slot itself — going through the wrapper
 * would claim twice and re-spool the message it is trying to drain.
 */
class DrainEmailQueue extends Command
{
    public const BATCH = 25;

    protected $signature = 'app:drain-email-queue';

    protected $description = 'Send queued email within the remaining daily budget';

    public function handle(): int
    {
        $transport = Mail::mailer(config('mail.mailers.budgeted.inner', 'smtp'))->getSymfonyTransport();

        $sent = 0;

        for ($i = 0; $i < self::BATCH; $i++) {
            $queued = QueuedEmail::nextPending();

            if (! $queued) {
                break;
            }

            if (! EmailBudget::claim()) {
                break;
            }

            try {
                $transport->send($queued->rawMessage(), $queued->envelope());
                $queued->markSent();
                $sent++;
            } catch (Throwable $e) {
                EmailBudget::release();
                $queued->markFailed($e->getMessage());
                report($e);
            }
        }

        $this->info("Sent {$sent} queued email(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=DrainEmailQueueTest`
Expected: PASS, 7 tests

- [ ] **Step 5: Add the schedule entry**

In `routes/console.php`, after the existing `Schedule::command(...)` lines:

```php
Schedule::command('app:drain-email-queue')->everyMinute()->withoutOverlapping();
```

- [ ] **Step 6: Verify the schedule registers**

Run: `php artisan schedule:list`
Expected: `app:drain-email-queue` listed, next due within a minute

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/DrainEmailQueue.php routes/console.php tests/Feature/Email/DrainEmailQueueTest.php
git commit -m "feat: drain the email queue each minute within budget"
```

---

### Task 5: Priority headers on the notifications

**Files:**
- Modify: `app/Notifications/LunchPublished.php` — add `withSymfonyMessage` to `toMail()`
- Modify: `app/Notifications/ActivityPublished.php`
- Modify: `app/Notifications/LunchVoteNudge.php`
- Modify: `app/Notifications/ActivityReminder.php`
- Modify: `app/Notifications/BillReminder.php`
- Test: `tests/Feature/Email/NotificationPriorityTest.php`

**Interfaces:**
- Consumes: `QueuedEmail::PRIORITY_HEADER`
- Produces: nothing other tasks depend on

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Email;

use App\Models\EmailBudget;
use App\Models\QueuedEmail;
use App\Models\User;
use App\Notifications\BillReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * Asserts the header through the spool rather than poking at MailMessage internals:
 * exhaust the budget, send the notification, read the priority the spool recorded.
 * That exercises the real path the priority is actually used on.
 */
class NotificationPriorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $transport = new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                return new SentMessage($message, $envelope ?? Envelope::create($message));
            }

            public function __toString(): string
            {
                return 'noop://';
            }
        };

        Mail::extend('noop', fn () => $transport);
        config([
            'mail.mailers.noop' => ['transport' => 'noop'],
            'mail.mailers.budgeted.inner' => 'noop',
            'mail.default' => 'budgeted',
        ]);

        for ($i = 0; $i < EmailBudget::DAILY_LIMIT; $i++) {
            EmailBudget::claim();
        }
    }

    public function test_a_bill_reminder_is_spooled_at_priority_eighty(): void
    {
        User::factory()->create()->notify(new BillReminder(50.0, 1));

        $this->assertSame(80, QueuedEmail::first()->priority);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=NotificationPriorityTest`
Expected: FAIL — header is null

- [ ] **Step 3: Add the header to each notification's `toMail()`**

In `BillReminder::toMail()`, chain onto the returned `MailMessage`:

```php
use App\Models\QueuedEmail;
use Symfony\Component\Mime\Email as SymfonyEmail;

// ... in toMail(), on the MailMessage being returned:
    ->withSymfonyMessage(function (SymfonyEmail $message) {
        $message->getHeaders()->addTextHeader(QueuedEmail::PRIORITY_HEADER, '80');
    })
```

Repeat with these values — the whole point is that deadline-bound mail overtakes the bill backlog:

| File | Priority |
|---|---|
| `LunchPublished.php` | `'10'` |
| `ActivityPublished.php` | `'10'` |
| `LunchVoteNudge.php` | `'10'` |
| `ActivityReminder.php` | `'20'` |
| `BillReminder.php` | `'80'` |

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=NotificationPriorityTest`
Expected: PASS

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Notifications tests/Feature/Email/NotificationPriorityTest.php
git commit -m "feat: priority headers so deadline mail overtakes bill reminders"
```

---

### Task 6: `EmailBudgetStats` widget

**Files:**
- Create: `app/Filament/Widgets/EmailBudgetStats.php`
- Test: `tests/Feature/Email/EmailBudgetStatsTest.php`

**Interfaces:**
- Consumes: `EmailBudget::usedToday()`, `EmailBudget::monthToDate()`, `EmailBudget::DAILY_LIMIT`, `EmailBudget::MONTHLY_LIMIT`, `QueuedEmail`
- Produces: nothing other tasks depend on

Widgets are auto-discovered by `discoverWidgets()` in `AccountPanelProvider` — no registration step.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Email;

use App\Filament\Widgets\EmailBudgetStats;
use App\Models\EmailBudget;
use App\Models\QueuedEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EmailBudgetStatsTest extends TestCase
{
    use RefreshDatabase;

    private function queueOne(): QueuedEmail
    {
        $email = (new Email)->from('noreply@pentana.test')->to('m@example.com')->subject('x')->text('b');

        return QueuedEmail::spool($email, Envelope::create($email));
    }

    public function test_only_admins_can_view_it(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->assertFalse(EmailBudgetStats::canView());

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->assertTrue(EmailBudgetStats::canView());
    }

    public function test_daily_colour_is_grey_below_seventy_percent(): void
    {
        for ($i = 0; $i < 50; $i++) {
            EmailBudget::claim();
        }

        $this->assertSame('gray', (new EmailBudgetStats)->dailyColour());
    }

    public function test_daily_colour_is_warning_at_seventy_percent(): void
    {
        for ($i = 0; $i < 70; $i++) {
            EmailBudget::claim();
        }

        $this->assertSame('warning', (new EmailBudgetStats)->dailyColour());
    }

    public function test_daily_colour_is_danger_at_ninety_percent(): void
    {
        for ($i = 0; $i < 90; $i++) {
            EmailBudget::claim();
        }

        $this->assertSame('danger', (new EmailBudgetStats)->dailyColour());
    }

    public function test_queue_colour_is_grey_while_draining_normally(): void
    {
        $this->queueOne();

        $this->assertSame('gray', (new EmailBudgetStats)->queueColour());
    }

    public function test_queue_colour_is_danger_when_the_oldest_is_over_a_day_old(): void
    {
        $this->queueOne()->forceFill(['created_at' => now()->subHours(25)])->save();

        $this->assertSame('danger', (new EmailBudgetStats)->queueColour());
    }

    public function test_monthly_colour_warns_on_projection_before_going_over(): void
    {
        $widget = new EmailBudgetStats;

        $this->assertSame('warning', $widget->monthlyColourFor(used: 2000, projected: 3400));
        $this->assertSame('gray', $widget->monthlyColourFor(used: 1284, projected: 2340));
        $this->assertSame('danger', $widget->monthlyColourFor(used: 3012, projected: 3012));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=EmailBudgetStatsTest`
Expected: FAIL — `Class "App\Filament\Widgets\EmailBudgetStats" not found`

- [ ] **Step 3: Write the widget**

```php
<?php

namespace App\Filament\Widgets;

use App\Models\EmailBudget;
use App\Models\QueuedEmail;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Email budget against Resend's free tier.
 *
 * The normal state is deliberately colourless — if this widget is interesting, something
 * is wrong. A large queue immediately after a bill run is healthy and stays grey; only a
 * backlog that is failing to drain turns red, because that means average inflow has
 * exceeded the daily cap and the queue is compounding.
 */
class EmailBudgetStats extends StatsOverviewWidget
{
    protected static ?int $sort = -5;

    protected ?string $heading = 'Email budget';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function dailyColour(): string
    {
        $ratio = EmailBudget::usedToday() / EmailBudget::DAILY_LIMIT;

        return match (true) {
            $ratio >= 0.9 => 'danger',
            $ratio >= 0.7 => 'warning',
            default => 'gray',
        };
    }

    public function projectedMonthly(): int
    {
        $now = Carbon::now('UTC');
        $used = EmailBudget::monthToDate();

        return (int) round($used / $now->day * $now->daysInMonth);
    }

    public function monthlyColourFor(int $used, int $projected): string
    {
        return match (true) {
            $used >= EmailBudget::MONTHLY_LIMIT => 'danger',
            $projected > EmailBudget::MONTHLY_LIMIT => 'warning',
            default => 'gray',
        };
    }

    public function oldestPendingAt(): ?Carbon
    {
        $oldest = QueuedEmail::whereNull('sent_at')->whereNull('failed_at')
            ->orderBy('created_at')->value('created_at');

        return $oldest ? Carbon::parse($oldest) : null;
    }

    public function queueColour(): string
    {
        $oldest = $this->oldestPendingAt();

        return $oldest && $oldest->lt(now()->subDay()) ? 'danger' : 'gray';
    }

    protected function getStats(): array
    {
        $usedToday = EmailBudget::usedToday();
        $usedMonth = EmailBudget::monthToDate();
        $projected = $this->projectedMonthly();
        $pending = QueuedEmail::whereNull('sent_at')->whereNull('failed_at')->count();
        $oldest = $this->oldestPendingAt();
        $dailyColour = $this->dailyColour();

        $todayDescription = $usedToday >= EmailBudget::DAILY_LIMIT
            ? 'cap reached — mail is queued'
            : (EmailBudget::DAILY_LIMIT - $usedToday).' remaining today';

        $monthPercent = (int) round($usedMonth / EmailBudget::MONTHLY_LIMIT * 100);
        $monthlyColour = $this->monthlyColourFor($usedMonth, $projected);

        $monthDescription = match ($monthlyColour) {
            'danger' => 'monthly limit exceeded',
            'warning' => "projected {$projected} — over limit",
            default => "{$monthPercent}% used · projected {$projected}",
        };

        $queueColour = $this->queueColour();

        $queueDescription = match (true) {
            $pending === 0 => 'nothing waiting',
            $queueColour === 'danger' => 'oldest '.$oldest->diffForHumans(syntax: true).' — not draining',
            default => 'oldest '.$oldest->diffForHumans(syntax: true),
        };

        return [
            Stat::make('Email today', $usedToday.' / '.EmailBudget::DAILY_LIMIT)
                ->description($todayDescription)
                ->descriptionIcon($dailyColour === 'gray' ? 'heroicon-m-envelope' : 'heroicon-m-exclamation-triangle')
                ->color($dailyColour),

            Stat::make('This month', number_format($usedMonth).' / '.number_format(EmailBudget::MONTHLY_LIMIT))
                ->description($monthDescription)
                ->descriptionIcon($monthlyColour === 'gray' ? 'heroicon-m-calendar' : 'heroicon-m-arrow-trending-up')
                ->color($monthlyColour),

            Stat::make('Queued', (string) $pending)
                ->description($queueDescription)
                ->descriptionIcon($queueColour === 'danger' ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-clock')
                ->color($queueColour),
        ];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=EmailBudgetStatsTest`
Expected: PASS, 7 tests

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Widgets/EmailBudgetStats.php tests/Feature/Email/EmailBudgetStatsTest.php
git commit -m "feat: email budget widget with projection and stall warnings"
```

---

### Task 7: End-to-end — a bill run overflows and drains

**Files:**
- Test: `tests/Feature/Email/BillRunOverflowTest.php`
- Modify: `.env.example` — document `MAIL_INNER_MAILER`

**Interfaces:**
- Consumes: everything from Tasks 1-5
- Produces: nothing

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Email;

use App\Models\EmailBudget;
use App\Models\Payment\FeePeriod;
use App\Models\QueuedEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class BillRunOverflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_hundred_and_fifty_member_bill_run_sends_ninety_nine_and_queues_the_rest(): void
    {
        $transport = $this->countingTransport();
        $this->seedMembersWithBills(150);

        $this->artisan('app:send-bill-reminders')->assertSuccessful();

        $this->assertSame(99, $transport->count, 'exactly the daily cap should have been sent');
        $this->assertSame(99, EmailBudget::usedToday());
        $this->assertSame(51, QueuedEmail::whereNull('sent_at')->count());

        // The in-app bell is unaffected by the cap.
        $this->assertSame(150, \Illuminate\Support\Facades\DB::table('notifications')->count());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=BillRunOverflowTest`
Expected: FAIL — counts do not match

- [ ] **Step 3: Fix whatever the integration reveals**

No new production code is planned here. If this test fails after Tasks 1-5 pass, the bug is real and belongs in whichever unit it comes from — add a failing unit test there first, then fix.

- [ ] **Step 4: Add the drain-next-day test**

```php
    private function countingTransport(): object
    {
        $transport = new class implements TransportInterface
        {
            public int $count = 0;

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->count++;

                return new SentMessage($message, $envelope ?? Envelope::create($message));
            }

            public function __toString(): string
            {
                return 'counting://';
            }
        };

        Mail::extend('counting', fn () => $transport);
        config([
            'mail.mailers.counting' => ['transport' => 'counting'],
            'mail.mailers.budgeted.inner' => 'counting',
            'mail.default' => 'budgeted',
        ]);

        return $transport;
    }

    private function seedMembersWithBills(int $count): void
    {
        foreach (User::factory()->count($count)->create() as $member) {
            FeePeriod::create([
                'user_id' => $member->id,
                'month_date' => now()->startOfMonth()->toDateString(),
                'amount_due' => 50,
                'status' => 'unpaid',
            ]);
        }
    }

    public function test_the_backlog_drains_the_next_day(): void
    {
        $transport = $this->countingTransport();
        $this->seedMembersWithBills(150);

        $this->artisan('app:send-bill-reminders')->assertSuccessful();
        $this->assertSame(51, QueuedEmail::whereNull('sent_at')->count());

        $this->travel(1)->day();

        // 51 queued, 25 per run, so three runs clear it.
        $this->artisan('app:drain-email-queue')->assertSuccessful();
        $this->artisan('app:drain-email-queue')->assertSuccessful();
        $this->artisan('app:drain-email-queue')->assertSuccessful();

        $this->assertSame(0, QueuedEmail::whereNull('sent_at')->count());
        $this->assertSame(150, $transport->count);
    }
```

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 6: Document the env var**

In `.env.example`, below the existing `MAIL_` block:

```dotenv
# The mailer BudgetedTransport wraps. Set MAIL_MAILER=budgeted to enable the send cap.
MAIL_INNER_MAILER=smtp
```

- [ ] **Step 7: Commit**

```bash
git add tests/Feature/Email/BillRunOverflowTest.php .env.example
git commit -m "test: bill run overflows the cap and drains the next day"
```

---

## Deployment

Not a code task, but the feature is inert until this happens.

- [ ] On the server, set `MAIL_MAILER=budgeted` and `MAIL_INNER_MAILER=smtp` in `/var/www/pentana/.env`
- [ ] Run `php artisan migrate --force` and `php artisan optimize`
- [ ] Confirm `php artisan schedule:list` shows `app:drain-email-queue`
- [ ] **Verify the lock against Postgres manually.** The suite runs SQLite, where
      `lockForUpdate()` is a no-op, so concurrency is untested. Publish a lunch to 150 members
      while a drain is running and confirm `sent_count` never exceeds 99:

      ```bash
      ssh pentana 'cd /var/www/pentana && php artisan tinker --execute="echo App\Models\EmailBudget::usedToday();"'
      ```

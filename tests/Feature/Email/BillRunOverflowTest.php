<?php

namespace Tests\Feature\Email;

use App\Models\EmailBudget;
use App\Models\Payment\FeePeriod;
use App\Models\QueuedEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class BillRunOverflowTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_a_hundred_and_fifty_member_bill_run_sends_the_daily_cap_and_queues_the_rest(): void
    {
        $transport = $this->countingTransport();
        $this->seedMembersWithBills(150);

        $this->artisan('app:send-bill-reminders')->assertSuccessful();

        $this->assertSame(EmailBudget::DAILY_LIMIT, $transport->count, 'exactly the daily cap should have been sent');
        $this->assertSame(EmailBudget::DAILY_LIMIT, EmailBudget::usedToday());
        $this->assertSame(150 - EmailBudget::DAILY_LIMIT, QueuedEmail::whereNull('sent_at')->count());

        // The in-app bell is unaffected by the cap.
        $this->assertSame(150, DB::table('notifications')->count());
    }

    public function test_the_backlog_drains_the_next_day(): void
    {
        $transport = $this->countingTransport();
        $this->seedMembersWithBills(150);

        $this->artisan('app:send-bill-reminders')->assertSuccessful();
        $this->assertSame(150 - EmailBudget::DAILY_LIMIT, QueuedEmail::whereNull('sent_at')->count());

        $this->travel(1)->day();

        // 50 queued, 25 per run, so two runs clear it (a third is a harmless no-op).
        $this->artisan('app:drain-email-queue')->assertSuccessful();
        $this->artisan('app:drain-email-queue')->assertSuccessful();
        $this->artisan('app:drain-email-queue')->assertSuccessful();

        $this->assertSame(0, QueuedEmail::whereNull('sent_at')->count());
        $this->assertSame(150, $transport->count);
    }
}

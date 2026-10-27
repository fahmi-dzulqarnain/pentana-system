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

    public function test_claims_up_to_the_daily_cap_succeed_and_the_next_is_refused(): void
    {
        for ($i = 0; $i < EmailBudget::DAILY_LIMIT; $i++) {
            $this->assertTrue(EmailBudget::claim(), "claim {$i} should succeed");
        }

        $this->assertFalse(EmailBudget::claim());
        $this->assertSame(EmailBudget::DAILY_LIMIT, EmailBudget::usedToday());
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
        for ($i = 0; $i < EmailBudget::DAILY_LIMIT; $i++) {
            EmailBudget::claim();
        }
        $this->assertFalse(EmailBudget::claim());

        Carbon::setTestNow(Carbon::now('UTC')->addDay());

        $this->assertTrue(EmailBudget::claim());
        $this->assertSame(1, EmailBudget::usedToday());
    }

    public function test_the_monthly_ceiling_refuses_even_with_daily_headroom(): void
    {
        // Pinned mid-month so the seeded row is never today's: otherwise on the 1st the
        // daily check trips first and this stops exercising the monthly ceiling.
        Carbon::setTestNow(Carbon::create(2026, 10, 15, 9, 0, 0, 'UTC'));

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
        Carbon::setTestNow(Carbon::create(2026, 10, 15, 9, 0, 0, 'UTC'));

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

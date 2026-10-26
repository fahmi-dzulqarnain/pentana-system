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

    public function test_permanently_failed_messages_are_surfaced(): void
    {
        $this->queueOne()->forceFill(['attempts' => QueuedEmail::MAX_ATTEMPTS, 'failed_at' => now()])->save();

        $widget = new EmailBudgetStats;

        $this->assertSame(1, $widget->failedCount());
        $this->assertSame('danger', $widget->queueColour(), 'lost mail must be visible');
    }

    public function test_expired_and_cancelled_mail_is_not_reported_as_lost(): void
    {
        $this->queueOne()->forceFill(['expires_at' => now()->subMinute()])->save();
        QueuedEmail::failExpired();
        $this->queueOne()->forceFill(['failed_at' => now(), 'last_error' => 'Cancelled by admin'])->save();

        $widget = new EmailBudgetStats;

        $this->assertSame(0, $widget->failedCount());
        $this->assertSame('gray', $widget->queueColour());
    }

    public function test_monthly_colour_warns_on_projection_before_going_over(): void
    {
        $widget = new EmailBudgetStats;

        $this->assertSame('warning', $widget->monthlyColourFor(used: 2000, projected: 3400));
        $this->assertSame('gray', $widget->monthlyColourFor(used: 1284, projected: 2340));
        $this->assertSame('danger', $widget->monthlyColourFor(used: 3012, projected: 3012));
    }
}

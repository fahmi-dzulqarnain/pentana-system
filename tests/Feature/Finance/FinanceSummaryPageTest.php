<?php

namespace Tests\Feature\Finance;

use App\Filament\Pages\FinanceSummary;
use App\Filament\Resources\FeePeriods\FeePeriodResource;
use App\Filament\Resources\FeePeriods\Pages\ListFeePeriods;
use App\Filament\Widgets\FinanceTotals;
use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use App\Notifications\BillReminder;
use App\Services\FinanceService;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceSummaryPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('account'));
        Carbon::setTestNow('2026-10-19 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** One unpaid bill per month, starting at $firstMonth. */
    private function bills(User $user, int $count, float $due, string $firstMonth = '2026-01-01'): void
    {
        for ($i = 0; $i < $count; $i++) {
            FeePeriod::create([
                'user_id' => $user->id,
                'month_date' => Carbon::parse($firstMonth)->addMonths($i)->toDateString(),
                'amount_due' => $due,
                'status' => 'unpaid',
            ]);
        }
    }

    private function proof(User $user, string $status, float $amount, $verifiedAt = null): PaymentProof
    {
        return PaymentProof::create([
            'user_id' => $user->id,
            'proof_image_path' => 'r.jpg',
            'amount_claimed' => $amount,
            'amount_verified' => $status === 'verified' ? $amount : null,
            'status' => $status,
            'verified_at' => $status === 'verified' ? ($verifiedAt ?? now()) : null,
        ]);
    }

    private function treasurer(): User
    {
        return User::factory()->treasurer()->create(['activated_at' => now()]);
    }

    public function test_only_finance_users_can_open_the_page(): void
    {
        $url = FinanceSummary::getUrl();

        $this->actingAs(User::factory()->create(['activated_at' => now()]))->get($url)->assertForbidden();

        $admin = User::factory()->admin()->create(['activated_at' => now()]);
        $this->actingAs($admin)->get($url)->assertForbidden();
        $this->actingAs($admin)->get(Dashboard::getUrl())->assertOk()->assertDontSee('Finance summary');

        $this->actingAs($this->treasurer())->get($url)->assertOk()->assertSee('Finance summary');
        $this->actingAs(User::factory()->superAdmin()->create(['activated_at' => now()]))->get($url)->assertOk();
    }

    public function test_treasurer_sidebar_lists_the_page_but_the_dashboard_has_no_finance_totals(): void
    {
        $this->assertNotContains(FinanceTotals::class, Filament::getWidgets());

        // Separate test: Filament builds the sidebar once per test process.
        $this->actingAs($this->treasurer())->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee('Finance summary')
            ->assertDontSee('Credit held');
    }

    public function test_owing_only_by_default_sorted_by_amount(): void
    {
        $small = User::factory()->create(['name' => 'Small Owing']);
        $this->bills($small, 1, 50);
        $big = User::factory()->create(['name' => 'Big Owing']);
        $this->bills($big, 6, 50);
        $creditOnly = User::factory()->create(['name' => 'Credit Only']);
        $this->proof($creditOnly, 'verified', 20);

        $this->actingAs($this->treasurer());

        Livewire::test(FinanceSummary::class)
            ->assertCanSeeTableRecords([$big, $small], inOrder: true)
            ->assertCanNotSeeTableRecords([$creditOnly])
            ->assertTableActionVisible('remind', $big)
            ->filterTable('scope', 'all')
            ->assertCanSeeTableRecords([$big, $small, $creditOnly])
            ->assertSee('20.00')
            ->assertTableActionHidden('remind', $creditOnly)
            ->searchTable('Credit')
            ->assertCanSeeTableRecords([$creditOnly])
            ->assertCanNotSeeTableRecords([$big, $small]);
    }

    public function test_partial_marker_shows_when_the_oldest_unpaid_bill_is_partial(): void
    {
        $partial = User::factory()->create(['name' => 'Part Payer']);
        $this->bills($partial, 2, 50);
        $this->proof($partial, 'verified', 20);
        app(FinanceService::class)->applyAvailableFunds($partial);

        $this->actingAs($this->treasurer());

        $badge = '/fi-badge-label">\s*partial\s*</';

        $page = Livewire::test(FinanceSummary::class)
            ->assertCanSeeTableRecords([$partial])
            ->assertSee('January 2026');
        $this->assertMatchesRegularExpression($badge, $page->html());

        FeePeriod::where('user_id', $partial->id)->update(['status' => 'unpaid']);
        $this->assertDoesNotMatchRegularExpression($badge, Livewire::test(FinanceSummary::class)->html());
    }

    public function test_tiles_show_totals(): void
    {
        // Owes 300 over six bills.
        $big = User::factory()->create();
        $this->bills($big, 6, 50);

        // Owes 50: a 100 bill paid last month, a 50 bill still open.
        $small = User::factory()->create();
        $this->bills($small, 1, 100, '2026-08-01');
        $this->bills($small, 1, 50, '2026-09-01');
        $this->proof($small, 'verified', 100, Carbon::parse('2026-09-15 09:00:00'));
        app(FinanceService::class)->applyAvailableFunds($small);

        // Paid ahead: 20 credit, verified this month.
        $ahead = User::factory()->create();
        $this->proof($ahead, 'verified', 20);

        // One proof waiting since three days ago.
        Carbon::setTestNow('2026-10-16 10:00:00');
        $this->proof($big, 'pending', 50);
        Carbon::setTestNow('2026-10-19 10:00:00');

        $this->actingAs($this->treasurer());

        Livewire::test(FinanceTotals::class)
            ->assertSeeInOrder([
                'Outstanding', 'MYR 350.00', '2 members owe',
                'Collected this month', 'MYR 20.00', 'MYR 120.00 all time',
                'Credit held', 'MYR 20.00', 'Paid ahead by 1 member',
                'Payments to verify', '1', 'oldest 3 days ago',
            ]);

        PaymentProof::where('status', 'pending')->delete();
        Livewire::test(FinanceTotals::class)->assertSee('All checked');
    }

    public function test_remind_sends_one_inline_reminder_with_current_balance(): void
    {
        Notification::fake();

        $member = User::factory()->create(['name' => 'Ahmad']);
        $this->bills($member, 6, 50);
        $other = User::factory()->create();
        $this->bills($other, 1, 30);

        $this->actingAs($this->treasurer());

        Livewire::test(FinanceSummary::class)
            ->mountTableAction('remind', $member)
            ->assertMountedActionModalSee('Send a reminder to Ahmad for MYR 300.00 across 6 bills?')
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified('Reminder sent to Ahmad');

        Notification::assertSentTo($member, BillReminder::class,
            fn (BillReminder $n) => $n->outstanding === 300.0 && $n->unpaidCount === 6);
        Notification::assertNotSentTo($other, BillReminder::class);
        Notification::assertCount(1);
    }

    public function test_remind_warns_when_the_member_cannot_be_notified(): void
    {
        $member = User::factory()->create(['name' => 'Ahmad']);
        $this->bills($member, 1, 50);
        $this->actingAs($this->treasurer());

        Notification::shouldReceive('send')->andThrow(new \RuntimeException('APNs down'));

        Livewire::test(FinanceSummary::class)
            ->callTableAction('remind', $member)
            ->assertNotified('The member could not be notified.');
    }

    public function test_remind_is_refused_for_plain_admin_calling_the_action_directly(): void
    {
        Notification::fake();

        $member = User::factory()->create();
        $this->bills($member, 2, 50);

        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(FinanceSummary::class)->assertForbidden();

        // A treasurer demoted after the page loaded is refused by the action itself.
        $treasurer = $this->treasurer();
        $this->actingAs($treasurer);
        $page = Livewire::test(FinanceSummary::class);
        $treasurer->forceFill(['is_treasurer' => false])->save();

        // The page re-checks access on every Livewire request, before any action runs.
        $page->call('mountAction', 'remind', [], ['table' => true, 'recordKey' => $member->getKey()])
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_bills_action_links_to_the_members_fee_periods(): void
    {
        $member = User::factory()->create();
        $this->bills($member, 2, 50);
        $other = User::factory()->create();
        $this->bills($other, 1, 30);

        $this->actingAs($this->treasurer());

        $url = FeePeriodResource::getUrl('index', ['filters' => ['user_id' => ['value' => $member->id]]]);

        Livewire::test(FinanceSummary::class)
            ->assertTableActionHasUrl('bills', $url, $member);

        Livewire::withQueryParams(['filters' => ['user_id' => ['value' => $member->id]]])
            ->test(ListFeePeriods::class)
            ->assertCanSeeTableRecords(FeePeriod::where('user_id', $member->id)->get())
            ->assertCanNotSeeTableRecords(FeePeriod::where('user_id', $other->id)->get());
    }
}

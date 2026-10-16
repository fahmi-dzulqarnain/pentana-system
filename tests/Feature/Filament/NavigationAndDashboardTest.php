<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\NeedsAttention;
use App\Models\Lunch;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NavigationAndDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['activated_at' => now()]);
    }

    public function test_admin_sidebar_puts_personal_pages_first_then_tools_by_domain(): void
    {
        $html = $this->actingAs($this->admin())->get('/account')->assertOk()->getContent();

        preg_match_all('/fi-sidebar-group-label[^>]*>\s*([^<]+?)\s*</', $html, $groups);
        $this->assertSame(['Personal', 'Lunch', 'Activities & emails', 'Finance', 'Members'], array_map('html_entity_decode', $groups[1]));
        $this->assertStringContainsString('Lunch Planner', $html);

        // One way into lunch planning: the list is reached from the planner, not the sidebar.
        $this->assertStringNotContainsString('href="'.url('/account/lunches').'"', $html);
        $this->assertStringNotContainsString('Activity Management', $html);
        $this->assertStringNotContainsString('Welfare &amp; Social', $html);
    }

    public function test_members_see_their_payments_as_a_personal_page(): void
    {
        $member = User::factory()->create(['activated_at' => now()]);

        $this->actingAs($member)->get('/account')
            ->assertOk()
            ->assertSee('Personal')
            ->assertSee('My Payments')
            ->assertDontSee('Finance')
            ->assertDontSee('Lunch Planner');
    }

    public function test_planner_and_lunch_list_link_to_each_other(): void
    {
        $this->actingAs($this->admin());

        $this->get('/account/lunch-calendar')->assertOk()->assertSee('List view');
        $this->get('/account/lunches')->assertOk()->assertSee('Calendar view');
    }

    public function test_needs_attention_shows_the_pending_queue_and_an_empty_lunch_state(): void
    {
        $member = User::factory()->create();
        PaymentProof::create(['user_id' => $member->id, 'proof_image_path' => 'r.jpg', 'amount_claimed' => 30, 'status' => 'pending']);
        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(NeedsAttention::class)
            ->assertSee('Needs attention')
            ->assertSee('Payments to verify')
            ->assertSee('None open')
            ->assertSee('Plan the next lunch')
            ->assertSee('No one waiting');
    }

    public function test_needs_attention_counts_orders_on_the_next_open_lunch(): void
    {
        Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addDay()]);
        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(NeedsAttention::class)
            ->assertSee('0 ordered')
            ->assertDontSee('None open');
    }

    public function test_dashboard_is_admin_work_first_and_has_no_welcome_card(): void
    {
        $html = $this->actingAs($this->admin())->get('/account')->assertOk()->getContent();

        $this->assertStringNotContainsString('fi-account-widget', $html);
        preg_match_all('/wire:name="App\\\\Filament\\\\Widgets\\\\(\w+)"/', $html, $widgets);
        $this->assertSame(['NeedsAttention', 'EmailBudgetStats'], $widgets[1]);
    }

    public function test_members_do_not_see_the_admin_widgets(): void
    {
        $this->actingAs(User::factory()->create(['activated_at' => now()]))->get('/account')
            ->assertOk()
            ->assertDontSee('Needs attention')
            ->assertDontSee('Email budget');
    }

    public function test_counts_follow_the_viewers_number_convention(): void
    {
        app()->setLocale('id');
        $this->assertSame('3.000', NeedsAttention::count(3000));

        app()->setLocale('ms');
        $this->assertSame('3,000', NeedsAttention::count(3000));
    }
}

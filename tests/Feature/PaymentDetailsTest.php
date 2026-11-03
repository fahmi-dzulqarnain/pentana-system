<?php

namespace Tests\Feature;

use App\Filament\Widgets\PaymentDetails;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_is_visible_to_members_only(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertTrue(PaymentDetails::canView());

        $this->actingAs(User::factory()->admin()->create());
        $this->assertFalse(PaymentDetails::canView());
    }

    public function test_member_dashboard_shows_where_to_transfer_and_the_qr(): void
    {
        $member = User::factory()->create(['locale' => 'en', 'activated_at' => now()]);

        $this->actingAs($member);

        Livewire::test(PaymentDetails::class)
            ->assertSee('How to pay')
            ->assertSee('CIMB Bank')
            ->assertSee('8605765606')
            ->assertSee('KELAB PENTANA')
            ->assertSee($member->payment_reference)
            ->assertSee(asset('images/duitnow-qr.jpg'), false);
    }

    public function test_submit_payment_form_shows_the_account_details(): void
    {
        $member = User::factory()->create(['locale' => 'en', 'activated_at' => now()]);

        $this->actingAs($member)->get('/account/fee-payments/create')
            ->assertOk()
            ->assertSee('8605765606')
            ->assertSee('kelab-pentana-duitnow-qr.jpg');
    }
}

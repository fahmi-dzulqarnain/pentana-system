<?php

namespace Tests\Feature\Filament;

use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Livewire\GlobalSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_global_search_finds_members_and_their_payments_and_bills(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'activated_at' => now()]);
        $member = User::factory()->create(['name' => 'Banmathi Mahaindran', 'activated_at' => now()]);
        PaymentProof::create(['user_id' => $member->id, 'proof_image_path' => 'x.jpg', 'amount_claimed' => 30, 'status' => 'pending']);
        FeePeriod::create(['user_id' => $member->id, 'month_date' => '2026-10-01', 'amount_due' => 30, 'status' => 'unpaid']);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(GlobalSearch::class)
            ->set('search', 'Banmathi')
            ->assertOk()
            ->assertSee('Banmathi Mahaindran')
            ->assertSee('MYR 30.00')
            ->assertSee('October 2026');
    }
}

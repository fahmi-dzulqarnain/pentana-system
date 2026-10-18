<?php

namespace Tests\Feature;

use App\Filament\Resources\FeePeriods\Pages\ListFeePeriods;
use App\Models\Payment\FeePeriod;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FeePeriodsTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_renders_the_bill_columns_with_data(): void
    {
        $admin = User::factory()->treasurer()->create();
        $member = User::factory()->create(['name' => 'Alice Member']);
        FeePeriod::create([
            'user_id' => $member->id,
            'month_date' => '2026-06-01',
            'amount_due' => 100,
            'status' => 'unpaid',
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(ListFeePeriods::class)
            ->assertCanRenderTableColumn('user.name')
            ->assertCanRenderTableColumn('month_date')
            ->assertCanRenderTableColumn('amount_due')
            ->assertCanRenderTableColumn('status')
            ->assertSee('Alice Member')
            ->assertSee('100.00');
    }
}

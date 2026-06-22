<?php

namespace Tests\Feature;

use App\Filament\Widgets\MyBillsTable;
use App\Models\Payment\FeePeriod;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyBillsTableTest extends TestCase
{
    use RefreshDatabase;

    private function bill(User $user, string $monthDate, float $amount): FeePeriod
    {
        return FeePeriod::create([
            'user_id' => $user->id,
            'month_date' => $monthDate,
            'amount_due' => $amount,
            'status' => 'unpaid',
        ]);
    }

    public function test_table_is_visible_to_members_only(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertTrue(MyBillsTable::canView());

        $this->actingAs(User::factory()->admin()->create());
        $this->assertFalse(MyBillsTable::canView());
    }

    public function test_table_shows_only_the_members_own_bills(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->bill($member, '2026-06-01', 100);
        $theirs = $this->bill($other, '2026-06-01', 250);

        $this->actingAs($member);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(MyBillsTable::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->assertSee('100.00');
    }
}

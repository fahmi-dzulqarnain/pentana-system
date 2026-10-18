<?php

namespace Tests\Feature;

use App\Filament\Resources\FeePeriods\Pages\CreateFeePeriod;
use App\Models\MemberCategory;
use App\Models\Payment\FeePeriod;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FeePeriodFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->treasurer()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));
    }

    public function test_type_and_remarks_are_saved(): void
    {
        $member = User::factory()->create();

        Livewire::test(CreateFeePeriod::class)
            ->fillForm([
                'user_id' => $member->id,
                'month_date' => '2026-09-01',
                'amount_due' => 70,
                'type' => 'adjustment',
                'remarks' => 'Fee + jersey charge',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $bill = FeePeriod::where('user_id', $member->id)->firstOrFail();
        $this->assertSame('adjustment', $bill->type);
        $this->assertSame('Fee + jersey charge', $bill->remarks);
    }

    public function test_amount_defaults_to_the_selected_members_fee(): void
    {
        $category = MemberCategory::create(['name' => 'Grade 12-14', 'default_fee' => 20]);
        $member = User::factory()->create(['member_category_id' => $category->id]);

        Livewire::test(CreateFeePeriod::class)
            ->fillForm(['user_id' => $member->id])
            ->assertFormSet(['amount_due' => 20.0]);
    }
}

<?php

namespace Tests\Feature\Nickname;

use App\Filament\Pages\FinanceSummary;
use App\Filament\Resources\Activities\Pages\EditActivity;
use App\Filament\Resources\Activities\RelationManagers\RegistrationsRelationManager;
use App\Filament\Resources\FeePayments\Pages\ListFeePayments;
use App\Filament\Resources\FeePeriods\Pages\ListFeePeriods;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Support\MemberName;
use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\MemberCategory;
use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminNicknameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->treasurer()->create(['activated_at' => now()]));
        Filament::setCurrentPanel(Filament::getPanel('account'));
    }

    public function test_users_table_shows_nickname_with_full_name_beneath(): void
    {
        $member = User::factory()->create(['name' => 'Ahmad Zulkifli', 'nickname' => 'Fahmi']);

        Livewire::test(ListUsers::class)
            ->assertTableColumnStateSet('name', 'Fahmi', $member)
            ->assertTableColumnFormattedStateSet('name', 'Fahmi', $member)
            ->assertSeeInOrder(['Fahmi', 'Ahmad Zulkifli']);
    }

    public function test_bold_marks_only_members_with_a_nickname(): void
    {
        $nicknamed = new User(['name' => 'Ahmad Zulkifli', 'nickname' => 'Fahmi']);
        $plain = new User(['name' => 'Siti Aminah']);
        $column = MemberName::column();

        $this->assertSame(FontWeight::Bold, $column->record($nicknamed)->getWeight());
        $this->assertNull($column->record($plain)->getWeight());

        $entry = fn (User $user): TextEntry => collect(
            Schema::make(Livewire::new(ListUsers::class))->record($user)->components([MemberName::entry()])->getFlatComponents()
        )->first(fn ($c) => $c instanceof TextEntry);

        $this->assertSame(FontWeight::Bold, $entry($nicknamed)->getWeight());
        $this->assertNull($entry($plain)->getWeight());
    }

    public function test_fee_periods_member_filter_uses_nickname_labels_and_searches_both(): void
    {
        $member = User::factory()->create(['name' => 'Ahmad Zulkifli', 'nickname' => 'Fahmi']);
        $component = Livewire::test(ListFeePeriods::class);

        $select = collect($component->instance()->getTableFiltersForm()->getFlatComponents())
            ->first(fn ($c) => $c instanceof Select && str_ends_with($c->getStatePath(), 'user_id.value'));

        $this->assertSame([$member->id => 'Fahmi (Ahmad Zulkifli)'], $select->getSearchResults('fahmi'));
        $this->assertSame([$member->id => 'Fahmi (Ahmad Zulkifli)'], $select->getSearchResults('zulkifli'));
    }

    public function test_users_table_search_matches_nickname(): void
    {
        $nicknamed = User::factory()->create(['name' => 'Ahmad Zulkifli', 'nickname' => 'Fahmi']);
        $other = User::factory()->create(['name' => 'Siti Aminah']);

        Livewire::test(ListUsers::class)
            ->searchTable('fahmi')
            ->assertCanSeeTableRecords([$nicknamed])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_fee_payments_search_matches_nickname(): void
    {
        $nicknamed = User::factory()->create(['name' => 'Ahmad Zulkifli', 'nickname' => 'Fahmi']);
        $other = User::factory()->create(['name' => 'Siti Aminah']);
        $proof = $this->proof($nicknamed);
        $otherProof = $this->proof($other);

        Livewire::test(ListFeePayments::class)
            ->searchTable('fahmi')
            ->assertCanSeeTableRecords([$proof])
            ->assertCanNotSeeTableRecords([$otherProof]);
    }

    public function test_registrations_show_nickname(): void
    {
        $activity = Activity::factory()->create(['status' => 'published', 'starts_at' => now()->addWeek()]);
        $member = User::factory()->create(['name' => 'Ahmad Zulkifli', 'nickname' => 'Fahmi']);
        ActivityRegistration::factory()->create([
            'activity_id' => $activity->id,
            'user_id' => $member->id,
            'status' => 'registered',
        ]);

        Livewire::test(RegistrationsRelationManager::class, [
            'ownerRecord' => $activity,
            'pageClass' => EditActivity::class,
        ])
            ->assertSee('Fahmi')
            ->assertSee('Ahmad Zulkifli');
    }

    public function test_fee_period_member_option_label(): void
    {
        $this->assertSame('Fahmi (Ahmad Zulkifli)', MemberName::optionLabel(
            new User(['name' => 'Ahmad Zulkifli', 'nickname' => 'Fahmi'])
        ));
        $this->assertSame('Ahmad Zulkifli', MemberName::optionLabel(new User(['name' => 'Ahmad Zulkifli'])));
    }

    public function test_admin_can_clear_nickname(): void
    {
        $member = User::factory()->create(['nickname' => 'Fahmi']);
        $plain = User::factory()->create();

        Livewire::test(ListUsers::class)
            ->assertTableActionVisible('clearNickname', $member)
            ->assertTableActionHidden('clearNickname', $plain)
            ->callTableAction('clearNickname', $member);

        $this->assertNull($member->fresh()->nickname);
    }

    public function test_finance_summary_description_includes_full_name_when_nicknamed(): void
    {
        $category = MemberCategory::create(['name' => 'Grade 7-10', 'default_fee' => 100]);
        $member = User::factory()->create([
            'name' => 'Ahmad Zulkifli',
            'nickname' => 'Fahmi',
            'member_category_id' => $category->id,
        ]);
        FeePeriod::create([
            'user_id' => $member->id,
            'month_date' => '2026-01-01',
            'amount_due' => 50,
            'status' => 'unpaid',
        ]);

        Livewire::test(FinanceSummary::class)
            ->assertSee('Fahmi')
            ->assertSee('Ahmad Zulkifli · Grade 7-10', escape: false)
            ->searchTable('fahmi')
            ->assertCanSeeTableRecords([$member]);
    }

    private function proof(User $user): PaymentProof
    {
        return PaymentProof::create([
            'user_id' => $user->id,
            'proof_image_path' => 'r.jpg',
            'amount_claimed' => 20,
            'status' => 'pending',
        ]);
    }
}

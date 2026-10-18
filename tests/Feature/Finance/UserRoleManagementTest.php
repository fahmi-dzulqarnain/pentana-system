<?php

namespace Tests\Feature\Finance;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\MemberCategory;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private MemberCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('account'));
        $this->category = MemberCategory::create(['name' => 'Grade 7-10', 'default_fee' => 100]);
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->create(['member_category_id' => $this->category->id] + $attributes);
    }

    public function test_super_admin_sees_and_can_set_role_toggles(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $member = $this->member();

        Livewire::test(EditUser::class, ['record' => $member->getKey()])
            ->assertFormFieldVisible('is_treasurer')
            ->assertFormFieldVisible('is_super_admin')
            ->fillForm(['is_treasurer' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($member->refresh()->is_treasurer);
    }

    public function test_plain_admin_cannot_grant_roles_even_by_crafted_payload(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $member = $this->member();

        Livewire::test(EditUser::class, ['record' => $member->getKey()])
            ->assertFormFieldHidden('is_treasurer')
            ->set('data.is_treasurer', true)
            ->set('data.is_super_admin', true)
            ->call('save');

        $member->refresh();
        $this->assertFalse($member->is_treasurer);
        $this->assertFalse($member->is_super_admin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Newbie',
                'email' => 'newbie@example.com',
                'password' => 'secret-pass-123',
                'member_category_id' => $this->category->id,
            ])
            ->set('data.is_treasurer', true)
            ->set('data.is_super_admin', true)
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'newbie@example.com')->firstOrFail();
        $this->assertFalse($created->is_treasurer);
        $this->assertFalse($created->is_super_admin);
    }

    public function test_plain_admin_cannot_change_a_treasurers_password_email_or_admin_switch(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $treasurer = User::factory()->treasurer()->create([
            'member_category_id' => $this->category->id,
            'name' => 'Old',
            'email' => 'treasurer@example.com',
        ]);
        $hash = $treasurer->password;

        Livewire::test(EditUser::class, ['record' => $treasurer->getKey()])
            ->set('data.name', 'New')
            ->set('data.email', 'hijack@example.com')
            ->set('data.password', 'hijacked-pass-123')
            ->set('data.is_admin', false)
            ->call('save');

        $treasurer->refresh();
        $this->assertSame('New', $treasurer->name);
        $this->assertSame('treasurer@example.com', $treasurer->email);
        $this->assertSame($hash, $treasurer->password);
        $this->assertFalse(Hash::check('hijacked-pass-123', $treasurer->password));
        $this->assertTrue($treasurer->is_admin);
        $this->assertTrue($treasurer->is_treasurer);
    }

    public function test_plain_admin_cannot_delete_a_treasurer(): void
    {
        $treasurer = User::factory()->treasurer()->create(['member_category_id' => $this->category->id]);
        $member = $this->member();

        $this->actingAs(User::factory()->admin()->create());
        $this->assertFalse(UserResource::canDelete($treasurer));
        $this->assertTrue(UserResource::canDelete($member));

        Livewire::test(ListUsers::class)
            ->selectTableRecords([$treasurer->getKey(), $member->getKey()])
            ->callAction(TestAction::make('delete')->table()->bulk());

        $this->assertNotNull($treasurer->fresh());
        $this->assertNull($member->fresh());

        $this->actingAs(User::factory()->superAdmin()->create());
        $this->assertTrue(UserResource::canDelete($treasurer));
    }

    public function test_super_admin_cannot_remove_own_super_admin_or_admin_switch(): void
    {
        $super = User::factory()->superAdmin()->create(['member_category_id' => $this->category->id]);
        $this->actingAs($super);

        Livewire::test(EditUser::class, ['record' => $super->getKey()])
            ->set('data.is_admin', false)
            ->set('data.is_super_admin', false)
            ->call('save');

        $super->refresh();
        $this->assertTrue($super->is_admin);
        $this->assertTrue($super->is_super_admin);
    }

    public function test_users_table_shows_treasurer_and_super_admin_badges_and_filters(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create(['name' => 'Sue Super']));
        $treasurer = User::factory()->treasurer()->create(['name' => 'Tom Treasurer']);
        $admin = User::factory()->admin()->create(['name' => 'Al Admin']);
        $member = User::factory()->create(['name' => 'Mia Member']);

        Livewire::test(ListUsers::class)
            ->assertTableColumnStateSet('account_status', 'super-admin', User::where('name', 'Sue Super')->first())
            ->assertTableColumnStateSet('account_status', 'treasurer', $treasurer)
            ->assertTableColumnStateSet('account_status', 'admin', $admin)
            ->filterTable('account_status', 'treasurer')
            ->assertCanSeeTableRecords([$treasurer])
            ->assertCanNotSeeTableRecords([$admin, $member]);
    }
}

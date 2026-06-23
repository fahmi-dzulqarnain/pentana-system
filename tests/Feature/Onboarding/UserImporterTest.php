<?php

namespace Tests\Feature\Onboarding;

use App\Filament\Imports\UserImporter;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\MemberCategory;
use App\Models\User;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_an_unclaimed_user_and_maps_grade_to_category_case_insensitively(): void
    {
        $category = MemberCategory::create(['name' => 'Grade 7-10', 'default_fee' => 50]);

        $user = UserImporter::buildRecord(['email' => 'new@example.com', 'name' => 'New Member', 'grade' => '  grade 7-10 ']);

        $this->assertSame('new@example.com', $user->email);
        $this->assertSame('New Member', $user->name);
        $this->assertSame($category->id, $user->member_category_id);
        $this->assertNull($user->password);
        $this->assertNull($user->activated_at);
        $this->assertFalse((bool) $user->is_admin);
    }

    public function test_unknown_grade_fails_the_row(): void
    {
        $this->expectException(RowImportFailedException::class);
        UserImporter::buildRecord(['email' => 'x@example.com', 'name' => 'X', 'grade' => 'Grade 99']);
    }

    public function test_existing_email_is_skipped_as_a_failed_row(): void
    {
        MemberCategory::create(['name' => 'Grade 7-10', 'default_fee' => 50]);
        User::factory()->create(['email' => 'dupe@example.com']);

        $this->expectException(RowImportFailedException::class);
        UserImporter::buildRecord(['email' => 'dupe@example.com', 'name' => 'Dupe', 'grade' => 'Grade 7-10']);
    }

    public function test_admin_users_list_renders_with_the_import_action(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertTableActionExists('import');
    }
}

<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UsersTableActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rows_open_the_member_details_without_a_separate_view_button(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));
        $member = User::factory()->create();

        $page = Livewire::test(ListUsers::class)
            ->assertTableActionDoesNotExist('view')
            ->assertTableActionExists('edit');

        $this->assertSame(
            UserResource::getUrl('view', ['record' => $member]),
            $page->instance()->getTable()->getRecordUrl($member),
        );
    }
}

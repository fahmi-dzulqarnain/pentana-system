<?php

namespace Tests\Feature\Birthday;

use App\Filament\Auth\Profile;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BirthdayProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_shows_birthday_read_only(): void
    {
        $user = User::factory()->create(['birthday' => '2000-05-01', 'activated_at' => now()]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(Profile::class)
            ->assertSuccessful()
            ->assertFormFieldExists('birthday')
            ->assertFormFieldIsDisabled('birthday');
    }

    public function test_admin_user_form_has_editable_birthday(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(CreateUser::class)
            ->assertFormFieldExists('birthday')
            ->assertFormFieldIsEnabled('birthday');
    }
}

<?php

namespace Tests\Feature\Onboarding;

use App\Filament\Auth\Profile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PasskeysPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_user_can_open_the_profile_page_with_passkeys(): void
    {
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(Profile::class)
            ->assertSuccessful()
            ->assertSee('Passkeys')
            ->assertSeeLivewire('passkeys');
    }

    public function test_a_guest_is_redirected_from_the_profile_page(): void
    {
        $this->withoutVite();

        $this->get(Profile::getUrl(panel: 'account'))
            ->assertRedirect();
    }
}

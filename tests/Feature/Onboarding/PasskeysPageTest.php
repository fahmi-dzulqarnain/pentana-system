<?php

namespace Tests\Feature\Onboarding;

use App\Filament\Pages\Passkeys;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PasskeysPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_user_can_open_the_passkeys_page(): void
    {
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(Passkeys::class)->assertSuccessful();
    }

    public function test_a_guest_cannot_access_the_passkeys_page(): void
    {
        $this->assertFalse(Passkeys::canAccess());
    }
}

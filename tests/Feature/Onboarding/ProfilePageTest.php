<?php

namespace Tests\Feature\Onboarding;

use App\Filament\Auth\Profile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\LaravelPasskeys\Models\Passkey;
use Tests\TestCase;

class ProfilePageTest extends TestCase
{
    use RefreshDatabase;

    private function profile(User $user): string
    {
        return $this->actingAs($user)->get('/account/profile')->assertOk()->getContent();
    }

    public function test_the_page_does_not_grab_focus_on_load(): void
    {
        $this->assertStringNotContainsString('autofocus', $this->profile(User::factory()->create()));
    }

    public function test_the_birthday_is_plain_text_not_a_date_picker(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('account'));

        $this->actingAs(User::factory()->create(['birthday' => '2002-01-13']));
        Livewire::test(Profile::class)->assertSet('data.birthday', '13 Jan 2002');

        $this->actingAs(User::factory()->create(['birthday' => null]));
        $html = Livewire::test(Profile::class)->assertSet('data.birthday', null)->html();
        $this->assertStringContainsString('placeholder="Not set"', $html);
        // The JS date picker rendered an empty box until its script loaded.
        $this->assertStringNotContainsString('fi-fo-date-time-picker', $html);
    }

    public function test_passkeys_use_device_wording_and_a_quiet_confirmed_remove(): void
    {
        $user = User::factory()->create();
        Passkey::factory()->create(['authenticatable_id' => $user->id, 'name' => 'Work iPhone', 'last_used_at' => null]);
        Passkey::factory()->create(['authenticatable_id' => $user->id, 'name' => 'MacBook', 'last_used_at' => now()->subMonths(3)]);

        $html = $this->profile($user);

        $this->assertStringContainsString('Device name', $html);
        $this->assertStringContainsString('Add passkey', $html);
        $this->assertStringContainsString('Never used', $html);
        $this->assertStringContainsString('Last used 3 months ago', $html);
        $this->assertStringNotContainsString('Last used: ', $html);
        $this->assertStringContainsString('fi-outlined', $html);
        $this->assertStringContainsString('wire:confirm="Remove the passkey “Work iPhone”?', $html);
    }

    public function test_a_passkey_name_cannot_inject_markup_into_the_confirmation(): void
    {
        $user = User::factory()->create();
        Passkey::factory()->create(['authenticatable_id' => $user->id, 'name' => '"><script>x</script>']);

        $html = $this->profile($user);

        $this->assertStringNotContainsString('<script>x</script>', $html);
    }
}

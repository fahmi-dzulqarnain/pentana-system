<?php

namespace Tests\Feature\Localisation;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Tests\TestCase;

class SyncLocaleOnLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locales' => 'en,ms,id']);
    }

    private function login(User $user, mixed $cookie): void
    {
        $this->app['request']->cookies->set('locale', $cookie);
        event(new Login('web', $user, false));
    }

    public function test_account_locale_wins_and_is_queued_as_the_cookie(): void
    {
        $user = User::factory()->create(['locale' => 'id']);

        $this->login($user, 'ms');

        $this->assertSame('id', Cookie::queued('locale')?->getValue());
        $this->assertSame('id', $user->fresh()->locale);
    }

    public function test_account_without_locale_adopts_the_device_cookie(): void
    {
        $user = User::factory()->create(['locale' => null]);

        $this->login($user, 'ms');

        $this->assertSame('ms', $user->fresh()->locale);
        $this->assertNull(Cookie::queued('locale'));
    }

    public function test_nothing_to_sync_leaves_both_alone(): void
    {
        $user = User::factory()->create(['locale' => null]);

        $this->login($user, 'xx');

        $this->assertNull($user->fresh()->locale);
        $this->assertNull(Cookie::queued('locale'));
    }

    public function test_array_shaped_cookie_is_ignored(): void
    {
        $user = User::factory()->create(['locale' => null]);

        $this->login($user, ['x' => '1']);

        $this->assertNull($user->fresh()->locale);
        $this->assertNull(Cookie::queued('locale'));
    }

    public function test_real_filament_login_triggers_sync(): void
    {
        $user = User::factory()->create(['locale' => 'id', 'activated_at' => now(), 'password' => bcrypt('secret-pass')]);

        \Livewire\Livewire::test(\App\Filament\Auth\Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'secret-pass'])
            ->call('authenticate');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('id', Cookie::queued('locale')?->getValue());
    }
}

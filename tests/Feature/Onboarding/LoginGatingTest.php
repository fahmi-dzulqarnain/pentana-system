<?php

namespace Tests\Feature\Onboarding;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LoginGatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unclaimed_user_cannot_authenticate_by_password(): void
    {
        User::factory()->create(['email' => 'pending@example.com', 'password' => null, 'activated_at' => null]);
        $this->assertFalse(Auth::attempt(['email' => 'pending@example.com', 'password' => 'anything']));
    }

    public function test_an_activated_user_can_authenticate(): void
    {
        // The User model's 'password' => 'hashed' cast auto-hashes the plain string on
        // factory create; Auth::attempt then calls password_verify() against that hash.
        User::factory()->create(['email' => 'ok@example.com', 'password' => 'secret-password', 'activated_at' => now()]);
        $this->assertTrue(Auth::attempt(['email' => 'ok@example.com', 'password' => 'secret-password']));
    }

    public function test_the_login_page_links_to_the_claim_flow(): void
    {
        $this->get('/account/login')->assertSee('Claim your account')->assertSee('/claim');
    }

    public function test_the_login_page_shows_the_passkey_sign_in_option(): void
    {
        // Spatie's <x-authenticate-passkey> renders its default label
        // "Authenticate using Passkey" (passkeys::passkeys.authenticate_using_passkey)
        // plus a hidden POST form to route('passkeys.login'). Asserting on both
        // proves the component (not just the claim link) is on the login page.
        $this->get('/account/login')
            ->assertSuccessful()
            ->assertSee('Authenticate using Passkey')
            ->assertSee('passkey-login-form');
    }

    public function test_the_passkey_authentication_options_route_is_registered(): void
    {
        // Proves Route::passkeys() is wired (the package does not auto-register
        // it). We only assert the route resolves — a registered route returns
        // anything but 404; the WebAuthn ceremony itself is smoke-tested later.
        $this->get(route('passkeys.authentication_options'))->assertStatus(200);
    }
}

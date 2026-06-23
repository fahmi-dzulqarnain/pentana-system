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
        User::factory()->create(['email' => 'ok@example.com', 'password' => 'secret-password', 'activated_at' => now()]);
        $this->assertTrue(Auth::attempt(['email' => 'ok@example.com', 'password' => 'secret-password']));
    }

    public function test_the_login_page_links_to_the_claim_flow(): void
    {
        $this->get('/account/login')->assertSee('Claim your account')->assertSee('/claim');
    }
}

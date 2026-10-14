<?php

namespace Tests\Feature\Localisation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChooseLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locales' => 'en,ms,id']);
    }

    public function test_guest_choice_sets_a_plain_year_long_cookie_and_redirects_back(): void
    {
        $response = $this->from('/privacy')->post('/locale', ['locale' => 'ms']);

        $response->assertStatus(303)->assertRedirect('/privacy');
        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'locale');
        $this->assertSame('ms', $cookie->getValue());
        $this->assertFalse($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower($cookie->getSameSite()));
        $this->assertGreaterThan(now()->addDays(360)->timestamp, $cookie->getExpiresTime());
    }

    public function test_signed_in_choice_is_saved_to_the_account(): void
    {
        $user = User::factory()->create(['locale' => null]);

        $this->actingAs($user)->from('/account')->post('/locale', ['locale' => 'id'])->assertStatus(303);

        $this->assertSame('id', $user->fresh()->locale);
    }

    public function test_unknown_or_disabled_locale_is_rejected_and_changes_nothing(): void
    {
        config(['app.locales' => 'en,id']);
        $user = User::factory()->create(['locale' => 'id']);

        foreach (['my', 'ms', ''] as $bad) {
            $this->actingAs($user)->from('/')->post('/locale', ['locale' => $bad])
                ->assertSessionHasErrors('locale')
                ->assertCookieMissing('locale');
        }
        $this->assertSame('id', $user->fresh()->locale);
    }

    public function test_route_is_csrf_protected(): void
    {
        // Laravel skips CSRF checks in tests, so assert the route sits in the `web`
        // group, which applies VerifyCsrfToken to real requests.
        $this->assertContains('web', app('router')->getRoutes()->getByName('locale.update')->gatherMiddleware());
    }
}

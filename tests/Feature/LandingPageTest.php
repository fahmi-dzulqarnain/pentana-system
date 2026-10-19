<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_renders_the_key_entry_points(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('PENTANA');

        // Primary member action → the claim flow.
        $response->assertSee('Claim your account');
        $response->assertSee(route('claim'), false);

        // Sign-in → the Account (Filament) panel.
        $response->assertSee(url('/account'), false);

        // Feature highlights + the "how it works" steps.
        $response->assertSee('Lunch');
        $response->assertSee('Activities');
        $response->assertSee('How it works');

        // Dark-mode toggle.
        $response->assertSee('Toggle dark mode');
    }

    public function test_landing_page_links_to_the_live_app_store_listing(): void
    {
        $response = $this->get('/');

        $response->assertSee('href="https://apps.apple.com/my/app/pentana/id6784914158"', false);
        $response->assertSee('<meta name="apple-itunes-app" content="app-id=6784914158">', false);
        // Android isn't out yet: no Play link, and the page says so.
        $response->assertDontSee('play.google.com', false);
        $response->assertSee('Coming soon to Android');
    }
}

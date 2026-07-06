<?php

namespace Tests\Feature;

use Tests\TestCase;

class LandingPageTest extends TestCase
{
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
    }
}

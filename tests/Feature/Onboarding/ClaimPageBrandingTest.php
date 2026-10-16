<?php

namespace Tests\Feature\Onboarding;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaimPageBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_claim_page_says_whose_it_is_and_offers_a_way_home_and_to_sign_in(): void
    {
        $this->get('/claim')
            ->assertOk()
            ->assertSee('<title>Activate your account · PENTANA</title>', false)
            ->assertSee('class="guest-header"', false)
            ->assertSee('images/pentana-mark.png', false)
            ->assertSee('href="/" aria-label="PENTANA home"', false)
            ->assertSee('Next, you will confirm your details and choose a password.')
            ->assertSee('Already activated your account?')
            ->assertSee('href="/account/login"', false);
    }

    public function test_the_language_switcher_lives_in_the_header_when_enabled(): void
    {
        config(['app.locales' => 'en,ms,id']);

        $html = $this->get('/claim')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="guest-header".*?class="guest-locale-switcher"/s', $html);
        $this->assertStringNotContainsString('position:fixed;top:8px;right:8px', $html);
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_policy_is_published(): void
    {
        $response = $this->get(route('privacy'));

        $response->assertOk();
        $response->assertSee('Privacy Policy');
        $response->assertSee('payment-proof');
    }

    public function test_privacy_policy_names_the_club_and_makes_no_compliance_claim(): void
    {
        foreach (['en', 'ms', 'id'] as $locale) {
            $this->withCookie('locale', $locale)->get(route('privacy'))
                ->assertOk()
                ->assertSee('Kelab Sosial &amp; Rekreasi PENTANA', false)
                ->assertDontSee('Pentana Org')
                ->assertDontSee('PDPA')
                ->assertDontSee('Personal Data Protection Act');
        }
    }

    public function test_landing_links_to_the_privacy_policy(): void
    {
        $this->get('/')->assertSee(route('privacy'), false);
    }
}

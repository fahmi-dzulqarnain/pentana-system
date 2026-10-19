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
        $response->assertSee('Personal Data Protection Act');
        $response->assertSee('payment-proof');
    }

    public function test_landing_links_to_the_privacy_policy(): void
    {
        $this->get('/')->assertSee(route('privacy'), false);
    }
}

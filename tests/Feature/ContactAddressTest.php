<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_and_privacy_show_the_real_contact_address(): void
    {
        foreach (['/', '/privacy'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('mailto:hipentana@pentana.silentmode.net', false)
                ->assertDontSee('pentanaorg.my')
                ->assertDontSee('privacy@silentmode.my');
        }
    }
}

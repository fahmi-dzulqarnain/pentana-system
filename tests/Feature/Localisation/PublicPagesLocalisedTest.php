<?php

namespace Tests\Feature\Localisation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesLocalisedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locales' => 'en,ms,id']);
    }

    public function test_landing_renders_in_each_language(): void
    {
        $this->withUnencryptedCookie('locale', 'ms')->get('/')
            ->assertSee('lang="ms"', false)
            ->assertSee(__('Claim your account', [], 'ms'))
            ->assertDontSee('>Claim your account<', false);

        $this->withUnencryptedCookie('locale', 'id')->get('/')
            ->assertSee(__('Claim your account', [], 'id'));
    }

    public function test_privacy_and_claim_render_translated_headings(): void
    {
        $this->withUnencryptedCookie('locale', 'id')->get('/privacy')->assertSee(__('Privacy Policy', [], 'id'));
        $this->withUnencryptedCookie('locale', 'ms')->get('/claim')->assertSee(__('Activate your account', [], 'ms'));
    }

    public function test_english_is_unchanged(): void
    {
        $this->get('/')->assertSee('Claim your account')->assertSee('Your dues, lunch and activities, all in one place.');
    }
}

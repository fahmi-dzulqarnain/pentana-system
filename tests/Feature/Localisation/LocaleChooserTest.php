<?php

namespace Tests\Feature\Localisation;

use App\Filament\Auth\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Livewire\Livewire;
use Tests\TestCase;

class LocaleChooserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locales' => 'en,ms,id']);
    }

    public function test_public_pages_ask_when_no_choice_exists(): void
    {
        foreach (['/', '/privacy', '/claim', '/account/login'] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('data-locale-chooser', false)
                ->assertSee('Bahasa Melayu')
                ->assertSee('Bahasa Indonesia');
        }
    }

    public function test_no_chooser_once_chosen_but_switcher_shows(): void
    {
        $this->withUnencryptedCookie('locale', 'ms')->get('/')
            ->assertDontSee('data-locale-chooser', false)
            ->assertSee('data-locale-switcher', false);
    }

    public function test_member_with_saved_locale_is_not_asked_in_the_portal(): void
    {
        $user = User::factory()->create(['locale' => 'id', 'activated_at' => now()]);

        $this->actingAs($user)->get('/account')->assertOk()->assertDontSee('data-locale-chooser', false);
    }

    public function test_everything_hidden_when_only_english_is_enabled(): void
    {
        config(['app.locales' => 'en']);

        $this->get('/')->assertDontSee('data-locale-chooser', false)->assertDontSee('data-locale-switcher', false);
        $this->get('/account/login')->assertDontSee('data-locale-chooser', false);
    }

    public function test_every_page_carries_the_sync_script(): void
    {
        foreach (['/', '/privacy', '/claim', '/account/login'] as $url) {
            $this->get($url)->assertSee('data-locale-sync', false);
        }
    }

    public function test_profile_language_field_saves_and_sets_the_cookie(): void
    {
        $user = User::factory()->create(['locale' => 'en', 'activated_at' => now()]);
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->fillForm(['locale' => 'ms'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('ms', $user->fresh()->locale);
        $this->assertSame('ms', Cookie::queued('locale')?->getValue());
    }
}

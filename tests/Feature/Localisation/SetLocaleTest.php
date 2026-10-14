<?php

namespace Tests\Feature\Localisation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

class SetLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locales' => 'en,ms,id']);

        Route::middleware('web')->get('/_locale-probe', fn () => response()->json([
            'locale' => app()->getLocale(),
            'carbon' => now()->locale,
            'chosen' => View::shared('localeChosen'),
        ]));
    }

    public function test_no_choice_renders_default_and_is_not_chosen(): void
    {
        $this->get('/_locale-probe')->assertJson(['locale' => 'en', 'chosen' => false]);
    }

    public function test_cookie_sets_locale(): void
    {
        $this->withUnencryptedCookie('locale', 'ms')
            ->get('/_locale-probe')
            ->assertJson(['locale' => 'ms', 'carbon' => 'ms', 'chosen' => true]);
    }

    public function test_account_beats_cookie(): void
    {
        $user = User::factory()->create(['locale' => 'id']);

        $this->actingAs($user)->withUnencryptedCookie('locale', 'ms')
            ->get('/_locale-probe')
            ->assertJson(['locale' => 'id', 'chosen' => true]);
    }

    public function test_unknown_cookie_is_ignored(): void
    {
        $this->withUnencryptedCookie('locale', 'my')
            ->get('/_locale-probe')
            ->assertJson(['locale' => 'en', 'chosen' => false]);
    }

    public function test_disabled_stored_locale_falls_back_and_reprompts(): void
    {
        config(['app.locales' => 'en,id']);
        $user = User::factory()->create(['locale' => 'ms']);

        $this->withUnencryptedCookie('locale', 'ms')
            ->get('/_locale-probe')
            ->assertJson(['locale' => 'en', 'chosen' => false]);

        $this->actingAs($user)
            ->get('/_locale-probe')
            ->assertJson(['locale' => 'en', 'chosen' => false]);
    }

    public function test_cookie_is_not_encrypted(): void
    {
        // A raw (unencrypted) cookie must be read as-is; if `locale` were still in
        // EncryptCookies, decryption would fail and the request would render `en`.
        $this->withUnencryptedCookie('locale', 'id')
            ->get('/_locale-probe')
            ->assertJson(['locale' => 'id']);
    }

    public function test_panel_pages_use_the_locale(): void
    {
        $this->withUnencryptedCookie('locale', 'ms')
            ->get('/account/login')
            ->assertOk()
            ->assertSee('lang="ms"', false);
    }

    public function test_api_responses_stay_english_for_a_malay_member(): void
    {
        $user = User::factory()->create(['locale' => 'ms']);

        $this->actingAs($user, 'sanctum')
            ->withUnencryptedCookie('locale', 'ms')
            ->postJson('/api/v1/login', [])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'required'));
    }

    public function test_livewire_update_keeps_the_locale(): void
    {
        Route::middleware('web')->get('/_locale-component', LocaleProbeComponent::class);

        $html = $this->withUnencryptedCookie('locale', 'ms')
            ->get('/_locale-component')
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match('/wire:snapshot="([^"]+)"/', $html, $m), 'No wire:snapshot found in the probe page.');
        $snapshot = html_entity_decode($m[1], ENT_QUOTES);

        // A real Livewire update request (not Livewire::test(), which bypasses routing
        // middleware) must run as `ms` too, or a re-render falls back to English mid-form.
        // The initial GET left `ms` on the shared app instance; reset so only the update request can set it.
        app()->setLocale('en');
        \Carbon\Carbon::setLocale('en');

        $response = $this->withUnencryptedCookie('locale', 'ms')
            ->withHeaders(['X-Livewire' => 'true'])
            ->postJson(Livewire::getUpdateUri(), ['components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [['method' => 'capture', 'params' => [], 'metadata' => []]],
            ]]])
            ->assertOk();

        $this->assertStringContainsString('"updateLocale":"ms"', $response->json('components.0.snapshot'));
    }
}

#[Layout('components.layouts.guest')]
class LocaleProbeComponent extends Component
{
    public string $updateLocale = '';

    public function capture(): void
    {
        $this->updateLocale = app()->getLocale();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

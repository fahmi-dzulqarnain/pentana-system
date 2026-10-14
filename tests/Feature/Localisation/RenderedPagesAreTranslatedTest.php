<?php

namespace Tests\Feature\Localisation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * Renders real pages in ms and id and fails on any app string that has no
 * translation - catching Filament auto-labels and dynamic keys the static scan misses.
 */
class RenderedPagesAreTranslatedTest extends TestCase
{
    use AppTranslationKey;
    use RefreshDatabase;

    /** Guest pages. */
    public static function guestPages(): array
    {
        return ['/', '/privacy', '/claim', '/account/login'];
    }

    /** @return list<string> pages rendered as an activated admin; Tasks 8-10 append here. */
    public static function pages(): array
    {
        return [];
    }

    public function test_pages_have_no_missing_translations(): void
    {
        config(['app.locales' => 'en,ms,id']);
        $this->seedForRendering();
        $admin = User::factory()->create(['is_admin' => true, 'activated_at' => now()]);

        foreach (['ms', 'id'] as $locale) {
            $missing = [];
            Lang::handleMissingKeysUsing(function (string $key, array $replace, ?string $l) use (&$missing, $locale) {
                if (($l ?? app()->getLocale()) === $locale && self::isAppKey($key)) {
                    $missing[$key] = true;
                }

                return $key;
            });

            foreach (self::guestPages() as $url) {
                auth()->logout();
                $this->withUnencryptedCookie('locale', $locale)->get($url)->assertOk();
            }
            $admin->forceFill(['locale' => $locale])->save();
            foreach (self::pages() as $url) {
                $this->actingAs($admin)->get($url)->assertOk();
            }

            $this->assertSame([], array_keys($missing), "Untranslated strings rendered in $locale");
        }

        Lang::handleMissingKeysUsing(null);
    }

    /** Minimal rows so index/edit pages render content, not just empty states. */
    private function seedForRendering(): void
    {
        // Extended in Task 9/10 as admin pages are added.
    }
}

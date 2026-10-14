<?php

namespace Tests\Feature\Localisation;

use App\Models\Activity;
use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\Payment\FeePeriod;
use App\Models\User;
use App\Services\ActivityRegistrationService;
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
        return [
            // Member screens (Task 9)
            '/account',
            '/account/my-lunch',
            '/account/my-activities',
            '/account/lunch-calendar',
            '/account/profile',
        ];
    }

    public function test_pages_have_no_missing_translations(): void
    {
        config(['app.locales' => 'en,ms,id']);
        $admin = User::factory()->create(['is_admin' => true, 'activated_at' => now()]);
        $this->seedForRendering($admin);

        try {
            foreach (['ms', 'id'] as $locale) {
                $missing = [];
                Lang::handleMissingKeysUsing(function (string $key, array $replace, ?string $l) use (&$missing, $locale) {
                    if (($l ?? app()->getLocale()) === $locale && self::isAppKey($key) && ! self::isVendorTranslatedValue($key, $locale)) {
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
        } finally {
            Lang::handleMissingKeysUsing(null);
        }
    }

    /** Minimal rows so index/edit pages render content, not just empty states. */
    private function seedForRendering(User $admin): void
    {
        self::seedMemberContent($admin);
    }

    /**
     * One published lunch with a meal option, one published activity with a registration
     * question (the user registered and answered), and an unpaid bill for $user.
     */
    public static function seedMemberContent(User $user): void
    {
        $lunch = Lunch::factory()->create([
            'date' => today()->addDays(2),
            'deadline' => now()->addDay(),
            'status' => 'published',
        ]);
        LunchOption::factory()->create(['lunch_id' => $lunch->id]);

        $activity = Activity::factory()->create([
            'status' => 'published',
            'capacity' => 10,
            'registration_fields' => [
                ['key' => 'diet', 'label' => 'Dietary needs', 'type' => 'text', 'required' => true, 'options' => []],
            ],
        ]);
        app(ActivityRegistrationService::class)->register($activity, $user, ['diet' => 'None']);
        Activity::factory()->create(['status' => 'published', 'capacity' => 10, 'starts_at' => now()->addWeeks(2)]);

        FeePeriod::create([
            'user_id' => $user->id,
            'month_date' => today()->startOfMonth()->toDateString(),
            'amount_due' => 70,
            'status' => 'unpaid',
        ]);
    }
}

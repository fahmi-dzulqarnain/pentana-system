<?php

namespace Tests\Feature\Localisation;

use App\Models\Activity;
use App\Models\AutoEmail;
use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\Meal;
use App\Models\MealOption;
use App\Models\MemberCategory;
use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentAllocation;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use App\Services\ActivityRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /**
     * Pages rendered as an activated admin; Tasks 8-10 append here.
     * {placeholders} are replaced with the ids of the records seedForRendering() creates.
     *
     * @return list<string>
     */
    public static function pages(): array
    {
        return [
            // Member screens (Task 9)
            '/account',
            '/account/my-lunch',
            '/account/my-activities',
            '/account/lunch-calendar',
            '/account/profile',
            // Admin screens (Task 10)
            '/account/activities',
            '/account/activities/create',
            '/account/activities/{activity}/edit',
            '/account/auto-emails',
            '/account/auto-emails/create',
            '/account/auto-emails/{autoEmail}/edit',
            '/account/caterers',
            '/account/caterers/create',
            '/account/caterers/{caterer}/edit',
            '/account/fee-payments',
            '/account/fee-payments/create',
            '/account/fee-payments/{paymentProof}',
            '/account/fee-payments/{paymentProof}/edit',
            '/account/fee-periods',
            '/account/fee-periods/create',
            '/account/fee-periods/{feePeriod}/edit',
            '/account/lunches',
            '/account/lunches/create',
            '/account/lunches/{lunch}/edit',
            '/account/meal-options',
            '/account/meal-options/create',
            '/account/meal-options/{mealOption}/edit',
            '/account/meals',
            '/account/meals/create',
            '/account/meals/{meal}/edit',
            '/account/member-categories',
            '/account/users',
            '/account/users/create',
            '/account/users/{user}',
            '/account/users/{user}/edit',
        ];
    }

    public function test_pages_have_no_missing_translations(): void
    {
        config(['app.locales' => 'en,ms,id']);
        $admin = User::factory()->create(['is_admin' => true, 'activated_at' => now()]);
        $ids = $this->seedForRendering($admin);
        $urls = array_map(fn (string $url): string => strtr($url, $ids), self::pages());

        try {
            foreach (['ms', 'id'] as $locale) {
                $missing = [];
                $translator = $this->guardMissingKeys($locale, $missing);

                foreach (self::guestPages() as $url) {
                    auth()->logout();
                    $translator->reset();
                    $this->withUnencryptedCookie('locale', $locale)->get($url)->assertOk();
                }
                $admin->forceFill(['locale' => $locale])->save();
                foreach ($urls as $url) {
                    $translator->reset();
                    $this->actingAs($admin)->get($url)->assertOk();
                }

                $this->assertSame([], array_keys($missing), "Untranslated strings rendered in $locale");
            }
        } finally {
            $this->app['translator']->handleMissingKeysUsing(null);
        }
    }

    /**
     * Minimal rows so index/edit pages render content, not just empty states.
     *
     * @return array<string, int> {placeholder} => record id, for pages()
     */
    private function seedForRendering(User $admin): array
    {
        self::seedMemberContent($admin);

        $category = MemberCategory::create(['name' => 'Grade 7-10', 'default_fee' => 70]);
        $admin->forceFill(['member_category_id' => $category->id])->save();

        $meal = Meal::factory()->create();
        $meal->options()->attach(MealOption::factory()->create());

        $proof = PaymentProof::create([
            'user_id' => $admin->id,
            'proof_image_path' => 'receipts/proof.jpg',
            'amount_claimed' => 70,
            'amount_verified' => 70,
            'status' => 'verified',
        ]);
        PaymentAllocation::create([
            'payment_proof_id' => $proof->id,
            'fee_period_id' => FeePeriod::firstOrFail()->id,
            'amount_allocated' => 70,
        ]);

        $autoEmail = AutoEmail::create([
            'type' => 'one_off',
            'subject' => 'Annual dinner',
            'body' => '<p>See you there.</p>',
            'status' => 'scheduled',
            'send_at' => now()->addDay(),
        ]);

        $lunch = Lunch::firstOrFail();

        return [
            '{activity}' => Activity::whereHas('registrations')->firstOrFail()->id,
            '{autoEmail}' => $autoEmail->id,
            '{caterer}' => $lunch->menus()->value('caterer_id'),
            '{paymentProof}' => $proof->id,
            '{feePeriod}' => FeePeriod::firstOrFail()->id,
            '{lunch}' => $lunch->id,
            '{mealOption}' => MealOption::firstOrFail()->id,
            '{meal}' => $meal->id,
            '{user}' => $admin->id,
        ];
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

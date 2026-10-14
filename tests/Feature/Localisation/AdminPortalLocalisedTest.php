<?php

namespace Tests\Feature\Localisation;

use App\Filament\Imports\UserImporter;
use App\Filament\Resources\Activities\Pages\EditActivity;
use App\Filament\Resources\Activities\RelationManagers\RegistrationsRelationManager;
use App\Filament\Resources\Caterers\Pages\EditCaterer;
use App\Filament\Resources\Caterers\RelationManagers\CatererMealsRelationManager;
use App\Models\Activity;
use App\Models\CatererMeal;
use App\Models\User;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPortalLocalisedTest extends TestCase
{
    use AppTranslationKey;
    use RefreshDatabase;

    public function test_admin_navigation_groups_are_translated(): void
    {
        config(['app.locales' => 'en,ms,id']);
        $admin = User::factory()->create(['is_admin' => true, 'locale' => 'id', 'activated_at' => now()]);

        $this->actingAs($admin)->get('/account')
            ->assertOk()
            ->assertSee('Kesejahteraan & Sosial')
            ->assertSee('Akun')
            ->assertSee('Keuangan')
            ->assertDontSee('>Accounts<', false);
    }

    public function test_resource_labels_and_status_badges_follow_the_account_language(): void
    {
        config(['app.locales' => 'en,ms,id']);
        $admin = User::factory()->create(['is_admin' => true, 'locale' => 'ms', 'activated_at' => now()]);
        Activity::factory()->create(['title' => 'Family Day', 'status' => 'published']);

        $this->actingAs($admin)->get('/account/activities')
            ->assertOk()
            ->assertSee('Family Day')                       // admin-authored content is left as typed
            ->assertSee('Diterbitkan')                      // status badge, stored value stays 'published'
            ->assertSee('Pengurusan Aktiviti')              // navigation label
            ->assertDontSee('Activity Management');

        // Filament's page title is built from the translated model label.
        $this->actingAs($admin)->get('/account/activities/create')
            ->assertOk()
            ->assertSee('Cipta Aktiviti');
    }

    public function test_import_summary_is_pluralised_and_translated(): void
    {
        $import = new Import(['total_rows' => 1201, 'successful_rows' => 1200]);

        app()->setLocale('en');
        $this->assertSame('Imported 1,200 members. 1 row skipped or failed.', UserImporter::getCompletedNotificationBody($import));

        app()->setLocale('ms');
        $this->assertSame('1,200 ahli diimport. 1 baris dilangkau atau gagal.', UserImporter::getCompletedNotificationBody($import));
    }

    /**
     * Relation managers are lazy-loaded, so the page-level runtime guard never renders
     * their tables; render them through Livewire with the same missing-key detector.
     */
    public function test_relation_managers_have_no_missing_translations(): void
    {
        config(['app.locales' => 'en,ms,id']);
        $admin = User::factory()->create(['is_admin' => true, 'activated_at' => now()]);
        $activity = Activity::factory()->create(['status' => 'published', 'capacity' => 5]);
        $activity->registrations()->create(['user_id' => $admin->id, 'status' => 'registered', 'registered_at' => now()]);
        $meal = CatererMeal::factory()->create();

        try {
            foreach (['ms', 'id'] as $locale) {
                $missing = [];
                $translator = $this->guardMissingKeys($locale, $missing);
                app()->setLocale($locale);
                $this->actingAs($admin);

                $translator->reset();
                Livewire::test(RegistrationsRelationManager::class, ['ownerRecord' => $activity, 'pageClass' => EditActivity::class])
                    ->assertOk()
                    ->assertSee(__('Registered', [], $locale));
                $translator->reset();
                Livewire::test(CatererMealsRelationManager::class, ['ownerRecord' => $meal->caterer, 'pageClass' => EditCaterer::class])
                    ->assertOk()
                    ->assertSee($meal->name);

                $this->assertSame([], array_keys($missing), "Untranslated strings rendered in $locale");
            }
        } finally {
            $this->app['translator']->handleMissingKeysUsing(null);
        }
    }
}

<?php

namespace Tests\Feature\Localisation;

use App\Filament\Pages\MyLunch;
use App\Filament\Widgets\MyBillsStats;
use App\Filament\Widgets\MyBillsTable;
use App\Filament\Widgets\MyNextActivity;
use App\Models\Lunch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MemberPortalLocalisedTest extends TestCase
{
    use AppTranslationKey;
    use RefreshDatabase;

    public function test_member_dashboard_and_navigation_follow_the_account_language(): void
    {
        config(['app.locales' => 'en,ms,id']);
        $member = User::factory()->create(['locale' => 'ms', 'activated_at' => now()]);

        $this->actingAs($member)->get('/account')
            ->assertOk()
            ->assertSee('lang="ms"', false)
            ->assertSee(__('My Activities', [], 'ms'))
            ->assertDontSee('>My Activities<', false);
    }

    /**
     * The runtime guard renders as an admin, who never sees the member-only widgets
     * (bills, next activity). Render the member screens as a member too; dashboard
     * widgets are lazy-loaded, so they are rendered through Livewire directly.
     */
    public function test_member_only_screens_have_no_missing_translations(): void
    {
        config(['app.locales' => 'en,ms,id']);
        $member = User::factory()->create(['activated_at' => now()]);
        RenderedPagesAreTranslatedTest::seedMemberContent($member);

        try {
            foreach (['ms', 'id'] as $locale) {
                $missing = [];
                $translator = $this->guardMissingKeys($locale, $missing);

                $member->forceFill(['locale' => $locale])->save();
                foreach (['/account', '/account/my-lunch', '/account/my-activities', '/account/profile'] as $url) {
                    $translator->reset();
                    $this->actingAs($member)->get($url)->assertOk();
                }
                app()->setLocale($locale);
                foreach ([MyBillsStats::class, MyBillsTable::class, MyNextActivity::class] as $widget) {
                    $translator->reset();
                    Livewire::test($widget)->assertOk();
                }

                $this->assertSame([], array_keys($missing), "Untranslated strings rendered in $locale");
            }
        } finally {
            $this->app['translator']->handleMissingKeysUsing(null);
        }
    }

    public function test_member_bills_widgets_show_translated_labels(): void
    {
        $member = User::factory()->create(['locale' => 'id', 'activated_at' => now()]);
        RenderedPagesAreTranslatedTest::seedMemberContent($member);
        $this->actingAs($member);
        app()->setLocale('id');

        Livewire::test(MyBillsStats::class)
            ->assertSee('Tunggakan')
            ->assertSee('MYR 70.00')
            ->assertSee('1 tagihan jatuh tempo')
            ->assertDontSee('Outstanding');

        Livewire::test(MyBillsTable::class)
            ->assertSee('Riwayat tagihan')
            ->assertSee('Belum dibayar')
            ->assertSee("MYR\u{00A0}70.00") // ICU's non-breaking space; not "RM" (ms) or "70,00" (id)
            ->assertDontSee('Bill history');
    }

    public function test_service_errors_are_translated_in_portal_notifications(): void
    {
        $member = User::factory()->create(['locale' => 'ms', 'activated_at' => now()]);
        $lunch = Lunch::factory()->create(['status' => 'published', 'date' => today()->addDay(), 'deadline' => now()->subHour()]);
        $this->actingAs($member);
        app()->setLocale('ms');

        Livewire::test(MyLunch::class)
            ->call('notAttending', $lunch->id)
            ->assertNotified('Makan tengah hari ini tidak dibuka untuk pesanan.');
    }

    public function test_login_passkey_button_is_translated(): void
    {
        config(['app.locales' => 'en,ms,id']);

        $this->withUnencryptedCookie('locale', 'ms')->get('/account/login')
            ->assertOk()
            ->assertSee('Sahkan menggunakan kunci laluan')
            ->assertDontSee('Authenticate using Passkey');

        $this->withUnencryptedCookie('locale', 'id')->get('/account/login')
            ->assertOk()
            ->assertSee('Autentikasi menggunakan passkey')
            ->assertDontSee('Authenticate using Passkey');
    }
}

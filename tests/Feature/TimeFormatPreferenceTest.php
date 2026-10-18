<?php

namespace Tests\Feature;

use App\Filament\Auth\Profile;
use App\Models\Activity;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use App\Notifications\ActivityPublished;
use App\Support\TimeFormat;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class TimeFormatPreferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locales' => 'en,ms,id']);
        $this->travelTo('2026-10-16 09:00');
    }

    public function test_the_clock_follows_the_language_unless_the_member_chose_one(): void
    {
        foreach (['en' => 'g:i A', 'ms' => 'g:i A', 'id' => 'H:i'] as $locale => $expected) {
            app()->setLocale($locale);
            $this->assertSame($expected, TimeFormat::time(User::factory()->make(['time_format' => null])), $locale);
        }

        app()->setLocale('id');
        $this->assertSame('g:i A', TimeFormat::time(User::factory()->make(['time_format' => '12h'])));
        app()->setLocale('en');
        $this->assertSame('H:i', TimeFormat::time(User::factory()->make(['time_format' => '24h'])));
    }

    public function test_members_choose_their_clock_in_the_profile(): void
    {
        $member = User::factory()->create(['locale' => 'en', 'activated_at' => now()]);
        $this->actingAs($member);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(Profile::class)
            ->assertSet('data.time_format', 'auto')
            ->set('data.time_format', '24h')
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('24h', $member->fresh()->time_format);

        Livewire::test(Profile::class)->set('data.time_format', 'auto')->call('save');
        $this->assertNull($member->fresh()->time_format);
    }

    public function test_member_pages_use_the_members_clock(): void
    {
        Activity::factory()->create(['status' => 'published', 'starts_at' => '2026-10-24 14:30']);

        $this->actingAs(User::factory()->create(['locale' => 'en', 'time_format' => '24h', 'activated_at' => now()]))
            ->get('/account/my-activities')->assertOk()->assertSee('14:30')->assertDontSee('2:30 PM');

        $this->actingAs(User::factory()->create(['locale' => 'en', 'time_format' => null, 'activated_at' => now()]))
            ->get('/account/my-activities')->assertOk()->assertSee('2:30 PM');
    }

    public function test_admin_tables_use_the_admins_clock(): void
    {
        $this->travelTo('2026-10-16 14:30');
        PaymentProof::create(['user_id' => User::factory()->create()->id, 'proof_image_path' => 'r.jpg', 'amount_claimed' => 30, 'status' => 'pending']);

        $this->actingAs(User::factory()->treasurer()->create(['locale' => 'en', 'time_format' => '12h', 'activated_at' => now()]))
            ->get('/account/fee-payments')->assertOk()->assertSee('16 Oct 2026, 2:30 PM');

        $this->actingAs(User::factory()->treasurer()->create(['locale' => 'id', 'time_format' => null, 'activated_at' => now()]))
            ->get('/account/fee-payments')->assertOk()->assertSee('16 Okt 2026, 14:30');
    }

    public function test_each_recipient_gets_their_own_clock_not_the_senders(): void
    {
        $this->actingAs(User::factory()->treasurer()->create(['time_format' => '24h']));
        $indonesianWhoChose12h = User::factory()->create(['locale' => 'id', 'time_format' => '12h']);
        $indonesianDefault = User::factory()->create(['locale' => 'id', 'time_format' => null]);
        $englishDefault = User::factory()->create(['locale' => 'en', 'time_format' => null]);
        $activity = Activity::factory()->create(['starts_at' => '2026-10-24 14:30', 'location' => null]);

        Notification::send([$indonesianWhoChose12h, $indonesianDefault, $englishDefault], new ActivityPublished($activity));

        $body = fn (User $u) => $u->notifications()->first()->data['body'];
        $this->assertStringEndsWith('2:30 PM', $body($indonesianWhoChose12h));
        $this->assertStringEndsWith('14:30', $body($indonesianDefault));
        $this->assertStringEndsWith('2:30 PM', $body($englishDefault));

        // After the send, the signed-in admin's own clock applies again.
        $this->assertSame('H:i', TimeFormat::time());
    }
}

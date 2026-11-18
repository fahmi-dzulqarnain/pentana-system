<?php

namespace Tests\Feature\Activity;

use App\Filament\Pages\MyActivities;
use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyActivitiesPageTest extends TestCase
{
    use RefreshDatabase;

    private function actAsPanelUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        return $user;
    }

    private function published(array $overrides = []): Activity
    {
        return Activity::factory()->create(array_merge([
            'status' => 'published',
            'starts_at' => now()->addWeek(),
        ], $overrides));
    }

    public function test_any_logged_in_user_can_access(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertTrue(MyActivities::canAccess());
    }

    public function test_lists_upcoming_published_and_registers(): void
    {
        $activity = $this->published(['title' => 'Beach Cleanup', 'capacity' => 5]);
        $user = $this->actAsPanelUser();

        Livewire::test(MyActivities::class)
            ->assertSuccessful()
            ->assertSee('Beach Cleanup')
            ->call('register', $activity->id);

        $this->assertDatabaseHas('activity_registrations', [
            'activity_id' => $activity->id, 'user_id' => $user->id, 'status' => 'registered',
        ]);
    }

    public function test_registering_a_full_activity_waitlists(): void
    {
        $activity = $this->published(['capacity' => 1]);
        ActivityRegistration::factory()->create([
            'activity_id' => $activity->id, 'user_id' => User::factory()->create()->id, 'status' => 'registered',
        ]);
        $user = $this->actAsPanelUser();

        Livewire::test(MyActivities::class)->call('register', $activity->id);

        $this->assertDatabaseHas('activity_registrations', [
            'activity_id' => $activity->id, 'user_id' => $user->id, 'status' => 'waitlisted',
        ]);
    }

    public function test_cancel_marks_the_registration_cancelled(): void
    {
        $activity = $this->published(['capacity' => 5]);
        $user = $this->actAsPanelUser();
        ActivityRegistration::factory()->create([
            'activity_id' => $activity->id, 'user_id' => $user->id, 'status' => 'registered',
        ]);

        Livewire::test(MyActivities::class)->call('cancelRegistration', $activity->id);

        $this->assertDatabaseHas('activity_registrations', [
            'activity_id' => $activity->id, 'user_id' => $user->id, 'status' => 'cancelled',
        ]);
    }

    public function test_cannot_register_a_draft_activity(): void
    {
        $activity = Activity::factory()->create(['status' => 'draft', 'starts_at' => now()->addWeek()]);
        $user = $this->actAsPanelUser();

        Livewire::test(MyActivities::class)->call('register', $activity->id);

        $this->assertDatabaseMissing('activity_registrations', [
            'activity_id' => $activity->id, 'user_id' => $user->id,
        ]);
    }

    public function test_does_not_list_draft_or_past_activities(): void
    {
        $this->published(['title' => 'Upcoming Published']);
        Activity::factory()->create(['status' => 'draft', 'title' => 'Hidden Draft', 'starts_at' => now()->addWeek()]);
        Activity::factory()->create(['status' => 'published', 'title' => 'Old Activity', 'starts_at' => now()->subWeek()]);
        $this->actAsPanelUser();

        Livewire::test(MyActivities::class)
            ->assertSee('Upcoming Published')
            ->assertDontSee('Hidden Draft')
            ->assertDontSee('Old Activity');
    }

    public function test_past_registered_activity_appears_in_history(): void
    {
        $past = Activity::factory()->create(['status' => 'published', 'title' => 'Past Trip', 'starts_at' => now()->subWeek()]);
        $user = $this->actAsPanelUser();
        ActivityRegistration::factory()->create([
            'activity_id' => $past->id, 'user_id' => $user->id, 'status' => 'registered', 'attended' => true,
        ]);

        Livewire::test(MyActivities::class)->assertSee('Past Trip');
    }

    public function test_registering_with_questions_stores_answers(): void
    {
        $activity = $this->published([
            'capacity' => 5,
            'registration_fields' => [
                ['key' => 'diet', 'label' => 'Dietary needs', 'type' => 'text', 'required' => true, 'options' => []],
            ],
        ]);
        $user = $this->actAsPanelUser();

        Livewire::test(MyActivities::class)
            ->call('startRegistration', $activity->id)
            ->set('answers.diet', 'Halal')
            ->call('submitRegistration')
            ->assertHasNoErrors();

        $registration = ActivityRegistration::where(['activity_id' => $activity->id, 'user_id' => $user->id])->first();
        $this->assertSame('registered', $registration->status);
        $this->assertSame('Halal', $registration->answers['diet']);
    }

    public function test_member_sees_their_own_answers_on_the_card(): void
    {
        $activity = $this->published([
            'capacity' => 5,
            'registration_fields' => [
                ['key' => 'diet', 'label' => 'Dietary needs', 'type' => 'text', 'required' => false, 'options' => []],
            ],
        ]);
        $user = $this->actAsPanelUser();
        ActivityRegistration::factory()->create([
            'activity_id' => $activity->id, 'user_id' => $user->id, 'status' => 'registered', 'answers' => ['diet' => 'Halal only'],
        ]);

        Livewire::test(MyActivities::class)->assertSee('Halal only');
    }

    public function test_a_missing_required_answer_blocks_registration(): void
    {
        $activity = $this->published([
            'capacity' => 5,
            'registration_fields' => [
                ['key' => 'diet', 'label' => 'Dietary needs', 'type' => 'text', 'required' => true, 'options' => []],
            ],
        ]);
        $user = $this->actAsPanelUser();

        Livewire::test(MyActivities::class)
            ->call('startRegistration', $activity->id)
            ->call('submitRegistration')
            ->assertHasErrors('answers.diet');

        $this->assertDatabaseMissing('activity_registrations', [
            'activity_id' => $activity->id, 'user_id' => $user->id,
        ]);
    }

    public function test_the_error_names_the_question_not_its_internal_key(): void
    {
        $activity = $this->published([
            'capacity' => 5,
            'registration_fields' => [
                ['key' => 'a1b2-uuid', 'label' => 'Jersey size', 'type' => 'select', 'required' => true, 'options' => ['M']],
            ],
        ]);
        $this->actAsPanelUser();

        $errors = Livewire::test(MyActivities::class)
            ->call('startRegistration', $activity->id)
            ->call('submitRegistration')
            ->errors();

        $message = $errors->first('answers.a1b2-uuid');
        $this->assertStringContainsString('Jersey size', $message);
        $this->assertStringNotContainsString('a1b2-uuid', $message);
    }

    public function test_only_defined_question_keys_are_stored(): void
    {
        $activity = $this->published([
            'capacity' => 5,
            'registration_fields' => [
                ['key' => 'diet', 'label' => 'Dietary needs', 'type' => 'text', 'required' => false, 'options' => []],
            ],
        ]);
        $user = $this->actAsPanelUser();

        // A tampered client could push extra keys into the public `answers` prop.
        Livewire::test(MyActivities::class)
            ->call('startRegistration', $activity->id)
            ->set('answers.diet', 'None')
            ->set('answers.injected', 'evil')
            ->call('submitRegistration')
            ->assertHasNoErrors();

        $registration = ActivityRegistration::where(['activity_id' => $activity->id, 'user_id' => $user->id])->first();
        $this->assertSame(['diet' => 'None'], $registration->answers);
    }

    public function test_the_card_shows_the_overview_not_the_full_description(): void
    {
        $this->published(['overview' => "Tom's short blurb", 'description' => '<p>Long body with the whole programme</p>']);
        $this->actAsPanelUser();

        Livewire::test(MyActivities::class)
            ->assertSee("Tom's short blurb")
            ->assertDontSeeHtml('&amp;#039;')
            ->assertDontSee('Long body with the whole programme')
            ->assertSee(__('View details'));
    }

    public function test_view_details_shows_the_full_description_with_images(): void
    {
        $activity = $this->published([
            'overview' => 'Short blurb',
            'description' => '<p>Long body</p><img src="http://old-host/storage/activity-images/a.jpg" data-id="activity-images/a.jpg" alt="Poster">',
        ]);
        $this->actAsPanelUser();

        Livewire::test(MyActivities::class)
            ->call('viewDetails', $activity->id)
            ->assertSee('Long body')
            ->assertSeeHtml('activity-images/a.jpg')
            ->assertSeeHtml('<img')
            ->assertDontSeeHtml('old-host')
            ->call('closeDetails')
            ->assertDontSee('Long body');
    }

    public function test_details_strip_scripts_and_event_handlers(): void
    {
        $activity = $this->published([
            'description' => '<p>Bring <strong>water</strong>.</p><img src="https://example.com/x.png" onerror="alert(1)"><script>alert(2)</script>',
        ]);
        $this->actAsPanelUser();

        Livewire::test(MyActivities::class)
            ->call('viewDetails', $activity->id)
            ->assertSeeHtml('<strong>water</strong>')
            ->assertSeeHtml('https://example.com/x.png')
            ->assertDontSeeHtml('onerror')
            ->assertDontSeeHtml('alert(2)');
    }

    public function test_no_details_button_without_a_full_description(): void
    {
        $this->published(['overview' => 'Just a blurb', 'description' => null]);
        $this->actAsPanelUser();

        Livewire::test(MyActivities::class)
            ->assertSee('Just a blurb')
            ->assertDontSee(__('View details'));
    }

    public function test_details_of_a_draft_activity_are_not_shown(): void
    {
        $draft = Activity::factory()->create(['status' => 'draft', 'description' => '<p>Secret plans</p>']);
        $this->actAsPanelUser();

        Livewire::test(MyActivities::class)
            ->call('viewDetails', $draft->id)
            ->assertDontSee('Secret plans');
    }
}

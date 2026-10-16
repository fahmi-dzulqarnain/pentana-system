<?php

namespace Tests\Feature\Activity;

use App\Filament\Resources\Activities\Pages\CreateActivity;
use App\Filament\Resources\Activities\Pages\EditActivity;
use App\Filament\Resources\Activities\RelationManagers\RegistrationsRelationManager;
use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\User;
use App\Notifications\ActivityCancelled;
use App\Notifications\ActivityPublished;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ActivityAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));
    }

    private function registration(Activity $activity, string $status): ActivityRegistration
    {
        return ActivityRegistration::factory()->create([
            'activity_id' => $activity->id,
            'user_id' => User::factory()->create()->id,
            'status' => $status,
        ]);
    }

    public function test_admin_can_create_an_activity_via_the_form(): void
    {
        Livewire::test(CreateActivity::class)
            ->fillForm([
                'title' => 'Annual Dinner',
                'starts_at' => now()->addMonth(),
                'capacity' => 50,
                'location' => 'Grand Hall',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('activities', [
            'title' => 'Annual Dinner',
            'capacity' => 50,
            'status' => 'draft',
        ]);
    }

    public function test_registration_deadline_must_be_before_the_start(): void
    {
        Livewire::test(CreateActivity::class)
            ->fillForm([
                'title' => 'Late Reg',
                'starts_at' => now()->addMonth(),
                'registration_closes_at' => now()->addMonth()->addDay(), // after the start
            ])
            ->call('create')
            ->assertHasFormErrors(['registration_closes_at']);
    }

    public function test_registration_deadline_before_the_start_is_accepted(): void
    {
        Livewire::test(CreateActivity::class)
            ->fillForm([
                'title' => 'Good Reg',
                'starts_at' => now()->addMonth(),
                'registration_closes_at' => now()->addWeeks(3), // before the start
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('activities', ['title' => 'Good Reg']);
    }

    public function test_admin_can_attach_registration_questions(): void
    {
        Livewire::test(CreateActivity::class)
            ->fillForm([
                'title' => 'Workshop',
                'starts_at' => now()->addMonth(),
                'registration_fields' => [
                    ['key' => 'diet', 'label' => 'Dietary needs', 'type' => 'text', 'required' => true, 'options' => []],
                    ['key' => 'shirt', 'label' => 'T-shirt size', 'type' => 'select', 'required' => false, 'options' => ['S', 'M', 'L']],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $activity = Activity::where('title', 'Workshop')->firstOrFail();
        $this->assertCount(2, $activity->registration_fields);
        $this->assertSame('Dietary needs', $activity->registration_fields[0]['label']);
        $this->assertSame('select', $activity->registration_fields[1]['type']);
        $this->assertSame(['S', 'M', 'L'], $activity->registration_fields[1]['options']);
    }

    public function test_publish_sets_status_and_notifies_only_activated_members(): void
    {
        Notification::fake();
        $this->withoutDefer();
        $activated = User::factory()->count(2)->create(['activated_at' => now()]);
        $unactivated = User::factory()->create(['activated_at' => null]);
        $activity = Activity::factory()->create(['status' => 'draft', 'starts_at' => now()->addWeek()]);

        Livewire::test(EditActivity::class, ['record' => $activity->getKey()])
            ->callAction('publish')
            ->assertHasNoErrors();

        $this->assertSame('published', $activity->fresh()->status);
        Notification::assertSentTo($activated[0], ActivityPublished::class);
        Notification::assertSentTo($activated[1], ActivityPublished::class);
        Notification::assertNotSentTo($unactivated, ActivityPublished::class);
    }

    public function test_publish_is_hidden_for_past_or_already_published(): void
    {
        $past = Activity::factory()->create(['status' => 'draft', 'starts_at' => now()->subDay()]);
        Livewire::test(EditActivity::class, ['record' => $past->getKey()])
            ->assertActionHidden('publish');

        $published = Activity::factory()->create(['status' => 'published', 'starts_at' => now()->addWeek()]);
        Livewire::test(EditActivity::class, ['record' => $published->getKey()])
            ->assertActionHidden('publish');
    }

    public function test_cancel_sets_status_and_notifies_active_registrants(): void
    {
        Notification::fake();
        $this->withoutDefer();
        $activity = Activity::factory()->create(['status' => 'published', 'starts_at' => now()->addWeek(), 'capacity' => 5]);
        $registered = $this->registration($activity, 'registered');
        $waitlisted = $this->registration($activity, 'waitlisted');
        $alreadyCancelled = $this->registration($activity, 'cancelled');

        Livewire::test(EditActivity::class, ['record' => $activity->getKey()])
            ->callAction('cancelActivity')
            ->assertHasNoErrors();

        $this->assertSame('cancelled', $activity->fresh()->status);
        Notification::assertSentTo($registered->user, ActivityCancelled::class);
        Notification::assertSentTo($waitlisted->user, ActivityCancelled::class);
        Notification::assertNotSentTo($alreadyCancelled->user, ActivityCancelled::class);
    }

    public function test_registrations_panel_lists_registrants_with_an_attended_column(): void
    {
        $activity = Activity::factory()->create(['status' => 'published', 'starts_at' => now()->addWeek()]);
        $registration = $this->registration($activity, 'registered');

        Livewire::test(RegistrationsRelationManager::class, [
            'ownerRecord' => $activity,
            'pageClass' => EditActivity::class,
        ])
            ->assertSuccessful()
            ->assertTableColumnExists('attended')
            ->assertCanSeeTableRecords([$registration]);
    }

    public function test_admin_can_view_a_registrants_answers(): void
    {
        $activity = Activity::factory()->create([
            'status' => 'published',
            'starts_at' => now()->addWeek(),
            'registration_fields' => [
                ['key' => 'diet', 'label' => 'Dietary needs', 'type' => 'text', 'required' => true, 'options' => []],
            ],
        ]);
        $registration = ActivityRegistration::factory()->create([
            'activity_id' => $activity->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'registered',
            'answers' => ['diet' => 'Halal'],
        ]);

        Livewire::test(RegistrationsRelationManager::class, [
            'ownerRecord' => $activity,
            'pageClass' => EditActivity::class,
        ])
            ->assertTableActionExists('viewAnswers')
            ->mountTableAction('viewAnswers', $registration)
            ->assertHasNoErrors();

        // The modal body is an Alpine-rendered view; assert its content renders the answer.
        $html = view('filament.activities.registration-answers', [
            'fields' => $activity->registration_fields,
            'answers' => $registration->answers,
        ])->render();
        $this->assertStringContainsString('Dietary needs', $html);
        $this->assertStringContainsString('Halal', $html);
    }

    public function test_view_answers_is_hidden_when_the_activity_has_no_questions(): void
    {
        $activity = Activity::factory()->create(['status' => 'published', 'starts_at' => now()->addWeek()]);
        $registration = $this->registration($activity, 'registered');

        Livewire::test(RegistrationsRelationManager::class, [
            'ownerRecord' => $activity,
            'pageClass' => EditActivity::class,
        ])->assertTableActionHidden('viewAnswers', $registration);
    }
}

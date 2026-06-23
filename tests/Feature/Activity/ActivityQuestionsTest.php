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

class ActivityQuestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_fields_and_answers_cast_to_arrays(): void
    {
        $activity = Activity::factory()->create([
            'registration_fields' => [
                ['key' => 'k1', 'label' => 'Dietary needs', 'type' => 'text', 'required' => true],
            ],
        ]);

        $this->assertIsArray($activity->fresh()->registration_fields);
        $this->assertSame('Dietary needs', $activity->fresh()->registration_fields[0]['label']);

        $registration = ActivityRegistration::factory()->create([
            'activity_id' => $activity->id,
            'user_id' => User::factory()->create()->id,
            'answers' => ['k1' => 'Vegetarian'],
        ]);

        $this->assertSame('Vegetarian', $registration->fresh()->answers['k1']);
    }

    public function test_has_registration_fields_only_for_a_non_empty_array(): void
    {
        $this->assertFalse(Activity::factory()->create(['registration_fields' => null])->hasRegistrationFields());
        $this->assertFalse(Activity::factory()->create(['registration_fields' => []])->hasRegistrationFields());
        $this->assertTrue(Activity::factory()->create([
            'registration_fields' => [['key' => 'k', 'label' => 'Q', 'type' => 'text', 'required' => false]],
        ])->hasRegistrationFields());
    }

    public function test_member_page_renders_description_as_html(): void
    {
        Activity::factory()->create([
            'status' => 'published',
            'starts_at' => now()->addWeek(),
            'title' => 'Gala',
            'description' => '<p>Hello <strong>World</strong></p>',
        ]);
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(MyActivities::class)->assertSeeHtml('<strong>World</strong>');
    }
}

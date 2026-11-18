<?php

namespace Tests\Feature\Api;

use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityApiTest extends TestCase
{
    use RefreshDatabase;

    private function published(array $attributes = []): Activity
    {
        return Activity::factory()->create(array_merge([
            'status' => 'published',
            'starts_at' => now()->addWeek(),
        ], $attributes));
    }

    public function test_activities_require_authentication(): void
    {
        $this->getJson('/api/v1/activities')->assertStatus(401);
    }

    public function test_it_lists_upcoming_published_activities_with_questions(): void
    {
        $activity = $this->published([
            'title' => 'Beach Cleanup',
            'registration_fields' => [
                ['key' => 'diet', 'label' => 'Dietary needs', 'type' => 'text', 'required' => true, 'options' => []],
            ],
        ]);
        Activity::factory()->create(['status' => 'draft', 'starts_at' => now()->addWeek()]);
        Activity::factory()->create(['status' => 'published', 'starts_at' => now()->subWeek()]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/activities')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $activity->id)
            ->assertJsonPath('data.0.my_status', 'none')
            ->assertJsonPath('data.0.is_open', true)
            ->assertJsonPath('data.0.questions.0.label', 'Dietary needs');
    }

    public function test_register_without_questions(): void
    {
        $activity = $this->published(['capacity' => 5]);
        $member = User::factory()->create();
        Sanctum::actingAs($member);

        $this->postJson("/api/v1/activities/{$activity->id}/register", [])
            ->assertOk()
            ->assertJsonPath('data.my_status', 'registered');

        $this->assertDatabaseHas('activity_registrations', [
            'activity_id' => $activity->id, 'user_id' => $member->id, 'status' => 'registered',
        ]);
    }

    public function test_register_requires_answers_to_required_questions(): void
    {
        $activity = $this->published([
            'registration_fields' => [
                ['key' => 'diet', 'label' => 'Dietary needs', 'type' => 'text', 'required' => true, 'options' => []],
            ],
        ]);
        $member = User::factory()->create();
        Sanctum::actingAs($member);

        $this->postJson("/api/v1/activities/{$activity->id}/register", ['answers' => []])
            ->assertStatus(422);
        $this->assertDatabaseMissing('activity_registrations', ['activity_id' => $activity->id, 'user_id' => $member->id]);
    }

    public function test_register_stores_answers(): void
    {
        $activity = $this->published([
            'registration_fields' => [
                ['key' => 'diet', 'label' => 'Dietary needs', 'type' => 'text', 'required' => true, 'options' => []],
            ],
        ]);
        $member = User::factory()->create();
        Sanctum::actingAs($member);

        $this->postJson("/api/v1/activities/{$activity->id}/register", ['answers' => ['diet' => 'Halal']])
            ->assertOk()
            ->assertJsonPath('data.my_status', 'registered');

        $registration = ActivityRegistration::where(['activity_id' => $activity->id, 'user_id' => $member->id])->first();
        $this->assertSame('Halal', $registration->answers['diet']);
    }

    public function test_register_full_activity_waitlists_with_position(): void
    {
        $activity = $this->published(['capacity' => 1]);
        ActivityRegistration::factory()->create([
            'activity_id' => $activity->id, 'user_id' => User::factory()->create()->id, 'status' => 'registered',
        ]);
        $member = User::factory()->create();
        Sanctum::actingAs($member);

        $this->postJson("/api/v1/activities/{$activity->id}/register", [])
            ->assertOk()
            ->assertJsonPath('data.my_status', 'waitlisted')
            ->assertJsonPath('data.waitlist_position', 1);
    }

    public function test_cancel_registration(): void
    {
        $activity = $this->published(['capacity' => 5]);
        $member = User::factory()->create();
        ActivityRegistration::factory()->create([
            'activity_id' => $activity->id, 'user_id' => $member->id, 'status' => 'registered',
        ]);
        Sanctum::actingAs($member);

        $this->postJson("/api/v1/activities/{$activity->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.my_status', 'none');
    }

    public function test_registering_a_past_activity_is_rejected(): void
    {
        $activity = Activity::factory()->create(['status' => 'published', 'starts_at' => now()->subDay()]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/activities/{$activity->id}/register", [])->assertStatus(422);
    }

    public function test_description_is_the_plain_overview_for_older_app_releases(): void
    {
        $this->published(['overview' => "Tom's day out", 'description' => '<p>The <b>full</b> programme.</p>']);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/activities')
            ->assertOk()
            ->assertJsonPath('data.0.description', "Tom's day out")
            ->assertJsonPath('data.0.overview', "Tom's day out");

        $this->assertStringNotContainsString('&#39;', $response->getContent());
        $this->assertStringNotContainsString('&apos;', $response->getContent());
    }

    public function test_full_description_returns_html_with_current_image_urls(): void
    {
        $this->published([
            'description' => '<p>Hi</p><img src="http://old-host/storage/activity-images/a.jpg" data-id="activity-images/a.jpg">',
        ]);
        Sanctum::actingAs(User::factory()->create());

        $html = $this->getJson('/api/v1/activities')->assertOk()->json('data.0.full_description');

        $this->assertStringContainsString('<p>Hi</p>', $html);
        $this->assertStringContainsString(Storage::disk('public')->url('activity-images/a.jpg'), $html);
        $this->assertStringNotContainsString('old-host', $html);
    }

    public function test_overview_falls_back_to_an_excerpt_of_the_full_description(): void
    {
        $this->published(['overview' => null, 'description' => '<p>Bring water &amp; shoes</p>']);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/activities')
            ->assertOk()
            ->assertJsonPath('data.0.description', 'Bring water & shoes')
            ->assertJsonPath('data.0.overview', 'Bring water & shoes');
    }

    public function test_full_description_is_null_when_the_editor_was_left_empty(): void
    {
        $this->published(['overview' => 'Blurb', 'description' => '<p></p>']);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/activities')->assertOk()->assertJsonPath('data.0.full_description', null);
    }
}

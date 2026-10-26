<?php

namespace Tests\Feature\Api;

use App\Models\Activity;
use App\Models\DeviceToken;
use App\Models\Lunch;
use App\Models\User;
use App\Notifications\ActivityPublished;
use App\Notifications\BillReminder;
use App\Notifications\Channels\ResilientApnChannel;
use App\Notifications\LunchVoteNudge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationFailed;
use Laravel\Sanctum\Sanctum;
use NotificationChannels\Apn\ApnChannel;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_token_routes_require_authentication(): void
    {
        $this->postJson('/api/v1/device-tokens', ['token' => 'abc'])->assertStatus(401);
        $this->deleteJson('/api/v1/device-tokens/abc')->assertStatus(401);
    }

    public function test_member_can_register_a_device_token(): void
    {
        $member = User::factory()->create();
        Sanctum::actingAs($member);

        $this->postJson('/api/v1/device-tokens', ['token' => 'tok-abc', 'platform' => 'ios'])
            ->assertCreated();

        $this->assertDatabaseHas('device_tokens', [
            'token' => 'tok-abc', 'user_id' => $member->id, 'platform' => 'ios',
        ]);
    }

    public function test_registering_an_existing_token_reassigns_it(): void
    {
        $first = User::factory()->create();
        DeviceToken::create(['user_id' => $first->id, 'token' => 'shared-tok']);

        $second = User::factory()->create();
        Sanctum::actingAs($second);
        $this->postJson('/api/v1/device-tokens', ['token' => 'shared-tok'])->assertCreated();

        // Still one row - the device changed hands to whoever signed in.
        $this->assertDatabaseCount('device_tokens', 1);
        $this->assertDatabaseHas('device_tokens', ['token' => 'shared-tok', 'user_id' => $second->id]);
    }

    public function test_member_can_remove_only_their_own_token(): void
    {
        $member = User::factory()->create();
        DeviceToken::create(['user_id' => $member->id, 'token' => 'mine']);
        DeviceToken::create(['user_id' => User::factory()->create()->id, 'token' => 'theirs']);

        Sanctum::actingAs($member);
        $this->deleteJson('/api/v1/device-tokens/mine')->assertOk();
        $this->deleteJson('/api/v1/device-tokens/theirs')->assertOk(); // scoped no-op

        $this->assertDatabaseMissing('device_tokens', ['token' => 'mine']);
        $this->assertDatabaseHas('device_tokens', ['token' => 'theirs']);
    }

    public function test_notification_adds_apn_only_when_configured_and_member_has_a_token(): void
    {
        config(['broadcasting.connections.apn.key_id' => 'KEY123']);
        $activity = Activity::factory()->create(['starts_at' => now()->addWeek(), 'location' => 'HQ', 'title' => 'Hike']);
        $notification = new ActivityPublished($activity);

        $noToken = User::factory()->create();
        $this->assertNotContains(ResilientApnChannel::class, $notification->via($noToken));

        $withToken = User::factory()->create();
        DeviceToken::create(['user_id' => $withToken->id, 'token' => 'dev-tok']);
        $this->assertContains(ResilientApnChannel::class, $notification->via($withToken));

        $message = $notification->toApn($withToken);
        $this->assertSame('New activity: Hike', $message->title);
        $this->assertStringContainsString('HQ', (string) $message->body);
    }

    public function test_no_apn_channel_when_unconfigured_even_with_a_token(): void
    {
        config(['broadcasting.connections.apn.key_id' => null]);
        $activity = Activity::factory()->create(['starts_at' => now()->addWeek()]);
        $withToken = User::factory()->create();
        DeviceToken::create(['user_id' => $withToken->id, 'token' => 'dev-tok']);

        $this->assertNotContains(ResilientApnChannel::class, (new ActivityPublished($activity))->via($withToken));
    }

    public function test_routine_notices_go_by_push_instead_of_email_when_the_member_has_push(): void
    {
        config(['broadcasting.connections.apn.key_id' => 'KEY123']);
        $lunch = Lunch::factory()->create(['date' => today()->addDay()->toDateString(), 'status' => 'published', 'deadline' => now()->addHours(5)]);
        $nudge = new LunchVoteNudge($lunch);

        $noToken = User::factory()->create();
        $this->assertSame(['database', 'mail'], $nudge->via($noToken));

        $withToken = User::factory()->create();
        DeviceToken::create(['user_id' => $withToken->id, 'token' => 'dev-tok']);
        $this->assertSame(['database', ResilientApnChannel::class], $nudge->via($withToken));
    }

    public function test_money_notices_still_email_members_who_have_push(): void
    {
        config(['broadcasting.connections.apn.key_id' => 'KEY123']);
        $withToken = User::factory()->create();
        DeviceToken::create(['user_id' => $withToken->id, 'token' => 'dev-tok']);

        $this->assertContains('mail', (new BillReminder(50.0, 1))->via($withToken));
    }

    public function test_a_dead_token_is_pruned_so_the_member_falls_back_to_email(): void
    {
        $member = User::factory()->create();
        DeviceToken::create(['user_id' => $member->id, 'token' => 'dead-tok']);
        DeviceToken::create(['user_id' => $member->id, 'token' => 'live-tok']);
        $activity = Activity::factory()->create(['starts_at' => now()->addWeek()]);

        event(new NotificationFailed($member, new ActivityPublished($activity), ApnChannel::class, ['id' => 'x', 'token' => 'dead-tok', 'error' => 'Unregistered']));
        event(new NotificationFailed($member, new ActivityPublished($activity), ApnChannel::class, ['id' => 'y', 'token' => 'live-tok', 'error' => 'TooManyRequests']));

        $this->assertDatabaseMissing('device_tokens', ['token' => 'dead-tok']);
        $this->assertDatabaseHas('device_tokens', ['token' => 'live-tok']);
    }
}

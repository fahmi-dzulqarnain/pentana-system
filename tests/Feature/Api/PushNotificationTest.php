<?php

namespace Tests\Feature\Api;

use App\Models\Activity;
use App\Models\DeviceToken;
use App\Models\User;
use App\Notifications\ActivityPublished;
use App\Notifications\Channels\ResilientApnChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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
}

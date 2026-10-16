<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MemberNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use RuntimeException;
use Tests\TestCase;

class MemberNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_delivers_only_after_the_response(): void
    {
        NotificationFacade::fake();
        $users = User::factory()->count(2)->create();

        app(MemberNotifier::class)->sendAfterResponse($users, new PingNotification);

        NotificationFacade::assertNothingSent();
    }

    public function test_one_failing_recipient_does_not_stop_the_rest(): void
    {
        $this->withoutDefer();
        $users = User::factory()->count(3)->create();
        PingNotification::$failFor = $users[1]->id;

        app(MemberNotifier::class)->sendAfterResponse($users, new PingNotification);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $users[0]->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $users[1]->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $users[2]->id]);
    }

    protected function tearDown(): void
    {
        PingNotification::$failFor = null;

        parent::tearDown();
    }
}

class PingNotification extends Notification
{
    public static ?int $failFor = null;

    public function via(object $notifiable): array
    {
        if ($notifiable->id === static::$failFor) {
            throw new RuntimeException('channel down');
        }

        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['ping' => true];
    }
}

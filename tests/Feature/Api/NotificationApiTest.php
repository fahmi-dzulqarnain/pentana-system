<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Notifications\ActivityPublished;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private function notify(User $user, string $title, ?\DateTimeInterface $readAt = null): void
    {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => ActivityPublished::class,
            'data' => ['title' => $title, 'body' => 'Some details'],
            'read_at' => $readAt,
        ]);
    }

    public function test_notifications_require_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
        $this->postJson('/api/v1/notifications/read')->assertStatus(401);
    }

    public function test_it_lists_the_members_notifications_with_unread_count(): void
    {
        $member = User::factory()->create();
        $this->notify($member, 'New activity: Hiking');
        $this->notify($member, 'Already seen', now());

        // Another member's notification must not leak.
        $this->notify(User::factory()->create(), 'Not yours');

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('unread_count', 1)
            ->assertJsonFragment(['title' => 'New activity: Hiking', 'read' => false])
            ->assertJsonFragment(['title' => 'Already seen', 'read' => true]);
    }

    public function test_it_marks_all_notifications_read(): void
    {
        $member = User::factory()->create();
        $this->notify($member, 'One');
        $this->notify($member, 'Two');

        Sanctum::actingAs($member);

        $this->postJson('/api/v1/notifications/read')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, $member->unreadNotifications()->count());
    }
}

<?php

namespace Tests\Feature\Api;

use App\Enums\SupportTicketStatus;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupportTicketApiTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        Notification::fake();
        $this->member = User::factory()->create(['activated_at' => now()]);
        $this->admin = User::factory()->admin()->create(['activated_at' => now(), 'name' => 'Zainal Admin']);
    }

    private function ticketFor(User $owner, array $attributes = []): SupportTicket
    {
        $ticket = SupportTicket::factory()->for($owner)->create($attributes);
        SupportMessage::factory()->for($ticket, 'ticket')->create(['user_id' => $owner->id, 'body' => 'My question']);

        return $ticket;
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/support-tickets')->assertUnauthorized();
    }

    public function test_a_member_lists_only_their_own_tickets_newest_activity_first(): void
    {
        $older = $this->ticketFor($this->member, ['last_message_at' => now()->subDay()]);
        $newer = $this->ticketFor($this->member, ['last_message_at' => now(), 'status' => SupportTicketStatus::WaitingOnMember]);
        $this->ticketFor(User::factory()->create(['activated_at' => now()]));
        Sanctum::actingAs($this->member);

        $this->getJson('/api/v1/support-tickets')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.status', 'waiting_on_member')
            ->assertJsonPath('data.0.type', 'question')
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.1.last_message_from_committee', false);
    }

    public function test_show_returns_the_thread_without_admin_identity(): void
    {
        $ticket = $this->ticketFor($this->member);
        SupportMessage::factory()->for($ticket, 'ticket')
            ->create(['user_id' => $this->admin->id, 'from_committee' => true, 'body' => 'Answer', 'created_at' => now()->addMinute()]);
        Sanctum::actingAs($this->member);

        $response = $this->getJson("/api/v1/support-tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.is_mine', true)
            ->assertJsonPath('data.messages.0.from_committee', false)
            ->assertJsonPath('data.messages.1.from_committee', true)
            ->assertJsonPath('data.messages.1.is_mine', false)
            ->assertJsonPath('data.messages.1.attachment_url', null)
            ->assertJsonPath('data.closed_at', null)
            ->assertDontSee('Zainal');

        $this->assertArrayNotHasKey('user_id', $response->json('data.messages.1'));
        $this->assertArrayNotHasKey('author', $response->json('data.messages.1'));
    }

    public function test_closed_by_committee_distinguishes_admin_close_from_member_resolve(): void
    {
        $byAdmin = $this->ticketFor($this->member, ['status' => SupportTicketStatus::Closed, 'closed_at' => now(), 'closed_by_id' => $this->admin->id]);
        $byMember = $this->ticketFor($this->member, ['status' => SupportTicketStatus::Closed, 'closed_at' => now(), 'closed_by_id' => $this->member->id]);
        Sanctum::actingAs($this->member);

        $this->getJson("/api/v1/support-tickets/{$byAdmin->id}")->assertJsonPath('data.closed_by_committee', true);
        $this->getJson("/api/v1/support-tickets/{$byMember->id}")->assertJsonPath('data.closed_by_committee', false);
    }

    public function test_another_members_ticket_is_not_found(): void
    {
        $others = $this->ticketFor(User::factory()->create(['activated_at' => now()]));
        Sanctum::actingAs($this->member);

        $this->getJson("/api/v1/support-tickets/{$others->id}")->assertNotFound();
    }

    public function test_admins_are_forbidden(): void
    {
        $ticket = $this->ticketFor($this->member);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/support-tickets')->assertForbidden();
        $this->getJson("/api/v1/support-tickets/{$ticket->id}")->assertForbidden();
    }

    public function test_the_screenshot_is_served_to_the_owner_with_nosniff_and_hidden_from_others(): void
    {
        Storage::fake('local');
        $path = UploadedFile::fake()->image('s.png')->store('support', 'local');
        $ticket = SupportTicket::factory()->for($this->member)->create();
        $message = SupportMessage::factory()->for($ticket, 'ticket')
            ->create(['user_id' => $this->member->id, 'attachment_path' => $path]);
        $url = route('api.support-tickets.attachment', $message);

        Sanctum::actingAs($this->member);
        $this->getJson("/api/v1/support-tickets/{$ticket->id}")->assertJsonPath('data.messages.0.attachment_url', $url);
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        Sanctum::actingAs(User::factory()->create(['activated_at' => now()]));
        $this->get($url)->assertNotFound();

        Sanctum::actingAs($this->admin);
        $this->get($url)->assertForbidden();

        $this->member->forceFill(['disabled_at' => now()])->save();
        Sanctum::actingAs($this->member->fresh());
        $this->get($url)->assertNotFound();
    }
}

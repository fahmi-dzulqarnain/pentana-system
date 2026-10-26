<?php

namespace Tests\Feature\Api;

use App\Enums\SupportTicketStatus;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportReplyFromMember;
use App\Notifications\SupportTicketOpened;
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

    private function openTicket(array $fields): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/v1/support-tickets', $fields, ['Accept' => 'application/json']);
    }

    public function test_a_member_opens_a_ticket_with_a_screenshot(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->member);

        $this->openTicket([
            'type' => 'bug', 'subject' => 'Blank page', 'body' => 'The lunch page is blank.',
            'screenshot' => UploadedFile::fake()->image('s.jpg'),
        ])->assertCreated()
            ->assertJsonPath('data.status', 'needs_reply')
            ->assertJsonPath('data.type', 'bug')
            ->assertJsonPath('data.messages.0.body', 'The lunch page is blank.')
            ->assertJsonPath('data.messages.0.is_mine', true);

        $path = SupportTicket::sole()->messages->first()->attachment_path;
        $this->assertStringStartsWith('support/', $path);
        Storage::disk('local')->assertExists($path);
        Notification::assertSentTo($this->admin, SupportTicketOpened::class);
    }

    public function test_a_ticket_without_a_screenshot_is_fine(): void
    {
        Sanctum::actingAs($this->member);

        $this->openTicket(['type' => 'question', 'subject' => 'Trip?', 'body' => 'Can my spouse join?'])
            ->assertCreated()
            ->assertJsonPath('data.messages.0.attachment_url', null);
    }

    public function test_invalid_input_is_rejected(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->member);
        $valid = ['type' => 'question', 'subject' => 'S', 'body' => 'B'];

        foreach ([
            ['subject' => ''],
            ['type' => 'complaint'],
            ['body' => str_repeat('x', 5001)],
            ['subject' => str_repeat('x', 151)],
            ['screenshot' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')],
            ['screenshot' => UploadedFile::fake()->image('big.jpg')->size(6000)],
        ] as $override) {
            $this->openTicket(array_merge($valid, $override))->assertUnprocessable();
        }

        $this->assertSame(0, SupportTicket::count());
    }

    public function test_a_member_replies_and_a_closed_ticket_reopens(): void
    {
        $ticket = $this->ticketFor($this->member, ['status' => SupportTicketStatus::Closed, 'closed_at' => now(), 'closed_by_id' => $this->admin->id]);
        Sanctum::actingAs($this->member);

        $this->postJson("/api/v1/support-tickets/{$ticket->id}/replies", ['body' => 'One more thing'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'One more thing')
            ->assertJsonPath('data.is_mine', true)
            ->assertJsonPath('data.from_committee', false);

        $this->assertSame(SupportTicketStatus::NeedsReply, $ticket->fresh()->status);
        Notification::assertSentTo($this->admin, SupportReplyFromMember::class);
    }

    public function test_a_member_resolves_their_ticket(): void
    {
        $ticket = $this->ticketFor($this->member);
        Sanctum::actingAs($this->member);

        $this->postJson("/api/v1/support-tickets/{$ticket->id}/resolve")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.closed_by_committee', false);
    }

    public function test_writes_on_another_members_ticket_are_not_found_and_change_nothing(): void
    {
        $others = $this->ticketFor(User::factory()->create(['activated_at' => now()]));
        Sanctum::actingAs($this->member);

        $this->postJson("/api/v1/support-tickets/{$others->id}/replies", ['body' => 'Sneaky'])->assertNotFound();
        $this->postJson("/api/v1/support-tickets/{$others->id}/resolve")->assertNotFound();

        $this->assertSame(1, $others->messages()->count());
        $this->assertSame(SupportTicketStatus::NeedsReply, $others->fresh()->status);
    }

    public function test_admins_cannot_use_the_write_endpoints(): void
    {
        $ticket = $this->ticketFor($this->member);
        Sanctum::actingAs($this->admin);

        $this->openTicket(['type' => 'question', 'subject' => 'S', 'body' => 'B'])->assertForbidden();
        $this->postJson("/api/v1/support-tickets/{$ticket->id}/replies", ['body' => 'Hi'])->assertForbidden();
        $this->postJson("/api/v1/support-tickets/{$ticket->id}/resolve")->assertForbidden();
        $this->assertSame(1, SupportTicket::count());
        $this->assertSame(1, $ticket->messages()->count());
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

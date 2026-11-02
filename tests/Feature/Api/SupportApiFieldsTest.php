<?php

namespace Tests\Feature\Api;

use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportReplyFromCommittee;
use App\Notifications\SupportTicketClosed;
use App\Notifications\SupportTicketOpened;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The additive fields the apps use for support tickets on me, dashboard and notifications. */
class SupportApiFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = User::factory()->create(['activated_at' => now()]);
    }

    public function test_me_reports_whether_the_user_is_an_admin(): void
    {
        Sanctum::actingAs($this->member);
        $this->getJson('/api/v1/me')->assertJsonPath('data.is_admin', false);

        Sanctum::actingAs(User::factory()->admin()->create(['activated_at' => now()]));
        $this->getJson('/api/v1/me')->assertJsonPath('data.is_admin', true);
    }

    public function test_dashboard_counts_tickets_with_a_committee_reply_waiting(): void
    {
        foreach ([SupportTicketStatus::WaitingOnMember, SupportTicketStatus::NeedsReply, SupportTicketStatus::Closed] as $status) {
            SupportTicket::factory()->for($this->member)->create(['status' => $status]);
        }
        SupportTicket::factory()->create(['status' => SupportTicketStatus::WaitingOnMember]); // someone else's
        Sanctum::actingAs($this->member);

        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.support_replies_waiting', 1);
    }

    public function test_member_support_notices_carry_the_ticket_id(): void
    {
        $replied = SupportTicket::factory()->for($this->member)->create();
        $closed = SupportTicket::factory()->for($this->member)->create();
        $this->member->notifyNow(new SupportReplyFromCommittee($replied));
        $this->travel(1)->second();
        $this->member->notifyNow(new SupportTicketClosed($closed));
        Sanctum::actingAs($this->member);

        $this->getJson('/api/v1/notifications')
            ->assertJsonPath('data.0.support_ticket_id', $closed->id)
            ->assertJsonPath('data.1.support_ticket_id', $replied->id);
    }

    public function test_admin_support_notices_and_other_notices_have_no_ticket_id(): void
    {
        $admin = User::factory()->admin()->create(['activated_at' => now()]);
        $admin->notifyNow(new SupportTicketOpened(SupportTicket::factory()->for($this->member)->create()));
        $this->member->notifications()->create([
            'id' => (string) str()->uuid(),
            'type' => 'App\\Notifications\\ActivityPublished',
            'data' => ['title' => 'New activity'],
        ]);

        // assertJsonStructure: the key must be present (null), not merely absent.
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/notifications')
            ->assertJsonStructure(['data' => [['support_ticket_id']]])
            ->assertJsonPath('data.0.support_ticket_id', null);

        Sanctum::actingAs($this->member);
        $this->getJson('/api/v1/notifications')
            ->assertJsonStructure(['data' => [['support_ticket_id']]])
            ->assertJsonPath('data.0.support_ticket_id', null);
    }
}

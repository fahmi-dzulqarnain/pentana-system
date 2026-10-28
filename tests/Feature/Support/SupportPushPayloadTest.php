<?php

namespace Tests\Feature\Support;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportReplyFromCommittee;
use App\Notifications\SupportReplyFromMember;
use App\Notifications\SupportTicketClosed;
use App\Notifications\SupportTicketOpened;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportPushPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_facing_support_pushes_carry_ticket_id(): void
    {
        [$member, $ticket] = $this->ticket();
        $message = $this->message($ticket, fromCommittee: true);

        $reply = (new SupportReplyFromCommittee($ticket, $message))->toApn($member);
        $closed = (new SupportTicketClosed($ticket))->toApn($member);

        $this->assertSame($ticket->id, $reply->custom['ticket_id']);
        $this->assertSame($ticket->id, $closed->custom['ticket_id']);
    }

    public function test_admin_facing_support_pushes_carry_no_ticket_id(): void
    {
        [, $ticket] = $this->ticket();
        $admin = User::factory()->create(['activated_at' => now(), 'is_admin' => true]);
        $message = $this->message($ticket, fromCommittee: false);

        $opened = (new SupportTicketOpened($ticket, $message))->toApn($admin);
        $replied = (new SupportReplyFromMember($ticket, $message))->toApn($admin);

        $this->assertArrayNotHasKey('ticket_id', $opened->custom);
        $this->assertArrayNotHasKey('ticket_id', $replied->custom);
    }

    /** @return array{0: User, 1: SupportTicket} */
    private function ticket(): array
    {
        $member = User::factory()->create(['activated_at' => now()]);

        return [$member, SupportTicket::factory()->create(['user_id' => $member->id])];
    }

    private function message(SupportTicket $ticket, bool $fromCommittee): SupportMessage
    {
        return SupportMessage::factory()->create([
            'support_ticket_id' => $ticket->id,
            'from_committee' => $fromCommittee,
        ]);
    }
}

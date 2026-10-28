<?php

namespace Tests\Feature\Support;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportReplyFromCommittee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportPushPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_push_carries_ticket_id(): void
    {
        $member = User::factory()->create(['activated_at' => now()]);
        $ticket = SupportTicket::factory()->create(['user_id' => $member->id]);
        $message = SupportMessage::factory()->create([
            'support_ticket_id' => $ticket->id,
            'from_committee' => true,
        ]);

        $apn = (new SupportReplyFromCommittee($ticket, $message))->toApn($member);

        $this->assertSame($ticket->id, $apn->custom['ticket_id']);
    }
}

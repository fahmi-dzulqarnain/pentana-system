<?php

namespace Tests\Feature\Support;

use App\Enums\SupportTicketStatus;
use App\Enums\SupportTicketType;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupportTicketModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_ticket_keeps_its_messages_in_order_and_casts_enums(): void
    {
        $ticket = SupportTicket::factory()->create();
        SupportMessage::factory()->for($ticket, 'ticket')->create(['body' => 'second']);
        SupportMessage::factory()->for($ticket, 'ticket')->create(['created_at' => now()->subHour(), 'body' => 'first']);

        $this->assertSame(['first', 'second'], $ticket->messages->pluck('body')->all());
        $this->assertSame(SupportTicketStatus::NeedsReply, $ticket->fresh()->status);
        $this->assertSame(SupportTicketType::Question, $ticket->fresh()->type);
    }

    public function test_needs_reply_scope_only_returns_tickets_awaiting_the_committee(): void
    {
        SupportTicket::factory()->create(['status' => SupportTicketStatus::NeedsReply]);
        SupportTicket::factory()->create(['status' => SupportTicketStatus::WaitingOnMember]);
        SupportTicket::factory()->create(['status' => SupportTicketStatus::Closed]);

        $this->assertSame(1, SupportTicket::needsReply()->count());
    }

    public function test_the_lookups_every_screen_makes_are_indexed(): void
    {
        // Postgres doesn't index foreign keys on its own; the member list and the thread filter on these.
        $indexed = fn (string $table, string $column): bool => collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['columns'][0] ?? null) === $column);

        $this->assertTrue($indexed('support_tickets', 'user_id'));
        $this->assertTrue($indexed('support_messages', 'support_ticket_id'));
    }

    public function test_a_member_has_their_support_tickets(): void
    {
        $ticket = SupportTicket::factory()->create();

        $this->assertTrue($ticket->user->supportTickets->contains($ticket));
    }
}

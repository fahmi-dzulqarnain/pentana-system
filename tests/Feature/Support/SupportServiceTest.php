<?php

namespace Tests\Feature\Support;

use App\Enums\SupportTicketStatus;
use App\Enums\SupportTicketType;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportReplyFromCommittee;
use App\Notifications\SupportReplyFromMember;
use App\Notifications\SupportTicketClosed;
use App\Notifications\SupportTicketOpened;
use App\Services\SupportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SupportServiceTest extends TestCase
{
    use RefreshDatabase;

    private SupportService $support;

    private User $member;

    private User $adminA;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        Notification::fake();
        $this->support = app(SupportService::class);
        $this->member = User::factory()->create(['activated_at' => now()]);
        $this->adminA = User::factory()->admin()->create(['activated_at' => now()]);
        $this->adminB = User::factory()->admin()->create(['activated_at' => now()]);
    }

    private function openTicket(): SupportTicket
    {
        $ticket = $this->support->open($this->member, SupportTicketType::Question, 'Paid twice?', 'I was charged twice.');
        Notification::fake(); // forget the "opened" notices so each test sees only its own action

        return $ticket;
    }

    public function test_opening_a_ticket_stores_the_first_message_and_notifies_every_enabled_admin(): void
    {
        $disabledAdmin = User::factory()->admin()->create(['disabled_at' => now()]);
        $otherMember = User::factory()->create(['activated_at' => now()]);

        $ticket = $this->support->open($this->member, SupportTicketType::Bug, 'Blank page', 'Lunch page is blank.', 'support/s.png');

        $this->assertSame(SupportTicketStatus::NeedsReply, $ticket->status);
        $this->assertSame(SupportTicketType::Bug, $ticket->type);
        $this->assertCount(1, $ticket->messages);
        $this->assertFalse($ticket->messages->first()->from_committee);
        $this->assertSame('support/s.png', $ticket->messages->first()->attachment_path);
        Notification::assertSentTo([$this->adminA, $this->adminB], SupportTicketOpened::class);
        Notification::assertNotSentTo([$disabledAdmin, $otherMember, $this->member], SupportTicketOpened::class);
    }

    public function test_a_committee_reply_moves_the_ticket_to_waiting_on_member_and_notifies_only_the_owner(): void
    {
        $ticket = $this->openTicket();

        $message = $this->support->reply($ticket, $this->adminA, 'Refunded to credit.');

        $this->assertTrue($message->from_committee);
        $this->assertSame(SupportTicketStatus::WaitingOnMember, $ticket->fresh()->status);
        Notification::assertSentTo($this->member, SupportReplyFromCommittee::class);
        Notification::assertNotSentTo([$this->adminA, $this->adminB], SupportReplyFromCommittee::class);
    }

    public function test_reply_and_close_closes_the_ticket_and_sends_a_single_notification(): void
    {
        $ticket = $this->openTicket();

        $this->support->reply($ticket, $this->adminA, 'Done.', close: true);

        $ticket->refresh();
        $this->assertSame(SupportTicketStatus::Closed, $ticket->status);
        $this->assertSame($this->adminA->id, $ticket->closed_by_id);
        $this->assertNotNull($ticket->closed_at);
        Notification::assertSentToTimes($this->member, SupportReplyFromCommittee::class, 1);
        Notification::assertNotSentTo($this->member, SupportTicketClosed::class);
    }

    public function test_a_member_reply_on_a_closed_ticket_reopens_it_and_notifies_admins(): void
    {
        $ticket = $this->openTicket();
        $this->support->close($ticket, $this->adminA);
        Notification::fake();

        $message = $this->support->reply($ticket, $this->member, 'Actually, one more thing.');

        $ticket->refresh();
        $this->assertFalse($message->from_committee);
        $this->assertSame(SupportTicketStatus::NeedsReply, $ticket->status);
        $this->assertNull($ticket->closed_at);
        $this->assertNull($ticket->closed_by_id);
        Notification::assertSentTo([$this->adminA, $this->adminB], SupportReplyFromMember::class);
    }

    public function test_close_notifies_the_member_and_reopen_notifies_nobody(): void
    {
        $ticket = $this->openTicket();

        $this->support->close($ticket, $this->adminA);
        Notification::assertSentTo($this->member, SupportTicketClosed::class);
        $this->assertSame(SupportTicketStatus::Closed, $ticket->fresh()->status);

        Notification::fake();
        $this->support->reopen($ticket, $this->adminB);
        Notification::assertNothingSent();
        $ticket->refresh();
        $this->assertSame(SupportTicketStatus::NeedsReply, $ticket->status);
        $this->assertNull($ticket->closed_at);
    }

    public function test_a_member_marking_resolved_closes_without_notifying_anyone(): void
    {
        $ticket = $this->openTicket();

        $this->support->resolveByMember($ticket, $this->member);

        $ticket->refresh();
        $this->assertSame(SupportTicketStatus::Closed, $ticket->status);
        $this->assertSame($this->member->id, $ticket->closed_by_id);
        Notification::assertNothingSent();
    }

    public function test_the_acting_admin_is_not_notified_and_a_disabled_member_gets_nothing(): void
    {
        $ticket = $this->openTicket();
        $this->member->forceFill(['disabled_at' => now()])->save();

        $this->support->reply($ticket, $this->adminA, 'Hello?');
        $this->support->close($ticket, $this->adminA);

        Notification::assertNothingSent();
    }

    public function test_actors_outside_their_role_are_refused(): void
    {
        $ticket = $this->openTicket();
        $otherMember = User::factory()->create(['activated_at' => now()]);

        $refused = 0;
        foreach ([
            fn () => $this->support->open($this->adminA, SupportTicketType::Question, 'x', 'y'),
            fn () => $this->support->reply($ticket, $otherMember, 'Sneaky'),
            fn () => $this->support->close($ticket, $this->member),
            fn () => $this->support->reopen($ticket, $this->member),
            fn () => $this->support->resolveByMember($ticket, $otherMember),
            fn () => $this->support->resolveByMember($ticket, $this->adminA),
            fn () => $this->support->reply($ticket, $this->member, 'closing', close: true),
        ] as $call) {
            try {
                $call();
            } catch (AuthorizationException) {
                $refused++;
            }
        }

        $this->assertSame(7, $refused);
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertSame(1, SupportTicket::count());
    }

    public function test_an_attachment_path_outside_the_support_folder_is_refused(): void
    {
        foreach (['android/app.apk', 'livewire-tmp/x.png', 'support/../android/app.apk', 'support/a/b.png', '/support/x.png'] as $path) {
            try {
                $this->support->open($this->member, SupportTicketType::Bug, 'x', 'y', $path);
                $this->fail("Accepted attachment path [$path]");
            } catch (\InvalidArgumentException) {
                // refused, as it should be
            }
        }

        $this->assertSame(0, SupportTicket::count());
    }

    public function test_closing_an_already_closed_ticket_changes_nothing_and_notifies_nobody(): void
    {
        $ticket = $this->openTicket();
        $this->travelTo(now()->subDay());
        $this->support->close($ticket, $this->adminA);
        $this->travelBack();
        Notification::fake();

        $this->support->close($ticket->fresh(), $this->adminB);

        $ticket->refresh();
        $this->assertSame($this->adminA->id, $ticket->closed_by_id);
        $this->assertTrue($ticket->closed_at->isBefore(now()->subHours(12)));
        Notification::assertNothingSent();
    }

    public function test_resolving_a_ticket_the_committee_already_closed_keeps_the_committee_close(): void
    {
        $ticket = $this->openTicket();
        $this->travelTo(now()->subDay());
        $this->support->close($ticket, $this->adminA);
        $this->travelBack();

        $this->support->resolveByMember($ticket->fresh(), $this->member);

        $ticket->refresh();
        $this->assertSame($this->adminA->id, $ticket->closed_by_id);
        $this->assertTrue($ticket->closed_at->isBefore(now()->subHours(12)));
    }

    public function test_a_committee_reply_on_a_closed_ticket_reopens_it_as_waiting_on_member(): void
    {
        $ticket = $this->openTicket();
        $this->support->close($ticket, $this->adminA);
        Notification::fake();

        $this->support->reply($ticket->fresh(), $this->adminB, 'One more thing.');

        $ticket->refresh();
        $this->assertSame(SupportTicketStatus::WaitingOnMember, $ticket->status);
        $this->assertNull($ticket->closed_at);
        Notification::assertSentTo($this->member, SupportReplyFromCommittee::class);
    }

    public function test_the_push_carries_the_subject_and_a_shortened_message(): void
    {
        $ticket = $this->support->open($this->member, SupportTicketType::Question, 'Paid twice?', str_repeat('a', 300));
        $push = (new SupportTicketOpened($ticket, $ticket->messages->first()))->toApn($this->adminA);

        $this->assertSame(__('New support ticket'), $push->title);
        $this->assertStringStartsWith('Paid twice?: aaa', $push->body);
        $this->assertLessThan(200, mb_strlen($push->body));
    }

    public function test_the_bell_entry_shows_user_text_escaped_and_never_offers_email(): void
    {
        $ticket = $this->support->open($this->member, SupportTicketType::Question, '<script>x</script>', 'Body');
        $notification = new SupportTicketOpened($ticket, $ticket->messages->first());

        $this->assertSame(['database'], $notification->via($this->adminA));
        $data = json_encode($notification->toDatabase($this->adminA));
        $this->assertStringContainsString('&lt;script&gt;', $data);
        $this->assertStringNotContainsString('<script>', $data);
    }
}

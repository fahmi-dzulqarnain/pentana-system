<?php

namespace Tests\Feature\Support;

use App\Enums\SupportTicketStatus;
use App\Filament\Resources\SupportTickets\Pages\CreateSupportTicket;
use App\Filament\Resources\SupportTickets\Pages\ListSupportTickets;
use App\Filament\Resources\SupportTickets\Pages\ViewSupportTicket;
use App\Filament\Resources\SupportTickets\SupportTicketResource;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketOpened;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class SupportTicketResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('account'));
        $this->member = User::factory()->create(['activated_at' => now(), 'name' => 'Aisyah Member']);
        $this->admin = User::factory()->admin()->create(['activated_at' => now(), 'name' => 'Zainal Admin']);
    }

    private function ticketFor(User $owner, array $attributes = []): SupportTicket
    {
        $ticket = SupportTicket::factory()->for($owner)->create($attributes);
        SupportMessage::factory()->for($ticket, 'ticket')->create(['user_id' => $owner->id, 'body' => 'My question']);

        return $ticket;
    }

    public function test_a_member_opens_a_ticket_from_the_create_page(): void
    {
        $this->actingAs($this->member);

        Livewire::test(CreateSupportTicket::class)
            ->fillForm(['type' => 'bug', 'subject' => 'Blank page', 'body' => 'The lunch page is blank.'])
            ->call('create')
            ->assertHasNoFormErrors();

        $ticket = SupportTicket::sole();
        $this->assertSame($this->member->id, $ticket->user_id);
        $this->assertSame(SupportTicketStatus::NeedsReply, $ticket->status);
        $this->assertSame('The lunch page is blank.', $ticket->messages->first()->body);
        Notification::assertSentTo($this->admin, SupportTicketOpened::class);
    }

    public function test_an_admin_cannot_reach_the_create_page(): void
    {
        $this->actingAs($this->admin);

        $this->assertFalse(SupportTicketResource::canCreate());
        $this->get(SupportTicketResource::getUrl('create'))->assertForbidden();
        Livewire::test(ListSupportTickets::class)->assertActionHidden('create');
    }

    public function test_a_member_only_lists_their_own_tickets(): void
    {
        $own = $this->ticketFor($this->member);
        $others = $this->ticketFor(User::factory()->create(['activated_at' => now()]));
        $this->actingAs($this->member);

        Livewire::test(ListSupportTickets::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$others]);
    }

    public function test_a_member_cannot_open_another_members_ticket(): void
    {
        $others = $this->ticketFor(User::factory()->create(['activated_at' => now()]));
        $this->actingAs($this->member);

        $this->get(SupportTicketResource::getUrl('view', ['record' => $others]))->assertNotFound();
    }

    public function test_the_admin_inbox_defaults_to_needs_reply_and_badges_the_count(): void
    {
        $a = $this->ticketFor($this->member);
        $b = $this->ticketFor(User::factory()->create(['activated_at' => now()]));
        $waiting = $this->ticketFor($this->member, ['status' => SupportTicketStatus::WaitingOnMember]);
        $this->actingAs($this->admin);

        Livewire::test(ListSupportTickets::class)
            ->assertCanSeeTableRecords([$a, $b])
            ->assertCanNotSeeTableRecords([$waiting]);
        $this->assertSame('2', SupportTicketResource::getNavigationBadge());
    }

    public function test_an_admin_reply_from_the_view_page_shows_in_the_thread_and_changes_status(): void
    {
        $ticket = $this->ticketFor($this->member);
        $this->actingAs($this->admin);

        // Reloads the page so the status badge and the sidebar count both reflect the change.
        Livewire::test(ViewSupportTicket::class, ['record' => $ticket->getKey()])
            ->callAction('reply', data: ['body' => 'Done, credited to November.'])
            ->assertHasNoActionErrors()
            ->assertRedirect(SupportTicketResource::getUrl('view', ['record' => $ticket]));

        $this->assertSame(SupportTicketStatus::WaitingOnMember, $ticket->fresh()->status);
        Livewire::test(ViewSupportTicket::class, ['record' => $ticket->getKey()])
            ->assertSee('Done, credited to November.')
            ->assertSee('Waiting on member');
    }

    public function test_the_member_sees_committee_not_the_admin_name(): void
    {
        $ticket = $this->ticketFor($this->member);
        SupportMessage::factory()->for($ticket, 'ticket')
            ->create(['user_id' => $this->admin->id, 'from_committee' => true, 'body' => 'Answer']);

        $this->actingAs($this->member);
        Livewire::test(ViewSupportTicket::class, ['record' => $ticket->getKey()])
            ->assertSee('Committee')
            ->assertDontSee('Zainal');

        // Another admin sees who answered (the author themself sees "You").
        $this->actingAs(User::factory()->admin()->create(['activated_at' => now()]));
        Livewire::test(ViewSupportTicket::class, ['record' => $ticket->getKey()])
            ->assertSee('Zainal');
    }

    public function test_message_text_is_escaped_in_the_thread(): void
    {
        $ticket = $this->ticketFor($this->member);
        SupportMessage::factory()->for($ticket, 'ticket')->create(['user_id' => $this->member->id, 'body' => '<b>hi</b>']);
        $this->actingAs($this->member);

        Livewire::test(ViewSupportTicket::class, ['record' => $ticket->getKey()])
            ->assertSee('&lt;b&gt;hi&lt;/b&gt;', false)
            ->assertDontSee('<b>hi</b>', false);
    }

    public function test_a_member_can_mark_their_ticket_resolved_but_has_no_admin_actions(): void
    {
        $ticket = $this->ticketFor($this->member);
        $this->actingAs($this->member);

        Livewire::test(ViewSupportTicket::class, ['record' => $ticket->getKey()])
            ->assertActionVisible('reply')
            ->assertActionHidden('close')
            ->assertActionHidden('replyAndClose')
            ->assertActionHidden('reopen')
            ->callAction('markResolved');

        $this->assertSame(SupportTicketStatus::Closed, $ticket->fresh()->status);
    }

    public function test_an_admin_can_close_and_reopen_but_not_mark_resolved(): void
    {
        $ticket = $this->ticketFor($this->member);
        $this->actingAs($this->admin);

        Livewire::test(ViewSupportTicket::class, ['record' => $ticket->getKey()])
            ->assertActionHidden('markResolved')
            ->assertActionHidden('reopen')
            ->callAction('close');

        Livewire::test(ViewSupportTicket::class, ['record' => $ticket->getKey()])
            ->assertActionHidden('close')
            ->assertActionVisible('reopen')
            ->callAction('reopen');

        $this->assertSame(SupportTicketStatus::NeedsReply, $ticket->fresh()->status);
    }
}

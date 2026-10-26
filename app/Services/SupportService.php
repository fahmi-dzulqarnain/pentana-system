<?php

namespace App\Services;

use App\Enums\SupportTicketStatus;
use App\Enums\SupportTicketType;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportReplyFromCommittee;
use App\Notifications\SupportReplyFromMember;
use App\Notifications\SupportTicketClosed;
use App\Notifications\SupportTicketOpened;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Every support-ticket state change, and who hears about it. This is the authorization
 * boundary: screens (and later the app API) call it with the signed-in user and it refuses
 * anyone outside their role, so a tampered Livewire prop can't act on someone else's ticket.
 *
 * Members open and reply to their own tickets; admins can't open one. Any admin answers any
 * ticket. Notices go to enabled users only, never to whoever acted.
 */
class SupportService
{
    public function __construct(private MemberNotifier $notifier) {}

    public function open(User $member, SupportTicketType $type, string $subject, string $body, ?string $attachmentPath = null): SupportTicket
    {
        $this->ensure(! $member->is_admin);
        // A screenshot is served back to its member, so only a file stored straight under support/
        // may be referenced; anything else could expose another private file (APKs, other screenshots' temp copies).
        if ($attachmentPath !== null && (! preg_match('#^support/[A-Za-z0-9._-]+$#', $attachmentPath) || str_contains($attachmentPath, '..'))) {
            throw new InvalidArgumentException('Invalid support attachment path.');
        }

        $ticket = DB::transaction(function () use ($member, $type, $subject, $body, $attachmentPath) {
            $ticket = SupportTicket::create([
                'user_id' => $member->id,
                'type' => $type,
                'subject' => $subject,
                'status' => SupportTicketStatus::NeedsReply,
                'last_message_at' => now(),
            ]);
            $ticket->messages()->create([
                'user_id' => $member->id,
                'body' => $body,
                'attachment_path' => $attachmentPath,
                'from_committee' => false,
            ]);

            return $ticket;
        });

        $this->notifyAdmins($member, new SupportTicketOpened($ticket, $ticket->messages->first()));

        return $ticket;
    }

    public function reply(SupportTicket $ticket, User $author, string $body, bool $close = false): SupportMessage
    {
        $fromCommittee = (bool) $author->is_admin;
        $this->ensure($fromCommittee || $this->owns($author, $ticket));
        $this->ensure($fromCommittee || ! $close);

        $message = DB::transaction(function () use ($ticket, $author, $body, $close, $fromCommittee) {
            $message = $ticket->messages()->create([
                'user_id' => $author->id,
                'body' => $body,
                'from_committee' => $fromCommittee,
            ]);

            $ticket->update(match (true) {
                ! $fromCommittee => $this->openState(SupportTicketStatus::NeedsReply),
                $close => $this->closedState($author),
                default => $this->openState(SupportTicketStatus::WaitingOnMember),
            } + ['last_message_at' => now()]);

            return $message;
        });

        $fromCommittee
            ? $this->notifyOwner($ticket, new SupportReplyFromCommittee($ticket, $message))
            : $this->notifyAdmins($author, new SupportReplyFromMember($ticket, $message));

        return $message;
    }

    public function close(SupportTicket $ticket, User $by): void
    {
        $this->ensure((bool) $by->is_admin);

        // Already closed: keep who closed it and when, and don't tell the member twice.
        if ($ticket->status === SupportTicketStatus::Closed) {
            return;
        }

        $ticket->update($this->closedState($by));

        $this->notifyOwner($ticket, new SupportTicketClosed($ticket));
    }

    public function reopen(SupportTicket $ticket, User $by): void
    {
        $this->ensure((bool) $by->is_admin);

        $ticket->update($this->openState(SupportTicketStatus::NeedsReply));
    }

    public function resolveByMember(SupportTicket $ticket, User $member): void
    {
        $this->ensure(! $member->is_admin && $this->owns($member, $ticket));

        $ticket->update($this->closedState($member));
    }

    /** @return array<string, mixed> */
    private function openState(SupportTicketStatus $status): array
    {
        return ['status' => $status, 'closed_at' => null, 'closed_by_id' => null];
    }

    /** @return array<string, mixed> */
    private function closedState(User $by): array
    {
        return ['status' => SupportTicketStatus::Closed, 'closed_at' => now(), 'closed_by_id' => $by->id];
    }

    private function owns(User $user, SupportTicket $ticket): bool
    {
        return $ticket->user_id === $user->id;
    }

    private function ensure(bool $allowed): void
    {
        if (! $allowed) {
            throw new AuthorizationException;
        }
    }

    private function notifyAdmins(User $actor, Notification $notification): void
    {
        $admins = User::enabled()->where('is_admin', true)->whereKeyNot($actor->id)->get();

        $this->notifier->sendAfterResponse($admins, $notification);
    }

    private function notifyOwner(SupportTicket $ticket, Notification $notification): void
    {
        $owner = User::enabled()->whereKey($ticket->user_id)->get();

        $this->notifier->sendAfterResponse($owner, $notification);
    }
}

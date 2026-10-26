<?php

namespace App\Enums;

/**
 * Where a ticket stands, from who spoke last plus an explicit close. Admins and members
 * read the same state from their own side, so each gets its own wording.
 */
enum SupportTicketStatus: string
{
    case NeedsReply = 'needs_reply';
    case WaitingOnMember = 'waiting_on_member';
    case Closed = 'closed';

    public function adminLabel(): string
    {
        return match ($this) {
            self::NeedsReply => __('Needs reply'),
            self::WaitingOnMember => __('Waiting on member'),
            self::Closed => __('Closed'),
        };
    }

    public function memberLabel(): string
    {
        return match ($this) {
            self::NeedsReply => __('Open'),
            self::WaitingOnMember => __('Committee replied'),
            self::Closed => __('Closed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NeedsReply => 'danger',
            self::WaitingOnMember => 'warning',
            self::Closed => 'gray',
        };
    }
}

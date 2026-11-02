<?php

namespace App\Notifications;

/** Tells the member an admin closed their ticket without a further reply. */
class SupportTicketClosed extends SupportNotification
{
    protected function title(): string
    {
        return __('Your question was closed');
    }

    protected function body(): string
    {
        return $this->ticket->subject;
    }

    protected function icon(): string
    {
        return 'heroicon-o-check-circle';
    }

    protected function deepLinksToThread(): bool
    {
        return true;
    }
}

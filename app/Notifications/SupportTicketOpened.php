<?php

namespace App\Notifications;

/** Tells every admin a member opened a support ticket. */
class SupportTicketOpened extends SupportNotification
{
    protected function title(): string
    {
        return __('New support ticket');
    }

    protected function body(): string
    {
        return $this->memberName().' · '.$this->ticket->subject;
    }

    protected function icon(): string
    {
        return 'heroicon-o-lifebuoy';
    }
}

<?php

namespace App\Notifications;

/** Tells the member the committee answered their ticket. */
class SupportReplyFromCommittee extends SupportNotification
{
    protected function title(): string
    {
        return __('The committee replied');
    }

    protected function body(): string
    {
        return $this->ticket->subject;
    }

    protected function deepLinksToThread(): bool
    {
        return true;
    }
}

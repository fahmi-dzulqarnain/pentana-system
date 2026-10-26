<?php

namespace App\Notifications;

/** Tells every admin the member wrote again on their ticket. */
class SupportReplyFromMember extends SupportNotification
{
    protected function title(): string
    {
        return __('Member replied');
    }

    protected function body(): string
    {
        return $this->memberName().' · '.$this->ticket->subject;
    }
}

<?php

namespace App\Notifications;

use App\Models\Activity;
use Illuminate\Notifications\Notification;

/**
 * Shared base for activity notifications. Delivered INLINE (not ShouldQueue): this
 * deployment has no queue worker, so the bell (database) + logged email fire synchronously.
 */
abstract class ActivityNotification extends Notification
{
    public function __construct(public Activity $activity) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    protected function whenLabel(): string
    {
        return $this->activity->starts_at->format('l, j M · g:i A');
    }

    protected function whereSuffix(): string
    {
        return $this->activity->location ? ' · '.$this->activity->location : '';
    }

    protected function url(): string
    {
        // ACT-5 points this at MyActivities::getUrl(panel: 'account').
        return url('/account');
    }
}

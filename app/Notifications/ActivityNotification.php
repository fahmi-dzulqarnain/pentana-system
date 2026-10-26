<?php

namespace App\Notifications;

use App\Filament\Pages\MyActivities;
use App\Models\Activity;
use App\Notifications\Concerns\PushesViaApn;
use App\Support\TimeFormat;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnMessage;

/**
 * Shared base for activity notifications. Delivered INLINE (not ShouldQueue): this
 * deployment has no queue worker, so the bell (database) + logged email fire synchronously.
 */
abstract class ActivityNotification extends Notification
{
    use PushesViaApn;

    public function __construct(public Activity $activity) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->withApnOrMail($notifiable, ['database']);
    }

    public function toApn(object $notifiable): ApnMessage
    {
        return ApnMessage::create()
            ->title($this->pushTitle())
            ->body($this->pushBody());
    }

    /** Push/bell title - implemented per notification. */
    abstract protected function pushTitle(): string;

    /** Push/bell body - defaults to the when + where line. */
    protected function pushBody(): string
    {
        return $this->whenLabel().$this->whereSuffix();
    }

    protected function whenLabel(): string
    {
        return $this->activity->starts_at->translatedFormat('l, j M · '.TimeFormat::time());
    }

    protected function whereSuffix(): string
    {
        return $this->activity->location ? ' · '.$this->activity->location : '';
    }

    protected function url(): string
    {
        return MyActivities::getUrl(panel: 'account');
    }
}

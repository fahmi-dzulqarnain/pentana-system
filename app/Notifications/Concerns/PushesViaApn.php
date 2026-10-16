<?php

namespace App\Notifications\Concerns;

use App\Notifications\Channels\ResilientApnChannel;

/**
 * Adds APNs delivery to a notification - but only when APNs is configured and the
 * member actually has a device token. Dev/test environments without `APN_*` env
 * never gain the channel, so notifications keep working without touching APNs.
 */
trait PushesViaApn
{
    /** @param  array<int, string>  $channels */
    protected function withApn(object $notifiable, array $channels): array
    {
        if (config('broadcasting.connections.apn.key_id')
            && method_exists($notifiable, 'routeNotificationForApn')
            && ! empty($notifiable->routeNotificationForApn())) {
            $channels[] = ResilientApnChannel::class;
        }

        return $channels;
    }
}

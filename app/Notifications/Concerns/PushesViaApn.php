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
        if ($this->canPush($notifiable)) {
            $channels[] = ResilientApnChannel::class;
        }

        return $channels;
    }

    /**
     * Push in place of email: a member who gets this on their phone is not also emailed.
     * For routine notices only - the daily email budget is smaller than the member list,
     * so every email push replaces is one that is not spooled and sent late. The app drops
     * its token when notifications are turned off, and dead tokens are pruned on the first
     * failed push, so a member without working push falls back to email.
     *
     * @param  array<int, string>  $channels
     */
    protected function withApnOrMail(object $notifiable, array $channels): array
    {
        $channels[] = $this->canPush($notifiable) ? ResilientApnChannel::class : 'mail';

        return $channels;
    }

    private function canPush(object $notifiable): bool
    {
        return config('broadcasting.connections.apn.key_id')
            && method_exists($notifiable, 'routeNotificationForApn')
            && ! empty($notifiable->routeNotificationForApn());
    }
}

<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnChannel;
use Throwable;

/**
 * Push is the least important of a member's channels: a broken APNs setup must not stop the
 * bell and email that already went out, nor tell the admin the member was never notified.
 *
 * The real channel is built inside the try, because APNs fails while being constructed
 * (reading and signing the .p8 key), not only while sending.
 */
class ResilientApnChannel
{
    /** @return array<int, mixed>|null */
    public function send(mixed $notifiable, Notification $notification): ?array
    {
        try {
            return app(ApnChannel::class)->send($notifiable, $notification);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}

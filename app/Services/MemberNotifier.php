<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Fans a notification out to many members without holding the admin's request hostage.
 *
 * There is no queue worker, so delivery is still in-process - but it runs after the
 * response has gone back (php-fpm finishes the request first), and each recipient is
 * isolated: one bad channel or member is reported and skipped instead of aborting the
 * rest. Publishing a lunch used to send inline and 500 halfway through the list.
 */
class MemberNotifier
{
    /** @param  Collection<int, User>  $recipients */
    public function sendAfterResponse(Collection $recipients, Notification $notification): void
    {
        $ids = $recipients->modelKeys();

        defer(function () use ($ids, $notification): void {
            ignore_user_abort(true);
            set_time_limit(300);

            foreach (User::whereKey($ids)->get() as $user) {
                try {
                    $user->notify($notification);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });
    }

    /**
     * Same as sendAfterResponse(), for when each member gets their own notification.
     *
     * @param  Collection<int, array{0: User, 1: Notification}>  $notifications
     */
    public function sendEachAfterResponse(Collection $notifications): void
    {
        defer(function () use ($notifications): void {
            ignore_user_abort(true);
            set_time_limit(300);

            foreach ($notifications as [$user, $notification]) {
                try {
                    $user->notify($notification);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });
    }
}

<?php

namespace App\Services;

use App\Models\Lunch;
use App\Models\User;
use App\Notifications\LunchesPublished;
use App\Notifications\LunchPublished;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Publishes draft lunch days, and later tells members about them once.
 *
 * Publishing does not notify on the spot: admins often publish a week one day at a time,
 * which used to send every member one email per day (465 emails for one week, against a
 * 100/day budget). app:announce-lunches calls announcePending() every minute and sends a
 * single announcement covering every day published in a burst, once the burst has been
 * quiet for QUIET_MINUTES.
 */
class LunchPublisher
{
    public const QUIET_MINUTES = 10;

    /**
     * @param  Collection<int, Lunch>  $lunches
     * @return Collection<int, Lunch> the days that were drafts and are now published
     */
    public function publish(Collection $lunches): Collection
    {
        // Only a genuine draft -> published transition is announced.
        $drafts = $lunches->filter(fn (Lunch $lunch) => $lunch->status === 'draft')->values();

        $drafts->each(fn (Lunch $lunch) => $lunch->update(['status' => 'published', 'published_at' => now()]));

        return $drafts->map(fn (Lunch $lunch) => $lunch->fresh());
    }

    /**
     * Announce every published-but-unannounced day, once nothing has been published for
     * QUIET_MINUTES. A single day gets LunchPublished, several get one LunchesPublished.
     * Days whose deadline has already passed are stamped without telling anyone.
     *
     * @return int how many days were announced (0 while still waiting for the burst to end)
     */
    public function announcePending(): int
    {
        $pending = Lunch::where('status', 'published')->whereNotNull('published_at')->whereNull('announced_at')->orderBy('date')->get();

        if ($pending->isEmpty() || $pending->max('published_at')->gt(now()->subMinutes(self::QUIET_MINUTES))) {
            return 0;
        }

        $open = $pending->filter(fn (Lunch $lunch) => $lunch->deadline?->isFuture())->values();

        if ($open->isNotEmpty()) {
            $notification = $open->count() === 1 ? new LunchPublished($open->first()) : new LunchesPublished($open);

            foreach (User::enabled()->get() as $user) {
                try {
                    $user->notify($notification);
                } catch (Throwable $e) {
                    report($e); // one bad APNs token must not abort the whole run
                }
            }
        }

        // Stamp only the rows read above, so a day published mid-run waits for the next one.
        Lunch::whereKey($pending->modelKeys())->update(['announced_at' => now()]);

        return $open->count();
    }
}

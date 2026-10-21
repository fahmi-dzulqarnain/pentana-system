<?php

namespace App\Services;

use App\Models\Lunch;
use App\Models\User;
use App\Notifications\LunchesPublished;
use App\Notifications\LunchPublished;
use Illuminate\Support\Collection;

/**
 * Publishes draft lunch days and tells members once: a single day gets LunchPublished,
 * several days published together get one LunchesPublished listing them all.
 */
class LunchPublisher
{
    public function __construct(private readonly MemberNotifier $notifier) {}

    /**
     * @param  Collection<int, Lunch>  $lunches
     * @return Collection<int, Lunch> the days that were drafts and are now published
     */
    public function publish(Collection $lunches): Collection
    {
        // Only a genuine draft -> published transition should fan out notifications.
        $drafts = $lunches->filter(fn (Lunch $lunch) => $lunch->status === 'draft')->values();

        if ($drafts->isEmpty()) {
            return $drafts;
        }

        $drafts->each(fn (Lunch $lunch) => $lunch->update(['status' => 'published']));
        $fresh = $drafts->map(fn (Lunch $lunch) => $lunch->fresh());

        $notification = $fresh->count() === 1 ? new LunchPublished($fresh->first()) : new LunchesPublished($fresh);
        $this->notifier->sendAfterResponse(User::enabled()->get(), $notification);

        return $fresh;
    }
}

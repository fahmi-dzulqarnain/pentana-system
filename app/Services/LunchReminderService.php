<?php

namespace App\Services;

use App\Models\Lunch;
use App\Models\User;
use App\Notifications\LunchVoteNudge;
use RuntimeException;

class LunchReminderService
{
    public function __construct(private MemberNotifier $notifier) {}

    /**
     * Nudge every enabled member who hasn't voted yet. Separate from the automatic
     * last-call nudge: it never touches vote_nudge_sent_at and can be sent repeatedly.
     *
     * @return int how many members were reminded
     *
     * @throws RuntimeException when the lunch is not open for voting.
     */
    public function remindNonResponders(Lunch $lunch, User $admin): int
    {
        if (! $lunch->isOpenForVoting()) {
            throw new RuntimeException('This lunch is not open for voting.');
        }

        $recipients = $lunch->nonResponders()->get();

        if ($recipients->isEmpty()) {
            return 0;
        }

        $this->notifier->sendAfterResponse($recipients, new LunchVoteNudge($lunch));

        $lunch->forceFill([
            'manual_reminder_sent_at' => now(),
            'manual_reminder_sent_by' => $admin->id,
        ])->save();

        return $recipients->count();
    }
}

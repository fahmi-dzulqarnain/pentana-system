<?php

namespace App\Console\Commands;

use App\Models\Lunch;
use App\Models\User;
use App\Notifications\LunchVoteNudge;
use Illuminate\Console\Command;
use Throwable;

class SendLunchVoteNudges extends Command
{
    protected $signature = 'app:send-lunch-vote-nudges';

    protected $description = 'Nudge members who have not voted on published lunches closing within a day';

    public function handle(): int
    {
        $lunches = Lunch::where('status', 'published')
            ->whereNull('vote_nudge_sent_at')
            ->whereBetween('deadline', [now(), now()->addDay()])
            ->get();

        // One nudge per member listing every day they haven't answered, not one per day:
        // a published week shares a deadline, and five emails each blew the budget for days.
        $pending = [];

        foreach ($lunches as $lunch) {
            foreach ($lunch->nonResponders()->pluck('id') as $userId) {
                $pending[$userId][] = $lunch;
            }
        }

        foreach (User::whereKey(array_keys($pending))->get() as $user) {
            try {
                $user->notify(new LunchVoteNudge(collect($pending[$user->id])));
            } catch (Throwable $e) {
                report($e); // one bad APNs token must not abort the whole run
            }
        }

        // Stamp so a re-run the same day doesn't double-nudge (same guard as activity reminders).
        foreach ($lunches as $lunch) {
            $lunch->forceFill(['vote_nudge_sent_at' => now()])->save();
        }

        $this->info('Sent '.count($pending)." vote nudge(s) for {$lunches->count()} lunch(es).");

        return self::SUCCESS;
    }
}

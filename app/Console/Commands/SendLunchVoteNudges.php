<?php

namespace App\Console\Commands;

use App\Models\Lunch;
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

        foreach ($lunches as $lunch) {
            $nonResponders = $lunch->nonResponders()->get();

            foreach ($nonResponders as $user) {
                try {
                    $user->notify(new LunchVoteNudge($lunch));
                } catch (Throwable $e) {
                    report($e); // one bad APNs token must not abort the whole run
                }
            }

            // Stamp so a re-run the same day doesn't double-nudge (same guard as activity reminders).
            $lunch->forceFill(['vote_nudge_sent_at' => now()])->save();
        }

        $this->info("Sent vote nudges for {$lunches->count()} lunch(es).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Notifications\ActivityReminder;
use Illuminate\Console\Command;

class SendActivityReminders extends Command
{
    protected $signature = 'app:send-activity-reminders';

    protected $description = 'Remind registered members about published activities starting within a day';

    public function handle(): int
    {
        $activities = Activity::where('status', 'published')
            ->whereNull('reminder_sent_at')
            ->whereBetween('starts_at', [now(), now()->addDay()])
            ->get();

        foreach ($activities as $activity) {
            $activity->registrations()
                ->where('status', 'registered')
                ->with('user')
                ->get()
                ->each(fn (ActivityRegistration $registration) => $registration->user->notify(new ActivityReminder($activity)));

            // Stamp so a re-run the same day doesn't double-remind (same guard as birthday emails).
            $activity->forceFill(['reminder_sent_at' => now()])->save();
        }

        $this->info("Sent reminders for {$activities->count()} activity(ies).");

        return self::SUCCESS;
    }
}

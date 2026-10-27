<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:generate-monthly-fees')->monthlyOn(1, '00:00');
Schedule::command('app:close-lunch-orders')->everyFifteenMinutes();
Schedule::command('app:send-birthday-emails')->dailyAt('08:00');
Schedule::command('app:send-activity-reminders')->dailyAt('09:00');
Schedule::command('app:send-bill-reminders')->monthlyOn(15, '09:00');
Schedule::command('app:send-bill-reminders')->lastDayOfMonth('09:00');
Schedule::command('app:send-lunch-vote-nudges')->dailyAt('09:00');
Schedule::command('app:announce-lunches')->everyMinute()->withoutOverlapping(5);

Schedule::command('app:drain-email-queue')->everyMinute()->withoutOverlapping(5);

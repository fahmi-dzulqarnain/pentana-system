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

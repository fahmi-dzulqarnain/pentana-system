<?php
// Assertions for the Inside Out demo backend. Run:
//   APP_ENV=insideout php artisan tinker docs/presentations/inside-out-2026-10-30/demo/check-seed.php
// Prints "OK" or throws on the first failed check.

use App\Models\Activity;
use App\Models\Lunch;
use App\Models\Payment\FeePeriod;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\FinanceService;
use Illuminate\Support\Carbon;

$check = function (bool $ok, string $what) {
    if (! $ok) {
        throw new RuntimeException("check-seed FAILED: {$what}");
    }
};

$check(config('database.default') === 'sqlite', 'database is sqlite');
$check(str_ends_with((string) config('database.connections.sqlite.database'), 'insideout.sqlite'), 'database file is insideout.sqlite');

$ami = User::where('email', 'ami@pentana.test')->first();
$check($ami !== null, 'Ami exists');
$check($ami->nickname === 'Ami', 'Ami nickname');

$bills = FeePeriod::forMember($ami->id)->get();
$check(FinanceService::outstanding($bills) == 70.00, 'MYR 70.00 outstanding');
$check($ami->credit == 12.50, 'MYR 12.50 credit');
$check($bills->where('status', '!=', 'paid')->count() === 2, '2 unpaid bills');

$lunch = Lunch::where('status', 'published')->whereDate('date', '2026-11-02')->first();
$check($lunch !== null, 'published lunch on Mon 2 Nov');
$check($lunch->deadline->equalTo(Carbon::parse('2026-10-30 18:00')), 'lunch deadline Fri 30 Oct 18:00');
foreach (['2026-10-29 15:00', '2026-10-30 15:43'] as $at) {
    $check($lunch->deadline->greaterThan(Carbon::parse($at)), "lunch vote open at {$at}");
}

$session = Activity::where('title', 'Inside Out Sharing Session')->first();
$check($session !== null, 'Inside Out activity exists');
$check($session->starts_at->equalTo(Carbon::parse('2026-10-30 15:30')) && $session->ends_at->equalTo(Carbon::parse('2026-10-30 16:30')), 'Inside Out 15:30-16:30 on 30 Oct');
$check($session->registrations()->where('user_id', $ami->id)->where('status', 'registered')->exists(), 'Ami registered for Inside Out');

$rsvp = Activity::where('title', 'Beach Cleanup at Port Dickson')->where('status', 'published')
    ->whereBetween('starts_at', [Carbon::parse('2026-11-05'), Carbon::parse('2026-11-14 23:59')])
    ->where('registration_closes_at', '>', Carbon::parse('2026-10-30 16:30'))
    ->whereDoesntHave('registrations', fn ($q) => $q->where('user_id', $ami->id))
    ->first();
$check($rsvp !== null, 'Beach Cleanup (the live RSVP) is open 5-14 Nov and Ami is not yet registered');

$ticket = SupportTicket::where('user_id', $ami->id)->where('type', 'question')->where('status', 'waiting_on_member')->first();
$check($ticket !== null, 'Ami question ticket waiting on member');
$check($ticket->messages()->where('from_committee', false)->exists(), 'ticket has a member message');
$check($ticket->messages()->where('from_committee', true)->exists(), 'ticket has a committee reply');

$check($ami->notifications()->count() >= 2, 'Ami has >= 2 bell notifications');

echo "OK\n";

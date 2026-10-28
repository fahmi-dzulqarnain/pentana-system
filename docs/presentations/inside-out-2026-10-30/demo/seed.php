<?php
// Demo data for the Inside Out session. Only ever runs against the throwaway sqlite DB
// (APP_ENV=insideout); start-demo.sh passes the password in DEMO_PW.

use App\Enums\SupportTicketStatus;
use App\Enums\SupportTicketType;
use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\Lunch;
use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentAllocation;
use App\Models\Payment\PaymentProof;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\ActivityPublished;
use App\Notifications\LunchPublished;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Support\Carbon;

if (config('database.default') !== 'sqlite') {
    throw new RuntimeException('Refusing to seed: not the sqlite demo database');
}

$ami = User::updateOrCreate(['email' => 'ami@pentana.test'], [
    'name' => 'Ami Rahman',
    'nickname' => 'Ami',
    'password' => getenv('DEMO_PW'),
    'activated_at' => now(),
]);

// Bills: Aug paid by a verified proof that leaves MYR 12.50 credit; Sep + Oct unpaid.
foreach (['2026-08-01', '2026-09-01', '2026-10-01'] as $month) {
    FeePeriod::updateOrCreate(
        ['user_id' => $ami->id, 'month_date' => $month.' 00:00:00'],
        ['amount_due' => 35.00, 'status' => 'unpaid', 'type' => 'monthly'],
    );
}
$aug = FeePeriod::where('user_id', $ami->id)->whereDate('month_date', '2026-08-01')->first();
$aug->update(['status' => 'paid']);
$proof = PaymentProof::firstOrCreate(['user_id' => $ami->id, 'member_note' => 'August dues'], [
    'proof_image_path' => 'proofs/demo.jpg', 'amount_claimed' => 47.50, 'amount_verified' => 47.50,
    'status' => 'verified', 'verified_at' => Carbon::parse('2026-09-03 10:15'),
]);
PaymentAllocation::firstOrCreate(['payment_proof_id' => $proof->id, 'fee_period_id' => $aug->id], ['amount_allocated' => 35.00]);

// Lunch + activities from the shared demo seeder, then pinned to dates that are live during the session.
(new DemoContentSeeder)->run();
$lunch = Lunch::where('deadline', '>', now())->orderBy('date')->first();
$lunch->update(['date' => '2026-11-02', 'deadline' => Carbon::parse('2026-10-30 18:00'), 'status' => 'published']);

$beach = Activity::where('title', 'Beach Cleanup at Port Dickson')->first();
$beach->update([
    'starts_at' => Carbon::parse('2026-11-07 09:00'),
    'ends_at' => Carbon::parse('2026-11-07 12:00'),
    'registration_closes_at' => Carbon::parse('2026-11-05 18:00'),
]);

$session = Activity::updateOrCreate(['title' => 'Inside Out Sharing Session'], [
    'description' => 'Meet the new PENTANA member app.',
    'starts_at' => Carbon::parse('2026-10-30 15:30'),
    'ends_at' => Carbon::parse('2026-10-30 16:30'),
    'location' => 'Microsoft Teams',
    'capacity' => 150,
    'registration_closes_at' => Carbon::parse('2026-10-30 12:00'),
    'status' => 'published',
    'registration_fields' => [],
]);
ActivityRegistration::updateOrCreate(
    ['activity_id' => $session->id, 'user_id' => $ami->id],
    ['status' => 'registered', 'registered_at' => Carbon::parse('2026-10-27 09:30')],
);

// "Ask the committee": one answered question.
$ticket = SupportTicket::updateOrCreate(
    ['user_id' => $ami->id, 'subject' => 'Can I pay dues for 3 months at once?'],
    ['type' => SupportTicketType::Question, 'status' => SupportTicketStatus::WaitingOnMember, 'last_message_at' => Carbon::parse('2026-10-28 11:05')],
);
if ($ticket->messages()->doesntExist()) {
    $ticket->messages()->create(['user_id' => $ami->id, 'from_committee' => false,
        'body' => 'Hi! Can I pay my dues for 3 months at once instead of every month?']);
    $ticket->messages()->create(['user_id' => null, 'from_committee' => true,
        'body' => "Yes! Upload one proof for the total and we'll spread it across the months."]);
}

// Bell notifications, through the app's own notification classes (no device tokens, log mailer).
if ($ami->notifications()->doesntExist()) {
    $ami->notifyNow(new LunchPublished($lunch->fresh()));
    $ami->notifyNow(new ActivityPublished($beach->fresh()));
}

echo "seeded Ami (#{$ami->id})\n";

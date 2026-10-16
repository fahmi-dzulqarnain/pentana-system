<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\CatererMeal;
use App\Models\Lunch;
use App\Models\LunchMenu;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use App\Notifications\ActivityCancelled;
use App\Notifications\ActivityPublished;
use App\Notifications\ActivityReminder;
use App\Notifications\BillReminder;
use App\Notifications\BirthdayWish;
use App\Notifications\LunchPickRemoved;
use App\Notifications\LunchPublished;
use App\Notifications\LunchVoteNudge;
use App\Notifications\MemberInvitation;
use App\Notifications\PaymentRejected;
use App\Notifications\PaymentVerified;
use App\Notifications\WaitlistPromoted;
use Illuminate\Console\Command;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails one sample of every email type to an existing member, to check the designs in a
 * real inbox. Mail channel only: no bell entries, no pushes, nothing saved. The sample
 * lunch, activity and payment live in memory, so links point at placeholder records.
 */
class SendTestEmails extends Command
{
    protected $signature = 'app:send-test-emails {email : Address of an existing member} {--only= : Comma-separated types, e.g. lunch-published,birthday}';

    protected $description = 'Send a [Test] sample of every email type to one member';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No member has that email address.');

            return self::FAILURE;
        }

        $samples = $this->samples($user);
        if ($only = $this->option('only')) {
            $samples = array_intersect_key($samples, array_flip(array_map('trim', explode(',', $only))));
        }

        foreach ($samples as $type => $notification) {
            $user->notifyNow($this->asTest($notification), ['mail']);
            $this->line("Sent {$type}");
        }

        $this->info(count($samples)." test email(s) sent to {$user->email} in '{$user->preferredLocale()}'.");

        return self::SUCCESS;
    }

    /** @return array<string, Notification> */
    private function samples(User $user): array
    {
        $activity = new Activity([
            'title' => 'Futsal Night',
            'location' => 'Arena KL',
            'starts_at' => now()->addDays(3)->setTime(20, 0),
        ]);

        $lunch = new Lunch(['date' => now()->addDay(), 'deadline' => now()->addHours(20)]);
        $menu = new LunchMenu;
        $menu->setRelation('catererMeal', new CatererMeal(['name' => 'Nasi Lemak Ayam Berempah']));
        $lunch->setRelation('menus', collect([$menu]));

        // id 0: nothing has it, so allocation lookups stay empty and the link still builds.
        $payment = new PaymentProof([
            'user_id' => $user->id,
            'amount_claimed' => 30,
            'amount_verified' => 30,
            'admin_note' => 'The receipt is too blurry to read.',
        ]);
        $payment->id = 0;
        $payment->created_at = now();
        $payment->setRelation('user', $user); // their real balance, read-only

        return [
            'birthday' => new BirthdayWish,
            'invitation' => new MemberInvitation,
            'lunch-published' => new LunchPublished($lunch),
            'lunch-vote-nudge' => new LunchVoteNudge($lunch),
            'lunch-pick-removed' => new LunchPickRemoved($lunch),
            'activity-published' => new ActivityPublished($activity),
            'activity-reminder' => new ActivityReminder($activity),
            'activity-cancelled' => new ActivityCancelled($activity),
            'waitlist-promoted' => new WaitlistPromoted($activity),
            'payment-verified' => new PaymentVerified($payment),
            'payment-rejected' => new PaymentRejected($payment),
            'bill-reminder' => new BillReminder(60, 2),
        ];
    }

    /** Only the email, with "[Test]" in front of the subject. */
    private function asTest(Notification $inner): Notification
    {
        return new class($inner) extends Notification
        {
            public function __construct(private Notification $inner) {}

            /** @return array<int, string> */
            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                $mail = $this->inner->toMail($notifiable);

                return $mail->subject('[Test] '.$mail->subject);
            }
        };
    }
}

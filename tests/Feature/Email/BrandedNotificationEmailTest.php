<?php

namespace Tests\Feature\Email;

use App\Mail\BrandedMail;
use App\Models\Activity;
use App\Models\Lunch;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use App\Notifications\ActivityCancelled;
use App\Notifications\ActivityPublished;
use App\Notifications\ActivityReminder;
use App\Notifications\BillReminder;
use App\Notifications\LunchPickRemoved;
use App\Notifications\LunchPublished;
use App\Notifications\LunchVoteNudge;
use App\Notifications\PaymentRejected;
use App\Notifications\PaymentVerified;
use App\Notifications\WaitlistPromoted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Tests\TestCase;

class BrandedNotificationEmailTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{Notification, string}> notification => expected tone */
    private function notifications(): array
    {
        $activity = Activity::factory()->create(['title' => 'Futsal <Night>', 'starts_at' => now()->addDay()]);
        $lunch = Lunch::factory()->create(['date' => now(), 'deadline' => now()->addHours(3)]);
        $proof = PaymentProof::create([
            'user_id' => User::factory()->create()->id, 'proof_image_path' => 'receipts/r.jpg',
            'amount_claimed' => 30, 'status' => 'rejected', 'admin_note' => 'Blurry',
        ]);

        return [
            'activity published' => [new ActivityPublished($activity), 'activity'],
            'activity reminder' => [new ActivityReminder($activity), 'activity'],
            'activity cancelled' => [new ActivityCancelled($activity), 'alert'],
            'waitlist promoted' => [new WaitlistPromoted($activity), 'activity'],
            'lunch published' => [new LunchPublished($lunch), 'lunch'],
            'lunch vote nudge' => [new LunchVoteNudge($lunch), 'lunch'],
            'lunch pick removed' => [new LunchPickRemoved($lunch), 'alert'],
            'payment verified' => [new PaymentVerified($proof), 'payment'],
            'payment rejected' => [new PaymentRejected($proof), 'alert'],
            'bill reminder' => [new BillReminder(50, 2), 'bill'],
        ];
    }

    public function test_every_notification_email_uses_the_branded_layout_in_its_tone(): void
    {
        $member = User::factory()->create();

        foreach ($this->notifications() as $label => [$notification, $tone]) {
            $mail = $notification->toMail($member);

            $this->assertInstanceOf(BrandedMail::class, $mail, $label);
            $this->assertNotEmpty($mail->greeting, "$label has no heading");

            $html = view($mail->view['html'], $mail->data())->render();
            $text = view($mail->view['text'], $mail->data())->render();

            $this->assertStringContainsString('background:'.BrandedMail::TONES[$tone]['bg'], $html, "$label tone");
            $this->assertStringContainsString(e($mail->greeting), $html, $label);
            if ($mail->actionUrl) {
                $this->assertStringContainsString('href="'.e($mail->actionUrl).'"', $html, $label);
                $this->assertStringContainsString($mail->actionUrl, $text, $label);
            }
            $this->assertDoesNotMatchRegularExpression('#</?(p|a|div|table|td|br|strong|h1)\b#', $text, "$label text part contains HTML");
        }
    }

    public function test_member_entered_text_is_escaped_in_the_html(): void
    {
        $activity = Activity::factory()->create(['title' => '<script>x</script>', 'starts_at' => now()->addDay()]);

        $mail = (new ActivityPublished($activity))->toMail(User::factory()->create());
        $html = view($mail->view['html'], $mail->data())->render();

        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
    }
}

<?php

namespace Tests\Feature\Email;

use App\Models\Activity;
use App\Models\Lunch;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class SendTestEmailsTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<Email> */
    private function sent(): array
    {
        return array_map(
            fn ($message) => $message->getOriginalMessage(),
            iterator_to_array(app('mailer')->getSymfonyTransport()->messages()),
        );
    }

    public function test_it_emails_one_sample_of_every_type_and_saves_nothing(): void
    {
        $user = User::factory()->create(['email' => 'me@example.com', 'name' => 'Aisyah']);

        $this->artisan('app:send-test-emails', ['email' => 'me@example.com'])->assertSuccessful();

        $sent = $this->sent();
        $this->assertCount(12, $sent);
        foreach ($sent as $email) {
            $this->assertStringStartsWith('[Test] ', $email->getSubject());
            $this->assertSame('me@example.com', $email->getTo()[0]->getAddress());
            $this->assertNotEmpty($email->getHtmlBody());
            $this->assertNotEmpty($email->getTextBody());
        }

        // Mail only: no bell entries, and the sample records were never saved.
        $this->assertSame(0, $user->notifications()->count());
        $this->assertSame(0, Activity::count() + Lunch::count() + PaymentProof::count());
    }

    public function test_only_sends_the_requested_types(): void
    {
        User::factory()->create(['email' => 'me@example.com']);

        $this->artisan('app:send-test-emails', ['email' => 'me@example.com', '--only' => 'birthday, bill-reminder'])
            ->assertSuccessful();

        $subjects = array_map(fn (Email $email) => $email->getSubject(), $this->sent());
        $this->assertCount(2, $subjects);
        $this->assertContains('[Test] Reminder: outstanding dues', $subjects);
    }

    public function test_an_unknown_address_sends_nothing(): void
    {
        $this->artisan('app:send-test-emails', ['email' => 'nobody@example.com'])->assertFailed();

        $this->assertCount(0, $this->sent());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use App\Notifications\BillReminder;
use App\Services\FinanceService;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SendBillRemindersTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithBill(float $due): User
    {
        $user = User::factory()->create();
        FeePeriod::create([
            'user_id' => $user->id,
            'month_date' => now()->startOfMonth()->toDateString(),
            'amount_due' => $due,
            'status' => 'unpaid',
        ]);

        return $user;
    }

    public function test_reminds_members_with_outstanding_bills(): void
    {
        Notification::fake();
        $owing = $this->memberWithBill(50);
        $noBills = User::factory()->create();

        $this->artisan('app:send-bill-reminders')->assertSuccessful();

        Notification::assertSentTo($owing, BillReminder::class, function (BillReminder $notification) {
            return $notification->outstanding === 50.0 && $notification->unpaidCount === 1;
        });
        Notification::assertNotSentTo($noBills, BillReminder::class);
    }

    public function test_does_not_remind_settled_members(): void
    {
        Notification::fake();
        $user = $this->memberWithBill(80);

        $proof = PaymentProof::create([
            'user_id' => $user->id,
            'proof_image_path' => 'receipts/test.jpg',
            'amount_claimed' => 80,
            'status' => 'pending',
        ]);
        app(FinanceService::class)->verifyAndAllocate($proof, 80.0);

        $this->artisan('app:send-bill-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_partial_payment_reminds_with_the_remainder(): void
    {
        Notification::fake();
        $user = $this->memberWithBill(100);

        $proof = PaymentProof::create([
            'user_id' => $user->id,
            'proof_image_path' => 'receipts/test.jpg',
            'amount_claimed' => 40,
            'status' => 'pending',
        ]);
        app(FinanceService::class)->verifyAndAllocate($proof, 40.0);

        $this->artisan('app:send-bill-reminders')->assertSuccessful();

        Notification::assertSentTo($user, BillReminder::class, function (BillReminder $notification) {
            return $notification->outstanding === 60.0 && $notification->unpaidCount === 1;
        });
    }

    public function test_a_failed_send_does_not_abort_the_rest_of_the_run(): void
    {
        $failing = $this->memberWithBill(50);
        $succeeding = $this->memberWithBill(70);

        // User::notify() resolves the Dispatcher contract from the container, so a
        // bound mock lets us make one send blow up (e.g. a bad APNs token) while
        // recording who else still got notified.
        $notified = [];
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('send')
            ->andReturnUsing(function (User $notifiable, BillReminder $notification) use ($failing, &$notified) {
                if ($notifiable->is($failing)) {
                    throw new RuntimeException('APNs: bad device token');
                }
                $notified[] = $notifiable->id;
            });
        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->artisan('app:send-bill-reminders')
            ->expectsOutputToContain('Sent bill reminders to 1 member(s).')
            ->assertSuccessful();

        $this->assertSame([$succeeding->id], $notified);
    }
}

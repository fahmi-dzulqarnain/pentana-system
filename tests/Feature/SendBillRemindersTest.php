<?php

namespace Tests\Feature;

use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use App\Notifications\BillReminder;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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
}

<?php

namespace Tests\Feature\Api;

use App\Filament\Resources\FeePayments\Pages\ViewFeePayment;
use App\Models\DeviceToken;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use App\Notifications\PaymentRejected;
use ErrorException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Production, 2026-10-16 14:53: APNs could not read its key from a web request, and the
 * admin was told the member had not been notified although bell and email had gone out.
 */
class ResilientPushTest extends TestCase
{
    use RefreshDatabase;

    private function brokenPushSetup(): User
    {
        config([
            'broadcasting.connections.apn.key_id' => 'TESTKEY123',
            'broadcasting.connections.apn.team_id' => 'TEAM123456',
            'broadcasting.connections.apn.private_key_path' => '/nonexistent/AuthKey.p8',
        ]);
        cache()->flush();

        $member = User::factory()->create(['activated_at' => now()]);
        DeviceToken::create(['user_id' => $member->id, 'token' => str_repeat('a', 64), 'platform' => 'ios']);

        return $member;
    }

    public function test_a_push_failure_is_reported_while_bell_and_email_still_go_out(): void
    {
        Exceptions::fake();
        $member = $this->brokenPushSetup();
        $proof = PaymentProof::create(['user_id' => $member->id, 'proof_image_path' => 'r.jpg', 'amount_claimed' => 30, 'status' => 'rejected', 'admin_note' => 'Unreadable']);

        $member->notify(new PaymentRejected($proof));

        $this->assertSame(1, $member->notifications()->count(), 'bell missing');
        // The fake matches the closure's exact parameter type, so name the class APNs actually throws.
        Exceptions::assertReported(fn (ErrorException $e) => str_contains($e->getMessage(), 'AuthKey.p8'));
    }

    public function test_the_admin_is_told_the_member_was_notified_when_only_push_failed(): void
    {
        $member = $this->brokenPushSetup();
        $proof = PaymentProof::create(['user_id' => $member->id, 'proof_image_path' => 'r.jpg', 'amount_claimed' => 30, 'status' => 'pending']);
        $this->actingAs(User::factory()->treasurer()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(ViewFeePayment::class, ['record' => $proof->getKey()])
            ->callAction('rejectPayment', data: ['admin_note' => 'Unreadable'])
            ->assertNotified(FilamentNotification::make()->success()->title('Payment rejected')->body('The member has been notified.'));
    }

    public function test_a_relative_key_path_is_read_from_the_app_root(): void
    {
        $_ENV['APN_PRIVATE_KEY_PATH'] = $_SERVER['APN_PRIVATE_KEY_PATH'] = 'storage/app/private/apns/AuthKey.p8';
        $config = require config_path('broadcasting.php');
        $this->assertSame(base_path('storage/app/private/apns/AuthKey.p8'), $config['connections']['apn']['private_key_path']);

        $_ENV['APN_PRIVATE_KEY_PATH'] = $_SERVER['APN_PRIVATE_KEY_PATH'] = '/etc/keys/AuthKey.p8';
        $config = require config_path('broadcasting.php');
        $this->assertSame('/etc/keys/AuthKey.p8', $config['connections']['apn']['private_key_path']);

        unset($_ENV['APN_PRIVATE_KEY_PATH'], $_SERVER['APN_PRIVATE_KEY_PATH']);
    }
}

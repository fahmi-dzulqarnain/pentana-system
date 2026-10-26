<?php

namespace Tests\Feature\Finance;

use App\Filament\Resources\FeePayments\Pages\CreateFeePayment;
use App\Models\User;
use App\Notifications\BillReminder;
use App\Support\ReferenceCode;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_user_exposes_payment_reference(): void
    {
        $member = User::factory()->create();
        Sanctum::actingAs($member);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.payment_reference', ReferenceCode::for($member->id));
    }

    public function test_submit_payment_form_shows_the_member_code(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('account'));
        $member = User::factory()->create();
        $this->actingAs($member);

        Livewire::test(CreateFeePayment::class)->assertSee($member->payment_reference);
    }

    public function test_bill_reminder_mail_tells_the_member_their_code(): void
    {
        $member = User::factory()->create();

        $mail = (new BillReminder(50, 2))->toMail($member);

        $this->assertContains(
            __('Use reference :code when you transfer.', ['code' => $member->payment_reference]),
            $mail->introLines,
        );
        $this->assertStringContainsString($member->payment_reference, implode(' ', $mail->introLines));
    }

    public function test_only_admin_treasurers_can_import(): void
    {
        $this->assertTrue(User::factory()->treasurer()->create()->canImportBankStatements());
        $this->assertFalse(User::factory()->superAdmin()->create()->canImportBankStatements());
        $this->assertFalse(User::factory()->admin()->create()->canImportBankStatements());
        $this->assertFalse(User::factory()->create(['is_treasurer' => true])->canImportBankStatements());
    }
}

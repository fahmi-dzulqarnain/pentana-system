<?php

namespace Tests\Feature\Onboarding;

use App\Filament\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UnactivatedLoginMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_login_for_an_unactivated_account_prompts_to_claim(): void
    {
        User::factory()->create([
            'email' => 'unclaimed@example.com',
            'password' => null,
            'activated_at' => null,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'unclaimed@example.com',
                'password' => 'anything',
            ])
            ->call('authenticate')
            ->assertHasErrors(['data.email'])
            ->assertNotified();
    }

    public function test_claim_notification_body_mentions_claiming(): void
    {
        User::factory()->create([
            'email' => 'unclaimed2@example.com',
            'password' => null,
            'activated_at' => null,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'unclaimed2@example.com',
                'password' => 'anything',
            ])
            ->call('authenticate')
            ->assertNotified('Account not yet activated');
    }

    public function test_failed_login_for_an_activated_account_stays_generic(): void
    {
        User::factory()->create([
            'email' => 'activated@example.com',
            'password' => 'correct-password',
            'activated_at' => now(),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'activated@example.com',
                'password' => 'wrong-password',
            ])
            ->call('authenticate')
            ->assertHasErrors(['data.email'])
            ->assertNotNotified();
    }
}

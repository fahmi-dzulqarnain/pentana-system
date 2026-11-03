<?php

namespace App\Livewire;

use App\Services\AccountClaimService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Public "email me my claim link" page. It answers the same way whether or not the address
 * belongs to anyone, so it can't be used to discover members or their names: whatever proves
 * the claimant is the link that lands in the member's own inbox.
 */
class RequestClaimLink extends Component
{
    use WithRateLimiting;

    public string $email = '';

    public bool $sent = false;

    public ?string $throttleMessage = null;

    public function send(): void
    {
        $this->validate(['email' => 'required|email']);

        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $e) {
            $this->throttleMessage = __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => $e->secondsUntilAvailable]);

            return;
        }

        $this->throttleMessage = null;
        app(AccountClaimService::class)->sendLink($this->email);
        $this->sent = true;
    }

    #[Layout('components.layouts.guest')]
    public function render()
    {
        return view('livewire.request-claim-link')->title(__('Activate your account').' · PENTANA');
    }
}

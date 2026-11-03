<?php

namespace App\Filament\Auth;

use App\Services\AccountClaimService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Filament's "forgot password" page, with two changes: a member who never claimed their
 * account gets their claim link instead (resetting would leave them unable to sign in),
 * and an unknown address gets the same "sent" answer as a known one.
 */
class RequestPasswordReset extends \Filament\Auth\Pages\PasswordReset\RequestPasswordReset
{
    public function request(): void
    {
        $email = (string) $this->form->getState()['email'];

        if (app(AccountClaimService::class)->claimable($email) === null) {
            parent::request();

            return;
        }

        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        app(AccountClaimService::class)->sendLink($email);

        $this->getSentNotification(Password::RESET_LINK_SENT)?->send();
        $this->form->fill();
    }

    protected function getFailureNotification(string $status): ?Notification
    {
        // "We can't find a user with that email address" would tell a stranger who is a member.
        if ($status === Password::INVALID_USER) {
            return $this->getSentNotification(Password::RESET_LINK_SENT);
        }

        return parent::getFailureNotification($status);
    }
}

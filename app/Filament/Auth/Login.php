<?php

namespace App\Filament\Auth;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class Login extends \Filament\Auth\Pages\Login
{
    protected function throwFailureValidationException(): never
    {
        $email = $this->data['email'] ?? null;

        if ($email) {
            $user = User::where('email', $email)->first();

            if ($user && ! $user->isActivated()) {
                Notification::make()
                    ->title('Account not yet activated')
                    ->body('Your account hasn\'t been claimed yet. Use the link below to set your password and activate your account.')
                    ->warning()
                    ->persistent()
                    ->actions([
                        Action::make('claim')
                            ->label('Claim your account')
                            ->url('/claim')
                            ->button(),
                    ])
                    ->send();

                throw ValidationException::withMessages([
                    'data.email' => 'This account hasn\'t been activated yet. Please claim your account first.',
                ]);
            }
        }

        parent::throwFailureValidationException();
    }
}

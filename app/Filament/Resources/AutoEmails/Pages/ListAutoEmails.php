<?php

namespace App\Filament\Resources\AutoEmails\Pages;

use App\Filament\Concerns\HidesListBreadcrumb;
use App\Filament\Resources\AutoEmails\AutoEmailResource;
use App\Services\TestEmailSender;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAutoEmails extends ListRecords
{
    use HidesListBreadcrumb;

    protected static string $resource = AutoEmailResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Lets an admin check every email design in their own inbox without server access.
            Action::make('sendTestEmails')
                ->label(__('Send me test emails'))
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(__('Send me test emails'))
                ->modalDescription(fn (): string => __('Sends one [Test] sample of every email type to :email, in your language. Only the emails are sent: nothing else is created or changed.', [
                    'email' => auth()->user()->email,
                ]))
                ->modalSubmitActionLabel(__('Send'))
                ->action(function (TestEmailSender $sender) {
                    $sent = $sender->send(auth()->user());

                    Notification::make()
                        ->title(__(':count test emails sent to :email', ['count' => count($sent), 'email' => auth()->user()->email]))
                        ->body(__('If the daily email limit is reached, the rest wait in the email queue and go out later.'))
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}

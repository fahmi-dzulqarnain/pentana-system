<?php

namespace App\Filament\Resources\Activities\Pages;

use App\Filament\Resources\Activities\ActivityResource;
use App\Models\User;
use App\Notifications\ActivityCancelled;
use App\Notifications\ActivityPublished;
use App\Services\MemberNotifier;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Pages\EditRecord;

class EditActivity extends EditRecord
{
    protected static string $resource = ActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label(__('Publish'))
                ->visible(fn (): bool => $this->record->status === 'draft' && ! $this->record->isPast())
                ->requiresConfirmation()
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->action(function (): void {
                    // Only a genuine draft -> published transition should fan out notifications.
                    if ($this->record->status !== 'draft') {
                        return;
                    }

                    $this->record->update(['status' => 'published']);

                    app(MemberNotifier::class)->sendAfterResponse(
                        User::whereNotNull('activated_at')->enabled()->get(),
                        new ActivityPublished($this->record->fresh()),
                    );

                    FilamentNotification::make()->title(__('Published: members notified'))->success()->send();
                }),
            Action::make('cancelActivity')
                ->label(__('Cancel activity'))
                ->visible(fn (): bool => in_array($this->record->status, ['draft', 'published'], true) && ! $this->record->isPast())
                ->requiresConfirmation()
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->action(function (): void {
                    $wasPublished = $this->record->status === 'published';

                    $this->record->update(['status' => 'cancelled']);

                    if ($wasPublished) {
                        $recipients = User::enabled()->whereIn(
                            'id',
                            $this->record->registrations()->whereIn('status', ['registered', 'waitlisted'])->pluck('user_id'),
                        )->get();

                        app(MemberNotifier::class)->sendAfterResponse($recipients, new ActivityCancelled($this->record->fresh()));
                    }

                    FilamentNotification::make()->title(__('Activity cancelled'))->warning()->send();
                }),
            DeleteAction::make(),
        ];
    }
}

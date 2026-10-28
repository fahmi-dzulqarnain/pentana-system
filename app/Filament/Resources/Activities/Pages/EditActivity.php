<?php

namespace App\Filament\Resources\Activities\Pages;

use App\Filament\Resources\Activities\ActivityResource;
use App\Models\User;
use App\Notifications\ActivityCancelled;
use App\Notifications\ActivityDateConfirmed;
use App\Notifications\ActivityPublished;
use App\Services\MemberNotifier;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Pages\EditRecord;

class EditActivity extends EditRecord
{
    protected static string $resource = ActivityResource::class;

    /** Whether the record was coming soon (no start date) before this save. */
    private bool $wasComingSoon = false;

    protected function beforeSave(): void
    {
        $this->wasComingSoon = $this->record->isComingSoon();
    }

    /** Scheduling a coming-soon activity tells everyone already signed up for it. */
    protected function afterSave(): void
    {
        if (! $this->wasComingSoon || $this->record->isComingSoon() || $this->record->status !== 'published') {
            return;
        }

        $recipients = User::enabled()->whereIn(
            'id',
            $this->record->registrations()->whereIn('status', ['registered', 'waitlisted'])->pluck('user_id'),
        )->get();

        app(MemberNotifier::class)->sendAfterResponse($recipients, new ActivityDateConfirmed($this->record->fresh()));
    }

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

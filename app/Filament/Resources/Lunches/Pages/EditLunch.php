<?php

namespace App\Filament\Resources\Lunches\Pages;

use App\Filament\Resources\Lunches\LunchResource;
use App\Models\User;
use App\Notifications\LunchPublished;
use App\Services\LunchPlanningService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Notification;

class EditLunch extends EditRecord
{
    protected static string $resource = LunchResource::class;

    protected function afterSave(): void
    {
        if ($this->record->wasChanged('caterer_meal_id')) {
            $this->record->options()->delete();
            app(LunchPlanningService::class)->syncOptionsFromMenu($this->record->fresh());
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->visible(fn () => $this->record->status === 'draft')
                ->requiresConfirmation()
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->action(function () {
                    // Only a genuine draft -> published transition should fan out notifications.
                    if ($this->record->status !== 'draft') {
                        return;
                    }

                    $this->record->update(['status' => 'published']);
                    // Keep the form state in sync so a later Save doesn't write the stale 'draft' back.
                    $this->refreshFormData(['status']);

                    Notification::send(User::all(), new LunchPublished($this->record->fresh()));

                    FilamentNotification::make()
                        ->title('Published: members notified')
                        ->success()
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }
}

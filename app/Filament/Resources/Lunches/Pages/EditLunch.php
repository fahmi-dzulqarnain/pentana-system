<?php

namespace App\Filament\Resources\Lunches\Pages;

use App\Filament\Resources\Lunches\LunchResource;
use App\Services\LunchPlanningService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

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
                    $this->record->update(['status' => 'published']);
                    // Keep the form state in sync so a later Save doesn't write the stale 'draft' back.
                    $this->refreshFormData(['status']);
                }),
            DeleteAction::make(),
        ];
    }
}

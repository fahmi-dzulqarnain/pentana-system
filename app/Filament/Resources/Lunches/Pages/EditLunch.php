<?php

namespace App\Filament\Resources\Lunches\Pages;

use App\Filament\Resources\Lunches\LunchResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLunch extends EditRecord
{
    protected static string $resource = LunchResource::class;

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

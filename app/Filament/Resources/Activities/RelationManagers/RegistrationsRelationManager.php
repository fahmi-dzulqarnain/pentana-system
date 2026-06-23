<?php

namespace App\Filament\Resources\Activities\RelationManagers;

use App\Models\ActivityRegistration;
use App\Services\ActivityRegistrationService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class RegistrationsRelationManager extends RelationManager
{
    protected static string $relationship = 'registrations';

    protected static ?string $title = 'Registrations';

    public function table(Table $table): Table
    {
        return $table
            ->heading(function (): string {
                $activity = $this->getOwnerRecord();
                $capacity = $activity->capacity ? ' / '.$activity->capacity : '';

                return "Registered {$activity->registeredCount()}{$capacity}"
                    ." · Waitlist {$activity->waitlistCount()}"
                    ." · Attended {$activity->attendedCount()}";
            })
            ->columns([
                TextColumn::make('user.name')
                    ->label('Member')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'registered' => 'success',
                        'waitlisted' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('registered_at')
                    ->label('Registered at')
                    ->dateTime()
                    ->sortable(),
                // The check-in: ticking this writes `attended` straight to the registration.
                ToggleColumn::make('attended')
                    ->label('Attended'),
            ])
            ->defaultSort('registered_at')
            ->recordActions([
                Action::make('viewAnswers')
                    ->label('Answers')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('gray')
                    ->visible(fn (): bool => $this->getOwnerRecord()->hasRegistrationFields())
                    ->modalHeading('Registration answers')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (ActivityRegistration $record) => view('filament.activities.registration-answers', [
                        'fields' => $this->getOwnerRecord()->registration_fields ?? [],
                        'answers' => $record->answers ?? [],
                    ])),
                Action::make('remove')
                    ->label('Remove')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ActivityRegistration $record): bool => $record->status !== 'cancelled')
                    ->action(fn (ActivityRegistration $record) => app(ActivityRegistrationService::class)
                        ->cancel($record->activity, $record->user)),
            ]);
    }
}

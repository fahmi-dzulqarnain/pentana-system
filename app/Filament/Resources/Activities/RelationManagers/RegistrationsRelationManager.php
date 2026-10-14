<?php

namespace App\Filament\Resources\Activities\RelationManagers;

use App\Models\ActivityRegistration;
use App\Services\ActivityRegistrationService;
use App\Support\StatusLabel;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RegistrationsRelationManager extends RelationManager
{
    protected static string $relationship = 'registrations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Registrations');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('registration'))
            ->pluralModelLabel(__('registrations'))
            ->heading(function (): string {
                $activity = $this->getOwnerRecord();
                $registered = $activity->capacity
                    ? $activity->registeredCount().' / '.$activity->capacity
                    : (string) $activity->registeredCount();

                return __('Registered :registered · Waitlist :waitlist · Attended :attended', [
                    'registered' => $registered,
                    'waitlist' => $activity->waitlistCount(),
                    'attended' => $activity->attendedCount(),
                ]);
            })
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('Member'))
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StatusLabel::for($state))
                    ->color(fn (string $state): string => match ($state) {
                        'registered' => 'success',
                        'waitlisted' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('registered_at')
                    ->label(__('Registered at'))
                    ->dateTime()
                    ->sortable(),
                // The check-in: ticking this writes `attended` straight to the registration.
                ToggleColumn::make('attended')
                    ->label(__('Attended')),
            ])
            ->defaultSort('registered_at')
            ->recordActions([
                Action::make('viewAnswers')
                    ->label(__('Answers'))
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('gray')
                    ->visible(fn (): bool => $this->getOwnerRecord()->hasRegistrationFields())
                    ->modalHeading(__('Registration answers'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->modalContent(fn (ActivityRegistration $record) => view('filament.activities.registration-answers', [
                        'fields' => $this->getOwnerRecord()->registration_fields ?? [],
                        'answers' => $record->answers ?? [],
                    ])),
                Action::make('remove')
                    ->label(__('Remove'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ActivityRegistration $record): bool => $record->status !== 'cancelled')
                    ->action(fn (ActivityRegistration $record) => app(ActivityRegistrationService::class)
                        ->cancel($record->activity, $record->user)),
            ]);
    }
}

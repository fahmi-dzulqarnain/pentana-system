<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Imports\UserImporter;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\MemberAccess;
use App\Services\MemberInviter;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ImportAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('Email address'))
                    ->searchable(),
                TextColumn::make('memberCategory.name')
                    ->label(__('Member category')),
                // Whether the member has activated the account an admin created for them.
                TextColumn::make('account_status')
                    ->label(__('Account status'))
                    ->state(fn (User $record): string => match (true) {
                        $record->is_admin => 'admin',
                        $record->isDisabled() => 'disabled',
                        $record->activated_at !== null => 'claimed',
                        default => 'unclaimed',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'primary',
                        'claimed' => 'success',
                        'disabled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'admin' => __('Admin'),
                        'claimed' => __('Claimed'),
                        'disabled' => __('Disabled'),
                        default => __('Not claimed yet'),
                    })
                    ->description(fn (User $record): ?string => match (true) {
                        $record->isManuallyDisabled() => __('Disabled by an admin :when', ['when' => $record->disabled_at->diffForHumans()]),
                        $record->activated_at !== null || $record->invited_at === null => null,
                        $record->invitationExpired() => __('Invitation expired :when', ['when' => $record->invitationExpiresAt()->diffForHumans()]),
                        default => __('Invited :when · expires :date', [
                            'when' => $record->invited_at->diffForHumans(),
                            'date' => $record->invitationExpiresAt()->translatedFormat('j M'),
                        ]),
                    }),
                // Derived from unallocated payment proofs, so not a sortable DB column.
                TextColumn::make('credit')
                    ->label(__('Credit'))
                    ->money('MYR'),
                TextColumn::make('email_verified_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->with('memberCategory'))
            ->filters([
                SelectFilter::make('account_status')
                    ->label(__('Account status'))
                    ->options([
                        'admin' => __('Admin'),
                        'claimed' => __('Claimed'),
                        'unclaimed' => __('Not claimed yet'),
                        'disabled' => __('Disabled'),
                    ])
                    // Same rules as the column: admin first, then whether the account was activated.
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'admin' => $query->where('is_admin', true),
                        'claimed' => $query->where('is_admin', false)->whereNotNull('activated_at')->enabled(),
                        'unclaimed' => $query->where('is_admin', false)->whereNull('activated_at')->enabled(),
                        'disabled' => $query->where('is_admin', false)->disabled(),
                        default => $query,
                    }),
                SelectFilter::make('member_category_id')
                    ->label(__('Member category'))
                    ->relationship('memberCategory', 'name'),
            ])
            // No View button: clicking the row opens the member's details. Set explicitly,
            // because without a ViewAction Filament would send row clicks to Edit instead.
            ->recordUrl(fn (User $record): string => UserResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                EditAction::make(),
                Action::make('invite')
                    // Short on the row; the confirmation modal spells it out.
                    ->label(__('Invite'))
                    ->icon('heroicon-o-envelope')
                    ->color('gray')
                    ->visible(fn (User $record): bool => app(MemberInviter::class)->canInvite($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record): string => $record->invited_at ? __('Resend invitation') : __('Send invitation'))
                    ->modalDescription(fn (User $record): string => $record->invitationExpired()
                        ? __('This account is disabled because the last invitation expired. Sending a new one re-enables it and gives :email another :days days to claim it.', [
                            'email' => $record->email,
                            'days' => User::INVITATION_VALID_DAYS,
                        ])
                        : __('Email :email a link to claim their :app account? They have :days days to claim it.', [
                            'email' => $record->email,
                            'app' => config('app.name'),
                            'days' => User::INVITATION_VALID_DAYS,
                        ]))
                    ->modalSubmitActionLabel(__('Send'))
                    ->action(function (User $record, MemberInviter $inviter) {
                        $inviter->invite($record);
                        Notification::make()->title(__('Invitation sent to :email', ['email' => $record->email]))->success()->send();
                    }),
                Action::make('disable')
                    ->label(__('Disable'))
                    // Icon-only so the row stays as compact as before; the tooltip names it.
                    ->iconButton()
                    ->tooltip(__('Disable'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (User $record): bool => app(MemberAccess::class)->canDisable($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record): string => __('Disable :name?', ['name' => $record->name]))
                    ->modalDescription(__('They will be signed out and can no longer sign in. They stop receiving bills and notifications. Bills they already have are kept. You can enable the account again at any time.'))
                    ->modalSubmitActionLabel(__('Disable'))
                    ->action(function (User $record, MemberAccess $access) {
                        $access->disable($record);
                        Notification::make()->title(__(':name has been disabled', ['name' => $record->name]))->success()->send();
                    }),
                Action::make('enable')
                    ->label(__('Enable'))
                    ->iconButton()
                    ->tooltip(__('Enable'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn (User $record): bool => $record->isManuallyDisabled())
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record): string => __('Enable :name?', ['name' => $record->name]))
                    ->modalDescription(__('They can sign in again and will be billed and notified like any other member from now on.'))
                    ->modalSubmitActionLabel(__('Enable'))
                    ->action(function (User $record, MemberAccess $access) {
                        $access->enable($record);
                        Notification::make()->title(__(':name has been enabled', ['name' => $record->name]))->success()->send();
                    }),
            ])
            ->toolbarActions([
                ImportAction::make()
                    ->importer(UserImporter::class)
                    ->label(__('Import members')),
                BulkActionGroup::make([
                    BulkAction::make('invite')
                        ->label(__('Send invitations'))
                        ->icon('heroicon-o-envelope')
                        ->requiresConfirmation()
                        ->modalDescription(__('Members who have not claimed their account yet get an email with a link to claim it, and :days days to do so. Disabled members are re-enabled. Admins and claimed members are skipped.', ['days' => User::INVITATION_VALID_DAYS]))
                        ->modalSubmitActionLabel(__('Send'))
                        ->action(function (Collection $records, MemberInviter $inviter) {
                            $sent = $records->filter(fn (User $user): bool => $inviter->invite($user))->count();
                            $skipped = $records->count() - $sent;

                            Notification::make()
                                ->title(trans_choice('Invitation sent to :count member|Invitations sent to :count members', $sent, ['count' => $sent]))
                                ->body($skipped > 0 ? trans_choice(':count member skipped (already claimed or admin)|:count members skipped (already claimed or admin)', $skipped, ['count' => $skipped]) : null)
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

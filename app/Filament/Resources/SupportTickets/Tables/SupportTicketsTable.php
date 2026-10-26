<?php

namespace App\Filament\Resources\SupportTickets\Tables;

use App\Enums\SupportTicketStatus;
use App\Filament\Resources\SupportTickets\SupportTicketResource;
use App\Filament\Support\MemberName;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SupportTicketsTable
{
    public static function configure(Table $table): Table
    {
        $admin = SupportTicketResource::viewerIsAdmin();

        return $table
            ->columns([
                MemberName::column('user')
                    ->label(__('Member name'))
                    ->visible($admin),
                TextColumn::make('subject')
                    ->label(__('Subject'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (SupportTicketStatus $state): string => $admin ? $state->adminLabel() : $state->memberLabel())
                    ->color(fn (SupportTicketStatus $state): string => $state->color()),
                TextColumn::make('last_message_at')
                    ->label(__('Last message'))
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('last_message_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}

<?php

namespace App\Filament\Resources\SupportTickets\Pages;

use App\Enums\SupportTicketStatus;
use App\Filament\Resources\SupportTickets\SupportTicketResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\MemberName;
use App\Models\SupportTicket;
use App\Services\SupportService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * The conversation. Every action re-reads the ticket from the database and lets
 * SupportService decide whether the signed-in user may act on it, so a tampered Livewire
 * record id can't reach someone else's ticket.
 */
class ViewSupportTicket extends ViewRecord
{
    protected static string $resource = SupportTicketResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Second guard on top of the scoped query: only the owner and admins.
        abort_unless($this->isAdmin() || $this->getRecord()->user_id === auth()->id(), 404);
    }

    public function getTitle(): string
    {
        return $this->getRecord()->subject;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make()
                    ->schema([
                        MemberName::entry('user')
                            ->label(__('Member name'))
                            ->url(fn (SupportTicket $record): string => UserResource::getUrl('view', ['record' => $record->user_id]))
                            ->visible($this->isAdmin()),
                        TextEntry::make('type')->label(__('Type'))->badge(),
                        TextEntry::make('status')
                            ->label(__('Status'))
                            ->badge()
                            ->formatStateUsing(fn (SupportTicketStatus $state): string => $this->isAdmin() ? $state->adminLabel() : $state->memberLabel())
                            ->color(fn (SupportTicketStatus $state): string => $state->color()),
                    ])
                    ->columns(3),
                View::make('filament.support.thread')
                    ->viewData(fn (): array => [
                        'messages' => $this->getRecord()->messages()->with('author')->get(),
                        'viewerIsAdmin' => $this->isAdmin(),
                        'viewerId' => auth()->id(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->replyAction('reply', __('Reply'), close: false)
                ->visible(fn (): bool => $this->isAdmin() ? ! $this->isClosed() : $this->isOwner()),
            $this->replyAction('replyAndClose', __('Reply & close'), close: true)
                ->visible(fn (): bool => $this->isAdmin() && ! $this->isClosed()),
            $this->stateAction('close', __('Close'), fn (SupportService $s, SupportTicket $t) => $s->close($t, auth()->user()))
                ->color('gray')
                ->visible(fn (): bool => $this->isAdmin() && ! $this->isClosed()),
            $this->stateAction('reopen', __('Reopen'), fn (SupportService $s, SupportTicket $t) => $s->reopen($t, auth()->user()))
                ->color('gray')
                ->visible(fn (): bool => $this->isAdmin() && $this->isClosed()),
            $this->stateAction('markResolved', __('Mark as resolved'), fn (SupportService $s, SupportTicket $t) => $s->resolveByMember($t, auth()->user()))
                ->color('success')
                ->visible(fn (): bool => $this->isOwner() && ! $this->isAdmin() && ! $this->isClosed()),
        ];
    }

    private function replyAction(string $name, string $label, bool $close): Action
    {
        return Action::make($name)
            ->label($label)
            ->schema([
                Textarea::make('body')->label(__('Message'))->required()->maxLength(5000)->rows(5),
            ])
            ->action(function (array $data) use ($close): void {
                app(SupportService::class)->reply($this->freshTicket(), auth()->user(), $data['body'], $close);
                $this->done(__('Reply sent.'));
            });
    }

    private function stateAction(string $name, string $label, Closure $apply): Action
    {
        return Action::make($name)
            ->label($label)
            ->requiresConfirmation()
            ->action(function () use ($apply): void {
                $apply(app(SupportService::class), $this->freshTicket());
                $this->done(__('Ticket updated.'));
            });
    }

    // Reload rather than patch state in place: the status badge and the sidebar count both change.
    private function done(string $message): void
    {
        Notification::make()->title($message)->success()->send();
        $this->redirect(SupportTicketResource::getUrl('view', ['record' => $this->getRecord()]), navigate: true);
    }

    private function freshTicket(): SupportTicket
    {
        return SupportTicket::findOrFail($this->getRecord()->getKey());
    }

    private function isAdmin(): bool
    {
        return SupportTicketResource::viewerIsAdmin();
    }

    private function isOwner(): bool
    {
        return $this->getRecord()->user_id === auth()->id();
    }

    private function isClosed(): bool
    {
        return $this->getRecord()->status === SupportTicketStatus::Closed;
    }
}

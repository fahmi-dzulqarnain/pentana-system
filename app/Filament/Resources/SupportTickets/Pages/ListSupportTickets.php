<?php

namespace App\Filament\Resources\SupportTickets\Pages;

use App\Enums\SupportTicketStatus;
use App\Filament\Concerns\HidesListBreadcrumb;
use App\Filament\Resources\SupportTickets\SupportTicketResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSupportTickets extends ListRecords
{
    use HidesListBreadcrumb;

    protected static string $resource = SupportTicketResource::class;

    public function getTitle(): string
    {
        return SupportTicketResource::getNavigationLabel();
    }

    protected function getHeaderActions(): array
    {
        return [
            // CreateAction doesn't consult canCreate() here, so admins would see it without this.
            CreateAction::make()
                ->label(__('New question'))
                ->visible(fn (): bool => SupportTicketResource::canCreate()),
        ];
    }

    /** Admins work the inbox by status; a member's own list is short enough without tabs. */
    public function getTabs(): array
    {
        if (! SupportTicketResource::viewerIsAdmin()) {
            return [];
        }

        $byStatus = fn (SupportTicketStatus $status): Tab => Tab::make($status->adminLabel())
            ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status));

        return [
            'needs_reply' => $byStatus(SupportTicketStatus::NeedsReply),
            'waiting_on_member' => $byStatus(SupportTicketStatus::WaitingOnMember),
            'closed' => $byStatus(SupportTicketStatus::Closed),
            'all' => Tab::make(__('All')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return SupportTicketResource::viewerIsAdmin() ? 'needs_reply' : null;
    }
}

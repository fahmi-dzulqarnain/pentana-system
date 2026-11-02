<?php

namespace App\Filament\Resources\SupportTickets;

use App\Filament\NavGroup;
use App\Filament\Resources\SupportTickets\Pages\CreateSupportTicket;
use App\Filament\Resources\SupportTickets\Pages\ListSupportTickets;
use App\Filament\Resources\SupportTickets\Pages\ViewSupportTicket;
use App\Filament\Resources\SupportTickets\Schemas\SupportTicketForm;
use App\Filament\Resources\SupportTickets\Tables\SupportTicketsTable;
use App\Models\SupportTicket;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Members reach this as "Ask the committee" (their own tickets only); admins as the
 * "Support" inbox for every ticket. Admins can't open tickets themselves.
 */
class SupportTicketResource extends Resource
{
    protected static ?string $model = SupportTicket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    // Last in either group: below a member's own pages, below the admin member tools.
    protected static ?int $navigationSort = 99;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return self::viewerIsAdmin() ? NavGroup::Members : NavGroup::Personal;
    }

    public static function getNavigationLabel(): string
    {
        return self::viewerIsAdmin() ? __('Support') : __('Ask the committee');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! self::viewerIsAdmin()) {
            return null;
        }

        $count = SupportTicket::needsReply()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getModelLabel(): string
    {
        return __('support ticket');
    }

    public static function getPluralModelLabel(): string
    {
        return __('support tickets');
    }

    public static function canCreate(): bool
    {
        return auth()->check() && ! self::viewerIsAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return SupportTicketForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SupportTicketsTable::configure($table);
    }

    // Members only ever see their own tickets.
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! self::viewerIsAdmin()) {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    public static function viewerIsAdmin(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportTickets::route('/'),
            'create' => CreateSupportTicket::route('/create'),
            'view' => ViewSupportTicket::route('/{record}'),
        ];
    }
}

<?php

namespace App\Filament\Pages;

use App\Filament\NavGroup;
use App\Filament\Resources\FeePeriods\FeePeriodResource;
use App\Filament\Widgets\FinanceTotals;
use App\Models\Payment\FeePeriod;
use App\Models\User;
use App\Notifications\BillReminder;
use App\Services\FinanceService;
use App\Services\FinanceSummary as Summary;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Throwable;
use UnitEnum;

/**
 * The treasurer's overview: club-wide totals, then who owes what, with a way
 * to open a member's bills or send them a reminder.
 */
class FinanceSummary extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::PresentationChartLine;

    protected static ?int $navigationSort = 0;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canManageFinance();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return NavGroup::Finance;
    }

    public static function getNavigationLabel(): string
    {
        return __('Finance summary');
    }

    public function getTitle(): string
    {
        return __('Finance summary');
    }

    protected function getHeaderWidgets(): array
    {
        return [FinanceTotals::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Who owes'))
            ->query(fn (): Builder => Summary::members())
            ->columns([
                TextColumn::make('name')
                    ->label(__('Member'))
                    ->description(fn (User $record): ?string => $record->memberCategory?->name)
                    ->searchable(),
                TextColumn::make('owes')
                    ->label(__('Owes'))
                    ->money('MYR')
                    ->sortable(),
                TextColumn::make('unpaid_count')
                    ->label(__('Unpaid months'))
                    ->formatStateUsing(fn (int $state, User $record): HtmlString => self::unpaidMonths($state, $record)),
                TextColumn::make('oldest_unpaid')
                    ->label(__('Oldest unpaid'))
                    ->date('F Y')
                    ->placeholder('-'),
                TextColumn::make('credit')
                    ->label(__('Credit'))
                    ->money('MYR'),
            ])
            ->defaultSort('owes', 'desc')
            ->filters([
                SelectFilter::make('scope')
                    ->label(__('Show'))
                    ->options([
                        'owing' => __('Owing only'),
                        'all' => __('All active'),
                    ])
                    ->default('owing')
                    ->selectablePlaceholder(false)
                    ->query(fn (Builder $query, array $data): Builder => ($data['value'] ?? 'owing') === 'all'
                        ? $query
                        : Summary::owingOnly($query)),
            ])
            ->recordActions([
                Action::make('bills')
                    ->label(__('Bills'))
                    ->icon('heroicon-m-document-text')
                    ->color('gray')
                    ->url(fn (User $record): string => FeePeriodResource::getUrl('index', [
                        'filters' => ['user_id' => ['value' => $record->getKey()]],
                    ])),
                Action::make('remind')
                    ->label(__('Remind'))
                    ->icon('heroicon-m-bell-alert')
                    ->visible(fn (User $record): bool => $record->owes > 0)
                    ->requiresConfirmation()
                    ->modalHeading(__('Send reminder'))
                    ->modalDescription(fn (User $record): string => __('Send a reminder to :name for MYR :amount across :count bills?', [
                        'name' => $record->name,
                        'amount' => number_format($record->owes, 2),
                        'count' => $record->unpaid_count,
                    ]))
                    ->modalSubmitActionLabel(__('Send reminder'))
                    ->action(fn (User $record) => self::remind($record->getKey())),
            ]);
    }

    /**
     * Sends BillReminder inline, built exactly as SendBillReminders does. The member is
     * re-loaded and the caller re-checked: the row key arrives from the browser.
     */
    private static function remind(int|string $userId): void
    {
        abort_unless((bool) auth()->user()?->canManageFinance(), 403);

        $member = User::enabled()->findOrFail($userId);
        $bills = FeePeriod::forMember($member->id)->get();
        $outstanding = FinanceService::outstanding($bills);

        if ($outstanding <= 0) {
            Notification::make()->title(__(':name owes nothing now.', ['name' => $member->name]))->info()->send();

            return;
        }

        try {
            $member->notify(new BillReminder($outstanding, $bills->where('status', '!=', 'paid')->count()));
        } catch (Throwable $e) {
            report($e);

            Notification::make()->title(__('The member could not be notified.'))->warning()->send();

            return;
        }

        Notification::make()->title(__('Reminder sent to :name', ['name' => $member->name]))->success()->send();
    }

    /** The count of unpaid bills, flagged "partial" when the oldest one is part-paid. */
    private static function unpaidMonths(int $count, User $record): HtmlString
    {
        if ($record->oldest_unpaid_status !== 'partial') {
            return new HtmlString(e($count));
        }

        return new HtmlString(Blade::render(
            '<span style="display: inline-flex; align-items: center; gap: 0.5rem;">{{ $count }} <x-filament::badge color="warning" size="sm">{{ $label }}</x-filament::badge></span>',
            ['count' => $count, 'label' => __('partial')],
        ));
    }
}

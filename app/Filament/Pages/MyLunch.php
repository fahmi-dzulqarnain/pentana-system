<?php

namespace App\Filament\Pages;

use App\Filament\NavGroup;
use App\Models\Lunch;
use App\Models\LunchResponse;
use App\Services\LunchVotingService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use RuntimeException;
use UnitEnum;

class MyLunch extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::Ticket;

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.my-lunch';

    protected ?Collection $cachedLunches = null;

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return NavGroup::Personal;
    }

    public static function getNavigationLabel(): string
    {
        return __('My Lunch');
    }

    public function getTitle(): string
    {
        return __('My Lunch');
    }

    public function upcomingLunches(): Collection
    {
        return $this->cachedLunches ??= Lunch::query()
            ->with(['menus.caterer', 'menus.catererMeal', 'menus.options.mealOption'])
            ->where('status', 'published')
            ->whereDate('date', '>=', today())
            ->whereDate('date', '<=', today()->addDays(14))
            ->orderBy('date')
            ->get();
    }

    public function myResponses(): Collection
    {
        return LunchResponse::query()
            ->with('mealOption')
            ->where('user_id', auth()->id())
            ->whereIn('lunch_id', $this->upcomingLunches()->pluck('id'))
            ->get()
            ->keyBy('lunch_id');
    }

    public function choose(int $lunchId, int $lunchOptionId): void
    {
        $this->record($lunchId, $lunchOptionId, __('Choice saved.'));
    }

    public function notAttending(int $lunchId): void
    {
        $this->record($lunchId, null, __('Marked not attending.'));
    }

    protected function record(int $lunchId, ?int $lunchOptionId, string $success): void
    {
        $lunch = $this->upcomingLunches()->firstWhere('id', $lunchId) ?? Lunch::find($lunchId);

        if (! $lunch) {
            Notification::make()->title(__('That lunch is no longer available.'))->danger()->send();

            return;
        }

        try {
            app(LunchVotingService::class)->respond($lunch, auth()->user(), $lunchOptionId);
            $this->cachedLunches = null; // re-query so the highlighted choice updates
            Notification::make()->title($success)->success()->send();
        } catch (RuntimeException $e) {
            // Service messages stay English for the API; translate them for the portal.
            Notification::make()->title(__($e->getMessage()))->danger()->send();
        }
    }
}

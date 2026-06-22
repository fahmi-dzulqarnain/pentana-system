<?php

namespace App\Filament\Pages;

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
    protected static string|UnitEnum|null $navigationGroup = 'Welfare & Social';

    protected static ?string $navigationLabel = 'My Lunch';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Ticket;

    protected static ?string $title = 'My Lunch';

    protected string $view = 'filament.pages.my-lunch';

    protected ?Collection $cachedLunches = null;

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function upcomingLunches(): Collection
    {
        return $this->cachedLunches ??= Lunch::query()
            ->with(['catererMeal', 'caterer', 'options.mealOption'])
            ->where('status', 'published')
            ->whereDate('date', '>=', today())
            ->whereDate('date', '<=', today()->addDays(14))
            ->orderBy('date')
            ->get();
    }

    public function myResponses(): Collection
    {
        return LunchResponse::query()
            ->where('user_id', auth()->id())
            ->whereIn('lunch_id', $this->upcomingLunches()->pluck('id'))
            ->get()
            ->keyBy('lunch_id');
    }

    public function choose(int $lunchId, int $mealOptionId): void
    {
        $this->record($lunchId, $mealOptionId, 'Choice saved.');
    }

    public function notAttending(int $lunchId): void
    {
        $this->record($lunchId, null, 'Marked not attending.');
    }

    protected function record(int $lunchId, ?int $mealOptionId, string $success): void
    {
        $lunch = $this->upcomingLunches()->firstWhere('id', $lunchId) ?? Lunch::find($lunchId);

        if (! $lunch) {
            Notification::make()->title('That lunch is no longer available.')->danger()->send();

            return;
        }

        try {
            app(LunchVotingService::class)->respond($lunch, auth()->user(), $mealOptionId);
            $this->cachedLunches = null; // re-query so the highlighted choice updates
            Notification::make()->title($success)->success()->send();
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}

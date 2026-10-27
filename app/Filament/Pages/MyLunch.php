<?php

namespace App\Filament\Pages;

use App\Filament\NavGroup;
use App\Models\Lunch;
use App\Models\LunchResponse;
use App\Services\LunchAgenda;
use App\Services\LunchVotingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use RuntimeException;
use UnitEnum;

class MyLunch extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::Ticket;

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.my-lunch';

    protected ?Collection $cachedLunches = null;

    protected ?Collection $cachedResponses = null;

    /**
     * Which day's vote panel is showing. Display only: every action re-resolves the lunch on the
     * server and the voting service checks it, so a tampered value can't vote anywhere it shouldn't.
     */
    public ?int $openLunchId = null;

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
            // From Monday, so earlier days this week (closed, read-only) still show what was ordered.
            ->whereDate('date', '>=', today()->startOfWeek())
            ->whereDate('date', '<=', today()->addDays(14))
            ->orderBy('date')
            ->get();
    }

    public function myResponses(): Collection
    {
        return $this->cachedResponses ??= LunchResponse::query()
            ->with('mealOption')
            ->where('user_id', auth()->id())
            ->whereIn('lunch_id', $this->upcomingLunches()->pluck('id'))
            ->get()
            ->keyBy('lunch_id');
    }

    /** @return array{banner: ?array, sections: list<array>} */
    public function agenda(): array
    {
        return app(LunchAgenda::class)->build($this->upcomingLunches(), $this->myResponses());
    }

    public function panel(): ?array
    {
        return $this->openLunchId === null
            ? null
            : app(LunchAgenda::class)->panel($this->upcomingLunches(), $this->myResponses(), $this->openLunchId);
    }

    public function openLunch(int $lunchId): void
    {
        $this->openLunchId = $lunchId;
        $this->dispatch('open-modal', id: 'lunch-vote');
    }

    public function choose(int $lunchId, int $lunchOptionId): void
    {
        $this->record($lunchId, $lunchOptionId);
    }

    public function notAttending(int $lunchId): void
    {
        $this->record($lunchId, null);
    }

    /** From a notification's Undo button: put back the answer a change replaced. */
    #[On('lunch-undo')]
    public function undo(int $lunchId, ?int $lunchOptionId = null): void
    {
        $this->record($lunchId, $lunchOptionId, isUndo: true);
    }

    protected function record(int $lunchId, ?int $lunchOptionId, bool $isUndo = false): void
    {
        $lunch = $this->upcomingLunches()->firstWhere('id', $lunchId) ?? Lunch::find($lunchId);

        if (! $lunch) {
            Notification::make()->title(__('That lunch is no longer available.'))->danger()->send();

            return;
        }

        $before = LunchResponse::where(['lunch_id' => $lunch->id, 'user_id' => auth()->id()])->first();
        // Tapping the answer that's already saved changes nothing.
        if ($before && $before->lunch_option_id === $lunchOptionId && ($lunchOptionId !== null || $before->meal_option_id === null)) {
            return;
        }

        try {
            app(LunchVotingService::class)->respond($lunch, auth()->user(), $lunchOptionId);
        } catch (RuntimeException $e) {
            // Service messages stay English for the API; translate them for the portal.
            Notification::make()->title(__($e->getMessage()))->danger()->send();
            $this->dispatch('lunch-save-failed');

            return;
        }

        $this->cachedLunches = null; // re-query so the agenda and panel update
        $this->cachedResponses = null;
        $this->dispatch('lunch-saved');

        $agenda = app(LunchAgenda::class);
        $now = $agenda->answerLabel($lunch, $lunchOptionId);
        // An old answer known only by meal option can't be put back exactly, so it isn't undoable.
        $canUndo = ! $isUndo && $before !== null && ($before->lunch_option_id !== null || $before->meal_option_id === null);

        $notification = Notification::make()->success()->title(match (true) {
            $isUndo => __('Put back :pick', ['pick' => $now]),
            $canUndo => __('Changed :from → :to', ['from' => $agenda->answerLabel($lunch, $before->lunch_option_id), 'to' => $now]),
            default => __('Saved · :day: :pick', ['day' => $lunch->date->translatedFormat('D j M'), 'pick' => $now]),
        });

        if ($canUndo) {
            // Long enough to read the change and reach Undo.
            $notification->duration(10000)->actions([
                Action::make('undo')
                    ->label(__('Undo'))
                    ->button()
                    ->dispatch('lunch-undo', ['lunchId' => $lunch->id, 'lunchOptionId' => $before->lunch_option_id])
                    ->close(),
            ]);
        }

        $notification->send();
    }
}

<?php

namespace App\Filament\Pages;

use App\Models\Activity;
use App\Services\ActivityRegistrationService;
use App\Services\RegistrationException;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

class MyActivities extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Welfare & Social';

    protected static ?string $navigationLabel = 'My Activities';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Sparkles;

    protected static ?string $title = 'My Activities';

    protected string $view = 'filament.pages.my-activities';

    protected ?Collection $cachedUpcoming = null;

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    /** Published, not-yet-started activities, with all registrations loaded for counts. */
    public function upcomingActivities(): Collection
    {
        return $this->cachedUpcoming ??= Activity::query()
            ->with('registrations')
            ->where('status', 'published')
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->get();
    }

    /** Past activities the current member had a (non-cancelled) registration for. */
    public function pastActivities(): Collection
    {
        return Activity::query()
            ->where('starts_at', '<', now())
            ->whereHas('registrations', fn ($query) => $query
                ->where('user_id', auth()->id())
                ->where('status', '!=', 'cancelled'))
            ->with(['registrations' => fn ($query) => $query->where('user_id', auth()->id())])
            ->orderByDesc('starts_at')
            ->get();
    }

    public function statusFor(Activity $activity): ?string
    {
        return $activity->registrations->firstWhere('user_id', auth()->id())?->status;
    }

    public function waitlistPositionFor(Activity $activity): ?int
    {
        $waitlisted = $activity->registrations
            ->where('status', 'waitlisted')
            ->sortBy([['registered_at', 'asc'], ['id', 'asc']])
            ->values();

        $index = $waitlisted->search(fn ($registration) => $registration->user_id === auth()->id());

        return $index === false ? null : $index + 1;
    }

    public function register(int $activityId): void
    {
        $activity = $this->resolveActivity($activityId);

        if (! $activity) {
            return;
        }

        try {
            $registration = app(ActivityRegistrationService::class)->register($activity, auth()->user());
            $this->cachedUpcoming = null;

            Notification::make()
                ->title($registration->status === 'waitlisted' ? 'Added to the waitlist.' : "You're registered.")
                ->success()
                ->send();
        } catch (RegistrationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function cancelRegistration(int $activityId): void
    {
        $activity = $this->resolveActivity($activityId);

        if (! $activity) {
            return;
        }

        app(ActivityRegistrationService::class)->cancel($activity, auth()->user());
        $this->cachedUpcoming = null;

        Notification::make()->title('Registration cancelled.')->success()->send();
    }

    private function resolveActivity(int $activityId): ?Activity
    {
        $activity = $this->upcomingActivities()->firstWhere('id', $activityId) ?? Activity::find($activityId);

        if (! $activity) {
            Notification::make()->title('That activity is no longer available.')->danger()->send();
        }

        return $activity;
    }
}

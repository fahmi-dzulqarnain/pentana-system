<?php

namespace App\Filament\Pages;

use App\Filament\NavGroup;
use App\Models\Activity;
use App\Models\ActivityRegistration;
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
    protected static string|BackedEnum|null $navigationIcon = Heroicon::Flag;

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.my-activities';

    protected ?Collection $cachedUpcoming = null;

    /** Activity currently being registered for in the questions modal (null = closed). */
    public ?int $registeringActivityId = null;

    /** @var array<string, mixed> answers bound to the modal inputs */
    public array $answers = [];

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
        return __('My Activities');
    }

    public function getTitle(): string
    {
        return __('My Activities');
    }

    /** Published, not-yet-started (or not-yet-scheduled) activities, with all registrations loaded for counts. */
    public function upcomingActivities(): Collection
    {
        return $this->cachedUpcoming ??= Activity::query()
            ->with('registrations')
            ->where('status', 'published')
            ->upcoming()
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
        return $this->myRegistrationFor($activity)?->status;
    }

    public function myRegistrationFor(Activity $activity): ?ActivityRegistration
    {
        return $activity->registrations->firstWhere('user_id', auth()->id());
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

    /**
     * Entry point for the Register / Join-waitlist buttons. Activities with questions open
     * the modal; activities without go straight through (one-click, as before).
     */
    public function startRegistration(int $activityId): void
    {
        $activity = $this->resolveActivity($activityId);

        if (! $activity) {
            return;
        }

        if (! $activity->hasRegistrationFields()) {
            $this->register($activityId);

            return;
        }

        $this->answers = collect($activity->registration_fields)
            ->mapWithKeys(fn (array $field): array => [
                $field['key'] => ($field['type'] ?? 'text') === 'checkbox' ? false : '',
            ])
            ->all();
        $this->registeringActivityId = $activityId;
        $this->resetErrorBag();
    }

    /** Confirm a questions-modal registration: validate required answers, then register. */
    public function submitRegistration(): void
    {
        $activity = $this->resolveActivity((int) $this->registeringActivityId);

        if (! $activity) {
            return;
        }

        $fields = $activity->registration_fields ?? [];

        $rules = [];
        $attributes = [];
        foreach ($fields as $field) {
            if (! empty($field['required'])) {
                $rules["answers.{$field['key']}"] = ($field['type'] ?? 'text') === 'checkbox' ? 'accepted' : 'required';
                // Name the question in the error, not its internal uuid key.
                $attributes["answers.{$field['key']}"] = $field['label'] ?? __('This question');
            }
        }
        if ($rules !== []) {
            $this->validate($rules, [], $attributes);
        }

        // Never trust the public `answers` prop wholesale - keep only the defined question keys.
        $answers = collect($this->answers)->only(array_column($fields, 'key'))->all();

        try {
            $registration = app(ActivityRegistrationService::class)->register($activity, auth()->user(), $answers);
            $this->reset(['registeringActivityId', 'answers']);
            $this->cachedUpcoming = null;

            Notification::make()
                ->title($registration->status === 'waitlisted' ? __('Added to the waitlist.') : __("You're registered."))
                ->success()
                ->send();
        } catch (RegistrationException $e) {
            // Service messages stay English for the API; translate them for the portal.
            Notification::make()->title(__($e->getMessage()))->danger()->send();
        }
    }

    public function closeRegistration(): void
    {
        $this->reset(['registeringActivityId', 'answers']);
        $this->resetErrorBag();
    }

    /** Question definitions for the activity currently in the modal (for the view). */
    public function registeringFields(): array
    {
        if (! $this->registeringActivityId) {
            return [];
        }

        $activity = $this->upcomingActivities()->firstWhere('id', $this->registeringActivityId)
            ?? Activity::find($this->registeringActivityId);

        return $activity?->registration_fields ?? [];
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
                ->title($registration->status === 'waitlisted' ? __('Added to the waitlist.') : __("You're registered."))
                ->success()
                ->send();
        } catch (RegistrationException $e) {
            // Service messages stay English for the API; translate them for the portal.
            Notification::make()->title(__($e->getMessage()))->danger()->send();
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

        Notification::make()->title(__('Registration cancelled.'))->success()->send();
    }

    private function resolveActivity(int $activityId): ?Activity
    {
        $activity = $this->upcomingActivities()->firstWhere('id', $activityId) ?? Activity::find($activityId);

        if (! $activity) {
            Notification::make()->title(__('That activity is no longer available.'))->danger()->send();
        }

        return $activity;
    }
}

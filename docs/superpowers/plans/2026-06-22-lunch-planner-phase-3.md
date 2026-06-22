# Lunch Planner Phase 3 — Voting & Notifications Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let every panel user vote a meal option (or "not attending") for each published lunch, alert everyone on publish (in-app bell + logged email), auto-close past-deadline lunches, and surface real order counts on the Menu Calendar.

**Architecture:** A new `LunchResponse` (one row per lunch+user; null option = not attending; no row = default not-attending on read). Voting eligibility lives on `Lunch::isOpenForVoting()`; a `LunchVotingService::respond()` is the guarded write path used by a custom "My Lunch" Filament page. Publishing dispatches a queued `LunchPublished` notification (database + mail channels) to all users. A scheduled `app:close-lunch-orders` command locks past-deadline lunches. Count helpers on `Lunch` + `LunchPlanningStats::avgOrdersPerDay()` light up the calendar and an admin tally.

**Tech Stack:** Laravel 12, Filament 5, Livewire 4, PHP 8.5, PostgreSQL (Supabase) in prod / sqlite `:memory:` in tests, PHPUnit, Carbon, Pint.

**Spec:** `docs/superpowers/specs/2026-06-22-lunch-planner-phase-3-design.md`

**Conventions (match existing code):**
- Tests are PHPUnit classes under `tests/Feature/Lunch/`, `use RefreshDatabase`, extend `Tests\TestCase`.
- Filament page/Livewire tests set the panel: `Filament::setCurrentPanel(Filament::getPanel('account'))` after `actingAs`.
- Factories exist for `Caterer`, `CatererMeal`, `Lunch`, `LunchOption`, `MealOption`, `User` (with `->admin()` state). `LunchFactory` defaults: `status = draft`, `deadline = now()+3d`, makes its own caterer + caterer_meal. `LunchOptionFactory` defaults: `enabled = true`, `default_count = 0`.
- Run a single test file with: `php artisan test --filter <TestClassName>`.
- Filament's precompiled CSS cannot carry arbitrary Tailwind/`dark:` utilities — custom styling uses inline `style` + a `<style>` block with `.dark` overrides (see `resources/views/filament/pages/lunch-calendar.blade.php`).

---

## File Structure

**Create**
- `database/migrations/<ts>_create_lunch_responses_table.php` — the votes table.
- `database/migrations/<ts>_create_notifications_table.php` — Laravel's standard notifications table (via `make:notifications-table`).
- `app/Models/LunchResponse.php` — one vote per (lunch, user).
- `database/factories/LunchResponseFactory.php` — test fixtures.
- `app/Services/LunchVotingService.php` — the single guarded write path for a vote.
- `app/Filament/Pages/MyLunch.php` + `resources/views/filament/pages/my-lunch.blade.php` — the member card-list voting page.
- `app/Notifications/LunchPublished.php` — database + mail notification.
- `app/Console/Commands/CloseLunchOrders.php` — deadline auto-close.
- Tests: `LunchResponseTest`, `LunchVotingServiceTest`, `MyLunchPageTest`, `LunchPublishNotificationTest`, `CloseLunchOrdersTest`, `LunchOrderStatsTest` (all in `tests/Feature/Lunch/`).

**Modify**
- `app/Models/Lunch.php` — `responses()`, `isOpenForVoting()`, `attendingCount()`, `optionCounts()`, `notAttendingCount()`.
- `app/Services/LunchPlanningStats.php` — `avgOrdersPerDay()`.
- `app/Providers/Filament/AccountPanelProvider.php` — `->databaseNotifications()`.
- `app/Filament/Resources/Lunches/Pages/EditLunch.php` — dispatch `LunchPublished` from the publish action.
- `app/Filament/Resources/Lunches/Schemas/LunchForm.php` — read-only "Current orders" tally (`visibleOn('edit')`).
- `app/Filament/Pages/LunchCalendar.php` + `resources/views/filament/pages/lunch-calendar.blade.php` — real Avg. Orders/Day + per-day "N ordered" badge.
- `routes/console.php` — schedule `app:close-lunch-orders`.

---

## Task 1: `LunchResponse` model + migration + factory

**Files:**
- Create: `database/migrations/<ts>_create_lunch_responses_table.php`
- Create: `app/Models/LunchResponse.php`
- Create: `database/factories/LunchResponseFactory.php`
- Modify: `app/Models/Lunch.php`
- Test: `tests/Feature/Lunch/LunchResponseTest.php`

- [ ] **Step 1: Generate the migration file**

Run: `php artisan make:migration create_lunch_responses_table`
Then replace the generated file's body with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lunch_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lunch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_option_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['lunch_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lunch_responses');
    }
};
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Lunch/LunchResponseTest.php`:

```php
<?php

namespace Tests\Feature\Lunch;

use App\Models\Lunch;
use App\Models\LunchResponse;
use App\Models\MealOption;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LunchResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_a_vote_with_relationships(): void
    {
        $lunch = Lunch::factory()->create();
        $user = User::factory()->create();
        $option = MealOption::factory()->create();

        $response = LunchResponse::create([
            'lunch_id' => $lunch->id,
            'user_id' => $user->id,
            'meal_option_id' => $option->id,
        ]);

        $this->assertTrue($response->lunch->is($lunch));
        $this->assertTrue($response->user->is($user));
        $this->assertTrue($response->mealOption->is($option));
        $this->assertTrue($lunch->responses->contains($response));
    }

    public function test_meal_option_is_nullable_meaning_not_attending(): void
    {
        $lunch = Lunch::factory()->create();
        $user = User::factory()->create();

        $response = LunchResponse::create([
            'lunch_id' => $lunch->id,
            'user_id' => $user->id,
            'meal_option_id' => null,
        ]);

        $this->assertNull($response->fresh()->meal_option_id);
    }

    public function test_one_response_per_user_per_lunch_is_enforced(): void
    {
        $lunch = Lunch::factory()->create();
        $user = User::factory()->create();
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => $user->id, 'meal_option_id' => null]);

        $this->expectException(QueryException::class);
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => $user->id, 'meal_option_id' => null]);
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `php artisan test --filter LunchResponseTest`
Expected: FAIL — `Class "App\Models\LunchResponse" not found`.

- [ ] **Step 4: Create the model**

Create `app/Models/LunchResponse.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LunchResponse extends Model
{
    use HasFactory;

    protected $fillable = ['lunch_id', 'user_id', 'meal_option_id'];

    protected $casts = ['meal_option_id' => 'integer'];

    public function lunch(): BelongsTo
    {
        return $this->belongsTo(Lunch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mealOption(): BelongsTo
    {
        return $this->belongsTo(MealOption::class);
    }
}
```

- [ ] **Step 5: Create the factory**

Create `database/factories/LunchResponseFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Lunch;
use App\Models\LunchResponse;
use App\Models\MealOption;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LunchResponse>
 */
class LunchResponseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lunch_id' => Lunch::factory(),
            'user_id' => User::factory(),
            'meal_option_id' => MealOption::factory(),
        ];
    }
}
```

- [ ] **Step 6: Add the `responses()` relation to `Lunch`**

In `app/Models/Lunch.php`, add to the `use` block (it already imports `HasMany`) and add the relation after `options()`:

```php
public function responses(): HasMany
{
    return $this->hasMany(LunchResponse::class);
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --filter LunchResponseTest`
Expected: PASS (3 tests).

- [ ] **Step 8: Commit**

```bash
git add database/migrations app/Models/LunchResponse.php database/factories/LunchResponseFactory.php app/Models/Lunch.php tests/Feature/Lunch/LunchResponseTest.php
git commit -m "feat(lunch): add LunchResponse model, migration, factory"
```

---

## Task 2: Voting rules — `Lunch::isOpenForVoting()` + `LunchVotingService`

**Files:**
- Modify: `app/Models/Lunch.php`
- Create: `app/Services/LunchVotingService.php`
- Test: `tests/Feature/Lunch/LunchVotingServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Lunch/LunchVotingServiceTest.php`:

```php
<?php

namespace Tests\Feature\Lunch;

use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\LunchResponse;
use App\Models\MealOption;
use App\Models\User;
use App\Services\LunchVotingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LunchVotingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function openLunchWithOption(bool $enabled = true): array
    {
        $lunch = Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addDay()]);
        $option = MealOption::factory()->create();
        LunchOption::factory()->create([
            'lunch_id' => $lunch->id,
            'meal_option_id' => $option->id,
            'enabled' => $enabled,
        ]);

        return [$lunch, $option];
    }

    public function test_is_open_for_voting_only_when_published_and_before_deadline(): void
    {
        $this->assertTrue(Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addHour()])->isOpenForVoting());
        $this->assertFalse(Lunch::factory()->create(['status' => 'draft', 'deadline' => now()->addHour()])->isOpenForVoting());
        $this->assertFalse(Lunch::factory()->create(['status' => 'closed', 'deadline' => now()->addHour()])->isOpenForVoting());
        $this->assertFalse(Lunch::factory()->create(['status' => 'published', 'deadline' => now()->subHour()])->isOpenForVoting());
    }

    public function test_respond_creates_then_updates_a_single_row(): void
    {
        [$lunch, $option] = $this->openLunchWithOption();
        $user = User::factory()->create();
        $service = app(LunchVotingService::class);

        $service->respond($lunch, $user, $option->id);
        $this->assertDatabaseHas('lunch_responses', ['lunch_id' => $lunch->id, 'user_id' => $user->id, 'meal_option_id' => $option->id]);

        // Change to not attending — still one row.
        $service->respond($lunch, $user, null);
        $this->assertSame(1, LunchResponse::where(['lunch_id' => $lunch->id, 'user_id' => $user->id])->count());
        $this->assertNull(LunchResponse::where(['lunch_id' => $lunch->id, 'user_id' => $user->id])->first()->meal_option_id);
    }

    public function test_respond_rejects_a_disabled_or_unrelated_option(): void
    {
        [$lunch] = $this->openLunchWithOption(enabled: false);
        $disabledOptionId = $lunch->options()->first()->meal_option_id;
        $service = app(LunchVotingService::class);
        $user = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        $service->respond($lunch, $user, $disabledOptionId);
    }

    public function test_respond_refuses_when_lunch_is_not_open(): void
    {
        $lunch = Lunch::factory()->create(['status' => 'closed', 'deadline' => now()->addDay()]);
        $service = app(LunchVotingService::class);

        $this->expectException(\RuntimeException::class);
        $service->respond($lunch, User::factory()->create(), null);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter LunchVotingServiceTest`
Expected: FAIL — `Call to undefined method ...isOpenForVoting()` / `Class "App\Services\LunchVotingService" not found`.

- [ ] **Step 3: Add `isOpenForVoting()` to `Lunch`**

In `app/Models/Lunch.php`, add:

```php
public function isOpenForVoting(): bool
{
    return $this->status === 'published'
        && $this->deadline !== null
        && now()->lte($this->deadline);
}
```

- [ ] **Step 4: Create the service**

Create `app/Services/LunchVotingService.php`:

```php
<?php

namespace App\Services;

use App\Models\Lunch;
use App\Models\LunchResponse;
use App\Models\User;
use RuntimeException;

class LunchVotingService
{
    /**
     * Record (or update) the user's response for a lunch.
     * $mealOptionId === null means "not attending".
     *
     * @throws RuntimeException when the lunch is not open, or the option is not an enabled option on it.
     */
    public function respond(Lunch $lunch, User $user, ?int $mealOptionId): LunchResponse
    {
        if (! $lunch->isOpenForVoting()) {
            throw new RuntimeException('This lunch is not open for ordering.');
        }

        if ($mealOptionId !== null
            && ! $lunch->options()->where('meal_option_id', $mealOptionId)->where('enabled', true)->exists()) {
            throw new RuntimeException('That option is not available for this lunch.');
        }

        return LunchResponse::updateOrCreate(
            ['lunch_id' => $lunch->id, 'user_id' => $user->id],
            ['meal_option_id' => $mealOptionId],
        );
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter LunchVotingServiceTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Models/Lunch.php app/Services/LunchVotingService.php tests/Feature/Lunch/LunchVotingServiceTest.php
git commit -m "feat(lunch): add voting eligibility + guarded LunchVotingService"
```

---

## Task 3: "My Lunch" member voting page

**Files:**
- Create: `app/Filament/Pages/MyLunch.php`
- Create: `resources/views/filament/pages/my-lunch.blade.php`
- Test: `tests/Feature/Lunch/MyLunchPageTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Lunch/MyLunchPageTest.php`:

```php
<?php

namespace Tests\Feature\Lunch;

use App\Filament\Pages\MyLunch;
use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\LunchResponse;
use App\Models\MealOption;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyLunchPageTest extends TestCase
{
    use RefreshDatabase;

    private function publishedLunch(array $overrides = []): array
    {
        $lunch = Lunch::factory()->create(array_merge([
            'date' => today()->addDay()->toDateString(),
            'status' => 'published',
            'deadline' => now()->addDay(),
        ], $overrides));
        $option = MealOption::factory()->create(['name' => 'Beef']);
        LunchOption::factory()->create(['lunch_id' => $lunch->id, 'meal_option_id' => $option->id, 'enabled' => true]);

        return [$lunch, $option];
    }

    private function actAsPanelUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        return $user;
    }

    public function test_any_logged_in_user_can_access(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertTrue(MyLunch::canAccess());
    }

    public function test_it_lists_upcoming_published_lunches_and_records_a_choice(): void
    {
        [$lunch, $option] = $this->publishedLunch();
        $user = $this->actAsPanelUser();

        Livewire::test(MyLunch::class)
            ->assertSuccessful()
            ->assertSee('Beef')
            ->call('choose', $lunch->id, $option->id);

        $this->assertDatabaseHas('lunch_responses', [
            'lunch_id' => $lunch->id, 'user_id' => $user->id, 'meal_option_id' => $option->id,
        ]);
    }

    public function test_not_attending_records_a_null_option(): void
    {
        [$lunch] = $this->publishedLunch();
        $user = $this->actAsPanelUser();

        Livewire::test(MyLunch::class)->call('notAttending', $lunch->id);

        $row = LunchResponse::where(['lunch_id' => $lunch->id, 'user_id' => $user->id])->first();
        $this->assertNotNull($row);
        $this->assertNull($row->meal_option_id);
    }

    public function test_voting_does_not_touch_another_users_response(): void
    {
        [$lunch, $option] = $this->publishedLunch();
        $other = User::factory()->create();
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => $other->id, 'meal_option_id' => null]);

        $this->actAsPanelUser();
        Livewire::test(MyLunch::class)->call('choose', $lunch->id, $option->id);

        $this->assertNull(LunchResponse::where(['lunch_id' => $lunch->id, 'user_id' => $other->id])->first()->meal_option_id);
    }

    public function test_a_past_deadline_lunch_cannot_be_voted(): void
    {
        // Date today (so it still lists) but the deadline has already passed.
        [$lunch, $option] = $this->publishedLunch([
            'date' => today()->toDateString(),
            'deadline' => now()->subHour(),
        ]);
        $user = $this->actAsPanelUser();

        Livewire::test(MyLunch::class)->call('choose', $lunch->id, $option->id);

        $this->assertDatabaseMissing('lunch_responses', ['lunch_id' => $lunch->id, 'user_id' => $user->id]);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter MyLunchPageTest`
Expected: FAIL — `Class "App\Filament\Pages\MyLunch" not found`.

- [ ] **Step 3: Create the page class**

Create `app/Filament/Pages/MyLunch.php`:

```php
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
        $lunch = $this->upcomingLunches()->firstWhere('id', $lunchId) ?? Lunch::findOrFail($lunchId);

        try {
            app(LunchVotingService::class)->respond($lunch, auth()->user(), $mealOptionId);
            $this->cachedLunches = null; // re-query so the highlighted choice updates
            Notification::make()->title($success)->success()->send();
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
```

- [ ] **Step 4: Create the Blade view**

Create `resources/views/filament/pages/my-lunch.blade.php`:

```blade
<x-filament-panels::page>
    {{-- Inline + .dark styling: Filament's precompiled CSS can't carry arbitrary Tailwind/dark: utilities. --}}
    <style>
        .ml-card { background:#fff; border:1px solid #e5e7eb; border-radius:0.75rem; padding:1rem 1.25rem; margin-bottom:1rem; }
        .dark .ml-card { background:rgb(255 255 255 / 0.05); border-color:rgb(255 255 255 / 0.1); }
        .ml-date { font-size:0.75rem; font-weight:600; color:var(--primary-600,#d97706); text-transform:uppercase; letter-spacing:0.05em; }
        .ml-menu { font-size:1.125rem; font-weight:700; color:#111827; margin-top:0.125rem; }
        .dark .ml-menu { color:#f9fafb; }
        .ml-meta { font-size:0.8125rem; color:#6b7280; margin-top:0.125rem; }
        .dark .ml-meta { color:#9ca3af; }
        .ml-opts { display:flex; flex-wrap:wrap; gap:0.5rem; margin-top:0.875rem; }
        .ml-btn { display:inline-flex; align-items:center; padding:0.5rem 0.875rem; border-radius:0.5rem; border:1px solid rgb(0 0 0 / 0.1); background:#fff; color:#374151; cursor:pointer; font-size:0.875rem; font-weight:500; }
        .ml-btn:hover { background:#f9fafb; }
        .dark .ml-btn { background:rgb(255 255 255 / 0.05); border-color:rgb(255 255 255 / 0.1); color:#e5e7eb; }
        .dark .ml-btn:hover { background:rgb(255 255 255 / 0.1); }
        .ml-btn--active { background:var(--primary-600,#d97706); border-color:var(--primary-600,#d97706); color:#fff; }
        .dark .ml-btn--active { background:var(--primary-500,#f59e0b); border-color:var(--primary-500,#f59e0b); color:#1f2937; }
        .ml-locked { margin-top:0.75rem; font-size:0.875rem; color:#6b7280; }
        .dark .ml-locked { color:#9ca3af; }
        .ml-empty { text-align:center; padding:3rem 1rem; color:#6b7280; }
        .dark .ml-empty { color:#9ca3af; }
    </style>

    @php($responses = $this->myResponses())

    @forelse($this->upcomingLunches() as $lunch)
        @php($mine = $responses->get($lunch->id))
        <div class="ml-card">
            <div class="ml-date">{{ $lunch->date->format('l, j M') }}</div>
            <div class="ml-menu">{{ $lunch->catererMeal?->name ?? 'Menu' }}</div>
            <div class="ml-meta">{{ $lunch->caterer?->name }} &middot; Order by {{ $lunch->deadline->format('g:i A, D j M') }}</div>

            @if($lunch->isOpenForVoting())
                <div class="ml-opts">
                    @foreach($lunch->options->where('enabled', true) as $option)
                        @continue(! $option->mealOption)
                        <button type="button" wire:click="choose({{ $lunch->id }}, {{ $option->meal_option_id }})"
                            @class(['ml-btn', 'ml-btn--active' => $mine && $mine->meal_option_id === $option->meal_option_id])>
                            {{ $option->mealOption->name }}
                        </button>
                    @endforeach
                    <button type="button" wire:click="notAttending({{ $lunch->id }})"
                        @class(['ml-btn', 'ml-btn--active' => $mine && $mine->meal_option_id === null])>
                        Not attending
                    </button>
                </div>
            @else
                <div class="ml-locked">
                    Ordering closed.
                    @if($mine && $mine->meal_option_id)
                        You ordered: <strong>{{ $mine->mealOption?->name }}</strong>.
                    @else
                        You were marked not attending.
                    @endif
                </div>
            @endif
        </div>
    @empty
        <div class="ml-empty">No upcoming lunches to order right now.</div>
    @endforelse
</x-filament-panels::page>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter MyLunchPageTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Pages/MyLunch.php resources/views/filament/pages/my-lunch.blade.php tests/Feature/Lunch/MyLunchPageTest.php
git commit -m "feat(lunch): add My Lunch member voting page"
```

---

## Task 4: Publish notification (database + mail) + panel wiring

**Files:**
- Create: `database/migrations/<ts>_create_notifications_table.php` (via generator)
- Create: `app/Notifications/LunchPublished.php`
- Modify: `app/Providers/Filament/AccountPanelProvider.php`
- Modify: `app/Filament/Resources/Lunches/Pages/EditLunch.php`
- Test: `tests/Feature/Lunch/LunchPublishNotificationTest.php`

- [ ] **Step 1: Generate the notifications table migration**

Run: `php artisan make:notifications-table`
(This creates `database/migrations/<ts>_create_notifications_table.php` with Laravel's standard schema — a uuid id, morphs notifiable, type, data, read_at, timestamps. Leave it as generated.)

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Lunch/LunchPublishNotificationTest.php`:

```php
<?php

namespace Tests\Feature\Lunch;

use App\Filament\Resources\Lunches\Pages\EditLunch;
use App\Models\Lunch;
use App\Models\User;
use App\Notifications\LunchPublished;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class LunchPublishNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishing_notifies_every_user_on_both_channels(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        User::factory()->count(3)->create();
        $lunch = Lunch::factory()->create(['status' => 'draft', 'deadline' => now()->addDay()]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(EditLunch::class, ['record' => $lunch->getRouteKey()])
            ->callAction('publish');

        $this->assertSame('published', $lunch->fresh()->status);

        // 4 users total (admin + 3) each notified.
        Notification::assertSentTimes(LunchPublished::class, 4);
        Notification::assertSentTo($admin, LunchPublished::class, function (LunchPublished $n, array $channels) {
            return in_array('database', $channels, true) && in_array('mail', $channels, true);
        });
    }

    public function test_publish_action_is_hidden_once_published(): void
    {
        $admin = User::factory()->admin()->create();
        $lunch = Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addDay()]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(EditLunch::class, ['record' => $lunch->getRouteKey()])
            ->assertActionHidden('publish');
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `php artisan test --filter LunchPublishNotificationTest`
Expected: FAIL — `Class "App\Notifications\LunchPublished" not found`.

- [ ] **Step 4: Create the notification**

Create `app/Notifications/LunchPublished.php`. The `toDatabase()` returns a **Filament** notification payload (via `getDatabaseMessage()`) so it renders in the panel's notification bell, while `toMail()` builds the (logged) email — the documented way to combine Filament's database notifications with Laravel's mail channel:

```php
<?php

namespace App\Notifications;

use App\Filament\Pages\MyLunch;
use App\Models\Lunch;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LunchPublished extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Lunch $lunch) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Lunch menu for '.$this->lunch->date->format('D, j M'))
            ->greeting('Lunch is on the menu')
            ->line(($this->lunch->catererMeal?->name ?? 'A menu').' has been published for '.$this->lunch->date->format('l, j M').'.')
            ->line('Order by '.$this->lunch->deadline->format('g:i A, D j M').'.')
            ->action('Place your order', MyLunch::getUrl(panel: 'account'));
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Lunch published: '.($this->lunch->catererMeal?->name ?? 'Menu'))
            ->body('Order by '.$this->lunch->deadline->format('g:i A, D j M').'.')
            ->icon('heroicon-o-ticket')
            ->success()
            ->actions([
                Action::make('order')->label('Order now')->url(MyLunch::getUrl(panel: 'account'))->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
```

- [ ] **Step 5: Enable database notifications on the panel**

In `app/Providers/Filament/AccountPanelProvider.php`, add `->databaseNotifications()` to the panel chain (e.g. right after `->login()`):

```php
->login()
->databaseNotifications()
```

- [ ] **Step 6: Dispatch the notification from the publish action**

Replace `app/Filament/Resources/Lunches/Pages/EditLunch.php` with:

```php
<?php

namespace App\Filament\Resources\Lunches\Pages;

use App\Filament\Resources\Lunches\LunchResource;
use App\Models\User;
use App\Notifications\LunchPublished;
use App\Services\LunchPlanningService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Notification;

class EditLunch extends EditRecord
{
    protected static string $resource = LunchResource::class;

    protected function afterSave(): void
    {
        if ($this->record->wasChanged('caterer_meal_id')) {
            $this->record->options()->delete();
            app(LunchPlanningService::class)->syncOptionsFromMenu($this->record->fresh());
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->visible(fn () => $this->record->status === 'draft')
                ->requiresConfirmation()
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->action(function () {
                    $this->record->update(['status' => 'published']);
                    // Keep the form state in sync so a later Save doesn't write the stale 'draft' back.
                    $this->refreshFormData(['status']);

                    Notification::send(User::all(), new LunchPublished($this->record->fresh()));

                    FilamentNotification::make()
                        ->title('Published — members notified')
                        ->success()
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --filter LunchPublishNotificationTest`
Expected: PASS (2 tests).

- [ ] **Step 8: Commit**

```bash
git add database/migrations app/Notifications/LunchPublished.php app/Providers/Filament/AccountPanelProvider.php app/Filament/Resources/Lunches/Pages/EditLunch.php tests/Feature/Lunch/LunchPublishNotificationTest.php
git commit -m "feat(lunch): notify all users on publish (bell + logged email)"
```

---

## Task 5: Deadline auto-close command

**Files:**
- Create: `app/Console/Commands/CloseLunchOrders.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Lunch/CloseLunchOrdersTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Lunch/CloseLunchOrdersTest.php`:

```php
<?php

namespace Tests\Feature\Lunch;

use App\Models\Lunch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseLunchOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_closes_only_past_deadline_published_lunches(): void
    {
        $pastPublished = Lunch::factory()->create(['status' => 'published', 'deadline' => now()->subHour()]);
        $futurePublished = Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addHour()]);
        $pastDraft = Lunch::factory()->create(['status' => 'draft', 'deadline' => now()->subHour()]);

        $this->artisan('app:close-lunch-orders')->assertSuccessful();

        $this->assertSame('closed', $pastPublished->fresh()->status);
        $this->assertSame('published', $futurePublished->fresh()->status);
        $this->assertSame('draft', $pastDraft->fresh()->status);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter CloseLunchOrdersTest`
Expected: FAIL — command `app:close-lunch-orders` is not defined.

- [ ] **Step 3: Create the command**

Create `app/Console/Commands/CloseLunchOrders.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Lunch;
use Illuminate\Console\Command;

class CloseLunchOrders extends Command
{
    protected $signature = 'app:close-lunch-orders';

    protected $description = 'Close published lunches whose ordering deadline has passed';

    public function handle(): int
    {
        $closed = Lunch::where('status', 'published')
            ->where('deadline', '<', now())
            ->update(['status' => 'closed']);

        $this->info("Closed {$closed} lunch(es).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Schedule it**

In `routes/console.php`, add after the existing `Schedule::command('app:generate-monthly-fees')...` line:

```php
Schedule::command('app:close-lunch-orders')->everyFifteenMinutes();
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter CloseLunchOrdersTest`
Expected: PASS (1 test).

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/CloseLunchOrders.php routes/console.php tests/Feature/Lunch/CloseLunchOrdersTest.php
git commit -m "feat(lunch): scheduled command to close past-deadline lunches"
```

---

## Task 6: Order counts, Avg-Orders stat, calendar wiring, admin tally

**Files:**
- Modify: `app/Models/Lunch.php`
- Modify: `app/Services/LunchPlanningStats.php`
- Modify: `app/Filament/Pages/LunchCalendar.php`
- Modify: `resources/views/filament/pages/lunch-calendar.blade.php`
- Modify: `app/Filament/Resources/Lunches/Schemas/LunchForm.php`
- Test: `tests/Feature/Lunch/LunchOrderStatsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Lunch/LunchOrderStatsTest.php`:

```php
<?php

namespace Tests\Feature\Lunch;

use App\Models\Lunch;
use App\Models\LunchResponse;
use App\Models\MealOption;
use App\Models\User;
use App\Services\LunchPlanningStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LunchOrderStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_lunch_count_helpers(): void
    {
        $lunch = Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addDay()]);
        $beef = MealOption::factory()->create();
        $chicken = MealOption::factory()->create();

        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => User::factory()->create()->id, 'meal_option_id' => $beef->id]);
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => User::factory()->create()->id, 'meal_option_id' => $beef->id]);
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => User::factory()->create()->id, 'meal_option_id' => $chicken->id]);
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => User::factory()->create()->id, 'meal_option_id' => null]);

        $lunch->refresh()->load('responses');

        $this->assertSame(3, $lunch->attendingCount());
        $this->assertSame(1, $lunch->notAttendingCount());
        $this->assertSame([$beef->id => 2, $chicken->id => 1], $lunch->optionCounts());
    }

    public function test_avg_orders_per_day_over_published_or_closed_lunches(): void
    {
        $month = now()->startOfMonth();
        $stats = app(LunchPlanningStats::class);

        // Empty month → 0.0
        $this->assertSame(0.0, $stats->avgOrdersPerDay($month));

        // Two opened lunches: 4 attending + 2 attending → avg 3.0
        $a = Lunch::factory()->create(['date' => $month->copy()->addDays(1)->toDateString(), 'status' => 'published', 'deadline' => now()->addDay()]);
        $b = Lunch::factory()->create(['date' => $month->copy()->addDays(2)->toDateString(), 'status' => 'closed', 'deadline' => now()->subDay()]);
        // A draft in the same month must NOT drag the average down.
        Lunch::factory()->create(['date' => $month->copy()->addDays(3)->toDateString(), 'status' => 'draft', 'deadline' => now()->addDay()]);

        $option = MealOption::factory()->create();
        foreach (range(1, 4) as $i) {
            LunchResponse::create(['lunch_id' => $a->id, 'user_id' => User::factory()->create()->id, 'meal_option_id' => $option->id]);
        }
        foreach (range(1, 2) as $i) {
            LunchResponse::create(['lunch_id' => $b->id, 'user_id' => User::factory()->create()->id, 'meal_option_id' => $option->id]);
        }

        $this->assertSame(3.0, $stats->avgOrdersPerDay($month));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter LunchOrderStatsTest`
Expected: FAIL — `Call to undefined method App\Models\Lunch::attendingCount()`.

- [ ] **Step 3: Add count helpers to `Lunch`**

In `app/Models/Lunch.php`, add (these read the `responses` relation, so eager-loading with `->with('responses')` avoids extra queries):

```php
public function attendingCount(): int
{
    return $this->responses->whereNotNull('meal_option_id')->count();
}

public function notAttendingCount(): int
{
    return $this->responses->whereNull('meal_option_id')->count();
}

/** @return array<int, int> meal_option_id => count */
public function optionCounts(): array
{
    return $this->responses
        ->whereNotNull('meal_option_id')
        ->countBy('meal_option_id')
        ->map(fn ($count) => (int) $count)
        ->all();
}
```

- [ ] **Step 4: Add `avgOrdersPerDay()` to `LunchPlanningStats`**

In `app/Services/LunchPlanningStats.php`, add:

```php
public function avgOrdersPerDay(Carbon $month): float
{
    $lunches = Lunch::whereBetween('date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
        ->whereIn('status', ['published', 'closed'])
        ->with('responses')
        ->get();

    if ($lunches->isEmpty()) {
        return 0.0;
    }

    return round($lunches->sum(fn (Lunch $lunch) => $lunch->attendingCount()) / $lunches->count(), 1);
}
```

- [ ] **Step 5: Run the stats test to verify it passes**

Run: `php artisan test --filter LunchOrderStatsTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Expose `avgOrdersPerDay` on the calendar page**

In `app/Filament/Pages/LunchCalendar.php`, add a method beside `estimatedCost()`:

```php
public function avgOrdersPerDay(): float
{
    return app(LunchPlanningStats::class)->avgOrdersPerDay($this->monthStart());
}
```

And eager-load responses for the per-day badge — in `lunchesByDate()`, change the `with([...])` to include `responses`:

```php
return Lunch::with(['catererMeal', 'options.mealOption', 'responses'])
    ->whereBetween('date', [$start, $end])->get()
    ->keyBy(fn (Lunch $l) => $l->date->toDateString());
```

- [ ] **Step 7: Wire the Avg-Orders card + per-day badge in the calendar Blade**

In `resources/views/filament/pages/lunch-calendar.blade.php`:

Replace the middle stat card (currently the `&mdash;` / "available in Phase 3" block) with:

```blade
<x-filament::section>
    <div style="text-align:center;padding:0.5rem 0;">
        <div style="font-size:1.75rem;font-weight:700;color:var(--primary-600,#d97706);line-height:1.2;">
            {{ number_format($this->avgOrdersPerDay(), 1) }}
        </div>
        <div style="font-size:0.8125rem;color:var(--gray-500,#6b7280);margin-top:0.25rem;font-weight:500;">Avg. Orders/Day</div>
        <div style="font-size:0.75rem;color:var(--gray-400,#9ca3af);margin-top:0.125rem;">published days this month</div>
    </div>
</x-filament::section>
```

Inside the menu card (the `<a ... class="lc-menucard">` block), add an order count below the options badges — replace the badges `<div>` close so the card reads:

```blade
<a href="{{ $this->editUrlFor($lunch) }}" class="lc-menucard">
    <div class="lc-menucard__title">{{ $lunch->catererMeal?->name ?? 'Menu' }}</div>
    <div style="display:flex;flex-wrap:wrap;gap:0.25rem;">
        @foreach($lunch->options->where('enabled', true) as $option)
            @if($option->mealOption)
                <x-filament::badge color="primary" size="sm">{{ $option->mealOption->name }}</x-filament::badge>
            @endif
        @endforeach
    </div>
    @if(in_array($lunch->status, ['published', 'closed'], true))
        <div style="font-size:0.6875rem;color:var(--gray-500,#6b7280);margin-top:0.25rem;font-weight:600;">{{ $lunch->attendingCount() }} ordered</div>
    @endif
</a>
```

- [ ] **Step 8: Add the admin "Current orders" tally to the Lunch form**

In `app/Filament/Resources/Lunches/Schemas/LunchForm.php`, add the import:

```php
use Filament\Forms\Components\Placeholder;
```

and append this component to the `->components([...])` array (after the `Repeater`):

```php
Placeholder::make('orders_summary')
    ->label('Current orders')
    ->visibleOn('edit')
    ->columnSpanFull()
    ->content(function (?\App\Models\Lunch $record): string {
        if (! $record) {
            return '—';
        }

        $counts = $record->optionCounts();
        $perOption = $record->options
            ->map(fn ($o) => ($o->mealOption?->name ?? 'Option').': '.($counts[$o->meal_option_id] ?? 0))
            ->implode('   ·   ');

        return trim($perOption)
            .'      Not attending: '.$record->notAttendingCount()
            .'      Total ordering: '.$record->attendingCount();
    }),
```

- [ ] **Step 9: Verify the calendar still renders and the tally shows**

Add a render check to `tests/Feature/Lunch/LunchOrderStatsTest.php`:

```php
public function test_edit_page_shows_the_orders_tally(): void
{
    $lunch = \App\Models\Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addDay()]);
    \App\Models\LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => \App\Models\User::factory()->create()->id, 'meal_option_id' => null]);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('account'));

    \Livewire\Livewire::test(\App\Filament\Resources\Lunches\Pages\EditLunch::class, ['record' => $lunch->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Current orders');
}
```

Run: `php artisan test --filter LunchOrderStatsTest`
Expected: PASS (3 tests).

- [ ] **Step 10: Commit**

```bash
git add app/Models/Lunch.php app/Services/LunchPlanningStats.php app/Filament/Pages/LunchCalendar.php resources/views/filament/pages/lunch-calendar.blade.php app/Filament/Resources/Lunches/Schemas/LunchForm.php tests/Feature/Lunch/LunchOrderStatsTest.php
git commit -m "feat(lunch): real order counts on calendar + admin tally"
```

---

## Task 7: Full verification, Supabase migration, style & smoke, finalize

**Files:** none (verification + ops).

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: all green — the prior 56 tests plus the new Phase-3 tests (LunchResponse 3, Voting 4, MyLunch 5, Notification 2, Close 1, OrderStats 3).

- [ ] **Step 2: Lint with Pint**

Run: `vendor/bin/pint --dirty`
Expected: clean (it fixes style in place; re-run `php artisan test` if it touched anything).

- [ ] **Step 3: Apply the two new migrations to Supabase**

These are additive (two new tables: `lunch_responses`, `notifications`). Confirm them, then:

Run: `php artisan migrate`
Expected: `create_lunch_responses_table` and `create_notifications_table` run successfully against the Supabase Postgres database.

- [ ] **Step 4: Smoke-test both roles**

Start the server (`php artisan serve`) if not running. As an **admin**: publish a draft lunch → expect the "Published — members notified" toast and a bell notification; open the Lunch edit page → see "Current orders"; open Menu Calendar → Avg. Orders/Day shows a number and planned days show "N ordered". As a **member** (`member@silentmode.my`): open "My Lunch" → see the published lunch, pick an option, see it highlight, switch to "Not attending"; confirm the bell shows the publish notification. Check `storage/logs/laravel.log` for the rendered (stubbed) email.

- [ ] **Step 5: Finalize**

Use superpowers:finishing-a-development-branch. (Work is on `main` per the user's standing preference; verify the suite is green, then confirm with the user whether to leave the Phase-3 commits as-is on `main`.)

---

## Self-Review (completed during planning)

- **Spec coverage:** LunchResponse (T1) · isOpenForVoting + guarded respond (T2) · card-list My Lunch page, all users, editable-until-deadline + locked (T3) · publish notification database+mail to all users + panel `databaseNotifications` (T4) · scheduled close command (T5) · attending/option/not-attending counts, avgOrdersPerDay, calendar wiring, admin tally (T6) · verify + Supabase + smoke (T7). All spec sections map to a task.
- **Type consistency:** `respond(Lunch, User, ?int): LunchResponse` (throws `RuntimeException`) used identically in T2 service, T3 page; `attendingCount(): int`, `optionCounts(): array<int,int>`, `notAttendingCount(): int`, `avgOrdersPerDay(Carbon): float` consistent across T6 model/service/calendar/tests; `meal_option_id` cast to `integer` (T1) so the Blade `===` comparisons in T3 are sound.
- **No placeholders:** every code step shows complete code; the only `<ts>` tokens are real migration timestamps produced by the generators in T1/T4.

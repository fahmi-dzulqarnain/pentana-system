# Lunch Planner — Phase 2 (Planning) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Admins plan a lunch per date — caterer, menu, per-option enable + default count, deadline, publish — via a custom month-view calendar. Replaces the `LunchPlan` scaffold.

**Architecture:** New `Lunch` + `LunchOption` Eloquent models (exercised on sqlite). Options are auto-generated from the chosen menu via a testable `LunchPlanningService`. Cost/working-day math is unit-tested on the `Lunch` model + a stats helper. Admin UI = a `LunchResource` (forms + Publish) plus a custom `LunchCalendar` Filament Page (month grid + 3 stat cards) matching the approved mockup. All admin-only, group "Welfare & Social".

**Tech Stack:** Laravel 12, Filament 5, PHP 8.5, PostgreSQL (Supabase)/sqlite, Pint, PHPUnit, Carbon.

---

## File structure

- **Remove:** `app/Models/LunchPlan.php`, `app/Filament/Resources/LunchPlans/` (whole dir); new migration dropping `lunch_plans`; remove LunchPlan assertions from `tests/Feature/WelfareActivityAccessTest.php`.
- **Models:** `app/Models/Lunch.php`, `app/Models/LunchOption.php`
- **Migrations:** drop `lunch_plans`; create `lunches`, `lunch_options`
- **Factories:** `LunchFactory`, `LunchOptionFactory`
- **Service:** `app/Services/LunchPlanningService.php` (`syncOptionsFromMenu`)
- **Filament:** `app/Filament/Resources/Lunches/` (resource, form, table, pages) + `app/Filament/Pages/LunchCalendar.php` + `resources/views/filament/pages/lunch-calendar.blade.php`
- **Tests:** `tests/Feature/Lunch/LunchPlanningTest.php` (sync + cost + working days), `tests/Feature/Lunch/LunchResourceTest.php` (access, publish, options-on-create), `tests/Feature/Lunch/LunchCalendarTest.php` (renders, stats, access)

---

## Task 1: Remove LunchPlan scaffold; add Lunch + LunchOption

**Files:** delete LunchPlan model + `LunchPlans/` resource dir; create migrations `..._drop_lunch_plans_table.php`, `..._create_lunches_table.php`, `..._create_lunch_options_table.php`, models, factories; edit `tests/Feature/WelfareActivityAccessTest.php`.

- [ ] **Step 1: Remove the scaffold.** Delete `app/Models/LunchPlan.php` and the directory `app/Filament/Resources/LunchPlans/`. In `tests/Feature/WelfareActivityAccessTest.php`, delete the three LunchPlan tests (`test_members_can_view_lunch_but_not_manage_it`, `test_admins_can_manage_lunch`, `test_lunch_navigation_label_depends_on_role`) and the `LunchPlanResource` import. Keep the AutoEmail/Activity tests.

- [ ] **Step 2: Drop-table migration** `..._drop_lunch_plans_table.php`:

```php
public function up(): void { Schema::dropIfExists('lunch_plans'); }
public function down(): void {
    Schema::create('lunch_plans', function (Blueprint $table) {
        $table->id(); $table->date('date')->index(); $table->string('menu');
        $table->text('notes')->nullable(); $table->timestamps();
    });
}
```

- [ ] **Step 3: `lunches` migration:**

```php
Schema::create('lunches', function (Blueprint $table) {
    $table->id();
    $table->date('date')->unique();
    $table->foreignId('caterer_id')->constrained()->cascadeOnDelete();
    $table->foreignId('caterer_meal_id')->constrained()->cascadeOnDelete();
    $table->dateTime('deadline');
    $table->string('status')->default('draft'); // draft, published, closed
    $table->timestamps();
});
```

- [ ] **Step 4: `lunch_options` migration:**

```php
Schema::create('lunch_options', function (Blueprint $table) {
    $table->id();
    $table->foreignId('lunch_id')->constrained()->cascadeOnDelete();
    $table->foreignId('meal_option_id')->constrained()->cascadeOnDelete();
    $table->boolean('enabled')->default(true);
    $table->unsignedInteger('default_count')->default(0);
    $table->timestamps();
    $table->unique(['lunch_id', 'meal_option_id']);
});
```

- [ ] **Step 5: `app/Models/Lunch.php`:**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lunch extends Model
{
    use HasFactory;

    protected $table = 'lunches';

    protected $fillable = ['date', 'caterer_id', 'caterer_meal_id', 'deadline', 'status'];

    protected $casts = ['date' => 'date', 'deadline' => 'datetime'];

    public function caterer(): BelongsTo
    {
        return $this->belongsTo(Caterer::class);
    }

    public function catererMeal(): BelongsTo
    {
        return $this->belongsTo(CatererMeal::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(LunchOption::class);
    }

    /** Planned cost: each enabled option's default_count × its effective price on this menu. */
    public function estimatedCost(): float
    {
        $prices = $this->catererMeal?->options->keyBy('meal_option_id') ?? collect();

        return round($this->options->where('enabled', true)->sum(
            fn (LunchOption $o) => $o->default_count * (float) ($prices->get($o->meal_option_id)?->effective_price ?? 0)
        ), 2);
    }
}
```
(`$table = 'lunches'` because Laravel would otherwise pluralize "Lunch" to "lunchs".)

- [ ] **Step 6: `app/Models/LunchOption.php`:**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LunchOption extends Model
{
    use HasFactory;

    protected $fillable = ['lunch_id', 'meal_option_id', 'enabled', 'default_count'];

    protected $casts = ['enabled' => 'boolean', 'default_count' => 'integer'];

    public function lunch(): BelongsTo
    {
        return $this->belongsTo(Lunch::class);
    }

    public function mealOption(): BelongsTo
    {
        return $this->belongsTo(MealOption::class);
    }
}
```

- [ ] **Step 7: Factories.** `LunchFactory`: `date` => a weekday, `caterer_id` => `Caterer::factory()`, `caterer_meal_id` => `CatererMeal::factory()`, `deadline` => `now()->addDays(3)`, `status` => 'draft'. `LunchOptionFactory`: `lunch_id` => `Lunch::factory()`, `meal_option_id` => `MealOption::factory()`, `enabled` true, `default_count` 0.

- [ ] **Step 8: Run & commit.** `php artisan test` → green (LunchPlan tests gone, no new ones yet). Pint changed files.
```bash
git add -A && git commit -m "Lunch planning: drop LunchPlan scaffold; add Lunch + LunchOption"
```

---

## Task 2: `syncOptionsFromMenu` service (TDD)

**Files:** create `app/Services/LunchPlanningService.php`; test `tests/Feature/Lunch/LunchPlanningTest.php`.

- [ ] **Step 1: Failing test** `tests/Feature/Lunch/LunchPlanningTest.php`:

```php
<?php

namespace Tests\Feature\Lunch;

use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\CatererMealOption;
use App\Models\Lunch;
use App\Models\MealOption;
use App\Services\LunchPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LunchPlanningTest extends TestCase
{
    use RefreshDatabase;

    private function lunchWithMenu(array $optionPrices): Lunch
    {
        $caterer = Caterer::factory()->create();
        $meal = CatererMeal::create(['caterer_id' => $caterer->id, 'name' => 'Set A', 'price' => 10]);
        foreach ($optionPrices as $name => $price) {
            $opt = MealOption::factory()->create(['name' => $name]);
            CatererMealOption::create(['caterer_meal_id' => $meal->id, 'meal_option_id' => $opt->id, 'price' => $price]);
        }

        return Lunch::create([
            'date' => '2025-07-01', 'caterer_id' => $caterer->id,
            'caterer_meal_id' => $meal->id, 'deadline' => '2025-06-30 12:00', 'status' => 'draft',
        ]);
    }

    public function test_sync_creates_one_enabled_option_per_menu_option(): void
    {
        $lunch = $this->lunchWithMenu(['Beef' => 15, 'Vegan' => null]);

        app(LunchPlanningService::class)->syncOptionsFromMenu($lunch);

        $this->assertSame(2, $lunch->options()->count());
        $this->assertTrue((bool) $lunch->options()->first()->enabled);
        $this->assertSame(0, $lunch->options()->first()->default_count);
    }

    public function test_sync_is_idempotent(): void
    {
        $lunch = $this->lunchWithMenu(['Beef' => 15, 'Vegan' => null]);
        $service = app(LunchPlanningService::class);

        $service->syncOptionsFromMenu($lunch);
        $service->syncOptionsFromMenu($lunch->fresh());

        $this->assertSame(2, $lunch->options()->count());
    }
}
```

- [ ] **Step 2: Run → RED** (`LunchPlanningService` missing).

- [ ] **Step 3: Implement** `app/Services/LunchPlanningService.php`:

```php
<?php

namespace App\Services;

use App\Models\Lunch;

class LunchPlanningService
{
    /** Ensure the lunch has one option row per its menu's options (idempotent). */
    public function syncOptionsFromMenu(Lunch $lunch): void
    {
        $lunch->loadMissing('catererMeal.options');

        foreach ($lunch->catererMeal->options as $catererMealOption) {
            $lunch->options()->firstOrCreate(
                ['meal_option_id' => $catererMealOption->meal_option_id],
                ['enabled' => true, 'default_count' => 0],
            );
        }
    }
}
```

- [ ] **Step 4: Run → GREEN.** Commit `Lunch planning: syncOptionsFromMenu service`.

---

## Task 3: Estimated cost + working-day stats (TDD)

**Files:** add a stats helper `app/Services/LunchPlanningStats.php`; extend `tests/Feature/Lunch/LunchPlanningTest.php`.

- [ ] **Step 1: Failing tests** (append to `LunchPlanningTest`):

```php
public function test_estimated_cost_uses_enabled_options_and_effective_price(): void
{
    $lunch = $this->lunchWithMenu(['Beef' => 15, 'Vegan' => null]); // Vegan falls back to meal price 10
    app(\App\Services\LunchPlanningService::class)->syncOptionsFromMenu($lunch);
    $lunch->load('options');
    // Beef: 4 × 15 = 60 ; Vegan: 2 × 10 = 20 ; total 80
    $lunch->options()->where('meal_option_id', $lunch->options[0]->meal_option_id)->update(['default_count' => 4]);
    $lunch->options()->where('meal_option_id', $lunch->options[1]->meal_option_id)->update(['default_count' => 2]);

    $this->assertSame(80.0, $lunch->fresh()->load('catererMeal.options', 'options')->estimatedCost());
}

public function test_working_days_counts_weekdays_in_month(): void
{
    // July 2025 has 23 weekdays (Mon–Fri)
    $this->assertSame(23, app(\App\Services\LunchPlanningStats::class)->workingDays(\Carbon\Carbon::parse('2025-07-15')));
}

public function test_month_estimated_cost_sums_lunches(): void
{
    $stats = app(\App\Services\LunchPlanningStats::class);
    $lunch = $this->lunchWithMenu(['Beef' => 15]);
    app(\App\Services\LunchPlanningService::class)->syncOptionsFromMenu($lunch);
    $lunch->options()->update(['default_count' => 3]); // 3 × 15 = 45

    $this->assertSame(45.0, $stats->estimatedCostForMonth(\Carbon\Carbon::parse('2025-07-10')));
    $this->assertSame(1, $stats->daysPlanned(\Carbon\Carbon::parse('2025-07-10')));
}
```

- [ ] **Step 2: Run → RED.**

- [ ] **Step 3: Implement** `app/Services/LunchPlanningStats.php`:

```php
<?php

namespace App\Services;

use App\Models\Lunch;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class LunchPlanningStats
{
    public function workingDays(Carbon $month): int
    {
        return collect(CarbonPeriod::create($month->copy()->startOfMonth(), $month->copy()->endOfMonth()))
            ->filter(fn (Carbon $d) => $d->isWeekday())
            ->count();
    }

    public function daysPlanned(Carbon $month): int
    {
        return $this->lunchesForMonth($month)->count();
    }

    public function estimatedCostForMonth(Carbon $month): float
    {
        return round(
            $this->lunchesForMonth($month)
                ->load('catererMeal.options', 'options')
                ->sum(fn (Lunch $lunch) => $lunch->estimatedCost()),
            2
        );
    }

    private function lunchesForMonth(Carbon $month)
    {
        return Lunch::whereBetween('date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])->get();
    }
}
```

- [ ] **Step 4: Run → GREEN.** Commit `Lunch planning: estimated cost + working-day stats`.

---

## Task 4: LunchResource (admin) — forms, options, publish

**Files:** generate `app/Filament/Resources/Lunches/...`; test `tests/Feature/Lunch/LunchResourceTest.php`.

- [ ] **Step 1: Generate** `php artisan make:filament-resource Lunch --no-interaction`. (It will use the `Lunch` model / `lunches` table.)

- [ ] **Step 2: Customize the resource** — admin-only group "Welfare & Social" (mirror `MealResource`): `use UnitEnum;`, `navigationGroup = 'Welfare & Social'`, `navigationLabel = 'Lunch Planner'`, `canAccess() => is_admin`. Add navigation icon `Heroicon::OutlinedCalendarDays`.

- [ ] **Step 3: Create/Edit form** (`Schemas/LunchForm.php`):

```php
DatePicker::make('date')->required()->unique(ignoreRecord: true),
Select::make('caterer_id')->relationship('caterer', 'name')->required()->live(),
Select::make('caterer_meal_id')
    ->label('Menu')
    ->options(fn (Get $get) => $get('caterer_id')
        ? \App\Models\CatererMeal::where('caterer_id', $get('caterer_id'))->pluck('name', 'id')
        : [])
    ->required(),
DateTimePicker::make('deadline')->required(),
Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published', 'closed' => 'Closed'])
    ->default('draft')->disabled()->dehydrated(),
// Per-option enable + count (editable on the edit page; rows are auto-generated on create)
Repeater::make('options')
    ->relationship('options')
    ->schema([
        Select::make('meal_option_id')->relationship('mealOption', 'name')->disabled(),
        Toggle::make('enabled')->default(true),
        TextInput::make('default_count')->numeric()->default(0)->minValue(0),
    ])
    ->columns(3)->columnSpanFull()->addable(false)->deletable(false),
```
(imports: `Filament\Forms\Components\{DatePicker, DateTimePicker, Select, Toggle, TextInput, Repeater}`, `Filament\Schemas\Components\Utilities\Get`.)

- [ ] **Step 4: Auto-generate options on create.** In `Pages/CreateLunch.php`, add `afterCreate()` that calls the service:

```php
protected function afterCreate(): void
{
    app(\App\Services\LunchPlanningService::class)->syncOptionsFromMenu($this->record);
}
```

- [ ] **Step 5: Publish action.** In `Pages/EditLunch.php` `getHeaderActions()`, add an Action:

```php
Action::make('publish')
    ->visible(fn () => $this->record->status === 'draft')
    ->requiresConfirmation()
    ->action(fn () => $this->record->update(['status' => 'published'])),
```

- [ ] **Step 6: Table** (`Tables/LunchesTable.php`): columns `date` (date, sortable), `caterer.name`, `catererMeal.name` (label 'Menu'), `status` (badge: draft=gray/published=success/closed=warning, default arm), `deadline` (dateTime). defaultSort `date` desc.

- [ ] **Step 7: Tests** `tests/Feature/Lunch/LunchResourceTest.php`:

```php
public function test_lunch_resource_is_admin_only(): void { /* member→false, admin→true on LunchResource::canAccess() */ }

public function test_creating_a_lunch_generates_its_options(): void
{
    // admin; create a caterer+meal with 2 options; Livewire CreateLunch fill + create;
    // assert the created Lunch has 2 LunchOption rows.
}

public function test_publish_moves_draft_to_published(): void
{
    // make a draft Lunch; Livewire EditLunch ->callAction('publish'); assert status published.
}
```
(Use `Livewire::test` + `Filament::setCurrentPanel('account')` as in existing tests. Full code mirrors `tests/Feature/Lunch/CatalogAccessTest.php` patterns.)

- [ ] **Step 8: Run → green. Commit** `Lunch planning: LunchResource with publish + auto-generated options`.

---

## Task 5: LunchCalendar custom page (month grid + 3 cards)

**Files:** `app/Filament/Pages/LunchCalendar.php`, `resources/views/filament/pages/lunch-calendar.blade.php`; test `tests/Feature/Lunch/LunchCalendarTest.php`.

- [ ] **Step 1: Page class** `app/Filament/Pages/LunchCalendar.php` — admin-only, group "Welfare & Social". Holds `public string $month` (YYYY-MM, default current). Public methods: `previousMonth()`, `nextMonth()`, `today()` (Livewire actions adjusting `$month`); `weeks()` returns the month grid (array of weeks → days, each `['date','inMonth','lunch']`); `daysPlanned()`, `workingDays()`, `estimatedCost()` (delegate to `LunchPlanningStats`). `canAccess()` => is_admin. `protected static string $view = 'filament.pages.lunch-calendar';` Add a header action **Add Menu** linking to `LunchResource::getUrl('create')`.

```php
public static function canAccess(): bool { return (bool) auth()->user()?->is_admin; }

public function getLunchesByDate(): \Illuminate\Support\Collection
{
    $m = \Carbon\Carbon::parse($this->month.'-01');
    return \App\Models\Lunch::with('catererMeal', 'options.mealOption')
        ->whereBetween('date', [$m->copy()->startOfMonth(), $m->copy()->endOfMonth()])
        ->get()->keyBy(fn ($l) => $l->date->toDateString());
}
```
(Grid: iterate `CarbonPeriod` from `startOfMonth()->startOfWeek(Sunday)` to `endOfMonth()->endOfWeek(Saturday)` in 7s.)

- [ ] **Step 2: Blade view** `resources/views/filament/pages/lunch-calendar.blade.php` — within `<x-filament-panels::page>`: header with `{{ \Carbon\Carbon::parse($month.'-01')->format('F Y') }}`, prev/next/today buttons (`wire:click`), and 3 stat cards (Days Planned `{{ $this->daysPlanned() }} of {{ $this->workingDays() }}`; Avg. Orders/Day `—`; Estimated cost `MYR {{ number_format($this->estimatedCost(), 2) }}`). Then a 7-col grid; each day cell: if a lunch exists, show menu name + option badges (link to `LunchResource::getUrl('edit', ['record' => $lunch])`); else a "Set menu" link to `LunchResource::getUrl('create')`. Use inline styles for any non-Filament utility classes (Filament's precompiled CSS lacks arbitrary Tailwind — see Phase-1 receipt lesson).

- [ ] **Step 3: Tests** `tests/Feature/Lunch/LunchCalendarTest.php`: `canAccess` admin-only; `Livewire::test(LunchCalendar::class)->assertSuccessful()`; create a lunch in the current month and assert the page shows its menu name and the correct `daysPlanned()`/`estimatedCost()`.

- [ ] **Step 4: Run → green. Commit** `Lunch planning: LunchCalendar month view`.

---

## Task 6: Verify, migrate Supabase, finalize

- [ ] **Step 1:** `php artisan test` → all green.
- [ ] **Step 2:** `./vendor/bin/pint app/Models/Lunch.php app/Models/LunchOption.php app/Services/LunchPlanning*.php app/Filament/Resources/Lunches app/Filament/Pages/LunchCalendar.php tests/Feature/Lunch` → PASS.
- [ ] **Step 3:** `php artisan migrate --force` (Supabase) — drops `lunch_plans`, creates `lunches` + `lunch_options`.
- [ ] **Step 4:** restart `php artisan serve`; as admin confirm "Lunch Planner" + the calendar render; member no longer sees a Lunch item.
- [ ] **Step 5:** final commit if Pint changed anything.

---

## Notes
- `Lunch` needs `protected $table = 'lunches'` (Laravel pluralizes "Lunch" wrong).
- Mirror existing admin-only resource pattern (`canAccess`/`use UnitEnum`/group) and the existing Filament test harness (`Filament::setCurrentPanel('account')` + `Livewire::test`).
- Tests on sqlite `:memory:`; never touch Supabase in tests. Filter PHP 8.5 deprecation noise with `grep -vE 'Deprecation Notice|curl_close'`.
- Custom calendar styling: use inline styles for non-Filament utilities (precompiled CSS limitation learned in Phase 1).

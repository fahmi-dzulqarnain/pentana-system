# Lunch Planner — Phase 1 (Catalog) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the lunch *catalog* — admin-configurable meal options, a global meal database, caterers, and per-caterer menus (copied from the catalog with per-option price overrides).

**Architecture:** Plain Eloquent models + migrations, exercised by feature tests on sqlite (`:memory:`). Filament v5 admin resources (admin-only, group "Welfare & Social") generated via `make:filament-resource` then customized. The caterer's menu is a relation manager on `CatererResource` with a "Copy from catalog" header action backed by a testable service method. Per-option pricing lives on an explicit `CatererMealOption` model (cleaner for the Repeater UI than a pivot-with-columns); effective price = `option.price ?? catererMeal.price`.

**Tech Stack:** Laravel 12, Filament 5, PHP 8.5, PostgreSQL (Supabase) in prod / sqlite in tests, Pint, PHPUnit.

---

## File structure

**Models (`app/Models/`)**
- `MealOption.php` — option type (Beef/Chicken/Vegan/…)
- `Meal.php` — catalog meal; belongsToMany `MealOption`
- `Caterer.php` — caterer; hasMany `CatererMeal`
- `CatererMeal.php` — a caterer's menu item; belongsTo `Caterer`/`Meal`; hasMany `CatererMealOption`
- `CatererMealOption.php` — per-option price override; `effective_price` accessor

**Migrations (`database/migrations/`)** — `meal_options`, `meals`, `meal_meal_option` (pivot), `caterers`, `caterer_meals`, `caterer_meal_options`

**Factories (`database/factories/`)** — `MealOptionFactory`, `MealFactory`, `CatererFactory`, `CatererMealFactory`

**Service (`app/Services/`)**
- `LunchCatalogService.php` — `copyMealToCaterer(Meal, Caterer): CatererMeal`

**Filament (`app/Filament/Resources/`)** — `MealOptions/`, `Meals/`, `Caterers/` (+ `Caterers/RelationManagers/CatererMealsRelationManager.php`)

**Tests (`tests/Feature/`)** — `Lunch/CatalogPricingTest.php`, `Lunch/CopyMealToCatererTest.php`, `Lunch/CatalogAccessTest.php`

---

## Task 1: MealOption model, migration, factory + resource

**Files:**
- Create: `app/Models/MealOption.php`, `database/migrations/2026_06_23_000001_create_meal_options_table.php`, `database/factories/MealOptionFactory.php`
- Test: `tests/Feature/Lunch/CatalogAccessTest.php`

- [ ] **Step 1: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_options', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_options');
    }
};
```

- [ ] **Step 2: Model** — `app/Models/MealOption.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MealOption extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'is_active', 'sort'];

    protected $casts = ['is_active' => 'boolean'];
}
```

- [ ] **Step 3: Factory** — `database/factories/MealOptionFactory.php`

```php
<?php

namespace Database\Factories;

use App\Models\MealOption;
use Illuminate\Database\Eloquent\Factories\Factory;

class MealOptionFactory extends Factory
{
    protected $model = MealOption::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Beef', 'Chicken', 'Vegan', 'Seafood']),
            'is_active' => true,
            'sort' => 0,
        ];
    }
}
```

- [ ] **Step 4: Generate the resource**

Run: `php artisan make:filament-resource MealOption --no-interaction`
Expected: creates `app/Filament/Resources/MealOptions/...`

- [ ] **Step 5: Customize the resource** — `app/Filament/Resources/MealOptions/MealOptionResource.php`

Add (after the `$navigationIcon` line) the group + admin-only gate, and `use UnitEnum;`:

```php
    protected static string|UnitEnum|null $navigationGroup = 'Welfare & Social';

    protected static ?string $navigationLabel = 'Meal Options';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }
```

Fill `Schemas/MealOptionForm.php` components:

```php
TextInput::make('name')->required()->maxLength(255),
Toggle::make('is_active')->label('Active')->default(true),
TextInput::make('sort')->numeric()->default(0),
```

Fill `Tables/MealOptionsTable.php` columns:

```php
TextColumn::make('name')->searchable()->sortable(),
IconColumn::make('is_active')->boolean(),
TextColumn::make('sort')->sortable(),
```

- [ ] **Step 6: Write the access test** — `tests/Feature/Lunch/CatalogAccessTest.php`

```php
<?php

namespace Tests\Feature\Lunch;

use App\Filament\Resources\MealOptions\MealOptionResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_meal_options_are_admin_only(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertFalse(MealOptionResource::canAccess());

        $this->actingAs(User::factory()->admin()->create());
        $this->assertTrue(MealOptionResource::canAccess());
    }
}
```

- [ ] **Step 7: Run & verify**

Run: `php artisan test tests/Feature/Lunch/CatalogAccessTest.php`
Expected: PASS (1 test). If first run before Step 5, it FAILS (member canAccess true) — that's the RED.

- [ ] **Step 8: Commit**

```bash
git add app/Models/MealOption.php database/migrations/*_create_meal_options_table.php database/factories/MealOptionFactory.php app/Filament/Resources/MealOptions tests/Feature/Lunch/CatalogAccessTest.php
git commit -m "Lunch catalog: add MealOption (admin-configurable options)"
```

---

## Task 2: Caterer model, migration, factory + resource

**Files:**
- Create: `app/Models/Caterer.php`, `database/migrations/2026_06_23_000002_create_caterers_table.php`, `database/factories/CatererFactory.php`
- Modify: `tests/Feature/Lunch/CatalogAccessTest.php`

- [ ] **Step 1: Migration**

```php
Schema::create('caterers', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('contact_name')->nullable();
    $table->string('phone')->nullable();
    $table->string('email')->nullable();
    $table->text('notes')->nullable();
    $table->timestamps();
});
```
(Standard migration wrapper + `down()` dropping `caterers`.)

- [ ] **Step 2: Model** — `app/Models/Caterer.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Caterer extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'contact_name', 'phone', 'email', 'notes'];

    public function meals(): HasMany
    {
        return $this->hasMany(CatererMeal::class);
    }
}
```

- [ ] **Step 3: Factory** — name via `fake()->company()`, others nullable.

- [ ] **Step 4: Generate + customize resource**

Run: `php artisan make:filament-resource Caterer --no-interaction`
Then customize `CatererResource.php`: `navigationGroup = 'Welfare & Social'`, `navigationLabel = 'Caterers'`, `canAccess = is_admin` (as Task 1 Step 5).
Form fields: `name` (required), `contact_name`, `phone`, `email` (email), `notes` (Textarea).
Table columns: `name` (searchable), `contact_name`, `phone`, `meals_count` (`->counts('meals')`).

- [ ] **Step 5: Add access assertion** to `CatalogAccessTest.php`

```php
public function test_caterers_are_admin_only(): void
{
    $this->actingAs(User::factory()->create());
    $this->assertFalse(\App\Filament\Resources\Caterers\CatererResource::canAccess());

    $this->actingAs(User::factory()->admin()->create());
    $this->assertTrue(\App\Filament\Resources\Caterers\CatererResource::canAccess());
}
```

- [ ] **Step 6: Run & commit**

Run: `php artisan test tests/Feature/Lunch/CatalogAccessTest.php` → PASS (2 tests)
```bash
git add app/Models/Caterer.php database/migrations/*_create_caterers_table.php database/factories/CatererFactory.php app/Filament/Resources/Caterers tests/Feature/Lunch/CatalogAccessTest.php
git commit -m "Lunch catalog: add Caterer"
```

---

## Task 3: Meal model (+ options m2m) + resource

**Files:**
- Create: `app/Models/Meal.php`, `database/migrations/2026_06_23_000003_create_meals_table.php`, `database/migrations/2026_06_23_000004_create_meal_meal_option_table.php`, `database/factories/MealFactory.php`
- Modify: `tests/Feature/Lunch/CatalogAccessTest.php`

- [ ] **Step 1: Migrations**

`meals`:
```php
Schema::create('meals', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->decimal('base_price', 10, 2)->default(0);
    $table->string('image')->nullable();
    $table->timestamps();
});
```
`meal_meal_option` pivot:
```php
Schema::create('meal_meal_option', function (Blueprint $table) {
    $table->foreignId('meal_id')->constrained()->cascadeOnDelete();
    $table->foreignId('meal_option_id')->constrained()->cascadeOnDelete();
    $table->primary(['meal_id', 'meal_option_id']);
});
```

- [ ] **Step 2: Model** — `app/Models/Meal.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Meal extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'base_price', 'image'];

    protected $casts = ['base_price' => 'decimal:2'];

    public function options(): BelongsToMany
    {
        return $this->belongsToMany(MealOption::class);
    }
}
```

- [ ] **Step 3: Factory** — name `fake()->words(2, true)`, `base_price` `fake()->randomFloat(2, 5, 30)`.

- [ ] **Step 4: Generate + customize resource**

Run: `php artisan make:filament-resource Meal --no-interaction`
Customize `MealResource.php`: group, `navigationLabel = 'Meals'`, `canAccess = is_admin`.
Form (`MealForm.php`):
```php
TextInput::make('name')->required()->maxLength(255),
TextInput::make('base_price')->numeric()->prefix('MYR')->required(),
FileUpload::make('image')->image()->disk('public')->directory('meals')->visibility('public'),
CheckboxList::make('options')->relationship('options', 'name')->columns(2),
```
Table (`MealsTable.php`):
```php
TextColumn::make('name')->searchable()->sortable(),
TextColumn::make('base_price')->money('MYR')->sortable(),
TextColumn::make('options.name')->badge()->label('Options'),
```

- [ ] **Step 5: Add access assertion** for `MealResource` (mirror Task 2 Step 5).

- [ ] **Step 6: Run & commit**

Run: `php artisan test tests/Feature/Lunch/CatalogAccessTest.php` → PASS (3 tests)
```bash
git add app/Models/Meal.php database/migrations/*_create_meals_table.php database/migrations/*_create_meal_meal_option_table.php database/factories/MealFactory.php app/Filament/Resources/Meals tests/Feature/Lunch/CatalogAccessTest.php
git commit -m "Lunch catalog: add Meal with options"
```

---

## Task 4: CatererMeal + CatererMealOption + pricing resolution (TDD)

**Files:**
- Create: `app/Models/CatererMeal.php`, `app/Models/CatererMealOption.php`, `database/migrations/2026_06_23_000005_create_caterer_meals_table.php`, `database/migrations/2026_06_23_000006_create_caterer_meal_options_table.php`, `database/factories/CatererMealFactory.php`
- Test: `tests/Feature/Lunch/CatalogPricingTest.php`

- [ ] **Step 1: Migrations**

`caterer_meals`:
```php
Schema::create('caterer_meals', function (Blueprint $table) {
    $table->id();
    $table->foreignId('caterer_id')->constrained()->cascadeOnDelete();
    $table->foreignId('meal_id')->nullable()->constrained()->nullOnDelete();
    $table->string('name');
    $table->decimal('price', 10, 2)->default(0);
    $table->string('image')->nullable();
    $table->timestamps();
});
```
`caterer_meal_options`:
```php
Schema::create('caterer_meal_options', function (Blueprint $table) {
    $table->id();
    $table->foreignId('caterer_meal_id')->constrained()->cascadeOnDelete();
    $table->foreignId('meal_option_id')->constrained()->cascadeOnDelete();
    $table->decimal('price', 10, 2)->nullable(); // override; null = use caterer_meal.price
    $table->timestamps();
    $table->unique(['caterer_meal_id', 'meal_option_id']);
});
```

- [ ] **Step 2: Models**

`CatererMeal.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatererMeal extends Model
{
    use HasFactory;

    protected $fillable = ['caterer_id', 'meal_id', 'name', 'price', 'image'];

    protected $casts = ['price' => 'decimal:2'];

    public function caterer(): BelongsTo
    {
        return $this->belongsTo(Caterer::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(CatererMealOption::class);
    }
}
```
`CatererMealOption.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatererMealOption extends Model
{
    use HasFactory;

    protected $fillable = ['caterer_meal_id', 'meal_option_id', 'price'];

    protected $casts = ['price' => 'decimal:2'];

    public function catererMeal(): BelongsTo
    {
        return $this->belongsTo(CatererMeal::class);
    }

    public function mealOption(): BelongsTo
    {
        return $this->belongsTo(MealOption::class);
    }

    public function getEffectivePriceAttribute(): float
    {
        return (float) ($this->price ?? $this->catererMeal->price);
    }
}
```

- [ ] **Step 3: Write failing test** — `tests/Feature/Lunch/CatalogPricingTest.php`

```php
<?php

namespace Tests\Feature\Lunch;

use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\CatererMealOption;
use App\Models\MealOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_option_price_falls_back_to_the_meal_base_price(): void
    {
        $meal = CatererMeal::create([
            'caterer_id' => Caterer::factory()->create()->id,
            'name' => 'Nasi Campur',
            'price' => 12.00,
        ]);
        $beef = MealOption::factory()->create(['name' => 'Beef']);
        $vegan = MealOption::factory()->create(['name' => 'Vegan']);

        $beefRow = CatererMealOption::create([
            'caterer_meal_id' => $meal->id, 'meal_option_id' => $beef->id, 'price' => 15.00,
        ]);
        $veganRow = CatererMealOption::create([
            'caterer_meal_id' => $meal->id, 'meal_option_id' => $vegan->id, 'price' => null,
        ]);

        $this->assertSame(15.0, $beefRow->effective_price);  // override
        $this->assertSame(12.0, $veganRow->effective_price); // falls back to base
    }
}
```

- [ ] **Step 4: Run to verify RED**

Run: `php artisan test tests/Feature/Lunch/CatalogPricingTest.php`
Expected: FAIL (models/tables missing) until Steps 1–2 applied; then PASS.

- [ ] **Step 5: Factory** — `CatererMealFactory` (caterer via `Caterer::factory()`, name, price `randomFloat(2,8,25)`).

- [ ] **Step 6: Run to verify GREEN** → PASS (1 test).

- [ ] **Step 7: Commit**

```bash
git add app/Models/CatererMeal.php app/Models/CatererMealOption.php database/migrations/*_create_caterer_meals_table.php database/migrations/*_create_caterer_meal_options_table.php database/factories/CatererMealFactory.php tests/Feature/Lunch/CatalogPricingTest.php
git commit -m "Lunch catalog: caterer meals with per-option price override"
```

---

## Task 5: Copy-from-catalog service (TDD)

**Files:**
- Create: `app/Services/LunchCatalogService.php`
- Test: `tests/Feature/Lunch/CopyMealToCatererTest.php`

- [ ] **Step 1: Write failing test** — `tests/Feature/Lunch/CopyMealToCatererTest.php`

```php
<?php

namespace Tests\Feature\Lunch;

use App\Models\Caterer;
use App\Models\Meal;
use App\Models\MealOption;
use App\Services\LunchCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CopyMealToCatererTest extends TestCase
{
    use RefreshDatabase;

    public function test_copying_a_meal_creates_a_caterer_meal_with_its_options(): void
    {
        $beef = MealOption::factory()->create(['name' => 'Beef']);
        $vegan = MealOption::factory()->create(['name' => 'Vegan']);
        $meal = Meal::factory()->create(['name' => 'Nasi Lemak', 'base_price' => 10.50]);
        $meal->options()->attach([$beef->id, $vegan->id]);
        $caterer = Caterer::factory()->create();

        $catererMeal = app(LunchCatalogService::class)->copyMealToCaterer($meal, $caterer);

        $this->assertSame('Nasi Lemak', $catererMeal->name);
        $this->assertEquals(10.50, $catererMeal->price);
        $this->assertSame($caterer->id, $catererMeal->caterer_id);
        $this->assertSame($meal->id, $catererMeal->meal_id);
        // One CatererMealOption per source option, with null (base) price
        $this->assertSame(2, $catererMeal->options()->count());
        $this->assertNull($catererMeal->options()->first()->price);
    }
}
```

- [ ] **Step 2: Run to verify RED**

Run: `php artisan test tests/Feature/Lunch/CopyMealToCatererTest.php`
Expected: FAIL — `Class "App\Services\LunchCatalogService" not found`.

- [ ] **Step 3: Implement** — `app/Services/LunchCatalogService.php`

```php
<?php

namespace App\Services;

use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\Meal;
use Illuminate\Support\Facades\DB;

class LunchCatalogService
{
    /**
     * Copy a catalog meal into a caterer's menu, carrying its name, base price,
     * image, and available options (each with a null/base price override).
     */
    public function copyMealToCaterer(Meal $meal, Caterer $caterer): CatererMeal
    {
        return DB::transaction(function () use ($meal, $caterer) {
            $catererMeal = $caterer->meals()->create([
                'meal_id' => $meal->id,
                'name' => $meal->name,
                'price' => $meal->base_price,
                'image' => $meal->image,
            ]);

            foreach ($meal->options as $option) {
                $catererMeal->options()->create([
                    'meal_option_id' => $option->id,
                    'price' => null,
                ]);
            }

            return $catererMeal;
        });
    }
}
```

- [ ] **Step 4: Run to verify GREEN** → PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add app/Services/LunchCatalogService.php tests/Feature/Lunch/CopyMealToCatererTest.php
git commit -m "Lunch catalog: copyMealToCaterer service"
```

---

## Task 6: Caterer menu relation manager + Copy action

**Files:**
- Create: `app/Filament/Resources/Caterers/RelationManagers/CatererMealsRelationManager.php`
- Modify: `app/Filament/Resources/Caterers/CatererResource.php` (register the relation manager in `getRelations()`)

- [ ] **Step 1: Generate the relation manager**

Run: `php artisan make:filament-relation-manager CatererResource meals name --no-interaction`
Expected: creates `Caterers/RelationManagers/CatererMealsRelationManager.php` (related to `Caterer::meals()`).

- [ ] **Step 2: Configure the form** (in the relation manager `form()`):

```php
TextInput::make('name')->required()->maxLength(255),
TextInput::make('price')->numeric()->prefix('MYR')->required(),
FileUpload::make('image')->image()->disk('public')->directory('caterer-meals')->visibility('public'),
Repeater::make('options')
    ->relationship('options')
    ->schema([
        Select::make('meal_option_id')
            ->label('Option')
            ->options(\App\Models\MealOption::query()->where('is_active', true)->pluck('name', 'id'))
            ->required(),
        TextInput::make('price')
            ->numeric()->prefix('MYR')
            ->helperText('Leave blank to use the item price.'),
    ])
    ->columns(2)
    ->columnSpanFull(),
```

- [ ] **Step 3: Configure the table** — columns `name`, `price` (`money('MYR')`), `options_count` (`->counts('options')`).

- [ ] **Step 4: Add the "Copy from catalog" header action** (in the table `headerActions()`):

```php
Action::make('copyFromCatalog')
    ->label('Copy from catalog')
    ->icon('heroicon-o-document-duplicate')
    ->schema([
        Select::make('meals')
            ->multiple()
            ->options(\App\Models\Meal::query()->pluck('name', 'id'))
            ->required(),
    ])
    ->action(function (array $data, LunchCatalogService $service) {
        $caterer = $this->getOwnerRecord();
        foreach (\App\Models\Meal::with('options')->findMany($data['meals']) as $meal) {
            $service->copyMealToCaterer($meal, $caterer);
        }
    }),
```

- [ ] **Step 5: Register the relation manager** in `CatererResource::getRelations()`:

```php
return [
    \App\Filament\Resources\Caterers\RelationManagers\CatererMealsRelationManager::class,
];
```

- [ ] **Step 6: Verify it renders** — `tests/Feature/Lunch/CatalogAccessTest.php` add:

```php
public function test_admin_can_render_a_caterer_with_its_menu(): void
{
    $caterer = \App\Models\Caterer::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('account'));

    \Livewire\Livewire::test(\App\Filament\Resources\Caterers\Pages\EditCaterer::class, ['record' => $caterer->getKey()])
        ->assertSuccessful();
}
```

Run: `php artisan test tests/Feature/Lunch/CatalogAccessTest.php` → PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Filament/Resources/Caterers tests/Feature/Lunch/CatalogAccessTest.php
git commit -m "Lunch catalog: caterer menu manager + copy-from-catalog action"
```

---

## Task 7: Verify, migrate Supabase, finalize

- [ ] **Step 1: Full suite** — Run: `php artisan test` → all green (existing 38 + new Phase-1 tests).
- [ ] **Step 2: Pint** — Run: `./vendor/bin/pint app/Models app/Services/LunchCatalogService.php app/Filament/Resources/MealOptions app/Filament/Resources/Meals app/Filament/Resources/Caterers tests/Feature/Lunch` → PASS.
- [ ] **Step 3: Migrate Supabase** (additive, pre-approved) — Run: `php artisan migrate --force`; expect the 6 new tables DONE.
- [ ] **Step 4: Smoke** — restart `php artisan serve`; as admin, confirm nav shows Meal Options / Meals / Caterers under "Welfare & Social", and a caterer's Edit page shows the menu manager + "Copy from catalog".
- [ ] **Step 5: Final commit** (if Pint changed files):

```bash
git add -A && git commit -m "Lunch catalog: pint + finalize Phase 1"
```

---

## Notes for the implementer

- All new Filament resources are **admin-only** (`canAccess = is_admin`) in group **"Welfare & Social"** — mirror the existing `AutoEmailResource` pattern (incl. `use UnitEnum;`).
- Filament v5 imports: `TextInput`/`Toggle`/`Select`/`FileUpload`/`CheckboxList`/`Repeater` from `Filament\Forms\Components\*`; `TextColumn`/`IconColumn` from `Filament\Tables\Columns\*`; `Action` from `Filament\Actions\Action`.
- Tests run on sqlite `:memory:` (already configured in `phpunit.xml`); never point them at Supabase.
- Migration timestamps use `2026_06_23_*` to order after the existing scaffold migrations.
- Does **not** touch the `LunchPlan` scaffold — that is reworked in Phase 2.

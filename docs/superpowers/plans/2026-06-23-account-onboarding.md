# Account Onboarding (Import + Claim + Passkeys) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Admin bulk-imports pre-registered members (email, name, grade) as inactive accounts; members self-claim via a public `/claim` Wizard (confirm details → birthday + password → activate + auto-login); then secure the account with a passkey that also signs in.

**Architecture:** Unclaimed accounts are `users` rows with `password=NULL` + `activated_at=NULL`. Import = Filament `ImportAction` + `UserImporter` (forced `sync`), grade→`member_category` by name. Claim = a guest full-page Livewire component using a Filament Wizard schema. Passkeys = `spatie/laravel-passkeys` (post-activation registration + a login-page sign-in component), wired via Filament render hooks.

**Tech Stack:** Laravel 12, Filament 5.6, Livewire 4, PHP 8.5, PostgreSQL (Supabase) / sqlite tests, PHPUnit, Pint, `spatie/laravel-passkeys`.

**Spec:** `docs/superpowers/specs/2026-06-23-account-onboarding-import-claim-passkeys-design.md`

**Conventions:** PHPUnit classes under `tests/Feature/Onboarding/`, `use RefreshDatabase`, extend `Tests\TestCase`. Panel/Livewire tests: `actingAs($u)` + `Filament::setCurrentPanel(Filament::getPanel('account'))`. Run one file: `php artisan test --filter <Class>`. Lint: `vendor/bin/pint --dirty`. `User::factory()->admin()` exists; `MemberCategory` has no factory — create via `MemberCategory::create(['name'=>'…','default_fee'=>50])`. Work on `main`.

---

## Phase 1 — Data model

### Task 1.1: Migration + User fields

**Files:**
- Create: `database/migrations/<ts>_add_onboarding_fields_to_users.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Onboarding/UserActivationStateTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Onboarding;

use App\Models\MemberCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserActivationStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_imported_user_can_have_no_password_and_is_not_activated(): void
    {
        $category = MemberCategory::create(['name' => 'Grade 7-10', 'default_fee' => 50]);

        $user = User::create([
            'name' => 'Imported Member',
            'email' => 'imported@example.com',
            'member_category_id' => $category->id,
            'password' => null,
        ]);

        $this->assertNull($user->fresh()->password);
        $this->assertNull($user->activated_at);
        $this->assertFalse($user->isActivated());
    }

    public function test_setting_activated_at_marks_the_user_activated(): void
    {
        $user = User::factory()->create(['activated_at' => now()]);

        $this->assertTrue($user->isActivated());
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $user->activated_at);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (`activated_at` column/`isActivated()` missing; non-null password constraint).

Run: `php artisan test --filter UserActivationStateTest`

- [ ] **Step 3: Create the migration**

Run `php artisan make:migration add_onboarding_fields_to_users`, then set the body:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('birthday')->nullable()->after('email');
            $table->timestamp('activated_at')->nullable()->after('remember_token');
        });

        // Unclaimed accounts have no password until they claim.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['birthday', 'activated_at']);
        });
    }
};
```

- [ ] **Step 4: Update `User`**

Add `'birthday'` and `'activated_at'` to `$fillable`; in `casts()` add `'birthday' => 'date'` and `'activated_at' => 'datetime'`. Add:

```php
public function isActivated(): bool
{
    return $this->activated_at !== null;
}
```

- [ ] **Step 5: Run it — expect PASS.**
- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations app/Models/User.php tests/Feature/Onboarding/UserActivationStateTest.php
git commit -m "feat(onboarding): nullable password + birthday + activated_at on users"
```

---

## Phase 2 — Bulk import

### Task 2.1: Filament import tables

**Files:** Create: import migrations (published).

- [ ] **Step 1:** Run `php artisan vendor:publish --tag=filament-actions-migrations` (publishes `create_imports_table` + `create_failed_import_rows_table`).
- [ ] **Step 2:** `php artisan test` — confirm the suite still green (migrations run under `RefreshDatabase`).
- [ ] **Step 3: Commit**

```bash
git add database/migrations
git commit -m "feat(onboarding): publish Filament import tables"
```

### Task 2.2: `UserImporter` core logic (TDD)

**Files:**
- Create: `app/Filament/Imports/UserImporter.php`
- Test: `tests/Feature/Onboarding/UserImporterTest.php`

The testable core is a static `buildRecord(array $row): User` that throws `RowImportFailedException` to skip/fail a row.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Onboarding;

use App\Filament\Imports\UserImporter;
use App\Models\MemberCategory;
use App\Models\User;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_an_unclaimed_user_and_maps_grade_to_category_case_insensitively(): void
    {
        $category = MemberCategory::create(['name' => 'Grade 7-10', 'default_fee' => 50]);

        $user = UserImporter::buildRecord(['email' => 'new@example.com', 'name' => 'New Member', 'grade' => '  grade 7-10 ']);

        $this->assertSame('new@example.com', $user->email);
        $this->assertSame('New Member', $user->name);
        $this->assertSame($category->id, $user->member_category_id);
        $this->assertNull($user->password);
        $this->assertNull($user->activated_at);
        $this->assertFalse((bool) $user->is_admin);
    }

    public function test_unknown_grade_fails_the_row(): void
    {
        $this->expectException(RowImportFailedException::class);
        UserImporter::buildRecord(['email' => 'x@example.com', 'name' => 'X', 'grade' => 'Grade 99']);
    }

    public function test_existing_email_is_skipped_as_a_failed_row(): void
    {
        MemberCategory::create(['name' => 'Grade 7-10', 'default_fee' => 50]);
        User::factory()->create(['email' => 'dupe@example.com']);

        $this->expectException(RowImportFailedException::class);
        UserImporter::buildRecord(['email' => 'dupe@example.com', 'name' => 'Dupe', 'grade' => 'Grade 7-10']);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (`UserImporter` missing).

- [ ] **Step 3: Implement the importer**

```php
<?php

namespace App\Filament\Imports;

use App\Models\MemberCategory;
use App\Models\User;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

class UserImporter extends Importer
{
    protected static ?string $model = User::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('email')->requiredMapping()->rules(['required', 'email']),
            ImportColumn::make('name')->requiredMapping()->rules(['required', 'string']),
            // 'grade' is not a User attribute — validate it but don't let Filament write it to the model.
            ImportColumn::make('grade')->requiredMapping()->rules(['required', 'string'])->fillRecordUsing(fn () => null),
        ];
    }

    /** Testable core: build a new unclaimed user from a row, or throw to skip/fail it. */
    public static function buildRecord(array $row): User
    {
        $email = trim((string) ($row['email'] ?? ''));

        if (User::where('email', $email)->exists()) {
            throw new RowImportFailedException("An account with email {$email} already exists; skipped.");
        }

        $grade = trim((string) ($row['grade'] ?? ''));
        $category = MemberCategory::whereRaw('lower(name) = ?', [strtolower($grade)])->first();

        if (! $category) {
            throw new RowImportFailedException("Unknown grade '{$grade}'.");
        }

        return new User([
            'name' => trim((string) ($row['name'] ?? '')),
            'email' => $email,
            'member_category_id' => $category->id,
        ]);
    }

    public function resolveRecord(): ?User
    {
        return static::buildRecord($this->data);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return 'Imported '.number_format($import->successful_rows).' member(s). '
            .number_format($import->getFailedRowsCount()).' row(s) skipped or failed.';
    }

    // No queue worker runs in this app — import inline.
    public function getJobConnection(): ?string
    {
        return 'sync';
    }
}
```

- [ ] **Step 4: Run it — expect PASS (3 tests).**
- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Imports/UserImporter.php tests/Feature/Onboarding/UserImporterTest.php
git commit -m "feat(onboarding): UserImporter (grade->category, skip existing, sync)"
```

### Task 2.3: Wire `ImportAction` onto Users

**Files:** Modify: `app/Filament/Resources/Users/Tables/UsersTable.php`

- [ ] **Step 1:** Add a header `ImportAction` to the table config. In `UsersTable::configure`, add to (or create) the table's `->headerActions([...])`:

```php
use Filament\Actions\ImportAction;
use App\Filament\Imports\UserImporter;

// inside configure(), on the Table:
->headerActions([
    ImportAction::make()
        ->importer(UserImporter::class)
        ->label('Import members'),
])
```

(If `headerActions` already exists, append the action.)

- [ ] **Step 2: Verify it renders** — add to `tests/Feature/Onboarding/UserImporterTest.php`:

```php
public function test_admin_users_list_renders_with_the_import_action(): void
{
    $this->actingAs(User::factory()->admin()->create());
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('account'));

    \Livewire\Livewire::test(\App\Filament\Resources\Users\Pages\ListUsers::class)
        ->assertSuccessful()
        ->assertActionExists('import');
}
```

Run: `php artisan test --filter UserImporterTest` → expect PASS (4 tests).

- [ ] **Step 3: Commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/Users/Tables/UsersTable.php tests/Feature/Onboarding/UserImporterTest.php
git commit -m "feat(onboarding): add member ImportAction to Users list"
```

---

## Phase 3 — Self-claim Wizard

### Task 3.1: Activation service (TDD)

Keep the activation rule out of the UI so it's unit-testable.

**Files:**
- Create: `app/Services/AccountClaimService.php`
- Test: `tests/Feature/Onboarding/AccountClaimServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Onboarding;

use App\Models\User;
use App\Services\AccountClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AccountClaimServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_claimable_returns_the_unclaimed_user_for_a_known_email(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com', 'password' => null, 'activated_at' => null]);
        $this->assertTrue(app(AccountClaimService::class)->claimable('a@example.com')->is($user));
    }

    public function test_claimable_is_null_for_unknown_or_already_activated(): void
    {
        $svc = app(AccountClaimService::class);
        $this->assertNull($svc->claimable('missing@example.com'));

        User::factory()->create(['email' => 'done@example.com', 'activated_at' => now()]);
        $this->assertNull($svc->claimable('done@example.com'));
    }

    public function test_claim_sets_password_birthday_and_activates(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com', 'password' => null, 'activated_at' => null]);

        $claimed = app(AccountClaimService::class)->claim($user, '2000-05-01', 'sup3r-secret-pw');

        $claimed->refresh();
        $this->assertNotNull($claimed->activated_at);
        $this->assertSame('2000-05-01', $claimed->birthday->toDateString());
        $this->assertTrue(Hash::check('sup3r-secret-pw', $claimed->password));
    }

    public function test_claim_refuses_an_already_activated_account(): void
    {
        $user = User::factory()->create(['activated_at' => now()]);
        $this->expectException(RuntimeException::class);
        app(AccountClaimService::class)->claim($user, '2000-05-01', 'pw');
    }
}
```

- [ ] **Step 2: Run it — expect FAIL.**

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services;

use App\Models\User;
use RuntimeException;

class AccountClaimService
{
    /** The unclaimed user for this email, or null if unknown / already activated. */
    public function claimable(string $email): ?User
    {
        return User::where('email', trim($email))->whereNull('activated_at')->first();
    }

    /** @throws RuntimeException if already activated. */
    public function claim(User $user, string $birthday, string $password): User
    {
        if ($user->activated_at !== null) {
            throw new RuntimeException('This account has already been activated.');
        }

        $user->forceFill([
            'birthday' => $birthday,
            'password' => $password, // hashed by the model cast
            'activated_at' => now(),
        ])->save();

        return $user;
    }
}
```

- [ ] **Step 4: Run it — expect PASS (4 tests).**
- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/AccountClaimService.php tests/Feature/Onboarding/AccountClaimServiceTest.php
git commit -m "feat(onboarding): AccountClaimService (lookup + activate)"
```

### Task 3.2: Public claim page (Livewire + Filament Wizard)

**Files:**
- Create: `app/Livewire/ClaimAccount.php`
- Create: `resources/views/livewire/claim-account.blade.php`
- Create: `resources/views/components/layouts/guest.blade.php` (minimal layout including Filament assets)
- Modify: `routes/web.php`
- Test: `tests/Feature/Onboarding/ClaimAccountPageTest.php`

> **Implementation note:** This renders a Filament schema in a standalone (guest) Livewire component. Confirm the Filament v5 standalone-schema contract/trait by checking an existing schema usage and `vendor/filament`. The component below uses `Filament\Schemas\Concerns\InteractsWithSchemas` + `Filament\Schemas\Contracts\HasSchemas`; if v5.6 exposes these under the forms namespace in this install, adjust the imports to match (verify against the installed package) and note it. The guest layout must emit `@filamentStyles`/`@filamentScripts` + the app's Vite bundle so schema components render and hydrate.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Onboarding;

use App\Livewire\ClaimAccount;
use App\Models\MemberCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class ClaimAccountPageTest extends TestCase
{
    use RefreshDatabase;

    private function unclaimed(): User
    {
        $category = MemberCategory::create(['name' => 'Grade 7-10', 'default_fee' => 50]);

        return User::factory()->create([
            'name' => 'Jane Member',
            'email' => 'jane@example.com',
            'member_category_id' => $category->id,
            'password' => null,
            'activated_at' => null,
        ]);
    }

    public function test_lookup_reveals_the_wizard_for_an_unclaimed_email(): void
    {
        $this->unclaimed();

        Livewire::test(ClaimAccount::class)
            ->set('email', 'jane@example.com')
            ->call('lookup')
            ->assertSet('stage', 'claim')
            ->assertSee('Jane Member')
            ->assertSee('Grade 7-10');
    }

    public function test_lookup_reports_unknown_and_already_active(): void
    {
        Livewire::test(ClaimAccount::class)->set('email', 'nobody@example.com')->call('lookup')
            ->assertSet('stage', 'lookup')->assertSee('check with your admin');

        User::factory()->create(['email' => 'active@example.com', 'activated_at' => now()]);
        Livewire::test(ClaimAccount::class)->set('email', 'active@example.com')->call('lookup')
            ->assertSee('already active');
    }

    public function test_completing_the_claim_activates_and_logs_in(): void
    {
        $user = $this->unclaimed();

        Livewire::test(ClaimAccount::class)
            ->set('email', 'jane@example.com')->call('lookup')
            ->set('data.birthday', '2000-05-01')
            ->set('data.password', 'sup3r-secret-pw')
            ->set('data.passwordConfirmation', 'sup3r-secret-pw')
            ->call('submit');

        $this->assertNotNull($user->fresh()->activated_at);
        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
    }
}
```

- [ ] **Step 2: Run it — expect FAIL.**

- [ ] **Step 3: Implement the component**

```php
<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\AccountClaimService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

class ClaimAccount extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public string $email = '';

    public string $stage = 'lookup'; // 'lookup' | 'claim'

    public ?string $lookupMessage = null;

    public ?int $userId = null;

    public string $memberName = '';

    public string $memberGrade = '';

    /** @var array<string, mixed> */
    public array $data = [];

    public function lookup(): void
    {
        $this->validate(['email' => 'required|email']);
        $svc = app(AccountClaimService::class);

        $user = $svc->claimable($this->email);
        if ($user) {
            $this->userId = $user->id;
            $this->memberName = $user->name;
            $this->memberGrade = $user->memberCategory?->name ?? '—';
            $this->stage = 'claim';
            $this->lookupMessage = null;

            return;
        }

        $existing = User::where('email', trim($this->email))->first();
        $this->lookupMessage = $existing
            ? 'This account is already active — please log in.'
            : "We couldn't find that email — please check with your admin.";
    }

    public function claimForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Wizard::make([
                    Step::make('Confirm your details')->schema([
                        DatePicker::make('birthday')->required()->native(false)->maxDate(now()),
                    ]),
                    Step::make('Set a password')->schema([
                        TextInput::make('password')->password()->required()->rule(Password::defaults())->confirmed(),
                        TextInput::make('passwordConfirmation')->password()->required()->label('Confirm password')->dehydrated(false),
                    ]),
                ])->submitAction(view('livewire.claim-submit-button')),
            ]);
    }

    public function submit(): void
    {
        abort_if($this->userId === null, 403);
        $state = $this->claimForm($this->makeSchema())->getState();

        $user = User::findOrFail($this->userId);
        app(AccountClaimService::class)->claim($user, $state['birthday'], $state['password']);

        Auth::login($user);
        $this->redirect('/account', navigate: true);
    }

    #[Layout('components.layouts.guest')]
    public function render()
    {
        return view('livewire.claim-account');
    }
}
```

> **Note:** the exact schema-state retrieval (`$this->claimForm($this->makeSchema())->getState()` vs a registered `->getSchema('claimForm')->getState()`) and the `confirmed()` field-name convention must be confirmed against Filament v5.6's standalone-schema API during implementation; adjust the test's `data.*` keys to match the real state path. The `submitAction` view is a small Blade button calling `wire:click="submit"`.

- [ ] **Step 4: Blade + layout + route**

`resources/views/livewire/claim-account.blade.php` — `@if($stage==='lookup')` an email input + "Find my account" button (`wire:click="lookup"`) + `$lookupMessage` + a link to `/account/login`; `@else` show "Welcome, {{ $memberName }} ({{ $memberGrade }})" + `{{ $this->claimForm }}` (render the schema). Style with inline + `.dark` per the project convention.

`resources/views/components/layouts/guest.blade.php` — minimal HTML doc with `@filamentStyles @vite('resources/css/app.css') ... @filamentScripts @vite('resources/js/app.js')` and `{{ $slot }}`, centered card.

`routes/web.php` — add:
```php
use App\Livewire\ClaimAccount;
Route::get('/claim', ClaimAccount::class)->name('claim');
```

- [ ] **Step 5: Run it — expect PASS (3 tests).** Fix state-path / API mismatches surfaced by the run (see notes). If the standalone schema cannot render headless in the test, assert on `lookup()`/`submit()` behavior + state instead of `assertSee` of schema internals, and cover the visual render in the Phase 6 smoke. Note any such adjustment.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Livewire/ClaimAccount.php resources/views/livewire/claim-account.blade.php resources/views/components/layouts/guest.blade.php resources/views/livewire/claim-submit-button.blade.php routes/web.php tests/Feature/Onboarding/ClaimAccountPageTest.php
git commit -m "feat(onboarding): public /claim Wizard (lookup + activate + auto-login)"
```

---

## Phase 4 — Login gating + claim link

### Task 4.1: Gating + link (TDD)

**Files:**
- Modify: `app/Providers/Filament/AccountPanelProvider.php` (render hook for the claim link)
- Test: `tests/Feature/Onboarding/LoginGatingTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Onboarding;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LoginGatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unclaimed_user_cannot_authenticate_by_password(): void
    {
        User::factory()->create(['email' => 'pending@example.com', 'password' => null, 'activated_at' => null]);
        $this->assertFalse(Auth::attempt(['email' => 'pending@example.com', 'password' => 'anything']));
    }

    public function test_an_activated_user_can_authenticate(): void
    {
        User::factory()->create(['email' => 'ok@example.com', 'password' => 'secret-password', 'activated_at' => now()]);
        $this->assertTrue(Auth::attempt(['email' => 'ok@example.com', 'password' => 'secret-password']));
    }

    public function test_the_login_page_links_to_the_claim_flow(): void
    {
        $this->get('/account/login')->assertSee('Claim your account')->assertSee('/claim');
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** on the claim-link assertion (gating tests should already pass — null password can't authenticate; if `test_an_activated_user_can_authenticate` fails because the eloquent provider rejects, that's the signal Phase 5's `eloquent-webauthn` provider + `password_fallback` is needed — note it and proceed; revisit in Phase 5).

- [ ] **Step 3: Add the claim link via a render hook**

In `AccountPanelProvider::boot()` (add a `boot()` if absent) or `panel()`, register:

```php
use Filament\View\PanelsRenderHook;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\HtmlString;

FilamentView::registerRenderHook(
    PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
    fn (): HtmlString => new HtmlString(
        '<div style="margin-top:1rem;text-align:center;font-size:0.875rem;">'
        .'New here? <a href="/claim" style="color:var(--primary-600,#d97706);font-weight:600;">Claim your account</a>'
        .'</div>'
    ),
);
```

- [ ] **Step 4: Run it — expect PASS** (claim-link test passes; gating tests pass).
- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Providers/Filament/AccountPanelProvider.php tests/Feature/Onboarding/LoginGatingTest.php
git commit -m "feat(onboarding): claim link on login + verify password gating"
```

---

## Phase 5 — Passkeys (`spatie/laravel-passkeys`)

> **This phase is integration-heavy; its first task confirms the package's actual API before wiring.** Tests focus on the model/trait + page rendering; the WebAuthn ceremony itself is smoke-verified on localhost in Phase 6 (it needs a browser/authenticator).

### Task 5.1: Install + configure + spike

**Files:** `composer.json`/lock, `config/passkeys.php`, `app/Models/User.php`, passkeys migration.

- [ ] **Step 1:** `composer require spatie/laravel-passkeys`
- [ ] **Step 2:** `php artisan vendor:publish --tag="passkeys-migrations"` then `php artisan migrate` (creates `passkeys`). Publish config: `php artisan vendor:publish --tag="passkeys-config"`.
- [ ] **Step 3:** Add to `User`: `implements ... HasPasskeys` and `use ... InteractsWithPasskeys;` (namespaces `Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys` / `InteractsWithPasskeys`). If both the existing `FilamentUser` and `HasPasskeys` are implemented, list both.
- [ ] **Step 4 (spike — record findings):** Read the published `config/passkeys.php` and the package's views/components. **Document in the commit message / a comment the exact:** (a) Livewire component tag that generates a passkey for the logged-in user, (b) the login component tag, (c) the `redirect_to_after_login` value (set to `/account`), (d) whether `config/auth.php` needs the `eloquent-webauthn`/passkey auth provider or a `password_fallback` for password+passkey to coexist. Apply the config edits (`relying_party.id` correct for localhost + prod; `redirect_to_after_login => '/account'`).
- [ ] **Step 5: Test the trait wiring**

```php
// tests/Feature/Onboarding/PasskeyModelTest.php
public function test_user_has_a_passkeys_relation(): void
{
    $user = \App\Models\User::factory()->create();
    $this->assertCount(0, $user->passkeys); // relation resolves, empty
}
```

Run: `php artisan test --filter PasskeyModelTest` → PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add composer.json composer.lock config/passkeys.php app/Models/User.php database/migrations tests/Feature/Onboarding/PasskeyModelTest.php
git commit -m "feat(onboarding): install + configure spatie/laravel-passkeys"
```

### Task 5.2: Post-claim "add a passkey" + account settings page

**Files:** Create a Filament page `app/Filament/Pages/Passkeys.php` (auth-required, group "Account") embedding Spatie's generate-passkey component; redirect claim to it (instead of `/account`) so members are prompted right after activating.

- [ ] **Step 1:** Create the Filament page with a Blade view embedding the Spatie registration component (exact tag from Task 5.1). Nav label "Passkeys", visible to any authed user.
- [ ] **Step 2:** Change `ClaimAccount::submit()` redirect from `/account` to the Passkeys page URL (`\App\Filament\Pages\Passkeys::getUrl()`), so the post-claim landing is the "add a passkey" prompt. Update `ClaimAccountPageTest` to assert the redirect target.
- [ ] **Step 3: Render test**

```php
// tests/Feature/Onboarding/PasskeysPageTest.php — authed user can open the page
Livewire::test(\App\Filament\Pages\Passkeys::class)->assertSuccessful();
// + assert canAccess() true for authed, and the page is reachable.
```

- [ ] **Step 4:** Run the onboarding tests → PASS. Commit:

```bash
vendor/bin/pint --dirty
git add app/Filament/Pages/Passkeys.php resources/views/filament/pages/passkeys.blade.php app/Livewire/ClaimAccount.php tests/Feature/Onboarding
git commit -m "feat(onboarding): post-claim passkey setup + account passkeys page"
```

### Task 5.3: Passkey sign-in on the login page

**Files:** Modify `AccountPanelProvider` (render hook) + possibly `config/auth.php`.

- [ ] **Step 1:** Inject Spatie's "Log in with a passkey" component into the login page via the `PanelsRenderHook::AUTH_LOGIN_FORM_AFTER` render hook (alongside the claim link from Phase 4). Use the exact component confirmed in Task 5.1.
- [ ] **Step 2:** If Task 5.1 found that password + passkey coexistence needs the auth provider change, apply it to `config/auth.php` (`'driver' => 'eloquent-webauthn'` or the package's documented provider with `password_fallback => true`) and re-run `LoginGatingTest` to confirm password login still works for activated users and still fails for unclaimed ones.
- [ ] **Step 3: Render test** — `$this->get('/account/login')->assertSuccessful()` and asserts the passkey sign-in element is present.
- [ ] **Step 4:** Run the full onboarding suite → PASS. Commit:

```bash
vendor/bin/pint --dirty
git add app/Providers/Filament/AccountPanelProvider.php config/auth.php tests/Feature/Onboarding
git commit -m "feat(onboarding): passkey sign-in on the login page"
```

---

## Phase 6 — Verify, migrate Supabase, smoke, finalize

- [ ] **Step 1:** `php artisan test` — full suite green (existing 80 + onboarding tests).
- [ ] **Step 2:** `vendor/bin/pint --dirty` (and re-run tests if it changed files).
- [ ] **Step 3:** `npm run build` (the claim page + guest layout add Blade/Vite assets; rebuild so they're available).
- [ ] **Step 4: Migrate Supabase** — confirm only the new additive migrations are pending (`php artisan migrate:status`), then `php artisan migrate --force` (adds `birthday`/`activated_at` to users, makes `password` nullable, `imports`/`failed_import_rows`, `passkeys`). Verify the `password` nullable change applied on Postgres.
- [ ] **Step 5: Smoke (localhost, secure context for WebAuthn):**
  - Admin → Users → **Import members** with a small CSV (`email,name,grade`) incl. one unknown grade + one existing email → confirm the result counts + failed-rows download.
  - Visit `/claim` → look up an imported email → complete the Wizard → land on the passkey page, **register a passkey**, then sign out and **sign in with the passkey**; also confirm password login works.
  - Confirm an unknown email and an already-active email show the right messages.
- [ ] **Step 6:** Use superpowers:finishing-a-development-branch (work is on `main`; verify green, then confirm leaving the commits on `main`).

---

## Self-Review (done during planning)

- **Spec coverage:** data model (P1) · import incl. grade-map/skip/sync (P2) · claim lookup + Wizard + activate/auto-login (P3) · login gating + claim link (P4) · passkeys install/register/login (P5) · verify + Supabase + smoke (P6). All spec sections map to a phase.
- **Type/name consistency:** `AccountClaimService::claimable()/claim()`, `UserImporter::buildRecord()`, `User::isActivated()` used consistently across tasks/tests.
- **Known uncertainties (flagged, not hidden):** Filament v5.6 standalone-schema API/state-path (Task 3.2) and Spatie's exact component tags + auth-provider needs (Task 5.1) are confirmed against the installed packages at implementation time — the relevant tasks begin by verifying the real API and adjusting tests to match, rather than asserting unverified specifics. The WebAuthn ceremony is smoke-verified on localhost (Phase 6), not unit-tested, because it requires a browser/authenticator.

# Malay and Indonesian Localisation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** PENTANA's web pages and `/account` portal render in English, Bahasa Melayu or Bahasa Indonesia; each person is asked once, the choice is remembered on device and account, and their emails and notifications follow it.

**Architecture:** A `SetLocale` middleware resolves the locale (account → `locale` cookie → default `en`) on web, panel and Livewire requests. Choices are made through plain HTML forms posting to `/locale`, which sets the cookie and the account column; an inline script mirrors the cookie into `localStorage` and restores it if cookies are cleared. Strings stay English in code via `__()`, translated in `lang/ms.json` and `lang/id.json`; framework strings come from `laravel-lang`. `User` implements `HasLocalePreference` so notifications render per recipient. Everything is gated by `APP_LOCALES` (default `en`), so the build deploys with no visible change until a language is enabled.

**Tech Stack:** Laravel 12.62, Filament 5.6, Livewire 4.3, Carbon (`translatedFormat`), `laravel-lang/lang` + `laravel-lang/publisher` (dev), PHPUnit on SQLite.

**Spec:** `docs/superpowers/specs/2026-10-14-malay-indonesian-localisation-design.md`

**Refinement of the spec, agreed during planning:** `POST /locale` is submitted by an HTML form and answers with a **303 redirect back** (not `204` + client reload). The cookie it sets is mirrored into `localStorage` by the sync script on the next page load, so the observable behaviour is the same and no fetch/CSRF JavaScript is needed. Step 1 of Task 1 updates the spec to say so.

## Global Constraints

- Locale codes: `en`, `ms` (Bahasa Melayu — *not* `my`, which is Burmese), `id` (Bahasa Indonesia). Switcher labels: **EN · BM · ID**. Chooser labels: **English · Bahasa Melayu · Bahasa Indonesia**.
- `APP_LOCALES` is comma-separated; **default `en`**; `en` is always enabled even if omitted.
- Cookie: name `locale`, 1 year, `SameSite=Lax`, **not** HTTP-only, **excluded from `EncryptCookies`**.
- `users.locale`: nullable `string(5)`.
- English stays inline as the translation key: `__('Exact English text')`. Values go in placeholders (`__('Waitlisted #:position', ['position' => $n])`), never string concatenation.
- Money always renders `MYR 70.00`. Dates use `translatedFormat()` with the same format strings as today.
- Out of scope: admin-authored content (lunch/meal names, activity titles/descriptions/questions, auto-email bodies), people's names, member category names, the brand PENTANA, `/api` responses, the mobile apps' UI.
- Tests run on SQLite (`php artisan test`). No queue worker exists; notifications send inline.
- `main` auto-deploys on push. Do not push this branch to `main` until the whole plan is done and reviewed; `APP_LOCALES` stays `en` in production until native review.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **A translation drops or misspells a placeholder** (e.g. `ms.json` has `"Waitlisted #:position": "Dalam senarai menunggu"`) — the value silently vanishes from the UI. Expect: the guard fails. Pinned by `test_translations_keep_every_placeholder` in Task 7.
2. **One send to members with different languages** (e.g. an admin publishes a lunch to an `ms` member and an `id` member) — each must get their own language, with no locale leaking from one recipient to the next or into the admin's response. Pinned by `test_one_send_localises_per_recipient_and_restores_locale` in Task 11.
3. **A language is disabled after people chose it** — a stored `ms` cookie or `users.locale = 'ms'` with `APP_LOCALES=en,id` must render English and show the chooser, never 500 or render half-Malay. Pinned by `test_disabled_stored_locale_falls_back_and_reprompts` in Task 3.
4. **Mobile API requests from a member who chose Malay on the web** — `/api` validation and error messages must stay English, since the app's UI is English. Pinned by `test_api_responses_stay_english_for_a_malay_member` in Task 3.
5. **Cookies blocked entirely** — the sync script must not reload in a loop, and a visitor who already chose (in `localStorage`) must not be re-prompted on every page. Pinned by the browser check in Task 6, Step 6.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Support/Locales.php` | Single source of truth: enabled locales, validation, native names, short labels. |
| `config/app.php` | `locales` key read from `APP_LOCALES`. |
| `database/migrations/2026_10_14_000001_add_locale_to_users_table.php` | `users.locale`. |
| `app/Models/User.php` | `HasLocalePreference`, `locale` fillable. |
| `app/Http/Middleware/SetLocale.php` | Resolve + apply locale; share `$localeChosen`. |
| `app/Http/Controllers/LocaleController.php` | `POST /locale`. |
| `app/Support/LocaleCookie.php` | Build/queue the `locale` cookie in one place. |
| `app/Listeners/SyncLocaleOnLogin.php` | Account ↔ cookie on `Login`. |
| `resources/views/components/locale/sync-script.blade.php` | Inline cookie ↔ `localStorage` script. |
| `resources/views/components/locale/chooser.blade.php` | First-visit modal (forms). |
| `resources/views/components/locale/switcher.blade.php` | EN · BM · ID header control (forms). |
| `app/Providers/Filament/AccountPanelProvider.php` | Panel middleware, render hooks, user-menu Language action. |
| `app/Filament/Auth/Profile.php` | Language field; queue cookie after save. |
| `app/Providers/AppServiceProvider.php` | Livewire persistent middleware; Filament `translateLabel()` defaults. |
| `lang/ms.json`, `lang/id.json` | App translations. |
| `lang/ms/*.php`, `lang/id/*.php` | Framework translations published by laravel-lang. |
| `tests/Feature/Localisation/*` | All new tests. |
| `tests/Feature/Localisation/TranslationCompletenessTest.php` | Static key scan + placeholder parity. |
| `tests/Feature/Localisation/RenderedPagesAreTranslatedTest.php` | Runtime missing-key guard over rendered pages. |
| `docs/translations/build-review-sheet.py`, `docs/translations/review.xlsx` | Reviewer sheet. |
| `docs/deployment-internal-server.md` | "Enabling a language" section. |

---

### Task 1: Locale configuration, `Locales` helper and framework translations

**Files:**
- Modify: `docs/superpowers/specs/2026-10-14-malay-indonesian-localisation-design.md` (the `POST /locale` response line)
- Create: `app/Support/Locales.php`
- Modify: `config/app.php` (after the `fallback_locale` entry, line ~83)
- Modify: `.env.example` (after `APP_FALLBACK_LOCALE`)
- Create (generated): `lang/ms/*.php`, `lang/id/*.php`, `lang/ms.json`, `lang/id.json`
- Modify: `composer.json`, `composer.lock`
- Test: `tests/Feature/Localisation/LocalesTest.php`

**Interfaces:**
- Produces: `App\Support\Locales` with
  - `public static function enabled(): array` — e.g. `['en', 'ms', 'id']`, `en` first, always present, order as configured, unknown codes dropped.
  - `public static function isEnabled(?string $code): bool`
  - `public static function nativeName(string $code): string` — `English`, `Bahasa Melayu`, `Bahasa Indonesia`.
  - `public static function shortLabel(string $code): string` — `EN`, `BM`, `ID`.
  - `public static function multipleEnabled(): bool` — `count(enabled()) > 1`.
  - `public const SUPPORTED = ['en', 'ms', 'id'];`

- [ ] **Step 1: Update the spec's `POST /locale` response**

In the spec, §1 "Saving a choice", replace the bullet `- returns \`204\`. The client then writes \`localStorage.locale\` and reloads.` with:

```markdown
- is submitted by a plain HTML form and returns a **303 redirect back** to the page it came
  from. The sync script copies the new cookie into `localStorage` on that next load.
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/Localisation/LocalesTest.php`:

```php
<?php

namespace Tests\Feature\Localisation;

use App\Support\Locales;
use Tests\TestCase;

class LocalesTest extends TestCase
{
    public function test_defaults_to_english_only(): void
    {
        config(['app.locales' => 'en']);

        $this->assertSame(['en'], Locales::enabled());
        $this->assertFalse(Locales::multipleEnabled());
    }

    public function test_parses_list_keeps_english_first_and_drops_unknown_codes(): void
    {
        config(['app.locales' => ' id, xx ,ms']);

        $this->assertSame(['en', 'id', 'ms'], Locales::enabled());
        $this->assertTrue(Locales::multipleEnabled());
    }

    public function test_is_enabled_checks_membership_and_handles_null(): void
    {
        config(['app.locales' => 'en,ms']);

        $this->assertTrue(Locales::isEnabled('ms'));
        $this->assertFalse(Locales::isEnabled('id'));
        $this->assertFalse(Locales::isEnabled('my'));
        $this->assertFalse(Locales::isEnabled(null));
        $this->assertFalse(Locales::isEnabled(''));
    }

    public function test_names_and_labels(): void
    {
        $this->assertSame('Bahasa Melayu', Locales::nativeName('ms'));
        $this->assertSame('Bahasa Indonesia', Locales::nativeName('id'));
        $this->assertSame('English', Locales::nativeName('en'));
        $this->assertSame('BM', Locales::shortLabel('ms'));
        $this->assertSame('ID', Locales::shortLabel('id'));
        $this->assertSame('EN', Locales::shortLabel('en'));
    }
}
```

- [ ] **Step 3: Run it to confirm it fails**

Run: `php artisan test --filter=LocalesTest`
Expected: FAIL — `Class "App\Support\Locales" not found`.

- [ ] **Step 4: Implement**

`app/Support/Locales.php`:

```php
<?php

namespace App\Support;

/**
 * The languages PENTANA can render, and which of them are switched on.
 * APP_LOCALES gates rollout: a language is only offered once it is listed there.
 */
class Locales
{
    public const SUPPORTED = ['en', 'ms', 'id'];

    private const NATIVE_NAMES = ['en' => 'English', 'ms' => 'Bahasa Melayu', 'id' => 'Bahasa Indonesia'];

    private const SHORT_LABELS = ['en' => 'EN', 'ms' => 'BM', 'id' => 'ID'];

    /** @return list<string> */
    public static function enabled(): array
    {
        $configured = array_map('trim', explode(',', (string) config('app.locales', 'en')));
        $known = array_values(array_intersect($configured, self::SUPPORTED));

        return array_values(array_unique(['en', ...array_diff($known, ['en'])]));
    }

    public static function isEnabled(?string $code): bool
    {
        return $code !== null && $code !== '' && in_array($code, self::enabled(), true);
    }

    public static function multipleEnabled(): bool
    {
        return count(self::enabled()) > 1;
    }

    public static function nativeName(string $code): string
    {
        return self::NATIVE_NAMES[$code];
    }

    public static function shortLabel(string $code): string
    {
        return self::SHORT_LABELS[$code];
    }
}
```

`config/app.php`, directly after the `'fallback_locale' => ...` line:

```php
    /*
    | Languages offered to members (comma-separated). English is always on.
    | Add `ms` / `id` only after their translations have been reviewed.
    */
    'locales' => env('APP_LOCALES', 'en'),
```

`.env.example`, after `APP_FALLBACK_LOCALE=en`:

```dotenv
APP_LOCALES=en
```

- [ ] **Step 5: Run the test to confirm it passes**

Run: `php artisan test --filter=LocalesTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Publish framework translations**

```bash
composer require --dev laravel-lang/lang laravel-lang/publisher
php artisan lang:add ms id
ls lang/ms lang/id
```

Expected: `lang/ms/{auth,pagination,passwords,validation,actions,http-statuses}.php` (exact set may vary by package version), the same under `lang/id`, plus `lang/ms.json` and `lang/id.json` containing laravel-lang's JSON strings. Keep all generated files; later tasks add keys to the same two JSON files.

Run: `php artisan tinker --execute="app()->setLocale('ms'); echo __('validation.required', ['attribute' => 'emel']);"`
Expected: a Malay sentence (e.g. `Ruangan emel diperlukan.`), not `validation.required`.

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: all pass (the app still renders English — nothing calls `setLocale` yet).

- [ ] **Step 8: Commit**

```bash
git add app/Support/Locales.php config/app.php .env.example composer.json composer.lock lang tests/Feature/Localisation/LocalesTest.php docs/superpowers/specs/2026-10-14-malay-indonesian-localisation-design.md
git commit -m "feat(i18n): locale config, Locales helper, framework ms/id translations

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `users.locale` and notification locale preference

**Files:**
- Create: `database/migrations/2026_10_14_000001_add_locale_to_users_table.php`
- Modify: `app/Models/User.php` (class declaration line 18; `$fillable`)
- Test: `tests/Feature/Localisation/UserLocalePreferenceTest.php`

**Interfaces:**
- Consumes: `Locales::isEnabled()` (Task 1).
- Produces: `users.locale` (nullable `string(5)`); `User::preferredLocale(): ?string` — returns `locale` only when enabled, else `null`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Localisation;

use App\Models\User;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserLocalePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_exposes_an_enabled_locale_as_its_preference(): void
    {
        config(['app.locales' => 'en,ms,id']);
        $user = User::factory()->create(['locale' => 'id']);

        $this->assertInstanceOf(HasLocalePreference::class, $user);
        $this->assertSame('id', $user->fresh()->preferredLocale());
    }

    public function test_no_or_disabled_locale_means_no_preference(): void
    {
        config(['app.locales' => 'en,id']);

        $this->assertNull(User::factory()->create(['locale' => null])->preferredLocale());
        $this->assertNull(User::factory()->create(['locale' => 'ms'])->preferredLocale());
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test --filter=UserLocalePreferenceTest`
Expected: FAIL — `table users has no column named locale`.

- [ ] **Step 3: Implement**

Migration:

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
            // ISO 639-1 code (en/ms/id); null until the member chooses.
            $table->string('locale', 5)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
```

`app/Models/User.php`: add `use App\Support\Locales;` and `use Illuminate\Contracts\Translation\HasLocalePreference;`, change the declaration to

```php
class User extends Authenticatable implements FilamentUser, HasLocalePreference, HasPasskeys
```

add `'locale',` to `$fillable`, and add:

```php
    /**
     * Language for notifications and email. Laravel renders each notification in
     * the recipient's preference; disabled or unset falls back to English.
     */
    public function preferredLocale(): ?string
    {
        return Locales::isEnabled($this->locale) ? $this->locale : null;
    }
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=UserLocalePreferenceTest` → PASS. Then `php artisan test` → all pass.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_10_14_000001_add_locale_to_users_table.php app/Models/User.php tests/Feature/Localisation/UserLocalePreferenceTest.php
git commit -m "feat(i18n): users.locale and HasLocalePreference

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `SetLocale` middleware on web, panel and Livewire

**Files:**
- Create: `app/Http/Middleware/SetLocale.php`
- Modify: `bootstrap/app.php` (inside `withMiddleware`)
- Modify: `app/Providers/Filament/AccountPanelProvider.php` (`->middleware([...])`, after `StartSession::class`)
- Modify: `app/Providers/AppServiceProvider.php` (`boot()`)
- Test: `tests/Feature/Localisation/SetLocaleTest.php`

**Interfaces:**
- Consumes: `Locales` (Task 1), `users.locale` (Task 2).
- Produces: `App\Http\Middleware\SetLocale`; `SetLocale::COOKIE = 'locale'`; view variable `$localeChosen` (bool) shared on every web/panel request; `app()->getLocale()` and Carbon locale set.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Localisation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class SetLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locales' => 'en,ms,id']);

        Route::middleware('web')->get('/_locale-probe', fn () => response()->json([
            'locale' => app()->getLocale(),
            'carbon' => now()->locale,
            'chosen' => View::shared('localeChosen'),
        ]));
    }

    public function test_no_choice_renders_default_and_is_not_chosen(): void
    {
        $this->get('/_locale-probe')->assertJson(['locale' => 'en', 'chosen' => false]);
    }

    public function test_cookie_sets_locale(): void
    {
        $this->withUnencryptedCookie('locale', 'ms')
            ->get('/_locale-probe')
            ->assertJson(['locale' => 'ms', 'carbon' => 'ms', 'chosen' => true]);
    }

    public function test_account_beats_cookie(): void
    {
        $user = User::factory()->create(['locale' => 'id']);

        $this->actingAs($user)->withUnencryptedCookie('locale', 'ms')
            ->get('/_locale-probe')
            ->assertJson(['locale' => 'id', 'chosen' => true]);
    }

    public function test_unknown_cookie_is_ignored(): void
    {
        $this->withUnencryptedCookie('locale', 'my')
            ->get('/_locale-probe')
            ->assertJson(['locale' => 'en', 'chosen' => false]);
    }

    public function test_disabled_stored_locale_falls_back_and_reprompts(): void
    {
        config(['app.locales' => 'en,id']);
        $user = User::factory()->create(['locale' => 'ms']);

        $this->withUnencryptedCookie('locale', 'ms')
            ->get('/_locale-probe')
            ->assertJson(['locale' => 'en', 'chosen' => false]);

        $this->actingAs($user)
            ->get('/_locale-probe')
            ->assertJson(['locale' => 'en', 'chosen' => false]);
    }

    public function test_cookie_is_not_encrypted(): void
    {
        // A raw (unencrypted) cookie must be read as-is; if `locale` were still in
        // EncryptCookies, decryption would fail and the request would render `en`.
        $this->withUnencryptedCookie('locale', 'id')
            ->get('/_locale-probe')
            ->assertJson(['locale' => 'id']);
    }

    public function test_panel_pages_use_the_locale(): void
    {
        $this->withUnencryptedCookie('locale', 'ms')
            ->get('/account/login')
            ->assertOk()
            ->assertSee('lang="ms"', false);
    }

    public function test_api_responses_stay_english_for_a_malay_member(): void
    {
        $user = User::factory()->create(['locale' => 'ms']);

        $this->actingAs($user, 'sanctum')
            ->withUnencryptedCookie('locale', 'ms')
            ->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'required'));
    }
}
```

> Before running, confirm the API login route path with `php artisan route:list --path=api | grep -i login`. If it differs from `/api/v1/auth/login`, use the real path — the assertion is only that validation text is English.

- [ ] **Step 2: Run to confirm failure**

Run: `php artisan test --filter=SetLocaleTest`
Expected: FAIL — locale stays `en` with a cookie; `localeChosen` is null.

- [ ] **Step 3: Implement the middleware**

`app/Http/Middleware/SetLocale.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale for web, panel and Livewire requests: the signed-in account's choice,
 * else the `locale` cookie, else the default. Anything not enabled in
 * APP_LOCALES is ignored, so disabling a language safely re-prompts its users.
 */
class SetLocale
{
    public const COOKIE = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        $choice = collect([$request->user()?->locale, $request->cookie(self::COOKIE)])
            ->first(fn (?string $code) => Locales::isEnabled($code));

        $locale = $choice ?? config('app.locale');

        App::setLocale($locale);
        Carbon::setLocale($locale);
        View::share('localeChosen', $choice !== null);

        return $next($request);
    }
}
```

`bootstrap/app.php`, inside `withMiddleware`, after `trustProxies`:

```php
        // The locale cookie is read by the client-side sync script, so it must stay plain.
        $middleware->encryptCookies(except: [\App\Http\Middleware\SetLocale::COOKIE]);
        $middleware->web(append: [\App\Http\Middleware\SetLocale::class]);
```

`AccountPanelProvider` `->middleware([...])`: insert `\App\Http\Middleware\SetLocale::class,` immediately after `StartSession::class,` (it needs the session to resolve the user; it must run before the page renders).

`AppServiceProvider::boot()`, append:

```php
        // Livewire update requests re-run only "persistent" middleware; without this a
        // component re-render falls back to English mid-form.
        \Livewire\Livewire::addPersistentMiddleware([\App\Http\Middleware\SetLocale::class]);
```

- [ ] **Step 4: Add the Livewire regression test**

Append to `SetLocaleTest`:

```php
    public function test_livewire_update_keeps_the_locale(): void
    {
        app('translator')->addLines(['*.Claim your account' => 'Tuntut akaun anda'], 'ms');

        \Livewire\Livewire::withUnencryptedCookies(['locale' => 'ms'])
            ->test(\App\Livewire\ClaimAccount::class)
            ->set('email', 'nobody@example.com')
            ->call('lookup')
            ->assertSet('stage', 'lookup');

        $this->assertSame('ms', app()->getLocale());
    }
```

> If `ClaimAccount`'s lookup method is not named `lookup`, use its real name (`grep -n "public function" app/Livewire/ClaimAccount.php`). If `Livewire::withUnencryptedCookies` is unavailable in Livewire 4.3, use `Livewire::withCookies(['locale' => 'ms'])` — the cookie is excluded from encryption, so either works.

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter=SetLocaleTest` → PASS. Then `php artisan test` → all pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/SetLocale.php bootstrap/app.php app/Providers/Filament/AccountPanelProvider.php app/Providers/AppServiceProvider.php tests/Feature/Localisation/SetLocaleTest.php
git commit -m "feat(i18n): SetLocale middleware on web, panel and Livewire

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `POST /locale` and the `LocaleCookie` helper

**Files:**
- Create: `app/Support/LocaleCookie.php`
- Create: `app/Http/Controllers/LocaleController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Localisation/ChooseLocaleTest.php`

**Interfaces:**
- Consumes: `Locales`, `SetLocale::COOKIE`.
- Produces:
  - `App\Support\LocaleCookie::make(string $code): \Symfony\Component\HttpFoundation\Cookie`
  - `App\Support\LocaleCookie::queue(string $code): void`
  - Route `POST /locale` named `locale.update`, field `locale`, responds `303` to `url()->previous()`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Localisation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChooseLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locales' => 'en,ms,id']);
    }

    public function test_guest_choice_sets_a_plain_year_long_cookie_and_redirects_back(): void
    {
        $response = $this->from('/privacy')->post('/locale', ['locale' => 'ms']);

        $response->assertStatus(303)->assertRedirect('/privacy');
        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'locale');
        $this->assertSame('ms', $cookie->getValue());
        $this->assertFalse($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower($cookie->getSameSite()));
        $this->assertGreaterThan(now()->addDays(360)->timestamp, $cookie->getExpiresTime());
    }

    public function test_signed_in_choice_is_saved_to_the_account(): void
    {
        $user = User::factory()->create(['locale' => null]);

        $this->actingAs($user)->from('/account')->post('/locale', ['locale' => 'id'])->assertStatus(303);

        $this->assertSame('id', $user->fresh()->locale);
    }

    public function test_unknown_or_disabled_locale_is_rejected_and_changes_nothing(): void
    {
        config(['app.locales' => 'en,id']);
        $user = User::factory()->create(['locale' => 'id']);

        foreach (['my', 'ms', ''] as $bad) {
            $this->actingAs($user)->from('/')->post('/locale', ['locale' => $bad])
                ->assertSessionHasErrors('locale')
                ->assertCookieMissing('locale');
        }
        $this->assertSame('id', $user->fresh()->locale);
    }

    public function test_route_is_csrf_protected(): void
    {
        // Laravel skips CSRF checks in tests, so assert the route sits in the `web`
        // group, which applies VerifyCsrfToken to real requests.
        $this->assertContains('web', app('router')->getRoutes()->getByName('locale.update')->gatherMiddleware());
    }
}
```

- [ ] **Step 2: Run to confirm failure**

Run: `php artisan test --filter=ChooseLocaleTest`
Expected: FAIL — 404 / route not defined.

- [ ] **Step 3: Implement**

`app/Support/LocaleCookie.php`:

```php
<?php

namespace App\Support;

use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/** The `locale` cookie: one year, readable by the client-side sync script. */
class LocaleCookie
{
    public static function make(string $code): SymfonyCookie
    {
        return Cookie::make(SetLocale::COOKIE, $code, 60 * 24 * 365, httpOnly: false, sameSite: 'lax');
    }

    public static function queue(string $code): void
    {
        Cookie::queue(self::make($code));
    }
}
```

`app/Http/Controllers/LocaleController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Support\LocaleCookie;
use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $code = $request->validate([
            'locale' => ['required', 'string', Rule::in(Locales::enabled())],
        ])['locale'];

        $request->user()?->forceFill(['locale' => $code])->save();

        return redirect()->to(url()->previous('/'), 303)->withCookie(LocaleCookie::make($code));
    }
}
```

`routes/web.php`, after the `/claim` route:

```php
Route::post('/locale', [\App\Http\Controllers\LocaleController::class, 'update'])->name('locale.update');
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=ChooseLocaleTest` → PASS. Then `php artisan test` → all pass.

- [ ] **Step 5: Commit**

```bash
git add app/Support/LocaleCookie.php app/Http/Controllers/LocaleController.php routes/web.php tests/Feature/Localisation/ChooseLocaleTest.php
git commit -m "feat(i18n): POST /locale saves the choice to cookie and account

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Sign-in sync

**Files:**
- Create: `app/Listeners/SyncLocaleOnLogin.php`
- Test: `tests/Feature/Localisation/SyncLocaleOnLoginTest.php`

**Interfaces:**
- Consumes: `Locales::isEnabled`, `LocaleCookie::queue`, `SetLocale::COOKIE`.
- Produces: listener on `Illuminate\Auth\Events\Login` (auto-discovered from `app/Listeners` in Laravel 12). Fires for Filament password login and passkey login alike, since both go through the `web` guard.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Localisation;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Tests\TestCase;

class SyncLocaleOnLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locales' => 'en,ms,id']);
    }

    private function login(User $user, ?string $cookie): void
    {
        $this->app['request']->cookies->set('locale', $cookie);
        event(new Login('web', $user, false));
    }

    public function test_account_locale_wins_and_is_queued_as_the_cookie(): void
    {
        $user = User::factory()->create(['locale' => 'id']);

        $this->login($user, 'ms');

        $this->assertSame('id', Cookie::queued('locale')?->getValue());
        $this->assertSame('id', $user->fresh()->locale);
    }

    public function test_account_without_locale_adopts_the_device_cookie(): void
    {
        $user = User::factory()->create(['locale' => null]);

        $this->login($user, 'ms');

        $this->assertSame('ms', $user->fresh()->locale);
        $this->assertNull(Cookie::queued('locale'));
    }

    public function test_nothing_to_sync_leaves_both_alone(): void
    {
        $user = User::factory()->create(['locale' => null]);

        $this->login($user, 'xx');

        $this->assertNull($user->fresh()->locale);
        $this->assertNull(Cookie::queued('locale'));
    }

    public function test_real_filament_login_triggers_sync(): void
    {
        $user = User::factory()->create(['locale' => 'id', 'activated_at' => now(), 'password' => bcrypt('secret-pass')]);

        \Livewire\Livewire::test(\App\Filament\Auth\Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'secret-pass'])
            ->call('authenticate');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('id', Cookie::queued('locale')?->getValue());
    }
}
```

- [ ] **Step 2: Run to confirm failure**

Run: `php artisan test --filter=SyncLocaleOnLoginTest`
Expected: FAIL — nothing queued / locale not adopted.

- [ ] **Step 3: Implement**

`app/Listeners/SyncLocaleOnLogin.php`:

```php
<?php

namespace App\Listeners;

use App\Http\Middleware\SetLocale;
use App\Support\LocaleCookie;
use App\Support\Locales;
use Illuminate\Auth\Events\Login;

/**
 * On sign-in the account's language wins and is pushed to this device's cookie
 * (the page's sync script then updates localStorage). An account without one
 * adopts the language this device already chose.
 */
class SyncLocaleOnLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;
        $device = request()->cookie(SetLocale::COOKIE);

        if (Locales::isEnabled($user->locale ?? null)) {
            LocaleCookie::queue($user->locale);
        } elseif (Locales::isEnabled($device)) {
            $user->forceFill(['locale' => $device])->save();
        }
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=SyncLocaleOnLoginTest` → PASS. Confirm discovery: `php artisan event:list | grep -A2 "Auth\\\\Events\\\\Login"` lists `SyncLocaleOnLogin`. Then `php artisan test` → all pass.

- [ ] **Step 5: Commit**

```bash
git add app/Listeners/SyncLocaleOnLogin.php tests/Feature/Localisation/SyncLocaleOnLoginTest.php
git commit -m "feat(i18n): sync locale between account and device on sign-in

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Chooser, switchers and the sync script

**Files:**
- Create: `resources/views/components/locale/sync-script.blade.php`
- Create: `resources/views/components/locale/chooser.blade.php`
- Create: `resources/views/components/locale/switcher.blade.php`
- Modify: `resources/views/landing.blade.php` (`<html lang>`, head, header `<nav>`, end of body)
- Modify: `resources/views/privacy.blade.php` (`<html lang>`, head, header, end of body)
- Modify: `resources/views/components/layouts/guest.blade.php` (head, end of body)
- Modify: `app/Providers/Filament/AccountPanelProvider.php` (render hooks, user menu item)
- Modify: `app/Filament/Auth/Profile.php` (Language field, `afterSave`)
- Test: `tests/Feature/Localisation/LocaleChooserTest.php`

**Interfaces:**
- Consumes: `$localeChosen` (Task 3), `route('locale.update')` (Task 4), `Locales`, `LocaleCookie::queue`.
- Produces: Blade components `<x-locale.sync-script />`, `<x-locale.chooser />`, `<x-locale.switcher />`; panel user-menu action `language`; Profile field `locale`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Localisation;

use App\Filament\Auth\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Livewire\Livewire;
use Tests\TestCase;

class LocaleChooserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locales' => 'en,ms,id']);
    }

    public function test_public_pages_ask_when_no_choice_exists(): void
    {
        foreach (['/', '/privacy', '/claim', '/account/login'] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('data-locale-chooser', false)
                ->assertSee('Bahasa Melayu')
                ->assertSee('Bahasa Indonesia');
        }
    }

    public function test_no_chooser_once_chosen_but_switcher_shows(): void
    {
        $this->withUnencryptedCookie('locale', 'ms')->get('/')
            ->assertDontSee('data-locale-chooser', false)
            ->assertSee('data-locale-switcher', false);
    }

    public function test_member_with_saved_locale_is_not_asked_in_the_portal(): void
    {
        $user = User::factory()->create(['locale' => 'id', 'activated_at' => now()]);

        $this->actingAs($user)->get('/account')->assertOk()->assertDontSee('data-locale-chooser', false);
    }

    public function test_everything_hidden_when_only_english_is_enabled(): void
    {
        config(['app.locales' => 'en']);

        $this->get('/')->assertDontSee('data-locale-chooser', false)->assertDontSee('data-locale-switcher', false);
        $this->get('/account/login')->assertDontSee('data-locale-chooser', false);
    }

    public function test_every_page_carries_the_sync_script(): void
    {
        foreach (['/', '/privacy', '/claim', '/account/login'] as $url) {
            $this->get($url)->assertSee('data-locale-sync', false);
        }
    }

    public function test_profile_language_field_saves_and_sets_the_cookie(): void
    {
        $user = User::factory()->create(['locale' => 'en', 'activated_at' => now()]);
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->fillForm(['locale' => 'ms'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('ms', $user->fresh()->locale);
        $this->assertSame('ms', Cookie::queued('locale')?->getValue());
    }
}
```

- [ ] **Step 2: Run to confirm failure**

Run: `php artisan test --filter=LocaleChooserTest`
Expected: FAIL — no `data-locale-chooser` markup.

- [ ] **Step 3: Implement the sync script**

`resources/views/components/locale/sync-script.blade.php` — keeps `localStorage` and the cookie in step (spec §1 "Cookie and localStorage sync"):

```blade
@php($enabled = \App\Support\Locales::enabled())
@if (count($enabled) > 1)
<script data-locale-sync>
(function () {
    var enabled = @json($enabled);
    function readCookie() { var m = document.cookie.match(/(?:^|; )locale=([^;]*)/); return m ? decodeURIComponent(m[1]) : null; }
    var cookie = readCookie(), stored = null;
    try { stored = localStorage.getItem('locale'); } catch (e) {}
    if (stored && enabled.indexOf(stored) === -1) stored = null;
    if (cookie && enabled.indexOf(cookie) === -1) cookie = null;

    if (cookie) {
        // The server only changes the cookie deliberately, so it is the newer value.
        if (stored !== cookie) { try { localStorage.setItem('locale', cookie); } catch (e) {} }
        return;
    }
    if (!stored) return;
    // Cookie cleared but this device chose before: restore it once and reload.
    // With cookies blocked entirely the restore cannot stick; hide the chooser instead of looping.
    document.documentElement.setAttribute('data-locale-stored', stored);
    var guard = 'locale-restored';
    try { if (sessionStorage.getItem(guard)) return; sessionStorage.setItem(guard, '1'); } catch (e) { return; }
    document.cookie = 'locale=' + encodeURIComponent(stored) + '; path=/; max-age=31536000; samesite=lax' + (location.protocol === 'https:' ? '; secure' : '');
    if (readCookie() === stored) location.reload();
})();
</script>
<style>html[data-locale-stored] [data-locale-chooser]{display:none!important}</style>
@endif
```

- [ ] **Step 4: Implement the chooser and switcher**

`resources/views/components/locale/chooser.blade.php`:

```blade
@if (\App\Support\Locales::multipleEnabled() && ! ($localeChosen ?? false))
<div data-locale-chooser class="lc-overlay" role="dialog" aria-modal="true" aria-labelledby="lc-title">
    <div class="lc-card">
        <h2 id="lc-title" class="lc-title">Choose your language · Pilih bahasa anda · Pilih bahasa Anda</h2>
        <div class="lc-options">
            @foreach (\App\Support\Locales::enabled() as $code)
                <form method="POST" action="{{ route('locale.update') }}">
                    @csrf
                    <input type="hidden" name="locale" value="{{ $code }}">
                    <button type="submit" class="lc-option" data-locale-option="{{ $code }}" lang="{{ $code }}">
                        {{ \App\Support\Locales::nativeName($code) }}
                    </button>
                </form>
            @endforeach
        </div>
    </div>
</div>
<script>
(function () {
    // Pre-highlight the browser's language without choosing it.
    var langs = (navigator.languages || [navigator.language || '']).map(function (l) { return l.slice(0, 2).toLowerCase(); });
    var match = langs.find(function (l) { return document.querySelector('[data-locale-option="' + l + '"]'); }) || 'en';
    var btn = document.querySelector('[data-locale-option="' + match + '"]');
    if (btn) { btn.classList.add('is-suggested'); btn.focus(); }
})();
</script>
<style>
.lc-overlay{position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(10,9,18,.6);backdrop-filter:blur(4px)}
.lc-card{width:100%;max-width:400px;border-radius:20px;padding:24px;background:#fff;color:#101114;box-shadow:0 16px 40px rgba(0,0,0,.3);font-family:system-ui,sans-serif}
.lc-title{margin:0 0 16px;font-size:16px;font-weight:700;line-height:1.4;text-align:center}
.lc-options{display:flex;flex-direction:column;gap:10px}
.lc-option{width:100%;min-height:52px;border-radius:12px;border:1px solid #DEDEE5;background:#fff;color:inherit;font:600 16px/1 system-ui,sans-serif;cursor:pointer}
.lc-option:hover,.lc-option:focus-visible{border-color:#F7931E;outline:none}
.lc-option.is-suggested{border:2px solid #F7931E;background:#FFF8F0}
@media (prefers-color-scheme:dark){.lc-card{background:#1A1729;color:#F2F1F9}.lc-option{background:#1A1729;border-color:rgba(255,255,255,.22)}.lc-option.is-suggested{background:rgba(247,147,30,.12)}}
</style>
@endif
```

`resources/views/components/locale/switcher.blade.php`:

```blade
@if (\App\Support\Locales::multipleEnabled())
<div data-locale-switcher class="ls" role="group" aria-label="{{ __('Language') }}">
    @foreach (\App\Support\Locales::enabled() as $code)
        <form method="POST" action="{{ route('locale.update') }}">
            @csrf
            <input type="hidden" name="locale" value="{{ $code }}">
            <button type="submit" class="ls-btn {{ app()->getLocale() === $code ? 'is-current' : '' }}"
                    aria-pressed="{{ app()->getLocale() === $code ? 'true' : 'false' }}"
                    aria-label="{{ \App\Support\Locales::nativeName($code) }}" lang="{{ $code }}">
                {{ \App\Support\Locales::shortLabel($code) }}
            </button>
        </form>
    @endforeach
</div>
<style>
.ls{display:inline-flex;border:1px solid var(--border-subtle,#DEDEE5);border-radius:10px;overflow:hidden}
.ls form{margin:0}
.ls-btn{min-width:40px;min-height:44px;padding:0 10px;border:0;background:transparent;color:var(--text-secondary,#686B82);font:700 12.5px/1 var(--font-ui,system-ui);letter-spacing:.04em;cursor:pointer}
.ls-btn.is-current{background:var(--orange-500,#F7931E);color:var(--navy-900,#140E48)}
@media (max-width:480px){.ls-btn{min-width:36px;padding:0 6px}}
</style>
@endif
```

> The spec's narrow-screen globe button is replaced by the same three-letter control at a tighter size: at 375px the header holds logo + Sign in + EN·BM·ID + theme toggle without wrapping (verify in Step 6). If it does wrap in practice, collapse the switcher into a `<details>` with a globe `<summary>` under `@media (max-width:480px)`.

- [ ] **Step 5: Wire them in**

`landing.blade.php` and `privacy.blade.php`: change `<html lang="en">` to `<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">`; add `<x-locale.sync-script />` as the first child of `<head>` after `<meta charset>`; in `landing.blade.php` put `<x-locale.switcher />` inside `<nav>` immediately before the theme-toggle `<button>` (privacy: in its header beside the brand); add `<x-locale.chooser />` immediately before `</body>`.

`guest.blade.php`: `<x-locale.sync-script />` at the top of `<head>`, `<x-locale.switcher />` in its header if it has one (else top-right of the page container), `<x-locale.chooser />` before `</body>`.

`AccountPanelProvider::panel()`, add:

```php
            ->renderHook(PanelsRenderHook::HEAD_START, fn (): string => Blade::render('<x-locale.sync-script />'))
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => Blade::render('<x-locale.chooser />'))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, fn (): string => Blade::render('<div style="display:flex;justify-content:center;margin-bottom:1rem"><x-locale.switcher /></div>'))
            ->userMenuItems([
                \Filament\Actions\Action::make('language')
                    ->label(fn (): string => __('Language'))
                    ->icon('heroicon-o-language')
                    ->visible(fn (): bool => \App\Support\Locales::multipleEnabled())
                    ->schema([
                        \Filament\Forms\Components\Radio::make('locale')
                            ->label(fn (): string => __('Language'))
                            ->options(fn (): array => collect(\App\Support\Locales::enabled())
                                ->mapWithKeys(fn ($c) => [$c => \App\Support\Locales::nativeName($c)])->all())
                            ->default(fn (): string => app()->getLocale())
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        auth()->user()->forceFill(['locale' => $data['locale']])->save();
                        \App\Support\LocaleCookie::queue($data['locale']);
                        redirect(request()->header('Referer') ?? '/account');
                    }),
            ])
```

> Check the Filament 5 names before relying on them: `grep -n "HEAD_START\|BODY_END\|AUTH_LOGIN_FORM_BEFORE" vendor/filament/filament/src/View/PanelsRenderHook.php`; `Action::schema()` vs `->form()` (`grep -n "public function schema\|public function form" vendor/filament/actions/src/Action.php`). Use whatever exists.

`app/Filament/Auth/Profile.php`, in `form()` after `$this->getEmailFormComponent(),`:

```php
                \Filament\Forms\Components\Select::make('locale')
                    ->label(__('Language'))
                    ->options(fn (): array => collect(\App\Support\Locales::enabled())
                        ->mapWithKeys(fn ($c) => [$c => \App\Support\Locales::nativeName($c)])->all())
                    ->visible(fn (): bool => \App\Support\Locales::multipleEnabled())
                    ->in(fn (): array => \App\Support\Locales::enabled())
                    ->native(false),
```

and add:

```php
    protected function afterSave(): void
    {
        if (\App\Support\Locales::isEnabled($this->getUser()->locale)) {
            \App\Support\LocaleCookie::queue($this->getUser()->locale);
        }
    }
```

- [ ] **Step 6: Run tests, then the browser checks**

Run: `php artisan test --filter=LocaleChooserTest` → PASS; `php artisan test` → all pass.

Browser (local server with `APP_LOCALES=en,ms,id` in your local `.env`; `php artisan serve --port=8011`):
1. Fresh profile → `/`: chooser shows; the browser's language is outlined; pick **Bahasa Melayu** → page reloads, no chooser, `localStorage.locale === 'ms'` (DevTools console), cookie `locale=ms`.
2. Delete only the `locale` cookie, reload → one automatic reload, cookie back, no chooser.
3. Block cookies for `localhost`, reload `/` twice → **no reload loop** (Network tab shows one document request per manual reload) and no chooser (hidden via `data-locale-stored`). Unblock cookies after.
4. At 375px (`/` and `/account/login`): header shows logo, Sign in, EN·BM·ID and the theme toggle on one line.
5. Portal user menu → Language → Bahasa Indonesia → page reloads with `lang="id"`.

- [ ] **Step 7: Commit**

```bash
git add resources/views/components/locale resources/views/landing.blade.php resources/views/privacy.blade.php resources/views/components/layouts/guest.blade.php app/Providers/Filament/AccountPanelProvider.php app/Filament/Auth/Profile.php tests/Feature/Localisation/LocaleChooserTest.php
git commit -m "feat(i18n): first-visit chooser, switchers and cookie/localStorage sync

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Completeness guards

**Files:**
- Create: `tests/Feature/Localisation/TranslationCompletenessTest.php`
- Create: `tests/Feature/Localisation/RenderedPagesAreTranslatedTest.php`
- Modify: `app/Providers/AppServiceProvider.php` (Filament `translateLabel()` defaults)

**Interfaces:**
- Consumes: `lang/ms.json`, `lang/id.json`.
- Produces: two guards later tasks extend:
  - `TranslationCompletenessTest::SCAN_PATHS` (array of dirs) — static `__()`/`trans()`/`@lang()` literal keys must exist in both JSON files.
  - `RenderedPagesAreTranslatedTest::pages(): array` — URLs rendered in `ms` and `id` with Laravel's missing-key hook; Tasks 8–10 append their pages here.

- [ ] **Step 1: Make Filament auto-labels translatable**

Filament derives labels from field names (`TextColumn::make('send_at')` → "Send at") without passing them through the translator. In `AppServiceProvider::boot()` append:

```php
        // Route Filament's auto-generated labels ("Send at") through __() so they can
        // be translated in lang/*.json like any explicit label.
        foreach ([
            \Filament\Forms\Components\Field::class,
            \Filament\Tables\Columns\Column::class,
            \Filament\Tables\Filters\BaseFilter::class,
            \Filament\Infolists\Components\Entry::class,
        ] as $component) {
            $component::configureUsing(fn ($c) => $c->translateLabel());
        }
```

> Verify each class exists and has `translateLabel()`: `grep -rn "function translateLabel" vendor/filament/*/src | head`. Drop any class that lacks it and note which.

- [ ] **Step 2: Write the static guard**

```php
<?php

namespace Tests\Feature\Localisation;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class TranslationCompletenessTest extends TestCase
{
    /** Directories whose __()/trans()/@lang() literals must be translated. */
    public const SCAN_PATHS = ['app', 'resources/views'];

    /** Vendor-namespaced (pkg::file.key) and PHP group keys (file.key) are not ours. */
    private function isAppKey(string $key): bool
    {
        return ! str_contains($key, '::') && ! preg_match('/^[a-z_]+\.[a-z0-9_.]+$/', $key);
    }

    /** @return array<string, list<string>> key => locations */
    private function keysInCode(): array
    {
        $keys = [];
        $files = Finder::create()->files()->in(array_map('base_path', self::SCAN_PATHS))
            ->name(['*.php'])->notPath('vendor');

        foreach ($files as $file) {
            $src = $file->getContents();
            preg_match_all('/(?:__|trans|@lang)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s', $src, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[2] as [$raw, $offset]) {
                $key = stripcslashes($raw);
                if ($this->isAppKey($key)) {
                    $line = substr_count(substr($src, 0, $offset), "\n") + 1;
                    $keys[$key][] = $file->getRelativePathname().':'.$line;
                }
            }
        }

        return $keys;
    }

    /** @return array<string, string> */
    private function json(string $locale): array
    {
        return json_decode(file_get_contents(lang_path("$locale.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_every_key_in_code_is_translated(): void
    {
        $code = $this->keysInCode();

        foreach (['ms', 'id'] as $locale) {
            $missing = array_diff_key($code, $this->json($locale));
            $this->assertSame([], array_map(fn ($l) => implode(', ', $l), $missing),
                "Missing from lang/$locale.json (key => where used)");
        }
    }

    public function test_translations_keep_every_placeholder(): void
    {
        foreach (['ms', 'id'] as $locale) {
            foreach ($this->json($locale) as $key => $value) {
                preg_match_all('/:[a-zA-Z_]+/', $key, $k);
                preg_match_all('/:[a-zA-Z_]+/', $value, $v);
                $this->assertEqualsCanonicalizing($k[0], $v[0], "lang/$locale.json placeholders differ for: $key");
            }
        }
    }

    public function test_json_files_are_valid_and_values_are_non_empty(): void
    {
        foreach (['ms', 'id'] as $locale) {
            foreach ($this->json($locale) as $key => $value) {
                $this->assertNotSame('', trim($value), "Empty translation in $locale.json for: $key");
            }
        }
    }
}
```

- [ ] **Step 3: Write the runtime guard**

```php
<?php

namespace Tests\Feature\Localisation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * Renders real pages in ms and id and fails on any app string that has no
 * translation - catching Filament auto-labels and dynamic keys the static scan misses.
 */
class RenderedPagesAreTranslatedTest extends TestCase
{
    use RefreshDatabase;

    /** Guest pages. Tasks 8-10 append portal pages to pages(). */
    public static function guestPages(): array
    {
        return ['/', '/privacy', '/claim', '/account/login'];
    }

    /** @return list<string> pages rendered as an activated admin */
    public static function pages(): array
    {
        return [];
    }

    public function test_pages_have_no_missing_translations(): void
    {
        config(['app.locales' => 'en,ms,id']);
        $this->seedForRendering();
        $admin = User::factory()->create(['is_admin' => true, 'activated_at' => now()]);

        foreach (['ms', 'id'] as $locale) {
            $missing = [];
            Lang::handleMissingKeysUsing(function (string $key, array $replace, ?string $l) use (&$missing, $locale) {
                if (($l ?? app()->getLocale()) === $locale && ! str_contains($key, '::') && ! preg_match('/^[a-z_]+\.[a-z0-9_.]+$/', $key)) {
                    $missing[$key] = true;
                }

                return $key;
            });

            foreach (self::guestPages() as $url) {
                auth()->logout();
                $this->withUnencryptedCookie('locale', $locale)->get($url)->assertOk();
            }
            $admin->forceFill(['locale' => $locale])->save();
            foreach (self::pages() as $url) {
                $this->actingAs($admin)->get($url)->assertOk();
            }

            $this->assertSame([], array_keys($missing), "Untranslated strings rendered in $locale");
        }
    }

    /** Minimal rows so index/edit pages render content, not just empty states. */
    private function seedForRendering(): void
    {
        // Extended in Task 9/10 as admin pages are added.
    }
}
```

- [ ] **Step 4: Run the guards — expect failures listing every string**

Run: `php artisan test --filter=TranslationCompletenessTest`
Expected: FAIL listing existing `__()` keys missing from `ms.json`/`id.json` (e.g. passkey strings already in code).
Run: `php artisan test --filter=RenderedPagesAreTranslatedTest`
Expected: PASS for now only if guest pages emit no app keys yet — they don't use `__()` until Task 8, so it passes; that's expected.

- [ ] **Step 5: Translate the keys the static guard reports**

Add each reported key to `lang/ms.json` and `lang/id.json` (keep both files sorted by key, 4-space indented, `JSON_UNESCAPED_UNICODE`). Translation rules for every task from here on:
- Natural, idiomatic Malay and Indonesian — written separately, not one copied to the other (e.g. "account" = *akaun* in ms, *akun* in id; "email" = *e-mel* in ms, *email* in id; "sign in" = *log masuk* in ms, *masuk* in id).
- Keep placeholders (`:name`) exactly; keep brand **PENTANA**, **MYR**, and passkey as *kunci laluan* (ms) / *passkey* (id).
- Formal-friendly register: *anda* (ms), *Anda* (id).

Run both guards → PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Providers/AppServiceProvider.php tests/Feature/Localisation/TranslationCompletenessTest.php tests/Feature/Localisation/RenderedPagesAreTranslatedTest.php lang/ms.json lang/id.json
git commit -m "test(i18n): static and rendered-page translation guards

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Translate the public pages

**Files:**
- Modify: `resources/views/landing.blade.php`, `resources/views/privacy.blade.php`, `resources/views/livewire/claim-account.blade.php`, `resources/views/livewire/claim-submit-button.blade.php`, `resources/views/components/layouts/guest.blade.php`
- Modify: `app/Livewire/ClaimAccount.php` (messages), `app/Filament/Auth/Login.php`, `app/Providers/Filament/AccountPanelProvider.php` (the "New here?" render hook)
- Modify: `lang/ms.json`, `lang/id.json`
- Test: `tests/Feature/Localisation/PublicPagesLocalisedTest.php`; existing `tests/Feature/LandingPageTest.php`, `tests/Feature/PrivacyPolicyTest.php`, `tests/Feature/Onboarding/*` must keep passing in English.

**Interfaces:**
- Consumes: Tasks 1–7.
- Produces: no new code interfaces; translated strings.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Localisation;

use Tests\TestCase;

class PublicPagesLocalisedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locales' => 'en,ms,id']);
    }

    public function test_landing_renders_in_each_language(): void
    {
        $this->withUnencryptedCookie('locale', 'ms')->get('/')
            ->assertSee('lang="ms"', false)
            ->assertSee(__('Claim your account', [], 'ms'))
            ->assertDontSee('>Claim your account<', false);

        $this->withUnencryptedCookie('locale', 'id')->get('/')
            ->assertSee(__('Claim your account', [], 'id'));
    }

    public function test_privacy_and_claim_render_translated_headings(): void
    {
        $this->withUnencryptedCookie('locale', 'id')->get('/privacy')->assertSee(__('Privacy Policy', [], 'id'));
        $this->withUnencryptedCookie('locale', 'ms')->get('/claim')->assertSee(__('Activate your account', [], 'ms'));
    }

    public function test_english_is_unchanged(): void
    {
        $this->get('/')->assertSee('Claim your account')->assertSee('Your dues, lunch and activities, all in one place.');
    }
}
```

- [ ] **Step 2: Run to confirm failure**

Run: `php artisan test --filter=PublicPagesLocalisedTest`
Expected: FAIL — `ms` page still shows English.

- [ ] **Step 3: Wrap every user-visible string**

Rules (apply to each file listed above):
- Blade text nodes: `Claim your account` → `{{ __('Claim your account') }}`.
- Attributes: `aria-label="Toggle dark mode"` → `aria-label="{{ __('Toggle dark mode') }}"`; also `title`, `alt`, `placeholder`, `<title>`, `<meta name="description">`.
- Inline markup inside a sentence: use placeholders, not concatenation — e.g. the band note becomes `{!! __('Android is on the way. :claim now and we\'ll let you know when it lands.', ['claim' => '<a href="'.e(route('claim')).'" style="color:#fff;font-weight:600;text-decoration:underline">'.e(__('Claim your account')).'</a>']) !!}`. Only use `{!! !!}` where the placeholder holds markup you built with `e()`.
- PHP strings (`ClaimAccount`, `Login`, render hook): `'This account is already active. Please log in.'` → `__('This account is already active. Please log in.')`.
- Do **not** translate: `PENTANA`, `MYR 70.00`, `#PT-48213`, demo names (`Aisyah`, `Bukit Tabur`), the email address, the hostname.
- The phone/snapshot mockups are product illustrations: translate their UI labels ("This month's dues", "Paid", "Vote", "Registered", "Lunch vote") but keep demo data.
- `privacy.blade.php`: translate every heading, paragraph and list item, one key per paragraph/list item (keep `<strong>` lead-ins as their own keys: `{{ __('Account details') }}: {{ __('your name, email address, ...') }}`).

- [ ] **Step 4: Translate the new keys**

Run: `php artisan test --filter=TranslationCompletenessTest` — it lists every new key. Add them to `lang/ms.json` and `lang/id.json` following Task 7 Step 5's rules. Re-run until PASS.

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter="PublicPagesLocalisedTest|RenderedPagesAreTranslatedTest|LandingPageTest|PrivacyPolicyTest|Onboarding"` → PASS. Then `php artisan test` → all pass.

- [ ] **Step 6: Visual check at 375px and 1280px in ms and id**

Local server with `APP_LOCALES=en,ms,id`; for each of `/`, `/privacy`, `/claim`, `/account/login` in `ms` and `id`: nothing overflows horizontally (`document.documentElement.scrollWidth === innerWidth`), hero headline wraps cleanly, buttons don't truncate, header stays one line. Fix copy length or CSS where it breaks.

- [ ] **Step 7: Commit**

```bash
git add resources/views app/Livewire/ClaimAccount.php app/Filament/Auth/Login.php app/Providers/Filament/AccountPanelProvider.php lang/ms.json lang/id.json tests/Feature/Localisation/PublicPagesLocalisedTest.php
git commit -m "feat(i18n): translate public pages, claim flow and login

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Translate the portal — member screens

**Files:**
- Modify: `app/Filament/Pages/MyLunch.php`, `app/Filament/Pages/MyActivities.php`, `app/Filament/Pages/LunchCalendar.php`, `app/Filament/Widgets/*.php`, `app/Filament/Auth/Profile.php`, `resources/views/filament/**/*.blade.php`, `resources/views/vendor/passkeys/**/*.blade.php`
- Modify: `lang/ms.json`, `lang/id.json`
- Modify: `tests/Feature/Localisation/RenderedPagesAreTranslatedTest.php` (`pages()`, `seedForRendering()`)
- Test: `tests/Feature/Localisation/MemberPortalLocalisedTest.php`

**Interfaces:**
- Consumes: guards from Task 7.
- Produces: member page URLs appended to `RenderedPagesAreTranslatedTest::pages()`.

- [ ] **Step 1: Extend the runtime guard with member pages**

List them: `php artisan route:list --path=account --columns=uri | grep -v "{"`. Add every member-facing GET URI without parameters (dashboard `/account`, `/account/my-lunch`, `/account/my-activities`, `/account/lunch-calendar`, `/account/profile` — use the real URIs from the listing) to `pages()`.

In `seedForRendering()` create one published lunch with a meal option, one published activity with a registration question, and one fee period with an unpaid bill for the admin, using the existing factories (`ls database/factories`), so widgets and lists render rows rather than empty states.

- [ ] **Step 2: Write the member-portal test**

```php
<?php

namespace Tests\Feature\Localisation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberPortalLocalisedTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_dashboard_and_navigation_follow_the_account_language(): void
    {
        config(['app.locales' => 'en,ms,id']);
        $member = User::factory()->create(['locale' => 'ms', 'activated_at' => now()]);

        $this->actingAs($member)->get('/account')
            ->assertOk()
            ->assertSee('lang="ms"', false)
            ->assertSee(__('My Activities', [], 'ms'));
    }
}
```

> Use the exact English navigation label of the My Activities page (`grep -n "navigationLabel\|getNavigationLabel\|title" app/Filament/Pages/MyActivities.php`).

- [ ] **Step 3: Run to confirm failure**

Run: `php artisan test --filter="MemberPortalLocalisedTest|RenderedPagesAreTranslatedTest"`
Expected: FAIL — English labels; the runtime guard lists untranslated strings including Filament auto-labels.

- [ ] **Step 4: Wrap strings in member screens**

Rules for Filament PHP:
- Static properties can't call `__()`. Replace `protected static ?string $navigationLabel = 'X';` / `$title` / `$navigationGroup` / `$modelLabel` / `$pluralModelLabel` with overrides:

```php
    public static function getNavigationLabel(): string
    {
        return __('My Activities');
    }

    public function getTitle(): string
    {
        return __('My Activities');
    }
```

  (`getNavigationGroup(): ?string`, `getModelLabel(): string`, `getPluralModelLabel(): string` likewise. Keep the static property removed so there's one source.)
- Fluent labels: `->label('Paid')` → `->label(__('Paid'))`; same for `->heading`, `->description`, `->placeholder`, `->helperText`, `->hint`, `->emptyStateHeading`, `->emptyStateDescription`, `->modalHeading`, `->modalDescription`, `->modalSubmitActionLabel`, `->successNotificationTitle`, `Notification::make()->title(...)->body(...)`, `Text::make(...)`, `Section::make(...)`, badge/state formatters returning text.
- Option arrays: `['attending' => 'Attending']` → `['attending' => __('Attending')]`.
- Strings built from values use placeholders: `'Waitlisted #'.$n` → `__('Waitlisted #:position', ['position' => $n])`.
- Dates shown to members: `->format('l, j M')` → `->translatedFormat('l, j M')`; table columns `->date('D, j M')` → `->date('D, j M')` already localises through Carbon — verify by rendering in `ms`.
- Blade views under `resources/views/filament` and `resources/views/vendor/passkeys`: same rules as Task 8 Step 3. Passkey package keys (`passkeys::passkeys.*`) are not ours; for those with no `ms`/`id` translation, publish overrides: create `lang/vendor/passkeys/ms/passkeys.php` and `lang/vendor/passkeys/id/passkeys.php` copying the keys from `vendor/spatie/laravel-passkeys/resources/lang/en/passkeys.php` with translated values.

- [ ] **Step 5: Translate the new keys**

Run both guards; add every reported key (static and rendered — Filament auto-labels appear in the rendered list, e.g. `Send at`) to `lang/ms.json` and `lang/id.json` per Task 7 Step 5. Re-run until PASS.

- [ ] **Step 6: Run tests and visual check**

Run: `php artisan test` → all pass. Visual: each member page at 375px and 1280px in `ms` and `id` — sidebar labels don't truncate awkwardly, table headers fit, stats widgets don't overflow.

- [ ] **Step 7: Commit**

```bash
git add app/Filament resources/views lang tests/Feature/Localisation
git commit -m "feat(i18n): translate member portal screens

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Translate the portal — admin screens

**Files:**
- Modify: `app/Filament/Resources/**/*.php` (resources, pages, schemas, tables, relation managers), `app/Filament/Imports/UserImporter.php`, remaining `app/Filament/Widgets/*.php` used by admins
- Modify: `lang/ms.json`, `lang/id.json`
- Modify: `tests/Feature/Localisation/RenderedPagesAreTranslatedTest.php` (`pages()`, `seedForRendering()`)
- Test: `tests/Feature/Localisation/AdminPortalLocalisedTest.php`

**Interfaces:**
- Consumes: guards from Task 7; seeding from Task 9.
- Produces: admin URLs appended to `pages()`.

- [ ] **Step 1: Extend the runtime guard with admin pages**

Append to `pages()` every resource's index and create URL (from `php artisan route:list --path=account`), plus one edit/view URL per resource using a seeded record (`/account/lunches/{id}/edit` → build with `route('filament.account.resources.lunches.edit', $lunch)` inside the test after seeding; make `pages()` accept the seeded records or resolve them inside `test_pages_have_no_missing_translations` after `seedForRendering()` — keep a single list). Extend `seedForRendering()` with one record per resource (caterer + meal + meal option, member category, fee period + payment with a proof, auto-email, activity with a registration).

- [ ] **Step 2: Write the admin test**

```php
<?php

namespace Tests\Feature\Localisation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPortalLocalisedTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_navigation_groups_are_translated(): void
    {
        config(['app.locales' => 'en,ms,id']);
        $admin = User::factory()->create(['is_admin' => true, 'locale' => 'id', 'activated_at' => now()]);

        $this->actingAs($admin)->get('/account')
            ->assertOk()
            ->assertSee(__('Welfare & Social', [], 'id'))
            ->assertSee(__('Accounts', [], 'id'));
    }
}
```

- [ ] **Step 3: Run to confirm failure**

Run: `php artisan test --filter="AdminPortalLocalisedTest|RenderedPagesAreTranslatedTest"`
Expected: FAIL.

- [ ] **Step 4: Wrap strings in admin screens**

Same rules as Task 9 Step 4, across every file under `app/Filament/Resources` and `app/Filament/Imports`. Additionally:
- Importer column labels and the import-completed notification body (`getCompletedNotificationBody`) use `__()` with placeholders for counts (`__(':count rows imported.', ['count' => ...])`); use `trans_choice` where Filament's default body pluralises.
- Toasts such as `'Published: members notified'` → `__('Published: members notified')`.
- Placeholder `'-'` for empty values stays as is (not text).
- Do not wrap admin-authored data (record titles, meal names) — only interface text.

- [ ] **Step 5: Translate the new keys**

Run both guards; add every reported key to both JSON files (Task 7 Step 5 rules). Re-run until PASS.

- [ ] **Step 6: Run tests and visual check**

Run: `php artisan test` → all pass. Visual: each admin index and form in `ms` and `id` at 1280px; filters and bulk-action menus readable.

- [ ] **Step 7: Commit**

```bash
git add app/Filament lang tests/Feature/Localisation
git commit -m "feat(i18n): translate admin portal screens

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Translate notifications and emails

**Files:**
- Modify: `app/Notifications/*.php` (all 10), `resources/views/mail/auto-email.blade.php` (interface text only, not the admin-written body), `app/Console/Commands/*` only where they build member-facing text
- Modify: `lang/ms.json`, `lang/id.json`
- Test: `tests/Feature/Localisation/NotificationsLocalisedTest.php`

**Interfaces:**
- Consumes: `User::preferredLocale()` (Task 2).
- Produces: notifications whose `toMail`, `toApn`, `toDatabase`/`toArray` text goes through `__()`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Localisation;

use App\Models\Lunch;
use App\Models\User;
use App\Notifications\LunchPublished;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NotificationsLocalisedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locales' => 'en,ms,id']);
        Carbon::setTestNow('2026-11-06 09:00'); // a Friday
    }

    public function test_one_send_localises_per_recipient_and_restores_locale(): void
    {
        $ms = User::factory()->create(['locale' => 'ms']);
        $id = User::factory()->create(['locale' => 'id']);
        $en = User::factory()->create(['locale' => null]);
        $lunch = Lunch::factory()->create(['date' => now(), 'deadline' => now()->addHours(3)]);

        app()->setLocale('en');
        \Illuminate\Support\Facades\Notification::send([$ms, $id, $en], new LunchPublished($lunch));

        $this->assertSame('en', app()->getLocale(), 'locale leaked out of the send');

        $text = fn (User $u) => json_encode($u->notifications()->first()->data, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Jumaat', $text($ms));
        $this->assertStringContainsString('Jumat', $text($id));
        $this->assertStringContainsString('Friday', $text($en));
    }

    public function test_mail_subject_and_push_follow_the_recipient(): void
    {
        $id = User::factory()->create(['locale' => 'id']);
        $lunch = Lunch::factory()->create(['date' => now(), 'deadline' => now()->addHours(3)]);
        $notification = new LunchPublished($lunch);

        $mail = app()->call(fn () => \Illuminate\Support\Facades\App::withLocale('id', fn () => $notification->toMail($id)));
        $this->assertStringContainsString('Jumat', $mail->subject ?? implode(' ', $mail->introLines));

        $push = \Illuminate\Support\Facades\App::withLocale('id', fn () => $notification->toApn($id));
        $this->assertNotSame('', $push->title);
    }

    public function test_translated_dates(): void
    {
        $this->assertSame('Jumaat', now()->locale('ms')->translatedFormat('l'));
        $this->assertSame('Jumat', now()->locale('id')->translatedFormat('l'));
    }
}
```

> Adjust model/field names to the real `Lunch` factory and the actual date fields (`grep -n "date\|deadline" database/factories/LunchFactory.php`). If `LunchPublished` does not use the `database` channel, assert on `toArray()`/`toDatabase()` via `App::withLocale` instead of reading `$u->notifications()`.

- [ ] **Step 2: Run to confirm failure**

Run: `php artisan test --filter=NotificationsLocalisedTest`
Expected: FAIL — `Friday` rendered for `ms`/`id` recipients (`format()` is not localised).

- [ ] **Step 3: Convert every notification**

For each class in `app/Notifications`:
- `->format(...)` → `->translatedFormat(...)` (same format string).
- Concatenated sentences → placeholder keys. Example (`LunchPublished`):

```php
            ->subject(__('Lunch menu for :date', ['date' => $this->lunch->date->translatedFormat('D, j M')]))
            ->line(__(':menu has been published for :date.', [
                'menu' => $this->lunch->catererMeal?->name ?? __('A menu'),
                'date' => $this->lunch->date->translatedFormat('l, j M'),
            ]))
            ->line(__('Order by :deadline.', ['deadline' => $this->lunch->deadline->translatedFormat('g:i A, D j M')]))
```

- `ActivityNotification::whenLabel()` → `translatedFormat('l, j M · g:i A')`; each subclass's `pushTitle()` returns `__('...')`.
- `WaitlistPromoted`: `__('A spot opened up, so you are now registered for :activity on :when.', [...])` and `__('A spot opened up: :when', [...])`.
- Mail action button labels (`->action('View lunch', $url)`) → `__('View lunch')`.
- `BirthdayWish` / auto-email: only interface text (greeting, sign-off, button) — the admin-authored body is left as written.
- No changes to `via()` — `HasLocalePreference` already makes Laravel render each recipient in their locale.

- [ ] **Step 4: Translate the new keys**

Run: `php artisan test --filter=TranslationCompletenessTest` and add every reported key to both JSON files (Task 7 Step 5 rules). Re-run until PASS.

- [ ] **Step 5: Run tests**

Run: `php artisan test` → all pass (existing notification tests still assert English for users without a locale).

- [ ] **Step 6: Commit**

```bash
git add app/Notifications app/Console resources/views/mail lang tests/Feature/Localisation/NotificationsLocalisedTest.php
git commit -m "feat(i18n): notifications and emails in the recipient's language

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Review sheet and runbook

**Files:**
- Create: `docs/translations/build-review-sheet.py`
- Create (generated): `docs/translations/review.xlsx`
- Modify: `docs/deployment-internal-server.md` (new section after "8. Email (Resend)")

**Interfaces:**
- Consumes: `lang/ms.json`, `lang/id.json`, code locations (re-uses the static scan's regex).

- [ ] **Step 1: Write the sheet builder**

`docs/translations/build-review-sheet.py`:

```python
#!/usr/bin/env python3
"""Build docs/translations/review.xlsx from lang/{ms,id}.json for native review.

Usage (repo root): python3 docs/translations/build-review-sheet.py
Rows: one per app string — where it is used, English, Malay, Indonesian, notes.
Privacy-policy rows are highlighted for legal review. Framework strings that
laravel-lang supplied (keys not found in our code) are listed on a second sheet.
"""
import json, re, pathlib
from openpyxl import Workbook
from openpyxl.styles import Alignment, Font, PatternFill

ROOT = pathlib.Path(__file__).resolve().parents[2]
PATTERN = re.compile(r"""(?:__|trans|@lang)\(\s*(['"])((?:\\.|(?!\1).)*)\1""", re.S)

def app_key(k):
    return "::" not in k and not re.fullmatch(r"[a-z_]+\.[a-z0-9_.]+", k)

uses = {}
for path in [*ROOT.glob("app/**/*.php"), *ROOT.glob("resources/views/**/*.php")]:
    src = path.read_text(encoding="utf-8")
    for m in PATTERN.finditer(src):
        key = bytes(m.group(2), "utf-8").decode("unicode_escape").encode("latin-1").decode("utf-8")
        if app_key(key):
            line = src.count("\n", 0, m.start()) + 1
            uses.setdefault(key, []).append(f"{path.relative_to(ROOT)}:{line}")

ms = json.loads((ROOT / "lang/ms.json").read_text(encoding="utf-8"))
idn = json.loads((ROOT / "lang/id.json").read_text(encoding="utf-8"))

wb = Workbook()
ws = wb.active
ws.title = "App strings"
ws.append(["Where used", "English", "Bahasa Melayu", "Bahasa Indonesia", "Reviewer notes"])
legal = PatternFill("solid", fgColor="FFF2CC")
for key in sorted(uses, key=lambda k: (uses[k][0], k)):
    ws.append(["\n".join(uses[key]), key, ms.get(key, ""), idn.get(key, ""), ""])
    if any("privacy.blade.php" in u for u in uses[key]):
        for cell in ws[ws.max_row]:
            cell.fill = legal
        ws.cell(ws.max_row, 5, "LEGAL: privacy policy — needs accountable review")

fw = wb.create_sheet("Framework strings")
fw.append(["English", "Bahasa Melayu", "Bahasa Indonesia"])
for key in sorted(set(ms) | set(idn)):
    if key not in uses:
        fw.append([key, ms.get(key, ""), idn.get(key, "")])

for sheet, widths in ((ws, [38, 48, 48, 48, 30]), (fw, [48, 48, 48])):
    sheet.freeze_panes = "A2"
    for i, w in enumerate(widths):
        sheet.column_dimensions[chr(65 + i)].width = w
    for cell in sheet[1]:
        cell.font = Font(bold=True)
    for row in sheet.iter_rows(min_row=2):
        for cell in row:
            cell.alignment = Alignment(wrap_text=True, vertical="top")

out = ROOT / "docs/translations/review.xlsx"
out.parent.mkdir(parents=True, exist_ok=True)
wb.save(out)
print(f"wrote {out.relative_to(ROOT)}: {ws.max_row - 1} app strings, {fw.max_row - 1} framework strings")
```

- [ ] **Step 2: Build and inspect the sheet**

Run: `python3 -m pip show openpyxl >/dev/null || python3 -m pip install --user openpyxl` then `python3 docs/translations/build-review-sheet.py`
Expected: `wrote docs/translations/review.xlsx: N app strings, M framework strings`, with N equal to the number of keys the static guard scans. Open it and confirm: privacy rows are yellow with the LEGAL note; no row has an empty Malay or Indonesian cell.

- [ ] **Step 3: Document enabling a language**

Add to `docs/deployment-internal-server.md`, after section 8:

````markdown
## 9. Languages (English, Bahasa Melayu, Bahasa Indonesia)

The web pages and portal support `en`, `ms` and `id`. Which are offered is set by
`APP_LOCALES`; it defaults to `en`, which hides the chooser and switchers entirely.

Enable a language only after its strings are reviewed (`docs/translations/review.xlsx`,
regenerated with `python3 docs/translations/build-review-sheet.py`):

```dotenv
APP_LOCALES=en,ms,id
```

then `php artisan optimize` (config is cached). One language can be enabled first
(`en,ms`). Removing a language later is safe: people who chose it see English and are
asked again.

A member's choice is stored on their account (`users.locale`) and in a `locale` cookie
mirrored to `localStorage`. Emails, push and in-app notifications use the account's
language, so the iOS app shows notification text in it while its own screens stay English.
````

Renumber the following "Open items before go-live" section to 10.

- [ ] **Step 4: Run the whole suite**

Run: `php artisan test` → all pass.

- [ ] **Step 5: Commit**

```bash
git add docs/translations docs/deployment-internal-server.md
git commit -m "docs(i18n): translation review sheet and enabling-a-language runbook

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Done when

- `php artisan test` passes with `APP_LOCALES` unset (English only, nothing visible changes) **and** with `APP_LOCALES=en,ms,id` exported in the shell.
- Both translation guards pass; `review.xlsx` is generated and has no empty cells.
- The five Review Focus items each have a passing test or a completed browser check.
- The branch is reviewed before it reaches `main` (which auto-deploys). Production keeps `APP_LOCALES=en` until the native review is applied.

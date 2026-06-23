<!--
SPIKE FINDINGS — spatie/laravel-passkeys v1.8.1 (web-auth/webauthn-lib ^5.3)
Task 5.1 of the Account Onboarding plan. These findings drive Tasks 5.2 and 5.3.
Everything below was read from the installed package under
vendor/spatie/laravel-passkeys/ — not from external docs.
-->

# Passkeys SPIKE — spatie/laravel-passkeys v1.8.1

Installed via `composer require spatie/laravel-passkeys` (resolved to **1.8.1**).
Pulls in `web-auth/webauthn-lib ^5.3`. The package's PHP constraint is
`^8.2|^8.3|^8.4`; this app runs **PHP 8.5** — Composer resolved it without a
platform error, but 8.5 is not formally declared by the package (minor risk).

## (a) Register / generate a passkey for the logged-in user — LIVEWIRE

- It is a **Livewire component**, registered by the package's service provider as
  `Livewire::component('passkeys', PasskeysComponent::class)`.
- Tag: `<livewire:passkeys />`. **No props required.** It reads the current user
  via `auth()->user()` and lists `->passkeys`. It must be rendered on a page
  where the user is already authenticated.
- Class: `Spatie\LaravelPasskeys\Livewire\PasskeysComponent`.
- Flow: the Blade view (`passkeys::livewire.passkeys`) renders a name input +
  "Create" button (`wire:submit="validatePasskeyProperties"`). On validation it
  dispatches a browser event `passkeyPropertiesValidated`; the inline
  `createScript` partial calls the JS `startRegistration({ optionsJSON })`, then
  `@this.call('storePasskey', JSON.stringify(passkey))`. Deletion is
  `wire:click="deletePasskey(id)"`.
- **No route needed for registration** — it is entirely Livewire round-trips.
- The default Blade view is plain Tailwind (gray-100, indigo, etc.), NOT styled
  for Filament. Task 5.2 will likely publish/override
  `resources/views/vendor/passkeys/livewire/passkeys.blade.php` to match the
  panel, or wrap `<livewire:passkeys />` inside a Filament page.

## (b) "Log in with a passkey" on the login page — BLADE component

- It is a **Blade component**, registered as
  `Blade::component('authenticate-passkey', AuthenticatePasskeyComponent::class)`.
- Tag: `<x-authenticate-passkey />`. Optional prop `:redirect="..."` (a URL) — if
  given, it is stored in session as `passkeys.redirect` and used after a
  successful login instead of the config default. The component accepts a slot
  for custom button content; with no slot it renders an underlined
  "Authenticate using Passkey" link.
- Class: `Spatie\LaravelPasskeys\Http\Components\AuthenticatePasskeyComponent`.
- It renders a hidden `<form id="passkey-login-form" method="POST"
  action="{{ route('passkeys.login') }}">` and an inline `authenticateScript`
  that, on click, fetches `route('passkeys.authentication_options')` (GET JSON),
  calls JS `startAuthentication({ optionsJSON })`, stuffs the result +
  `remember` into the form, and submits.

### Routes — NOT auto-registered. Host app MUST opt in.

The service provider only defines a **route macro**. Nothing is wired until the
app calls it. Task 5.3 must add to `routes/web.php`:

```php
Route::passkeys();
```

That registers (default prefix `passkeys`):
- `GET  /passkeys/authentication-options` → name `passkeys.authentication_options`
  (`GeneratePasskeyAuthenticationOptionsController`) — returns options JSON +
  stores `passkey-authentication-options` in session.
- `POST /passkeys/authenticate`           → name `passkeys.login`
  (`AuthenticateUsingPasskeyController`).

### How it logs the user in

`AuthenticateUsingPasskeyController::__invoke()`:
1. Pulls `passkey-authentication-options` from session (set by the GET above).
2. `FindPasskeyToAuthenticateAction` matches the WebAuthn assertion to a
   `Passkey` row.
3. Resolves `$passkey->authenticatable` (the User via the belongsTo).
4. Calls **`auth()->login($authenticatable, $remember)`** then
   `Session::regenerate()` — i.e. the **default `web` guard**.
5. Fires `PasskeyUsedToAuthenticateEvent`.
6. Redirects to `Session('passkeys.redirect')` if set, else
   `redirect()->intended(config('passkeys.redirect_to_after_login'))`.

## (c) Coexistence with default Filament/eloquent auth — NO auth.php change

- Login uses `auth()->login($user)` on the standard guard — it does **NOT**
  require a custom user provider, no `eloquent-webauthn`, no `password_fallback`.
  Password login (Filament panel) and passkey login coexist out of the box on
  the default `web` guard + `eloquent` provider.
- **Task 5.3 does NOT need to change `config/auth.php`.** The only wiring is
  adding `Route::passkeys();` to `routes/web.php` and dropping
  `<x-authenticate-passkey />` on the login view.
- Caveat: this app logs in via **Filament's panel login**, not Fortify. Filament
  uses the same `web` guard, so the post-login `auth()->login()` is compatible.
  But the package's `<x-authenticate-passkey>` button posts to its own
  `passkeys.login` route (it bypasses the Filament login form). Task 5.3 needs to
  render that Blade component on Filament's login page — likely via a custom
  login page class or a render hook (`FORMS_LOGIN_FORM_AFTER` /
  `PANELS_AUTH_LOGIN_FORM_AFTER`) — since Filament's login is Livewire, not a
  plain Blade form. Verify the exact hook in Task 5.3.

## (d) `passkeys` table + relation

Migration `..._create_passkeys_table.php` (published; runs clean on Postgres):

| column              | type (pgsql)  | notes                                  |
|---------------------|---------------|----------------------------------------|
| id                  | bigint PK     |                                        |
| authenticatable_id  | bigint FK     | `foreignIdFor(User,'authenticatable_id')` → `users.id`, cascade on delete. **Direct FK, NOT a polymorphic morph.** Index `passkeys_authenticatable_fk`. |
| name                | text          | user-given label                       |
| credential_id       | text          | base64 on pgsql (see below)            |
| data                | json          | serialized `PublicKeyCredentialSource` |
| last_used_at        | timestamp NULL|                                        |
| created_at/updated_at | timestamp NULL |                                      |

- **Relation on the User trait: `passkeys()`** — `hasMany(Passkey, 'authenticatable_id')`
  (from `InteractsWithPasskeys`). Inverse: `Passkey::authenticatable()`
  `belongsTo(User)`.
- Contract methods the trait provides (used to build credentials):
  `getPasskeyName()` → `$user->email`, `getPasskeyId()` → `$user->id`,
  `getPasskeyDisplayName()` → `$user->name`.
- **Package quirk (not blocking):** the `HasPasskeys` interface declares
  `getPassKeyName/Id/DisplayName` (capital K) while the trait implements
  lowercase `getPasskey...`. PHP method names are case-insensitive so the trait
  still satisfies the interface — no action needed.

### Postgres notes (this app's prod is Supabase Postgres)

- The package is Postgres-aware: `Passkey::encodeCredentialId()` **base64-encodes
  binary credential ids on the `pgsql` driver** (avoids invalid UTF-8 in the
  `text` column). Verified the migration + schema on the default pgsql connection.
- `data` is a real `json` column (good — matches our Filament-notifications
  lesson about JSON operators on Postgres).

## (e) JS / asset requirement — host app MUST supply WebAuthn browser lib

- The package ships **no JS bundle and publishes none**. Its inline scripts call
  two globals: **`startRegistration`** (register flow) and
  **`startAuthentication`** (login flow). These are the API of
  **`@simplewebauthn/browser`**.
- Tasks 5.2/5.3 must install `@simplewebauthn/browser` (npm) and expose
  `startRegistration` / `startAuthentication` as **globals** (e.g. assign to
  `window` in `resources/js/app.js`, then `npm run build`), so the package's
  inline `<script>` partials can call them. Without this, both ceremonies throw
  "startRegistration/startAuthentication is not defined" in the browser.
- Reminder: WebAuthn requires a **secure context** — `localhost` is treated as
  secure by browsers, so dev works over `http://localhost:8000`. `relying_party.id`
  resolves to `localhost` here (host-only, from `parse_url(APP_URL)`).

## Config choices made in Task 5.1

- `config/passkeys.php`:
  - `redirect_to_after_login` → **`/account`** (PENTANA's dashboard; package
    default was `/dashboard`).
  - `relying_party.id` → left as the default `parse_url(config('app.url'),
    PHP_URL_HOST)`, which resolves to **`localhost`** in dev (correct: host only,
    no scheme/port). In prod it will resolve to the deployed host automatically.
  - `relying_party.name` → default `config('app.name')` = `PENTANA`.
- `app/Models/User.php`: added `implements ... HasPasskeys` and
  `use InteractsWithPasskeys;` alongside the existing `FilamentUser` /
  `HasFactory, Notifiable`.

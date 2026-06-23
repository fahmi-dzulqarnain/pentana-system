# Account Onboarding — Bulk Import + Self-Claim + Passkeys

**Status:** Approved 2026-06-23 (passkey package revised to spatie/laravel-passkeys after laragear/webauthn was found archived)
**Builds on:** existing `users` / `member_categories` (categories are named by grade, e.g. "Grade 7-10", and drive the fee) and the shared `account` Filament panel.

## Goal

Let an admin **bulk-import** pre-registered members (email, name, grade) as inactive accounts, let each member **self-claim** their account from a public Wizard page (confirm details → set birthday + password), then secure it with a **passkey (WebAuthn)** that also works as a first-class sign-in method alongside passwords.

## Decisions (locked)

- **Combined spec** for all three subsystems (import + claim + passkeys); built in phases within one plan.
- **Grade = member category.** The import's `grade` column maps to an existing `member_categories` row by name; the category sets the fee. No new "grade" field.
- **Unclaimed accounts are real `users` rows** with `password = NULL` and `activated_at = NULL`. Claiming fills them in. (Password column becomes nullable.)
- **Open email-lookup claim** — no email-ownership verification yet (SMTP is on hold). Revisit when SMTP lands.
- **Filament `ImportAction`, forced synchronous** (no queue worker exists).
- **Passkey package: `spatie/laravel-passkeys`** (stable v1). `laragear/webauthn` was archived in May 2026 and is avoided.
- **Passkey is optional but encouraged, and set up *after* activation while logged in** (Spatie registers passkeys for the authenticated user, so it can't sit inside the pre-auth Wizard): a prominent "Add a passkey" prompt right after claim + an account-settings entry point. Password is always set. The login page offers a passkey sign-in button + password fallback.

## Data model

### `users` migration

| change | detail |
|---|---|
| `password` | make **nullable** (`->nullable()->change()`) — unclaimed accounts have none |
| `birthday` | add `date('birthday')->nullable()` — set at claim |
| `activated_at` | add `timestamp('activated_at')->nullable()` — **NULL = imported/unclaimed**; set when claimed |

`member_category_id`, `is_admin`, `custom_fee` already exist.

> **Postgres note:** dropping `NOT NULL` on `password` via Laravel 12 native `change()` must be verified against Supabase; apply with the rest of the migrations.

### `User` model
- Add `birthday` (cast `date`), `activated_at` (cast `datetime`) to `$fillable` / casts; `password` already hashed via existing cast.
- `isActivated(): bool` → `$this->activated_at !== null`.
- Implements `Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys`, uses `Spatie\LaravelPasskeys\Models\Concerns\InteractsWithPasskeys`.

### `passkeys` table
Created by Spatie's published migration (`--tag=passkeys-migrations`); stores each user's passkey credentials.

## Subsystem 1 — Bulk import (admin)

A Filament **`ImportAction`** on `UserResource` (admin-only) backed by a `UserImporter` (`app/Filament/Imports/UserImporter.php`):

- **Columns:** `email` (required, email), `name` (required), `grade` (required).
- **grade → category:** resolve `member_categories` by `name`, **case-insensitive / trimmed**. No match → the row is rejected into the **failed-rows report** with a clear message ("Unknown grade '…'"). Categories are **never auto-created** (they carry a fee).
- **Row create:** new `users` with `name`, `email`, `member_category_id`, `is_admin = false`, `password = NULL`, `activated_at = NULL`.
- **Existing email → skipped** (do not overwrite — protects already-claimed accounts; makes re-import idempotent). Reported as skipped.
- **Synchronous execution:** override the importer's job connection to `sync` (e.g. `public function getJobConnection(): ?string { return 'sync'; }`) so it runs inline without a worker. Chunk size kept reasonable for org-scale files.
- **Infrastructure:** publish Filament's import migrations (`imports`, `failed_import_rows`) and run them (incl. Supabase).
- **Result:** Filament's standard notification ("N imported, M failed, K skipped") plus a downloadable failed-rows CSV. Expected header: `email,name,grade`.

## Subsystem 2 — Self-claim Wizard (public)

A **guest-accessible full-page Livewire component** at route `/claim` (top-level, outside the panel's `/account` prefix to avoid route conflicts), using Filament's Wizard schema + Filament styling. A **"Claim your account"** link is added to the login page.

**Email lookup** (first interaction): member enters their email → on submit:
- **Not found** → inline message: "We couldn't find that email — please check with your admin." (retry allowed)
- **Found but `activated_at` set** → "This account is already active — please log in." (link to login)
- **Found + unclaimed** → reveal the Wizard, pre-filled.

**Wizard steps** (`Filament\Schemas\Components\Wizard`):
1. **Confirm your details** — `name` and `grade` (category name) shown read-only ("Is this you?"); set **birthday** (required `DatePicker`, `->native(false)`).
2. **Set a password** — password + confirmation, validated with `Password::defaults()`.

**Finish:** in a transaction — set `birthday`, hashed `password`, stamp `activated_at = now()`. Guard against double-claim (re-check `activated_at` is still null at submit). Then **auto-login** the member and redirect to the dashboard, where a prominent **"Add a passkey for faster sign-in"** card is shown (Subsystem 3). The card is dismissible and the option also lives in account settings — so passkeys stay optional/encouraged.

## Subsystem 3 — Passkeys (WebAuthn) via `spatie/laravel-passkeys`

- **Install:** `composer require spatie/laravel-passkeys`; `php artisan vendor:publish --tag="passkeys-migrations"` then `migrate`; `--tag="passkeys-config"` for config; `--tag="passkeys-views"` to publish + style the components. Add the contract + trait to `User`.
- **Config (`config/passkeys.php`):** `relying_party.name` (defaults to `config('app.name')`), `relying_party.id` (parsed from `config('app.url')`), `models.authenticatable` (`User`), `models.passkey`, `redirect_to_after_login` → set to the dashboard. Confirm `relying_party.id` resolves correctly for localhost and the prod domain.
- **Secure context:** WebAuthn requires HTTPS **except on `localhost`/`127.0.0.1`** (dev is fine). Production needs HTTPS; the relying-party ID derives from `app.url`. Flagged for deployment.
- **Registration:** Spatie ships a **Livewire component that generates a passkey for the currently authenticated user** (and lists existing passkeys). Embed it on the post-claim "Add a passkey" page/card and in account settings, styled to match. (Exact component tag is confirmed from the published config/views during implementation — the package is the source of truth.)
- **Authentication / login (passkey-first):** Spatie ships a **Blade/Livewire "Log in with a passkey" component** for the login page; it runs the assertion ceremony and logs the user in, honoring `redirect_to_after_login`. Embed it on a **custom Filament login page** alongside the existing password form.
- **Implementation seam & uncertainty:** the custom Filament login page + embedding Spatie's components is the highest-uncertainty part. The plan's passkey phase **starts by installing the package and confirming the exact component tags / config from the installed source**, then integrates and verifies the ceremony on localhost before finalizing the UI.

## Login changes
- Custom Filament login page that keeps the password form and adds Spatie's "Log in with a passkey" component.
- Add a "Claim your account" link → `/claim`.
- **Gating:** a user with `password = NULL` / `activated_at = NULL` cannot authenticate by password (Hash check against null fails); add an explicit, friendly check that points unactivated users to the claim page rather than a generic "invalid credentials".

## Access & security notes
- Import is **admin-scoped**; the passkey-management settings page is **owner-scoped** (logged-in user manages their own passkeys); the claim page and login are **public**.
- **Open claim disclosure:** the page reveals a member's name + grade on an exact email match, and the first person to reach the page for a given email can claim it. Accepted for a controlled internal rollout; the mitigation (email-verification step) is deferred until SMTP is configured.
- Unknown grades are rejected, never auto-created, so the import can't silently create fee-bearing categories.

## Testing (TDD)
- **Importer:** maps grade→category (case-insensitive); creates an unclaimed user (`password` null, `activated_at` null, category set, `is_admin` false); unknown grade → failed row; existing email → skipped (not overwritten); runs on the `sync` connection.
- **Claim lookup:** not-found / already-activated / found-unclaimed branches each behave correctly.
- **Claim completion:** sets `birthday` + hashed `password` + `activated_at`; auto-login works; a second claim attempt on an already-activated account is refused.
- **Login gating:** an unclaimed user cannot authenticate by password; an activated user can.
- **Passkeys:** the `passkeys` relation/trait works on `User`; the post-claim prompt + settings page render for an authenticated user; the login page renders Spatie's component. The full WebAuthn ceremony (which needs a browser/authenticator) is **smoke-verified on localhost**, not unit-tested.

## Files (indicative)
**Create:** `database/migrations/*_add_onboarding_fields_to_users` (nullable password, birthday, activated_at); Filament import migrations (`imports`, `failed_import_rows`); Spatie `passkeys` migration; `app/Filament/Imports/UserImporter.php`; `app/Livewire/ClaimAccount.php` + Blade view; a post-claim "add a passkey" page + an account-settings passkeys page (or section); a custom Filament login page class + Blade; tests under `tests/Feature/Onboarding/`.
**Modify:** `app/Models/User.php`; `app/Filament/Resources/Users/UserResource.php` (ImportAction); `app/Providers/Filament/AccountPanelProvider.php` (custom login page); `routes/web.php` (claim route); the login Blade (passkey component + claim link); `config/passkeys.php`.

## Build order (one plan, phased)
1. **Data model** — users migration (nullable password, birthday, activated_at) + `User` (`isActivated`, casts).
2. **Bulk import** — `UserImporter` (sync, grade mapping, skip/fail rules) + `ImportAction` on Users + import tables.
3. **Claim Wizard** — public `/claim` route + email lookup + Wizard (birthday + password) + activation/auto-login.
4. **Login gating** — unactivated guard + "Claim your account" link.
5. **Passkeys** — install + configure `spatie/laravel-passkeys` (confirm component API from the installed package first) + `passkeys` table + `User` trait + post-claim "add a passkey" prompt + account-settings page + custom login page with the passkey sign-in component.
6. **Verify** — full suite, migrate Supabase, smoke (import a CSV, claim an account, register + use a passkey on localhost), finalize.

## Out of scope (future)
Email-ownership verification at claim (needs SMTP); admin-side resend/reset of a member's claim; per-member claim tokens; bulk *update* via re-import (current re-import only skips existing); social/SSO login. Passkey UX is built now; production passkeys require HTTPS (a hosting/config step, not code).

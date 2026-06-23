# Account Onboarding — Bulk Import + Self-Claim + Passkeys

**Status:** Approved 2026-06-23
**Builds on:** existing `users` / `member_categories` (categories are named by grade, e.g. "Grade 7-10", and drive the fee) and the shared `account` Filament panel.

## Goal

Let an admin **bulk-import** pre-registered members (email, name, grade) as inactive accounts, let each member **self-claim** their account from a public Wizard page (confirm details → set birthday + password → optionally add a passkey), and support **passkey (WebAuthn) sign-in** as a first-class method alongside passwords.

## Decisions (locked)

- **Combined spec** for all three subsystems (import + claim + passkeys); built in phases within one plan.
- **Grade = member category.** The import's `grade` column maps to an existing `member_categories` row by name; the category sets the fee. No new "grade" field.
- **Unclaimed accounts are real `users` rows** with `password = NULL` and `activated_at = NULL`. Claiming fills them in. (Password column becomes nullable.)
- **Open email-lookup claim** — no email-ownership verification yet (SMTP is on hold). Revisit when SMTP lands.
- **Filament `ImportAction`, forced synchronous** (no queue worker exists).
- **Passkey at claim is optional but encouraged**; password is always set. Login offers a passkey button + password fallback.
- **Passkey package: `laragear/webauthn`.**

## Data model

### `users` migration

| change | detail |
|---|---|
| `password` | make **nullable** (`->nullable()->change()`) — unclaimed accounts have none |
| `birthday` | add `date('birthday')->nullable()` — set at claim |
| `activated_at` | add `timestamp('activated_at')->nullable()` — **NULL = imported/unclaimed**; set when claimed |

`member_category_id`, `is_admin`, `custom_fee` already exist.

> **Postgres note:** `->change()` to drop NOT NULL on `password` must be verified against Supabase (Laravel 12 native `change()`); apply with the rest of the migrations.

### `User` model
- Add `birthday` (cast `date`), `activated_at` (cast `datetime`) to `$fillable` / casts; `birthday`/`activated_at`/`password` already hashed via existing cast.
- `isActivated(): bool` → `$this->activated_at !== null`.
- Implements `Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable`, uses `Laragear\WebAuthn\WebAuthnAuthentication` trait.

### `webauthn_credentials`
Created by laragear's published migration (stores each user's passkey credentials).

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

A **guest-accessible full-page Livewire component** at route `/claim`, using Filament's Wizard schema + Filament styling (registered in `routes/web.php`, outside the panel's auth middleware). A **"Claim your account"** link is added to the login page.

**Email lookup** (first interaction): member enters their email → on submit:
- **Not found** → inline message: "We couldn't find that email — please check with your admin." (retry allowed)
- **Found but `activated_at` set** → "This account is already active — please log in." (link to login)
- **Found + unclaimed** → reveal the Wizard, pre-filled.

**Wizard steps** (`Filament\Schemas\Components\Wizard`):
1. **Confirm your details** — `name` and `grade` (category name) shown read-only ("Is this you?"); set **birthday** (required `DatePicker`, `->native(false)`).
2. **Set a password** — password + confirmation, validated with `Password::defaults()`.
3. **Add a passkey (optional)** — a "Create a passkey" button runs the WebAuthn registration ceremony (Subsystem 3); the member may complete it or **Skip**. Shows a success state when a credential is registered.

**Finish:** in a transaction — set `birthday`, hashed `password`, stamp `activated_at = now()`; the passkey (if created) is already persisted. Then **auto-login** the member and redirect to the dashboard with a welcome notification. Guard against double-claim (re-check `activated_at` is still null at submit).

## Subsystem 3 — Passkeys (WebAuthn) via `laragear/webauthn`

- **Install:** `composer require laragear/webauthn`; publish + run its migration; add the contract + trait to `User`.
- **Secure context:** WebAuthn requires HTTPS **except on `localhost`/`127.0.0.1`** (dev is fine). Production needs HTTPS; the relying-party ID derives from the app domain/config. Flagged for deployment.
- **Registration ceremony** (claim step 3 *and* a later account-settings entry point): a button triggers attestation — server issues options via laragear's `Attestation` flow, browser `navigator.credentials.create()` (small JS), server validates and stores the credential against the user.
- **Assertion / login (passkey-first):** a **"Sign in with a passkey"** button on the login page issues assertion options, runs `navigator.credentials.get()`, the server validates via laragear and logs the user into the `account` panel (`Filament::auth()->login($user)` / `auth()->login()`). The password form remains as fallback.
- **Implementation seam:** this requires a **custom login page** (extending Filament's) plus a small JS bundle for the WebAuthn calls and laragear's routes/controllers. This is the most custom and highest-uncertainty part; the plan isolates it as the final phase and verifies the full ceremony on localhost before wiring the UI.

## Login changes
- Add the "Sign in with a passkey" button + keep the password form.
- Add a "Claim your account" link → `/claim`.
- **Gating:** a user with `password = NULL` / `activated_at = NULL` cannot authenticate by password (Hash check against null fails); add an explicit, friendly check that points unactivated users to the claim page rather than a generic "invalid credentials".

## Access & security notes
- Import + the future passkey-management settings page are **admin / owner-scoped** appropriately; the claim page and login are **public**.
- **Open claim disclosure:** the page reveals a member's name + grade on an exact email match, and the first person to reach the page for a given email can claim it. Accepted for a controlled internal rollout; the mitigation path (email-verification step in the Wizard) is deferred until SMTP is configured.
- Unknown grades are rejected, never auto-created, so the import can't silently create fee-bearing categories.

## Testing (TDD)
- **Importer:** maps grade→category (case-insensitive); creates an unclaimed user (`password` null, `activated_at` null, category set, `is_admin` false); unknown grade → failed row; existing email → skipped (not overwritten); runs on the `sync` connection.
- **Claim lookup:** not-found / already-activated / found-unclaimed branches each behave correctly.
- **Claim completion:** sets `birthday` + hashed `password` + `activated_at`; auto-login works; a second claim attempt on an already-activated account is refused.
- **Login gating:** an unclaimed user cannot authenticate by password; an activated user can.
- **Passkeys:** credential registration persists a credential for the user; passkey assertion authenticates — exercised with laragear's testing/fake helpers (no physical authenticator needed). The browser ceremony is smoke-verified on localhost.

## Files (indicative)
**Create:** `database/migrations/*_add_onboarding_fields_to_users` (nullable password, birthday, activated_at); Filament import migrations (`imports`, `failed_import_rows`); laragear `webauthn_credentials` migration; `app/Filament/Imports/UserImporter.php`; `app/Livewire/ClaimAccount.php` + its Blade view; a custom login page class + Blade; WebAuthn registration/assertion glue (controller(s)/Livewire actions + JS); tests under `tests/Feature/Onboarding/`.
**Modify:** `app/Models/User.php`; `app/Filament/Resources/Users/UserResource.php` (ImportAction); `app/Providers/Filament/AccountPanelProvider.php` (custom login page); `routes/web.php` (claim route + WebAuthn routes); the login Blade (passkey button + claim link).

## Build order (one plan, phased)
1. **Data model** — users migration (nullable password, birthday, activated_at) + `User` (`isActivated`, casts).
2. **Bulk import** — `UserImporter` (sync, grade mapping, skip/fail rules) + `ImportAction` on Users + import tables.
3. **Claim Wizard** — public route + email lookup + Wizard (birthday + password) + activation/auto-login.
4. **Login gating** — unactivated guard + "Claim your account" link.
5. **Passkeys** — package install + `webauthn_credentials` + registration (claim step 3 + settings) + custom login page with the passkey button.
6. **Verify** — full suite, migrate Supabase, smoke (import a CSV, claim an account, register+use a passkey on localhost), finalize.

## Out of scope (future)
Email-ownership verification at claim (needs SMTP); admin-side resend/reset of a member's claim; per-member claim tokens; bulk *update* via re-import (current re-import only skips existing); social/SSO login. Passkey UX is built now; production passkeys require HTTPS (a hosting/config step, not code).

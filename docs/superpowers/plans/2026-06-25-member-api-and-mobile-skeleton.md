# Member API + Mobile Walking Skeleton — Implementation Plan

> REQUIRED SUB-SKILL: superpowers:subagent-driven-development (executed inline this session). Steps use checkbox syntax.

**Goal:** Sanctum-secured `/api/v1` for the Bills slice (login + bills + proof submit), then a KMP + SwiftUI walking skeleton consuming it.

**Two phases:**
- **Phase 1 — API (`pentana-system`, this repo):** built + tested here (TDD).
- **Phase 2 — `pentana-mobile` (new repo):** KMP shared module + SwiftUI app, authored here, compiled by the user.

**Conventions:** API Resources (`{data}`); Form Requests (422); `auth:sanctum` + per-user scoping on every data route; integer/decimal casts already on the finance models; no queue worker; additive migrations only.

---

## Phase 1 — Laravel API

### API-1: Sanctum + auth endpoints
**Files:** `php artisan install:api` (adds `routes/api.php`, wires `api:` in `bootstrap/app.php`, creates the `personal_access_tokens` migration) · modify `app/Models/User.php` (`use HasApiTokens`) · `app/Http/Controllers/Api/V1/{AuthController,MeController}.php` · `app/Http/Requests/Api/LoginRequest.php` · `app/Http/Resources/Api/UserResource.php` · `routes/api.php` · Test: `tests/Feature/Api/AuthTest.php`

- [ ] `php artisan install:api` (do NOT auto-migrate prod; migration runs in API-4 / locally via sqlite for tests).
- [ ] `User` adds `Laravel\Sanctum\HasApiTokens`.
- [ ] `routes/api.php`: `v1` prefix; `POST login` (throttle:5,1), `POST logout` + `GET me` behind `auth:sanctum`.
- [ ] `AuthController@login`: validate email/password/device_name; `Auth::attempt`-style check (reject when no password / not `activated_at`); on success `createToken(device_name)` → `{ token, user: UserResource }`; else 401.
- [ ] `AuthController@logout`: `$request->user()->currentAccessToken()->delete()`.
- [ ] `MeController@show`: `UserResource` (id, name, email, member_category name, birthday, credit).
- [ ] **Tests:** login ok returns token; bad password → 401; unactivated/passwordless → 401; throttle after 5; `me`/`logout` need a token (401 without); `me` returns the authed user only.
- [ ] Pint; full suite green. Commit.

### API-2: Bills read endpoints
**Files:** `app/Http/Controllers/Api/V1/BillController.php` · `app/Http/Resources/Api/BillResource.php` · `routes/api.php` · Test: `tests/Feature/Api/BillsTest.php`

- [ ] `GET /v1/bills` → `FeePeriod::forMember($request->user()->id)->get()` → `BillResource::collection`. Resource fields: `id`, `month` (`month_date->format('Y-m')`), `amount_due`, `amount_paid` (`allocations_sum_amount_allocated ?? 0`), `outstanding` (`max(0, due − paid)`), `status`.
- [ ] `GET /v1/bills/summary` → `{ total_outstanding, available_credit (user->credit), unpaid_count, bill_count }`.
- [ ] **Tests:** 401 without token; only the caller's bills returned (seed two members, assert no cross-leak); `outstanding`/`amount_paid` math with a partial allocation; summary totals.
- [ ] Pint; full suite. Commit.

### API-3: Payment proofs (list + submit)
**Files:** `app/Http/Controllers/Api/V1/PaymentProofController.php` · `app/Http/Requests/Api/StorePaymentProofRequest.php` · `app/Http/Resources/Api/PaymentProofResource.php` · `routes/api.php` · Test: `tests/Feature/Api/PaymentProofsTest.php`

- [ ] `GET /v1/payment-proofs` → caller's proofs newest-first → resource (`id, amount_claimed, amount_verified, status, member_note, admin_note, proof_image_url` via `Storage::url`, `submitted_at`).
- [ ] `StorePaymentProofRequest`: `proof_image` `required|image|mimes:jpg,jpeg,png,webp|max:5120`, `amount_claimed` `required|numeric|gt:0`, `member_note` `nullable|string|max:1000`.
- [ ] `POST /v1/payment-proofs`: store image to `payment-proofs` disk `public`; create `PaymentProof{ user_id: auth, status: 'pending', amount_claimed, member_note, proof_image_path }`; `201 { data }`.
- [ ] **Tests:** (`Storage::fake('public')`) upload creates a pending row owned by the caller + persists the file + returns a url; validation rejects non-image / missing amount / amount ≤ 0 (422); list is caller-scoped; 401 without token.
- [ ] Pint; full suite. Commit.

### API-4: Verify + migrate + finalize
- [ ] Full `php artisan test` green; `vendor/bin/pint --dirty`.
- [ ] Confirm with the user, then `php artisan migrate` on Supabase (only `personal_access_tokens`, additive); verify with `migrate:status`.
- [ ] Smoke with curl: login → capture token → `me` / `bills` / `bills/summary` / `payment-proofs` (Bearer).
- [ ] Commit. (API is now consumable by the mobile app.)

---

## Phase 2 — `pentana-mobile` (authored; user compiles)

### MOB-1: Repo + KMP shared module
- [ ] Create private repo `pentana-mobile` (confirm with user first) at `/Users/fahmi/Silentmode/pentana-mobile`.
- [ ] KMP Gradle scaffold: `shared` (commonMain/iosMain/androidMain), targets `androidTarget` + `iosArm64` + `iosSimulatorArm64` (static framework `Shared`), `iosApp` placeholder. Plugins: kotlin-multiplatform, kotlinx-serialization, SKIE.
- [ ] `shared/commonMain`: Ktor `HttpClient` (CIO/Darwin engines per target) + bearer-auth plugin reading `TokenStore`; configurable `baseUrl`; `kotlinx.serialization` DTOs (`User`, `Bill`, `BillsSummary`, `PaymentProof`, `LoginResponse`); `ApiResult`; `AuthRepository`, `BillsRepository` (`suspend`).
- [ ] `TokenStore` `expect`; `iosMain` `actual` → Keychain.
- [ ] `shared/commonTest`: repository tests against Ktor `MockEngine` (these I can reason about; the user runs `./gradlew shared:allTests`).
- [ ] `README.md`: prerequisites (JDK, Android Studio, Xcode, CocoaPods), how to build the framework, where to set `baseUrl`.

### MOB-2: SwiftUI iOS app
- [ ] `iosApp`: `App` entry + `NavigationStack` router. Views: `LoginView`, `BillsView` (summary header + list), `SubmitProofView` (`PhotosPicker`), `ProofsView`. `@Observable` view models calling `shared` repos via `async/await` (SKIE).
- [ ] Token persistence via the shared `TokenStore` (Keychain) → auto-login.
- [ ] App config for `baseUrl` (Cloudflare hostname / localhost).
- [ ] `swiftui-pro` review pass over the Swift; fold in fixes.

### MOB-3: Handoff
- [ ] Final `README` build/run steps (simulator), and a short "wire to a real device + the deployed API" note (HTTPS already via Cloudflare).
- [ ] Push `pentana-mobile` to its remote.

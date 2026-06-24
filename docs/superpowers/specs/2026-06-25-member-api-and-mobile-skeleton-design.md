# Member API + Mobile Walking Skeleton — Design

**Goal:** Stand up the foundation for a native member app — a Sanctum-secured JSON API on the existing Laravel backend, plus a KMP (shared logic) + SwiftUI (iOS) "walking skeleton" that exercises the whole pipeline on one feature: **Bills/dues**.

**Walking skeleton (the one vertical slice):** a member can **log in**, see **what they owe + payment history**, and **submit a payment proof** (which an admin still reviews on the web). Auth is email+password → token (passkeys later).

**Three pieces:**
1. **Laravel member API** — built and fully tested in this repo (`pentana-system`). The verifiable deliverable.
2. **KMP shared module** — authored here, compiled by the user.
3. **SwiftUI iOS app** — authored here, compiled by the user in Xcode.
Both 2 & 3 live in a **new private repo `pentana-mobile`**.

---

## 1. Laravel API (this repo)

Install **Laravel Sanctum** (`php artisan install:api`), add `HasApiTokens` to `User`, expose a versioned `/api/v1` surface. Conventions: Laravel API Resources (`{ "data": … }`), Form Request validation (422 on failure), `auth:sanctum` on everything except login, and **every query strictly scoped to `auth()->user()`** (a member can only ever see their own data).

### Endpoints

| Method | Path | Auth | Body / Notes |
|---|---|---|---|
| `POST` | `/api/v1/login` | none (throttled 5/min) | `{ email, password, device_name }` → `{ token, user }`. 401 on bad creds. Only **activated** accounts with a password can log in (claimed members). |
| `POST` | `/api/v1/logout` | sanctum | revokes the current access token |
| `GET` | `/api/v1/me` | sanctum | `{ data: { id, name, email, member_category, birthday, credit } }` |
| `GET` | `/api/v1/bills` | sanctum | `{ data: [ { id, month, amount_due, amount_paid, outstanding, status } ] }` (member's `FeePeriod`s, newest first) |
| `GET` | `/api/v1/bills/summary` | sanctum | `{ data: { total_outstanding, available_credit, unpaid_count, bill_count } }` |
| `GET` | `/api/v1/payment-proofs` | sanctum | `{ data: [ { id, amount_claimed, amount_verified, status, member_note, admin_note, proof_image_url, submitted_at } ] }` |
| `POST` | `/api/v1/payment-proofs` | sanctum | **multipart**: `proof_image` (image, ≤5 MB), `amount_claimed` (numeric > 0), `member_note?` → creates a **pending** `PaymentProof` for the member; returns `201 { data: proof }` |

### Data shapes
- **Bill** (from `FeePeriod::forMember`): `amount_paid` = `allocations_sum_amount_allocated ?? 0`; `outstanding` = `max(0, amount_due − amount_paid)`; `month` = `month_date` (`Y-m`).
- **Summary**: `total_outstanding` summed across bills; `available_credit` = `user.credit`; counts of unpaid vs total.
- **PaymentProof**: `proof_image_url` via `Storage::url(proof_image_path)`; `submitted_at` = `created_at`.

### Proof submission
`POST /payment-proofs` stores the upload to `storage/app/public/payment-proofs`, creates `PaymentProof{ user_id: auth, status: 'pending', amount_claimed, member_note, proof_image_path }`. The existing admin FeePayments tool reviews/approves it (creates `PaymentAllocation`s) exactly as today — **no admin-side change needed**. This is the only new member-facing capability.

### Security / validation
- Login throttled; generic 401 (no user enumeration).
- `auth:sanctum` + per-user scoping on every data route (no `id`-based lookups that could cross users).
- Upload: `mimes:jpg,jpeg,png,webp`, `max:5120`; `amount_claimed` numeric, > 0.
- Tokens: single ability for v1 (plain access token); `logout` revokes the current token.

### Tests (PHPUnit, in this repo)
Per endpoint: 401 without a token; correct shape with a token; **scoping** (member A cannot see member B's bills/proofs); login success/failure + throttle; unactivated/passwordless login rejected; proof upload stores the file + a pending row and is owned by the caller; summary math. `Storage::fake('public')` for uploads.

---

## 2. KMP shared module (`pentana-mobile`, authored)

Gradle KMP project (`androidTarget`, `iosArm64`, `iosSimulatorArm64`). Module `shared`:
- **Ktor client** with a bearer-auth plugin that reads a `TokenStore`; base URL configurable.
- **`kotlinx.serialization`** DTOs mirroring the API: `User`, `Bill`, `BillsSummary`, `PaymentProof`, `LoginResponse`.
- **`TokenStore`** — `expect` interface; iOS `actual` backed by **Keychain** (Android actual later).
- **Repositories** exposing `suspend` functions: `AuthRepository` (login/logout/me), `BillsRepository` (bills/summary/proofs/submitProof).
- **SKIE** Gradle plugin so Swift sees `async/await` (+ `Flow`→`AsyncSequence`).
- A simple `ApiResult`/error type for surfacing failures.

## 3. SwiftUI app (iOS, authored)

`iosApp` consuming the `shared` framework:
- **Screens:** Login → Bills (outstanding/credit header + list with status badges) → Submit Proof (`PhotosPicker` + amount + note) → Proof status list.
- `NavigationStack`; lightweight `@Observable`/`ObservableObject` view models calling the shared repositories via `async/await`.
- Keychain-backed token (via the shared `TokenStore`) → auto-login when a token exists.
- Configurable API base URL (the deployed Cloudflare hostname, or localhost for dev).
- `README` with exact build steps; I'll run `swiftui-pro` over the Swift.

---

## Out of scope (later)
Lunch / Activities / profile-edit on mobile; native **passkeys**; online payments; the **Android app** (the shared module is Android-ready, but no Compose UI yet); push notifications; offline cache.

## Delivery split
- **Verified here:** the Laravel API (TDD, runs against the suite; Sanctum migration applied to Supabase on confirmation).
- **Authored, you build:** `pentana-mobile` (KMP + SwiftUI) — I create the repo and all source; you open it in Xcode/Android Studio and compile.

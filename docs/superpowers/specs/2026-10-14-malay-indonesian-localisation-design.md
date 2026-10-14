# Malay and Indonesian localisation — design

**Date:** 2026-10-14
**Status:** Approved in conversation; awaiting spec review
**Scope:** Web (public pages) and the `/account` Filament portal, member and admin screens

## Goal

Members and admins can use PENTANA in English, Bahasa Melayu (`ms`) or Bahasa Indonesia (`id`).
Each person is asked once, their choice is remembered on the device and on their account, and
the emails and notifications they receive arrive in that language.

Success looks like:

- A first-time visitor is asked to pick a language, and is never asked again on that device.
- Every screen of the web app and portal renders fully in the chosen language, with no flash
  of English on page load or Livewire re-render.
- A member set to Indonesian receives Indonesian email, push and in-app bell text.
- Languages are switched on by configuration after native review, independently of code deploys.

## Decisions taken

| Question | Decision |
|---|---|
| What does the setting cover? | Screens **and** a saved per-account preference that drives email and notifications. The mobile app's own screens are out of scope. |
| Which portal screens? | **All** — member and admin. |
| Who translates? | Claude drafts `ms` and `id`; a native speaker reviews before go-live. |
| First-visit behaviour | **Ask** the visitor; save to `localStorage`, mirrored to a cookie so the server can render. |
| Mechanism | Laravel **JSON translation files keyed by the English string**, plus `laravel-lang` for framework strings. |

`ms` is the ISO 639-1 code for Malay (`my` is Burmese). The switcher labels it **BM**.

## 1. Choosing and remembering the language

### Where the choice lives

Resolved on every request, first match wins:

1. `users.locale` — new nullable `string(5)` column — when the request is authenticated.
2. The `locale` cookie.
3. Nothing: render in the default locale (`en`) and show the chooser.

A value only counts if it is in the enabled set (`config('app.locales')`, see §4). An unknown or
disabled value is ignored, as if absent.

### `SetLocale` middleware

- Applies `App::setLocale()` and `Carbon::setLocale()` from the resolution above.
- Shares `$localeChosen` (bool) with views so layouts know whether to show the chooser.
- Registered on the `web` group and on the Filament panel's middleware stack, and added to
  Livewire's **persistent middleware** — without that, a Livewire update request renders in
  the default locale and a form flips back to English mid-interaction.

### Saving a choice

`POST /locale` with `locale` in the enabled set:

- sets the `locale` cookie (1 year, `SameSite=Lax`, not HTTP-only so the restore script can
  read it). The cookie is excluded from `EncryptCookies` — it holds a public two-letter code,
  and an encrypted value would be unreadable to the script;
- when authenticated, also writes `users.locale`;
- is submitted by a plain HTML form and returns a **303 redirect back** to the page it came
  from. The sync script copies the new cookie into `localStorage` on that next load.

Invalid or disabled values get `422` and change nothing.

### First-visit chooser

A small modal — **English · Bahasa Melayu · Bahasa Indonesia** — with the browser's language
(`navigator.language`) pre-highlighted but not pre-selected.

Shown when no choice exists (`$localeChosen === false`) on the public pages (landing, privacy,
claim account) and the portal's login page. Not shown inside the portal for a member whose
account has a locale.

The modal is dismissible only by choosing. Choosing English counts as a choice.

### Cookie and `localStorage` sync

An inline script in the head of every layout keeps the two in step:

- **Cookie missing, `localStorage` set** (cookies were cleared): rewrite the cookie from
  `localStorage` and reload once. A `sessionStorage` guard prevents a reload loop if cookies
  are blocked entirely.
- **Both set and different:** the cookie wins and `localStorage` is updated, no reload. The
  server only changes the cookie deliberately (a choice, or sign-in sync), so it is the newer value.
- **Cookie set, `localStorage` empty:** copy the cookie into `localStorage`.

### Switching later

- **Public pages:** an **EN · BM · ID** segmented control in the header beside the theme toggle;
  on narrow screens a single globe button opening the same three options.
- **Portal:** a "Language" item in Filament's user menu, and a Language field on the Profile page.

Both call `POST /locale` and update `localStorage`.

### Sign-in sync

On the `Login` event:

- if the user has a `locale`, it wins: the response sets the cookie, and the sync script then
  updates `localStorage` from it;
- if the user has none and the request carries a valid cookie, that value is saved to the account.

## 2. What gets translated

### In scope

- **Public pages:** landing, privacy policy, claim-account flow (including Livewire messages),
  guest layout.
- **Portal — member and admin:** every label, heading, placeholder, helper text, table column,
  filter, action, modal, confirmation, toast and empty state across `app/Filament` resources,
  pages and widgets, the profile page, and the passkey views. Filament's own strings switch
  automatically (it ships `ms` and `id`). `spatie/laravel-passkeys` does not, so its keys get
  our own `ms`/`id` entries.
- **Framework strings:** validation, auth, passwords, pagination, throttling — from
  `laravel-lang/lang` (`ms`, `id`).
- **Notifications:** all classes in `app/Notifications` — mail subject and body, push title and
  body, and database (bell) text.
- **Dates:** formatted with `translatedFormat()` so day and month names localise
  (`Friday, 27 June` → `Jumaat, 27 Jun` / `Jumat, 27 Juni`). Money stays `MYR 70.00` in every
  locale.

### Notification language

`User` implements `Illuminate\Contracts\Translation\HasLocalePreference`; `preferredLocale()`
returns `users.locale` if it is enabled, else `null` (English). Laravel then renders each
notification in the recipient's language on every channel, including scheduled commands and
the inline sends triggered when an admin publishes.

Side effect, accepted: **push and bell text shown in the iOS app follows the member's chosen
language**, while the app's own screens remain English.

### Out of scope

- Admin-authored content: lunch menus and options, activity titles, descriptions and
  registration questions, auto-email bodies.
- People's names, member categories, the brand name PENTANA.
- The mobile API's own responses (errors, field messages).
- The mobile apps' UI.

## 3. Translation workflow

- English stays inline in the code: `__('Lunch vote closes Friday')`, `@lang(...)` in Blade,
  `->label(__('...'))` in Filament. Strings with values use placeholders
  (`__('Waitlisted #:position', ['position' => $n])`), never concatenation, so word order can
  change per language.
- Translations live in `lang/ms.json` and `lang/id.json`. A missing key falls back to English.
- Claude drafts both files in natural, idiomatic language rather than word-for-word. Malay and
  Indonesian are translated separately — shared vocabulary is not assumed to mean shared usage.
- A review sheet, `docs/translations/review.xlsx`, lists one row per string: location(s),
  English, Malay, Indonesian, reviewer notes. Privacy-policy rows are highlighted as needing
  review for legal accuracy. Reviewer edits are applied back to the JSON by hand — no import
  tooling for a one-off.

## 4. Rollout

- New config `app.locales`, from `APP_LOCALES` (comma-separated), **default `en`**.
- With only `en` enabled, the chooser, switcher and Profile field are hidden and `POST /locale`
  rejects everything but `en`. The whole build therefore merges and auto-deploys with no
  visible change.
- After review, set `APP_LOCALES=en,ms,id` in the server's `.env` and run `php artisan optimize`.
  A single language can be enabled first.
- Disabling a language later is safe: stored values for it are ignored and those users fall back
  to English until they choose again.

## 5. Testing

- **Resolution:** account beats cookie beats default; unknown and disabled values are ignored;
  `$localeChosen` is false only when nothing valid is stored.
- **Cookie handling:** `locale` is readable unencrypted; the sync script's three cases are
  covered by a small browser check during step 1.
- **Chooser visibility:** shown on public pages with no choice; hidden with a cookie; hidden in
  the portal for a member with a saved locale; hidden entirely when only `en` is enabled.
- **`POST /locale`:** sets the cookie; persists to the account when authenticated; `422` for
  unknown or disabled values.
- **Sign-in sync:** account locale wins and is set as the cookie; an account without one adopts
  the device cookie.
- **Livewire:** a component update request carrying an `ms` cookie renders Malay.
- **Notifications:** a member set to `id` gets Indonesian mail subject, push title and database
  text; a member with no locale gets English.
- **Dates:** `translatedFormat` gives `Jumaat` (`ms`) and `Jumat` (`id`).
- **Completeness guard:** scans `app/` and `resources/views/` for literal `__()`, `trans()` and
  `@lang()` keys and fails if any is missing from `lang/ms.json` or `lang/id.json`. Reports
  unused JSON keys as a warning, not a failure.

These run on SQLite in the suite; the one migration (a nullable string column) has no
Postgres-specific behaviour.

## 6. Build order

Each step is independently shippable because of the `APP_LOCALES` flag.

1. **Plumbing:** `users.locale`, `SetLocale` (web, panel, Livewire persistent), `POST /locale`,
   chooser, switchers, restore script, sign-in sync, `HasLocalePreference`, `app.locales`,
   `laravel-lang`, completeness guard.
2. **Public pages.**
3. **Portal — member screens.**
4. **Portal — admin screens.**
5. **Notifications and emails**, including `translatedFormat` dates.
6. **Review sheet** export.

## Risks

- **String drift:** changing English wording orphans its translations. Mitigated by the
  completeness guard failing the build.
- **Concatenated strings** in existing code (e.g. `'A spot opened up: '.$when`) don't survive
  translation; each is converted to a placeholder string as it is touched.
- **Unreviewed translation reaching members:** prevented by the `APP_LOCALES` default; enabling
  a language is a deliberate step after review.
- **Text length:** Malay and Indonesian often run longer than English. Tight UI (header nav,
  badges, table columns) is checked at 375px in each language during steps 2–4.

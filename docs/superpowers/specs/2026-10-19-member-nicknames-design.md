# Member Nicknames — Design

Date: 2026-10-19
Repos: `pentana-system` (Laravel + Filament) and `pentana-mobile` (KMP shared + iOS + Android)

## Why

Members are stored under their full, formal names, such as "Muhammad Fahmi bin Dzulqarnain". But everyone knows them by a short name ("Fahmi"). Today the full name is the only name, so:

- admins scanning lunch responses, activity registrations and payments have to translate formal names into the people they know, and
- members are greeted by a name nobody uses for them.

A nickname lets each member say what they're actually called. The full name stays as the record of who they are, for matching bank transfers and payment proofs.

Members do not see other members anywhere today; every list of people is in the admin panel. So the admin panel is where nicknames help with recognition. On the member side, nicknames make greetings and emails personal.

## Goals

- Each member can set, change or clear their own nickname on the web profile and in both mobile apps.
- Admins see the nickname next to the full name on every admin screen that lists members, and can search by nickname.
- Members are greeted by their nickname on the web, in the apps and in friendly emails.

## Non-goals

- No new member-facing lists of other members, such as a "who's coming to lunch" list. That was considered and set aside.
- Admins don't assign nicknames. They can only view or clear one (see below).
- The CSV import and invitation flow stay as they are. A nickname is set after the member claims their account.

## Anti-goals (what would make this a failure)

- Losing the full name anywhere an admin needs it to match money or records. The full name must stay visible next to the nickname on admin screens.
- A nickname replacing the full name in places that need the formal identity: invitations, payment records and the member's own profile.

## Data and rules

- New nullable `nickname` column on `users`.
- Optional. Blank input, or input that is only spaces, is stored as `null`. Leading and trailing spaces are trimmed.
- Maximum 30 characters.
- **Unique, ignoring case:** "Fahmi" and "fahmi" can't both exist. Enforce this in validation and in the database. Prod runs on Postgres (Supabase) and tests run on SQLite, so the database rule must work on both. A unique index on `lower(nickname)` works in both, but run a check against Postgres too, not only the SQLite suite.
- Uniqueness is checked only against other nicknames, not against full names.
- **Display name** = nickname if set, otherwise full name. Put this in one place on the `User` model (for example a `displayName` accessor) and use it everywhere a member-facing greeting appears.
- **Greetings and initials without a nickname keep today's behaviour** *(builder-check call)*. The mobile greeting currently shows only the first word of the full name (`ContentView.swift:83`), and avatars use initials of the full name. With a nickname, use the nickname. Without one, keep exactly what happens today, so members who never set a nickname see no change.
- **Disabling a member clears their nickname** *(decided)*, so the name is free for someone else. Re-enabling doesn't restore it.

## Where it shows

### Member-facing: nickname only (falls back to full name)

- Web account panel: the user menu, avatar initials and any greeting.
- Mobile apps: the greeting or header that currently uses `name`.
- Emails and notifications that **already** use the member's name now use the display name. The lunch-published and vote-nudge messages don't greet by name today, so they stay unchanged *(builder-check call: no new copy in this round)*.
- Admin-written birthday templates: the `{name}` placeholder (`SendBirthdayEmails.php`) now fills in the display name *(decided)*. Update the hint in `AutoEmailForm.php` in all three languages to say it uses the nickname when one is set.
- **Exception:** the member invitation keeps the full name. It's sent before the member can set a nickname anyway.

### Member's own profile: both names

- Web profile (`app/Filament/Auth/Profile.php`): keep the existing full-name field and add an editable "Nickname" field below it, with a short hint such as "What people call you. Shown instead of your full name."
- Mobile profile screens (`iosApp/ProfileView.swift`, `androidApp/.../profile/ProfileSheet.kt`): show the full name and an editable nickname field.

### Admin panel: two lines

When a member has a nickname, show it in **bold** with the full name in smaller grey text underneath. With no nickname, show the full name on one line as today. Build this once as a shared column or entry helper so every screen looks the same.

Apply it on:

- the Members (Users) table and the member view/edit page. Admins can see the nickname here and clear it, for example if it's inappropriate. Admins can't type a new one.
- lunch responses (who ordered what),
- activity registrations (`RegistrationsRelationManager`),
- payments and bills: fee payments table and view page, fee periods, the payment-proof review, and the treasurer finance summary.

Places that use a dropdown to pick a member (for example the `FeePeriodForm` member select) should show "Nickname (Full name)" in each option, because a dropdown option can't hold two lines.

### Admin search

Wherever admins search for or pick members, a nickname match counts as well. Typing "Fahmi" should find the member by nickname. That covers table search, global search (which currently searches `user.name`) and member select dropdowns.

## API (consumed by the mobile apps)

- `UserResource` gains `nickname` (nullable) and `display_name`. `name` stays the full name, so older app versions keep working.
- New endpoint for members to update their own nickname, such as `PATCH /api/v1/me` with `{ "nickname": "..." | null }`. It uses the same validation as the web, and a uniqueness failure returns a normal 422 validation error.
- `GET /api/v1/me` keeps working as it does today, plus the new fields.

## Localisation

The app ships English, Malay and Indonesian. Every new label, hint and validation message needs all three translations, through the existing `lang/` files on web and the existing string resources in the apps.

## Left to the builder

- The exact migration and index syntax, and whether display-name logic lives in an accessor or a helper.
- How to build the reusable two-line admin column (a custom view column, a `description()` on `TextColumn`, or similar).
- The mobile UX details for editing (inline field, sheet or save button), following each app's existing profile patterns. Shared Kotlin code should own the API call and model changes, in line with the KMP sharing approach already in the repo.
- Test coverage. At minimum: validation (length, trimming, case-insensitive uniqueness), the API endpoint, the display-name fallback and nickname search.

## Rollout

This repo auto-deploys to production at pentana.silentmode.net, and dev shares the prod Supabase database. So the migration runs on real data as soon as it's merged or run locally. The column is nullable and additive, so the risk is low, but be aware of it. The mobile apps should handle `nickname` and `display_name` being missing until the server change is live.

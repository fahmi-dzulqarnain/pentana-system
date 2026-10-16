# Several menus on one lunch day — design

Date: 2026-10-16
Repos: `pentana-system` (Laravel + Filament admin/portal + API), `pentana-mobile` (KMP shared + Android Compose + iOS SwiftUI)

## Why

Committee feedback (QA, 2026-10-16): a lunch day can only hold **one caterer and one menu**. They
need days where food comes from **two different caterers**, and days with **two different menus
from the same caterer**. Today the admin cannot enter either — `lunches.date` is unique and a
lunch row carries a single `caterer_id` + `caterer_meal_id`.

## What the user decided

- **Menus on the same day are alternatives.** Each member picks **one meal across all of that
  day's menus**, or says **"Not attending" once for the whole day**. A member can never hold two
  meals on one day. Switching to a meal on another menu replaces the previous pick.
- **Older app versions:** keep working normally on one-menu days. On a day with two or more
  menus they show a message asking the member to update the app (they must not be able to order
  the wrong meal).

## Goals

- Admin can add one or more menus to a lunch day; menus may be from different caterers or the
  same caterer (any `caterer_meal`, each at most once per day).
- Members (portal "My Lunch", Android, iOS) see one card per day listing every menu under its
  caterer + menu name, each with its enabled options, and pick one meal or not attending.
- Committee sees order counts **per menu** (each caterer needs its own count) as well as the
  day total; the cost estimate sums every menu at its own prices.
- Existing lunches keep working unchanged as one-menu days after migration.

## Non-goals

- Per-menu deadlines, per-menu publishing or per-menu notifications. The **day** stays the unit:
  one deadline, one status (draft → published → closed), one "Lunch published" notification,
  one vote nudge. *(My call — matches today's behaviour and the "one pick per day" rule.)*
- Ordering from more than one menu on a day.
- Any change to how lunch is billed/paid.

## Anti-goals (would make this a failure even if it "works")

- A member ending up with two meals on one day, or "not attending" counted once per menu.
- Members receiving two "Lunch published" notifications or nudges for the same day.
- An old app silently recording the wrong meal on a two-menu day (e.g. the same "Regular"
  option exists on both menus and the server guesses).
- Existing lunches/responses losing data in the migration.

## Data model (my call)

Keep `lunches` as **one row per day** (`date` stays unique; `deadline`, `status`,
`vote_nudge_sent_at` stay on it). Move caterer + menu down a level:

- **New `lunch_menus`**: `id`, `lunch_id` (cascade), `caterer_id`, `caterer_meal_id`, `sort`,
  timestamps; unique `(lunch_id, caterer_meal_id)`.
- **`lunch_options`**: add `lunch_menu_id` (cascade). Unique becomes
  `(lunch_menu_id, meal_option_id)` instead of `(lunch_id, meal_option_id)` — the same global
  meal option (e.g. "Regular") may appear on two menus the same day. Keep `lunch_id` on the row
  for easy day-level queries.
- **`lunch_responses`**: still unique `(lunch_id, user_id)` — this is what enforces "one pick per
  day". Add `lunch_option_id` (nullable, FK to `lunch_options`). Keep `meal_option_id` as today:
  **`meal_option_id = null` still means not attending**, and the count methods keep reading it.
  `lunch_option_id` only records *which menu's* option was picked. *(My call — see orphans below.)*
- **Drop** `lunches.caterer_id` and `lunches.caterer_meal_id` after the data migration.

Data migration: for each existing lunch create one `lunch_menus` row from its caterer/menu, point
its `lunch_options` at it, and fill `lunch_responses.lunch_option_id` by matching
`(lunch_id, meal_option_id)`. Must run on Postgres (prod Supabase) as well as the SQLite tests.

**Orphan responses.** Today a menu change rebuilds `lunch_options` but leaves responses alone, so
some responses may carry a `meal_option_id` that is no longer an option on their lunch. The
migration leaves those rows as they are (`meal_option_id` kept, `lunch_option_id` null), so
historical attending counts don't change. *(My call.)* Before running it in prod, count orphans on
Supabase and report the number.

Alternative rejected: allowing several `lunches` rows per date. It would duplicate deadlines,
notifications and nudges, double-count "not attending", and need extra rules to stop two picks.

## Admin (Filament)

- **Lunch form:** date, deadline, status as today. Caterer + Menu become a **repeater of menus**
  (Caterer select → Menu select filtered by caterer), at least one required, no duplicate menu.
  On edit, each menu shows its own options (enabled toggle + default count) like today's options
  repeater.
- **Changing or removing a menu** on an existing lunch: its options are rebuilt or removed, as
  `EditLunch::afterSave` does today for a menu change.
  - **Swapping a menu:** a pick carries over when the same meal option (same `meal_option_id`)
    exists on the new menu. This matches today. *(My call.)*
  - **Picks that can't carry over:** those members' responses are deleted, so they show as "not
    responded" rather than "not attending". *(My call.)*
  - **Adding a menu:** existing picks are left alone.
  - **While the day is open** (draft, or published before the deadline), menus can be swapped or
    removed even after members have ordered. *(User's decision.)* If the save would drop any picks,
    the admin first sees a confirmation with the count ("3 members' picks will be removed"). After
    saving, each affected member gets a push + email (one `MemberNotifier` notification, sent
    inline; there is no queue worker) saying their pick for that day was removed and asking them to
    choose again.
  - **Once ordering has closed** (status `closed`, or the deadline has passed), that day's menus are
    locked. They can't be added, removed or swapped, so the counts already given to caterers stay
    intact. *(User's decision.)* Option toggles and default counts follow the same lock. *(My call.)*
- **Orders summary** on edit: grouped per menu — "Caterer · Menu: Option A: n · Option B: n" —
  then the day's not attending / total ordering.
- **Lunches table:** caterer/menu column lists all menus of the day.
- **Lunch calendar:** still one cell per day; the cell lists every menu. Month stats:
  "days planned" counts days (unchanged meaning), cost sums all menus, avg orders/day unchanged.

## Members — portal "My Lunch"

One card per day. Under the date, a section per menu (heading "Caterer — Menu"), each with its
option buttons; one "Not attending" for the day. The chosen option is highlighted wherever it
is. Voting rules stay in `LunchVotingService`.

## Voting service

`respond(Lunch $lunch, User $user, ?int $lunchOptionId)`: lunch must be open; a non-null id must
be an **enabled `lunch_options` row belonging to this lunch**. `updateOrCreate` on
`(lunch_id, user_id)` stores `lunch_option_id` and the matching `meal_option_id`.

## API (`/api/v1/lunches`, `/lunches/{lunch}/respond`, dashboard)

Additive, so old apps keep parsing (the app uses `ignoreUnknownKeys`).

Each lunch gains:

```json
"menus": [
  { "id": 12, "caterer": "Dapur Selera", "menu": "Nasi Lemak Royale",
    "options": [ { "lunch_option_id": 81, "meal_option_id": 3, "name": "Regular" } ] }
],
"my_lunch_option_id": 81
```

Legacy fields kept for old apps:

- **One-menu day:** `caterer`, `menu`, `options[].meal_option_id`, `my_meal_option_id` exactly as
  today, so old apps behave exactly as before.
- **Two+ menus:** `options` is **empty** and `menu` carries a short, translated message, e.g.
  "Several menus today — update the app to order". `caterer` lists the caterers joined with " · ".
  The old app then shows the message and only "Not attending" (which is unambiguous). *(Reason:
  old apps show a fixed "Couldn't save your choice" on any respond error, so the update message
  has to arrive through the list, not the error.)*

Respond body accepts `lunch_option_id` (new) **or** `meal_option_id` (legacy) or neither (not
attending). Legacy `meal_option_id` is accepted only when it resolves to exactly one enabled
option on that lunch; otherwise 422.

Dashboard `next_lunch`: `caterer`/`menu` follow the same one-menu vs several-menus rule; add
`menus` (caterer + menu names) for the new app.

## Mobile app (`pentana-mobile`)

- `LunchDto` gains `menus` and `myLunchOptionId`; new `LunchMenuDto`, option DTO gains
  `lunchOptionId`. `RespondRequest` sends `lunch_option_id`.
- `LunchStore.choose(lunchId, lunchOptionId)`; Android `LunchScreen` and iOS `LunchView` render a
  section per menu inside the day card, highlight by `myLunchOptionId`.
- Dashboard card shows the menus of the next lunch.
- Shared tests (`LunchStoreTest`, `PentanaApiTest`) updated for the new shape.

## Notifications

"Lunch published" and the vote nudge stay one per day. Their text names the menus/caterers
(e.g. "Dapur Selera · The Daily Grind") instead of a single menu. Strings go through `__()` with
ms/id translations like the rest of the app.

## Left to the builder

- Exact Filament component layout, ordering of menus (`sort`), copy wording (keep it plain and
  translated).
- Whether `lunch_options.lunch_id` is kept or derived; whether `meal_option_id` on responses is
  kept as a column or computed — as long as the legacy API fields come out right.
- Test coverage shape; must include the Postgres-sensitive migration and the anti-goals above.
